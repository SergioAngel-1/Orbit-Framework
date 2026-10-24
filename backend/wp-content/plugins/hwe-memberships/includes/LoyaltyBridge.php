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
 *
 * También guarda la lectura del referidor que membresías necesita (§7.5): `loyalty` no publica
 * la atribución, así que `ReferralGift` la recuerda en la meta `_hwe_membership_referrer` al
 * recibir `hwe_loyalty_attribution_confirmed` y aquí se lee para la comisión extra y el regalo.
 * Es el único dato de `loyalty` que se guarda, y es un id numérico.
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

	/** Meta del referido con el id del referidor N1 (la atribución no es pública en `loyalty`). */
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
	 * Referidor N1 recordado para un usuario; `0` si no hay atribución o el id ya no existe.
	 * El dato lo escribe `ReferralGift` al confirmarse la atribución, porque `loyalty` no
	 * publica su lectura.
	 */
	public static function referrerOf( int $userId ): int {
		if ( $userId <= 0 || ! function_exists( 'get_user_meta' ) ) {
			return 0;
		}

		$raw = get_user_meta( $userId, self::REFERRER_META, true );
		$id  = is_scalar( $raw ) ? (int) $raw : 0;
		if ( $id <= 0 || $id === $userId ) {
			return 0;
		}

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
