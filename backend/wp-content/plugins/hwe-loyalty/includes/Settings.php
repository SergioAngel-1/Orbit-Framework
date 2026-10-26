<?php
/**
 * Ajustes del módulo `loyalty` (fidelización): el grupo reservado del Control Center y la
 * lectura saneada que consume el resto del plugin.
 *
 * El grupo raíz `loyalty` lo declara el núcleo vacío (`SchemaGroups/Loyalty.php`) y este
 * archivo lo puebla con el filtro `hwe_control_center_schema`: así la pestaña de ajustes, la
 * API pública (`/wp-json/hwe/v1/config`), `wp hwe setup` y la revalidación de `site-config`
 * heredan la misma configuración sin código propio.
 *
 * Reglas de lectura:
 *  · Todo valor se lee con `\HWE\ControlCenter\Storage::get([...], $default)` y, si el Control
 *    Center no está disponible, con los defaults de `DEFAULTS` (nunca fatal).
 *  · Un valor absurdo se recorta al leer (foco de revisión 5): porcentajes a [0, 100], enteros
 *    a ≥ 0, `expiry_days` negativo a 0 y `min_cash_due` ≤ 0 al mínimo positivo `MIN_CASH_DUE`.
 *    Con `point_value` ≤ 0 el canje queda desactivado (falla cerrado).
 *  · «Puntos activos» exige, además del ajuste, que el MÓDULO esté activo. El plugin cargado es
 *    la señal; si el Control Center está disponible se confirma con `Modules::isActive('loyalty')`
 *    (lee `active_plugins`), para que incluir el archivo a mano no encienda el programa.
 *  · Referidos y transferencias exigen puntos activos: sin puntos no hay unidad con la que pagar.
 *
 * No hay lógica de libro, canje ni referidos aquí: solo ajustes y sus interruptores.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Settings {

	/** Id del módulo en el catálogo del Control Center (`Modules::CORE_IDS`). */
	public const MODULE_ID = 'loyalty';

	/**
	 * Mínimo positivo al que se eleva un `min_cash_due` ≤ 0. Es el menor importe con dos
	 * decimales: con el pago completo apagado, un pedido nunca queda a deber 0.
	 */
	public const MIN_CASH_DUE = 0.01;

	/** Decimales a los que se redondean los valores monetarios por punto. */
	public const DECIMALS = 4;

	/**
	 * Defaults del módulo: la fuente única en PHP. Los usa el esquema (para que el panel y la
	 * API pública tengan el mismo valor) y la lectura de respaldo sin Control Center. El
	 * espejo de TypeScript vive en `frontend/src/lib/config/{types,defaults}.ts`.
	 *
	 * Los campos `number` guardan texto (convención del Control Center: `NumberType` persiste
	 * la cadena ya acotada); `point_value` y `min_cash_due` son `text` porque admiten decimales
	 * y `number` solo enteros.
	 *
	 * @var array<string,mixed>
	 */
	public const DEFAULTS = array(
		'points_enabled'            => false,
		'referrals_enabled'         => false,
		'currency_name'             => 'punto',
		'currency_name_plural'      => 'puntos',
		'currency_icon'             => '',
		'point_value'               => '0.01',
		'min_redeem'                => '100',
		'max_redeem_per_order'      => '0',
		'max_redeem_percent'        => '0',
		'full_payment_enabled'      => false,
		'min_cash_due'              => '0.01',
		'expiry_days'               => '0',
		'cashback_enabled'          => false,
		'cashback_percent'          => '0',
		'events'                    => array(
			'registration' => '0',
			'review'       => '0',
			'birthday'     => '0',
		),
		'referral_signup_points_l1' => '0',
		'referral_signup_points_l2' => '0',
		'commission_first_percent'  => '0',
		'commission_next_percent'   => '0',
		'level2_enabled'            => false,
		'commission_level2_percent' => '0',
		'commission_duration_days'  => '0',
		'transfers_enabled'         => false,
		'transfer_min'              => '100',
		'transfer_fee_percent'      => '0',
		'transfer_daily_cap'        => '0',
		'allowed_roles'             => 'customer, subscriber',
	);

	/** Engancha el esquema del panel. Lo llama `HWE_Loyalty::boot()` con WooCommerce presente. */
	public static function register(): void {
		add_filter( 'hwe_control_center_schema', array( self::class, 'schema' ) );
		add_action( 'hwe_control_center_saved', array( self::class, 'onSaved' ), 10, 1 );
	}

	/**
	 * Los niveles de membresía anuncian como «beneficios disponibles» los que dependen de estos
	 * interruptores (puntos, referidos, envío): al guardar la config se invalida su tag ISR o la
	 * lista seguiría prometiendo ventajas apagadas hasta que caduque la ventana ISR. El tag es del
	 * núcleo del Control Center, así que se puede invalidar aunque `memberships` esté apagado.
	 *
	 * @param mixed $publicData Config pública ya persistida (no se usa: cualquier guardado puede
	 *                          cambiar los interruptores de los que dependen los beneficios).
	 */
	public static function onSaved( $publicData = null ): void {
		if ( class_exists( 'HWE\\ControlCenter\\Revalidation' ) ) {
			\HWE\ControlCenter\Revalidation::trigger( 'memberships' );
		}
	}

	// ------------------------------------------------------------------------
	//  Esquema del Control Center
	// ------------------------------------------------------------------------

	/**
	 * Puebla el grupo raíz `loyalty` (el núcleo lo declara vacío y reservado al módulo).
	 *
	 * @param mixed $schema
	 * @return mixed
	 */
	public static function schema( $schema ) {
		if (
			! is_array( $schema )
			|| ! isset( $schema['loyalty']['children'] )
			|| ! is_array( $schema['loyalty']['children'] )
		) {
			return $schema;
		}

		$schema['loyalty']['children'] += self::fields();

		return $schema;
	}

	/**
	 * Definición de cada campo de spec §9.1. Todos públicos salvo `allowed_roles`.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function fields(): array {
		$d = self::DEFAULTS;

		return array(
			'points_enabled'            => array(
				'type'        => 'boolean',
				'label'       => 'Programa de puntos',
				'default'     => $d['points_enabled'],
				'public'      => true,
				'description' => 'Enciende el monedero: ganar, canjear y transferir. Apagado, el resto de ajustes de puntos no se aplica.',
			),
			'referrals_enabled'         => array(
				'type'        => 'boolean',
				'label'       => 'Programa de referidos',
				'default'     => $d['referrals_enabled'],
				'public'      => true,
				'description' => 'Comisiones y puntos por alta de referidos. Requiere el programa de puntos: sin él se comporta como apagado.',
			),
			'currency_name'             => array(
				'type'        => 'text',
				'label'       => 'Nombre de la unidad (singular)',
				'default'     => $d['currency_name'],
				'public'      => true,
				'description' => 'Cómo se llama la unidad en singular en los textos que compone el módulo. Por defecto, «punto».',
			),
			'currency_name_plural'      => array(
				'type'        => 'text',
				'label'       => 'Nombre de la unidad (plural)',
				'default'     => $d['currency_name_plural'],
				'public'      => true,
				'description' => 'Cómo se llama la unidad en plural. Por defecto, «puntos».',
			),
			'currency_icon'             => array(
				'type'        => 'image',
				'label'       => 'Icono de la unidad',
				'default'     => $d['currency_icon'],
				'public'      => true,
				'description' => 'Imagen de la medioteca o URL. Vacío = sin icono.',
			),
			'point_value'               => array(
				'type'        => 'text',
				'label'       => 'Valor de 1 unidad en la moneda de la tienda',
				'default'     => $d['point_value'],
				'public'      => true,
				'description' => 'Decimal (p. ej. 0.01). Convierte a unidades los porcentajes (cashback y comisiones) y el canje. Con 0 o menos, el canje queda desactivado y los porcentajes se pagan 1 unidad = 1 de la moneda.',
			),
			'min_redeem'                => array(
				'type'        => 'number',
				'label'       => 'Mínimo de unidades para canjear',
				'default'     => $d['min_redeem'],
				'min'         => 0,
				'public'      => true,
				'description' => 'Por debajo de esta cantidad no se aplica el canje. 0 = sin mínimo.',
			),
			'max_redeem_per_order'      => array(
				'type'        => 'number',
				'label'       => 'Máximo de unidades por pedido',
				'default'     => $d['max_redeem_per_order'],
				'min'         => 0,
				'public'      => true,
				'description' => 'Tope de unidades que se pueden canjear en un pedido. 0 = sin tope.',
			),
			'max_redeem_percent'        => array(
				'type'        => 'number',
				'label'       => 'Tope del canje sobre la base sin envío (%)',
				'default'     => $d['max_redeem_percent'],
				'min'         => 0,
				'max'         => 100,
				'public'      => true,
				'description' => 'Porcentaje máximo de la base sin envío (productos y cargos ajenos) que puede cubrir el canje. Se redondea a la baja. 0 = sin tope.',
			),
			'full_payment_enabled'      => array(
				'type'        => 'boolean',
				'label'       => 'Permitir pagar el pedido completo con puntos',
				'default'     => $d['full_payment_enabled'],
				'public'      => true,
				'description' => 'Apagado, el canje siempre deja por pagar en dinero al menos el mínimo de abajo (aunque el envío sea gratis).',
			),
			'min_cash_due'              => array(
				'type'        => 'text',
				'label'       => 'Mínimo a pagar en dinero',
				'default'     => $d['min_cash_due'],
				'public'      => true,
				'description' => 'Importe en la moneda de la tienda que debe quedar por pagar con el pago completo apagado. Un valor 0 o negativo se eleva al mínimo positivo (0.01).',
			),
			'expiry_days'               => array(
				'type'        => 'number',
				'label'       => 'Caducidad de las unidades (días)',
				'default'     => $d['expiry_days'],
				'min'         => 0,
				'public'      => true,
				'description' => 'Días desde el abono hasta que caduca. 0 = no caducan. Cambiarlo solo afecta a abonos nuevos.',
			),
			'cashback_enabled'          => array(
				'type'        => 'boolean',
				'label'       => 'Devolver un porcentaje de la compra al comprador',
				'default'     => $d['cashback_enabled'],
				'public'      => true,
				'description' => 'Apagado por defecto. Solo cuenta la parte pagada en dinero y sin envío.',
			),
			'cashback_percent'          => array(
				'type'        => 'number',
				'label'       => 'Porcentaje de devolución por compra',
				'default'     => $d['cashback_percent'],
				'min'         => 0,
				'max'         => 100,
				'public'      => true,
				'description' => 'Porcentaje de la base de compra que recibe el comprador, en valor: se convierte a unidades con el valor de la unidad. 0 = no devuelve nada.',
			),
			'events'                    => array(
				'type'     => 'group',
				'label'    => 'Unidades por evento',
				'children' => array(
					'registration' => array(
						'type'        => 'number',
						'label'       => 'Al confirmar la cuenta',
						'default'     => $d['events']['registration'],
						'min'         => 0,
						'public'      => true,
						'description' => 'Se abonan una sola vez, al confirmarse la cuenta. 0 = apagado.',
					),
					'review'       => array(
						'type'        => 'number',
						'label'       => 'Al publicar una reseña',
						'default'     => $d['events']['review'],
						'min'         => 0,
						'public'      => true,
						'description' => 'Una por pedido reseñado. 0 = apagado.',
					),
					'birthday'     => array(
						'type'        => 'number',
						'label'       => 'Cumpleaños',
						'default'     => $d['events']['birthday'],
						'min'         => 0,
						'public'      => true,
						'description' => 'Se abonan una vez por año natural. 0 = apagado.',
					),
				),
			),
			'referral_signup_points_l1' => array(
				'type'        => 'number',
				'label'       => 'Unidades al referidor por el alta (nivel 1)',
				'default'     => $d['referral_signup_points_l1'],
				'min'         => 0,
				'public'      => true,
				'description' => 'Se abonan al confirmarse la cuenta del referido. 0 = no abona.',
			),
			'referral_signup_points_l2' => array(
				'type'        => 'number',
				'label'       => 'Unidades al referidor de nivel 2 por el alta',
				'default'     => $d['referral_signup_points_l2'],
				'min'         => 0,
				'public'      => true,
				'description' => 'Solo con el nivel 2 encendido. 0 = no abona.',
			),
			'commission_first_percent'  => array(
				'type'        => 'number',
				'label'       => 'Comisión del referidor en la primera compra (%)',
				'default'     => $d['commission_first_percent'],
				'min'         => 0,
				'max'         => 100,
				'public'      => true,
				'description' => 'Porcentaje de la base de la primera compra pagada del referido, en valor: se convierte a unidades con el valor de la unidad.',
			),
			'commission_next_percent'   => array(
				'type'        => 'number',
				'label'       => 'Comisión del referidor en las siguientes compras (%)',
				'default'     => $d['commission_next_percent'],
				'min'         => 0,
				'max'         => 100,
				'public'      => true,
				'description' => 'Porcentaje de la base de las compras posteriores a la primera, en valor: se convierte a unidades con el valor de la unidad.',
			),
			'level2_enabled'            => array(
				'type'        => 'boolean',
				'label'       => 'Pagar también al referidor de nivel 2',
				'default'     => $d['level2_enabled'],
				'public'      => true,
				'description' => 'Apagado por defecto: solo cobra el referidor directo.',
			),
			'commission_level2_percent' => array(
				'type'        => 'number',
				'label'       => 'Comisión del referidor de nivel 2 (%)',
				'default'     => $d['commission_level2_percent'],
				'min'         => 0,
				'max'         => 100,
				'public'      => true,
				'description' => 'Porcentaje para el referidor del referidor, en valor: se convierte a unidades con el valor de la unidad. Solo con el nivel 2 encendido.',
			),
			'commission_duration_days'  => array(
				'type'        => 'number',
				'label'       => 'Duración de la comisión (días)',
				'default'     => $d['commission_duration_days'],
				'min'         => 0,
				'public'      => true,
				'description' => 'Días desde la confirmación de la atribución en los que se paga comisión. 0 = sin límite.',
			),
			'transfers_enabled'         => array(
				'type'        => 'boolean',
				'label'       => 'Permitir transferencias entre usuarios',
				'default'     => $d['transfers_enabled'],
				'public'      => true,
				'description' => 'Apagado por defecto. Se envían por el código de referido del receptor.',
			),
			'transfer_min'              => array(
				'type'        => 'number',
				'label'       => 'Mínimo de unidades por transferencia',
				'default'     => $d['transfer_min'],
				'min'         => 0,
				'public'      => true,
				'description' => 'Por debajo de esta cantidad no se puede transferir.',
			),
			'transfer_fee_percent'      => array(
				'type'        => 'number',
				'label'       => 'Comisión de la tienda por transferencia (%)',
				'default'     => $d['transfer_fee_percent'],
				'min'         => 0,
				'max'         => 100,
				'public'      => true,
				'description' => 'Porcentaje que se queda la tienda. Se redondea hacia arriba. 0 = sin comisión.',
			),
			'transfer_daily_cap'        => array(
				'type'        => 'number',
				'label'       => 'Tope diario de unidades transferidas',
				'default'     => $d['transfer_daily_cap'],
				'min'         => 0,
				'public'      => true,
				'description' => 'Máximo que un usuario puede transferir por día. 0 = sin tope.',
			),
			'allowed_roles'             => array(
				'type'        => 'text',
				'label'       => 'Roles que participan',
				'default'     => $d['allowed_roles'],
				// No se publica: es un ajuste operativo del backend, no un dato de la tienda.
				'description' => 'Roles de WordPress separados por comas. Por defecto, «customer, subscriber» (el registro del framework crea suscriptores).',
			),
		);
	}

	// ------------------------------------------------------------------------
	//  Lectura saneada
	// ------------------------------------------------------------------------

	/**
	 * Todos los ajustes, ya saneados y con los tipos nativos del módulo (bool, int, float).
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$d = self::DEFAULTS;

		return array(
			'points_enabled'            => self::toBool( self::read( 'points_enabled', $d['points_enabled'] ) ),
			'referrals_enabled'         => self::toBool( self::read( 'referrals_enabled', $d['referrals_enabled'] ) ),
			'currency_name'             => self::unitName( self::read( 'currency_name', $d['currency_name'] ), $d['currency_name'] ),
			'currency_name_plural'      => self::unitName( self::read( 'currency_name_plural', $d['currency_name_plural'] ), $d['currency_name_plural'] ),
			'currency_icon'             => self::toUrl( self::read( 'currency_icon', $d['currency_icon'] ) ),
			'point_value'               => self::toDecimal( self::read( 'point_value', $d['point_value'] ), 0.0 ),
			'min_redeem'                => self::toInt( self::read( 'min_redeem', $d['min_redeem'] ) ),
			'max_redeem_per_order'      => self::toInt( self::read( 'max_redeem_per_order', $d['max_redeem_per_order'] ) ),
			'max_redeem_percent'        => self::toPercent( self::read( 'max_redeem_percent', $d['max_redeem_percent'] ) ),
			'full_payment_enabled'      => self::toBool( self::read( 'full_payment_enabled', $d['full_payment_enabled'] ) ),
			'min_cash_due'              => self::toDecimal( self::read( 'min_cash_due', $d['min_cash_due'] ), self::MIN_CASH_DUE ),
			'expiry_days'               => self::toInt( self::read( 'expiry_days', $d['expiry_days'] ) ),
			'cashback_enabled'          => self::toBool( self::read( 'cashback_enabled', $d['cashback_enabled'] ) ),
			'cashback_percent'          => self::toPercent( self::read( 'cashback_percent', $d['cashback_percent'] ) ),
			'events'                    => array(
				'registration' => self::toInt( self::readEvent( 'registration', $d['events']['registration'] ) ),
				'review'       => self::toInt( self::readEvent( 'review', $d['events']['review'] ) ),
				'birthday'     => self::toInt( self::readEvent( 'birthday', $d['events']['birthday'] ) ),
			),
			'referral_signup_points_l1' => self::toInt( self::read( 'referral_signup_points_l1', $d['referral_signup_points_l1'] ) ),
			'referral_signup_points_l2' => self::toInt( self::read( 'referral_signup_points_l2', $d['referral_signup_points_l2'] ) ),
			'commission_first_percent'  => self::toPercent( self::read( 'commission_first_percent', $d['commission_first_percent'] ) ),
			'commission_next_percent'   => self::toPercent( self::read( 'commission_next_percent', $d['commission_next_percent'] ) ),
			'level2_enabled'            => self::toBool( self::read( 'level2_enabled', $d['level2_enabled'] ) ),
			'commission_level2_percent' => self::toPercent( self::read( 'commission_level2_percent', $d['commission_level2_percent'] ) ),
			'commission_duration_days'  => self::toInt( self::read( 'commission_duration_days', $d['commission_duration_days'] ) ),
			'transfers_enabled'         => self::toBool( self::read( 'transfers_enabled', $d['transfers_enabled'] ) ),
			'transfer_min'              => self::toInt( self::read( 'transfer_min', $d['transfer_min'] ) ),
			'transfer_fee_percent'      => self::toPercent( self::read( 'transfer_fee_percent', $d['transfer_fee_percent'] ) ),
			'transfer_daily_cap'        => self::toInt( self::read( 'transfer_daily_cap', $d['transfer_daily_cap'] ) ),
			'allowed_roles'             => self::allowedRoles(),
		);
	}

	/** El módulo está activo (plugin cargado y, si hay Control Center, presente en `active_plugins`). */
	public static function moduleActive(): bool {
		if ( class_exists( 'HWE\\ControlCenter\\Modules' ) ) {
			return \HWE\ControlCenter\Modules::isActive( self::MODULE_ID );
		}
		return defined( 'HWE_LOYALTY_VERSION' );
	}

	/** «Puntos activos»: el ajuste encendido y el módulo activo. */
	public static function pointsActive(): bool {
		return self::moduleActive() && self::get()['points_enabled'];
	}

	/** Referidos activos: puntos activos y el interruptor de referidos encendido. */
	public static function referralsActive(): bool {
		return self::pointsActive() && self::get()['referrals_enabled'];
	}

	/** Transferencias activas: puntos activos y el interruptor de transferencias encendido. */
	public static function transfersActive(): bool {
		return self::pointsActive() && self::get()['transfers_enabled'];
	}

	/** Canje activo: puntos activos y un valor por unidad positivo (si no, falla cerrado). */
	public static function redeemActive(): bool {
		return self::pointsActive() && self::get()['point_value'] > 0;
	}

	/**
	 * Roles que participan del programa. Filtrable con `hwe_loyalty_participant_roles`.
	 *
	 * @return list<string>
	 */
	public static function allowedRoles(): array {
		$configured = self::roleList( self::read( 'allowed_roles', self::DEFAULTS['allowed_roles'] ) );
		$filtered   = apply_filters( 'hwe_loyalty_participant_roles', $configured );

		if ( ! is_array( $filtered ) ) {
			error_log(
				'[hwe] loyalty: el filtro hwe_loyalty_participant_roles devolvió '
				. get_debug_type( $filtered )
				. '; se usan los roles configurados.'
			);
			return $configured;
		}

		return self::roleList( $filtered );
	}

	/**
	 * ¿Puede participar este usuario? Debe existir, tener algún rol permitido y el programa
	 * de puntos debe estar activo. Un id inexistente nunca participa.
	 */
	public static function canParticipate( int $userId ): bool {
		if ( $userId <= 0 || ! self::pointsActive() ) {
			return false;
		}
		if ( ! function_exists( 'get_userdata' ) ) {
			return false;
		}

		$user = get_userdata( $userId );
		if ( ! is_object( $user ) || empty( $user->roles ) || ! is_array( $user->roles ) ) {
			return false;
		}

		$allowed = self::allowedRoles();
		foreach ( $user->roles as $role ) {
			if ( is_string( $role ) && in_array( $role, $allowed, true ) ) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------------------
	//  Lectura y saneado de bajo nivel
	// ------------------------------------------------------------------------

	/**
	 * Lee `loyalty.<clave>` del Control Center. Sin Control Center (o sin WordPress) devuelve
	 * el default: el módulo nunca falla por una lectura.
	 */
	private static function read( string $key, mixed $default ): mixed {
		return self::readPath( array( self::MODULE_ID, $key ), $default );
	}

	private static function readEvent( string $event, mixed $default ): mixed {
		return self::readPath( array( self::MODULE_ID, 'events', $event ), $default );
	}

	/**
	 * @param list<string> $path
	 */
	private static function readPath( array $path, mixed $default ): mixed {
		if ( ! class_exists( 'HWE\\ControlCenter\\Storage' ) || ! function_exists( 'get_option' ) ) {
			return $default;
		}
		try {
			$value = \HWE\ControlCenter\Storage::get( $path, $default );
		} catch ( \Throwable $e ) {
			error_log( '[hwe] loyalty: no se pudo leer la configuración: ' . $e->getMessage() );
			return $default;
		}
		return null === $value ? $default : $value;
	}

	private static function toBool( mixed $raw ): bool {
		if ( is_bool( $raw ) ) {
			return $raw;
		}
		if ( is_int( $raw ) || is_float( $raw ) ) {
			return 0 !== (int) $raw;
		}
		$value = strtolower( trim( (string) ( is_scalar( $raw ) ? $raw : '' ) ) );
		return in_array( $value, array( '1', 'true', 'on', 'yes', 'sí', 'si' ), true );
	}

	/** Entero ≥ 0; redondea hacia abajo (puntos = enteros). */
	private static function toInt( mixed $raw ): int {
		if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) ) {
			return 0;
		}
		return max( 0, (int) floor( (float) $raw ) );
	}

	/** Porcentaje entero acotado a [0, 100]. */
	private static function toPercent( mixed $raw ): int {
		return min( 100, self::toInt( $raw ) );
	}

	/** Decimal con `DECIMALS` y un mínimo (el suelo del ajuste). */
	private static function toDecimal( mixed $raw, float $min ): float {
		$value = is_scalar( $raw ) && is_numeric( $raw ) ? (float) $raw : 0.0;
		return max( $min, round( $value, self::DECIMALS ) );
	}

	/** Nombre de la unidad: texto no vacío; vacío cae al nombre genérico por defecto. */
	private static function unitName( mixed $raw, string $default ): string {
		$value = self::toText( $raw );
		return '' === $value ? $default : $value;
	}

	private static function toText( mixed $raw ): string {
		$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		return function_exists( 'sanitize_text_field' ) ? (string) sanitize_text_field( $value ) : $value;
	}

	private static function toUrl( mixed $raw ): string {
		$value = self::toText( $raw );
		return function_exists( 'esc_url_raw' ) ? (string) esc_url_raw( $value ) : $value;
	}

	/**
	 * Normaliza una lista de roles desde un array o una cadena separada por comas. Sin roles
	 * válidos devuelve una lista vacía: nadie participa (falla cerrado).
	 *
	 * @return list<string>
	 */
	private static function roleList( mixed $raw ): array {
		if ( is_array( $raw ) ) {
			$parts = $raw;
		} else {
			$parts = preg_split( '/[\s,]+/', is_scalar( $raw ) ? (string) $raw : '' ) ?: array();
		}

		$roles = array();
		foreach ( $parts as $part ) {
			if ( ! is_scalar( $part ) ) {
				continue;
			}
			$role = strtolower( trim( (string) $part ) );
			$role = (string) preg_replace( '/[^a-z0-9_-]/', '', $role );
			if ( '' !== $role && ! in_array( $role, $roles, true ) ) {
				$roles[] = $role;
			}
		}
		return $roles;
	}
}
