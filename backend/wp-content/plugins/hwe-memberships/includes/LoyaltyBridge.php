<?php
/**
 * Puente único entre membresías y `loyalty` (spec §7.4, §7.5 y §8).
 *
 * Membresías no conoce las clases internas de `loyalty`: solo usa su contrato publicado
 * (`includes/Api.php` y los hooks de su README). Este archivo concentra esa conversación para
 * que el resto del módulo no tenga que comprobar disponibilidad ni capturar errores:
 *
 *  · `available()` pregunta si una función del programa (`points` o `referrals`) está
 *    encendida. Si el plugin no está cargado, la función global no existe y responde `false`.
 *  · `register()` declara los dos tipos de movimiento propios (`membership_activation` y
 *    `membership_referral_bonus`) como abonos con `hwe_loyalty_entry_types`. Solo engancha
 *    ese filtro con el módulo de membresías activo y `loyalty` cargado con alguna de sus dos
 *    funciones encendida: con todo apagado no queda nada registrado en sus hooks.
 *  · `credit()` y `reverseCredit()` son la única vía de abono y reversión. Nunca dejan caer
 *    una activación: cualquier fallo de `loyalty` se registra y se devuelve `null`.
 *  · `referrerOf()` lee el referidor **confirmado** del contrato de `loyalty` en el momento del
 *    cálculo. Antes se cacheaba en la meta `_hwe_membership_referrer` al confirmarse la
 *    atribución, pero esa copia divergía cuando `loyalty` reasignaba, anonimizaba o
 *    desvinculaba la red. La meta solo se conserva para exportar y borrar restos de versiones
 *    anteriores (`REFERRER_META`); este puente ya no la escribe ni la lee.
 *  · `lineEarns()` se engancha a `hwe_loyalty_line_earns` para que las líneas de un plan de
 *    membresía no generen puntos (base, cashback, comisiones y bono por producto). La instancia
 *    lo revierte con «Los planes generan puntos de fidelización» (`plans_earn_points`).
 *
 * Nada de aquí compone textos para el cliente ni registra datos personales: solo ids y claves
 * técnicas en `error_log('[hwe] memberships: …')`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_LoyaltyBridge {

	/** Tipo de movimiento de los puntos por activación / renovación de una compra. */
	public const ENTRY_ACTIVATION = 'membership_activation';

	/** Tipo de movimiento de la comisión extra del referidor de un socio. */
	public const ENTRY_REFERRAL_BONUS = 'membership_referral_bonus';

	/**
	 * Meta heredada con el id del referidor. Las versiones anteriores la escribían al confirmar
	 * la atribución; ya no se lee ni se escribe, solo la usan la migración y la privacidad para
	 * borrar restos.
	 */
	public const REFERRER_META = '_hwe_membership_referrer';

	/** Avisos ya emitidos en esta petición, para no repetirlos en cada lectura. */
	private static array $logged = array();

	/**
	 * ¿Está `loyalty` cargado y expone su contrato? Con el plugin inactivo las funciones
	 * globales no existen y no hay nada a lo que engancharse.
	 */
	public static function loaded(): bool {
		return function_exists( 'hwe_loyalty_available' ) && function_exists( 'hwe_loyalty_credit' );
	}

	/** Engancha la declaración de tipos. Lo llama `HWE_Memberships::boot()`. */
	public static function register(): void {
		if (
			! class_exists( 'HWE_Memberships_Settings' )
			|| ! HWE_Memberships_Settings::moduleActive()
		) {
			return;
		}
		if ( ! self::loaded() ) {
			return;
		}

		// Con «Puntos» y «Referidos» apagados no hay ningún abono posible: no se declara nada
		// en un hook de `loyalty` (spec §8).
		if ( ! self::available( 'points' ) && ! self::available( 'referrals' ) ) {
			return;
		}

		add_filter( 'hwe_loyalty_entry_types', array( self::class, 'entryTypes' ) );
		// Las líneas de un plan no puntúan salvo que la instancia active `plans_earn_points`.
		add_filter( 'hwe_loyalty_line_earns', array( self::class, 'lineEarns' ), 10, 3 );
	}

	/**
	 * Excluye las líneas de un plan de membresía de la base de ganancias y del bono por producto.
	 *
	 * `loyalty` aplica `hwe_loyalty_line_earns` a cada línea con `true` por defecto. Aquí se
	 * devuelve `false` para un producto de membresía (o una variación suya) mientras la instancia
	 * no active «Los planes generan puntos de fidelización». Nunca se pisa una decisión anterior:
	 * un `false` de otro filtro se respeta, y un valor no booleano se devuelve tal cual para que
	 * `loyalty` lo registre y lo trate como `true`.
	 *
	 * @param mixed $earns Valor recibido (por defecto `true`).
	 * @param mixed $item  Línea de pedido (`WC_Order_Item_Product`).
	 * @param mixed $order Pedido (`WC_Order`).
	 * @return mixed
	 */
	public static function lineEarns( $earns, $item, $order ) {
		if ( ! is_bool( $earns ) || ! $earns ) {
			return $earns;
		}

		if ( self::plansEarnPoints() || ! class_exists( 'HWE_Memberships_Products' ) ) {
			return true;
		}

		foreach ( self::lineProductIds( $item ) as $productId ) {
			if ( HWE_Memberships_Products::isMembershipLine( $productId ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * ¿La instancia quiere que los planes generen puntos? Falla cerrado (no puntúan) si el
	 * ajuste no se puede leer: es la misma decisión por defecto del Control Center.
	 */
	private static function plansEarnPoints(): bool {
		if ( ! class_exists( 'HWE_Memberships_Settings' ) ) {
			return false;
		}

		try {
			$settings = HWE_Memberships_Settings::get();
		} catch ( \Throwable $e ) {
			self::logOnce(
				'earn:settings',
				'no se pudo leer «planes generan puntos»; los planes no puntúan: ' . $e->getMessage()
			);
			return false;
		}

		return ! empty( $settings['plans_earn_points'] );
	}

	/**
	 * Ids de producto de una línea de pedido: el producto y su variación, cuando existan. En una
	 * línea de variación, WooCommerce guarda el padre en `product_id` y la variación en
	 * `variation_id`.
	 *
	 * @param mixed $item
	 * @return list<int>
	 */
	private static function lineProductIds( $item ): array {
		if ( ! is_object( $item ) ) {
			return array();
		}

		$ids = array();
		foreach ( array( 'get_product_id', 'get_variation_id' ) as $method ) {
			if ( ! method_exists( $item, $method ) ) {
				continue;
			}
			$id = (int) $item->{$method}();
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Declara los tipos propios como abonos. Nunca pisa una declaración existente: si alguien
	 * ya declaró la clave con otro lado, se conserva y se registra (falla cerrada).
	 *
	 * @param mixed $types
	 * @return mixed
	 */
	public static function entryTypes( $types ) {
		if ( ! is_array( $types ) ) {
			self::logOnce( 'types:not-array', 'el filtro hwe_loyalty_entry_types no recibió un mapa; no se declaran los tipos de membresías.' );
			return $types;
		}

		foreach ( array( self::ENTRY_ACTIVATION, self::ENTRY_REFERRAL_BONUS ) as $type ) {
			if ( array_key_exists( $type, $types ) ) {
				if ( 'credit' !== ( $types[ $type ] ?? null ) ) {
					self::logOnce(
						'type:side:' . $type,
						'el tipo «' . $type . '» ya estaba declarado como ' . ( is_scalar( $types[ $type ] ) ? (string) $types[ $type ] : 'otro lado' ) . '; se conserva y los abonos fallarán cerrados.'
					);
				}
				continue;
			}
			$types[ $type ] = 'credit';
		}

		return $types;
	}

	/**
	 * ¿Está encendida una función del programa (`points` o `referrals`)? Cualquier fallo se
	 * resuelve como no disponible.
	 */
	public static function available( string $feature ): bool {
		if ( ! function_exists( 'hwe_loyalty_available' ) ) {
			return false;
		}

		try {
			return (bool) hwe_loyalty_available( $feature );
		} catch ( \Throwable $e ) {
			self::logOnce(
				'available:' . $feature,
				'no se pudo comprobar si «' . self::label( $feature ) . '» está disponible en loyalty: ' . $e->getMessage()
			);
			return false;
		}
	}

	/**
	 * Abona puntos con el contrato de `loyalty`. Devuelve el id del movimiento o `null` si no
	 * procede (función apagada, usuario que no participa, tipo no declarado o clave repetida).
	 *
	 * @param array<mixed> $ref
	 * @param array<mixed> $data
	 */
	public static function credit(
		int $userId,
		int $points,
		string $type,
		string $key,
		array $ref = array(),
		array $data = array()
	): ?int {
		if ( $userId <= 0 || $points <= 0 || '' === trim( $type ) || '' === trim( $key ) ) {
			return null;
		}
		if ( ! self::available( 'points' ) || ! function_exists( 'hwe_loyalty_credit' ) ) {
			return null;
		}

		try {
			$entryId = hwe_loyalty_credit( $userId, $points, $type, $key, $ref, $data );
		} catch ( \Throwable $e ) {
			self::logOnce(
				'credit:' . self::label( $type ),
				'el abono «' . self::label( $type ) . '» al usuario ' . $userId . ' falló: ' . $e->getMessage()
			);
			return null;
		}

		return is_int( $entryId ) && $entryId > 0 ? $entryId : null;
	}

	/**
	 * Revierte un abono por su clave. No exige que «Puntos» siga encendido: es una corrección
	 * contable, igual que las reversiones internas de `loyalty`.
	 */
	public static function reverseCredit( string $key, string $reversalKey ): ?int {
		if ( '' === trim( $key ) || '' === trim( $reversalKey ) || ! function_exists( 'hwe_loyalty_reverse_credit' ) ) {
			return null;
		}

		try {
			$entryId = hwe_loyalty_reverse_credit( $key, $reversalKey );
		} catch ( \Throwable $e ) {
			self::logOnce(
				'reverse:' . self::label( $key ),
				'la reversión del abono «' . self::label( $key ) . '» falló: ' . $e->getMessage()
			);
			return null;
		}

		return is_int( $entryId ) && $entryId > 0 ? $entryId : null;
	}

	/**
	 * Referidor N1 confirmado de un usuario; `0` si no hay atribución, sigue pendiente, el
	 * referidor ya no existe o `loyalty` no está cargado. Lee el contrato
	 * `hwe_loyalty_referrer_of()` en cada cálculo (nunca una copia cacheada), así que sigue la
	 * atribución vigente cuando `loyalty` la reasigna, la anonimiza o la desvincula.
	 */
	public static function referrerOf( int $userId ): int {
		if ( $userId <= 0 || ! function_exists( 'hwe_loyalty_referrer_of' ) ) {
			return 0;
		}

		try {
			$raw = hwe_loyalty_referrer_of( $userId );
		} catch ( \Throwable $e ) {
			self::logOnce(
				'referrer:' . $userId,
				'no se pudo leer el referidor confirmado del usuario ' . $userId . ': ' . $e->getMessage()
			);
			return 0;
		}

		$id = is_int( $raw ) ? $raw : ( is_numeric( $raw ) ? (int) $raw : 0 );
		if ( $id <= 0 || $id === $userId ) {
			return 0;
		}

		// El contrato ya valida que el referidor exista; se comprueba otra vez para no fiarse de
		// una implementación futura que devuelva un id colgado (falla cerrada).
		if ( function_exists( 'get_userdata' ) && ! is_object( get_userdata( $id ) ) ) {
			return 0;
		}

		return $id;
	}

	/** Texto técnico saneado para un log: solo letras, números y separadores habituales. */
	private static function label( string $value ): string {
		$clean = (string) preg_replace( '/[^a-z0-9_:.\-]/i', '', $value );
		return substr( $clean, 0, 80 );
	}

	private static function logOnce( string $key, string $message ): void {
		if ( isset( self::$logged[ $key ] ) ) {
			return;
		}
		self::$logged[ $key ] = true;
		error_log( '[hwe] memberships: ' . $message );
	}
}
