<?php
/**
 * Ajustes del módulo `memberships`: el grupo reservado del Control Center y la lectura saneada
 * que consume el resto del plugin.
 *
 * El grupo raíz `memberships` lo declara el núcleo vacío (`SchemaGroups/Memberships.php`) y este
 * archivo lo puebla con el filtro `hwe_control_center_schema`: así la pestaña de ajustes, la API
 * pública (`/wp-json/hwe/v1/config`), `wp hwe setup` y la revalidación de `site-config` heredan
 * la misma configuración sin código propio.
 *
 * Reglas de lectura:
 *  · Todo valor se lee con `\HWE\ControlCenter\Storage::get([...], $default)` y, si el Control
 *    Center no está disponible, con los defaults de `DEFAULTS` (nunca fatal).
 *  · Un valor absurdo se recorta al leer: el TTL del token a [1, 720] horas, los días de aviso a
 *    ≥ 0 y la ruta del QR a una ruta del frontend que empieza por `/`.
 *  · «Carné activo» exige, además del ajuste, que el MÓDULO esté activo. El plugin cargado es la
 *    señal; si el Control Center está disponible se confirma con `Modules::isActive('memberships')`
 *    (lee `active_plugins`), para que incluir el archivo a mano no encienda el carné.
 *
 * No hay lógica de niveles, concesiones ni ciclo de membresía aquí: solo ajustes e interruptores.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Settings {

	/** Id del módulo en el catálogo del Control Center (`Modules::CORE_IDS`). */
	public const MODULE_ID = 'memberships';

	/** Ruta del frontend que verifica el carné cuando no hay una configurada. */
	public const DEFAULT_VERIFY_PATH = '/verify';

	/** Horas máximas de vigencia del token del carné (30 días). */
	public const MAX_TOKEN_TTL_HOURS = 720;

	/** Horas mínimas de vigencia del token del carné. */
	public const MIN_TOKEN_TTL_HOURS = 1;

	/** Roles que participan por defecto (el registro del framework crea suscriptores). */
	public const DEFAULT_ROLES = array( 'customer', 'subscriber' );

	/**
	 * Defaults del módulo: la fuente única en PHP. Los usa el esquema (para que el panel y la
	 * API pública tengan el mismo valor) y la lectura de respaldo sin Control Center. El
	 * espejo de TypeScript vive en `frontend/src/lib/config/{types,defaults}.ts` (solo los
	 * campos públicos).
	 *
	 * Los campos `number` guardan texto (convención del Control Center: `NumberType` persiste
	 * la cadena ya acotada).
	 *
	 * @var array<string,mixed>
	 */
	public const DEFAULTS = array(
		'card_enabled'                              => false,
		'card_verify_path'                          => self::DEFAULT_VERIFY_PATH,
		'member_number_prefix'                      => '',
		'card_token_ttl_hours'                      => '24',
		'expiring_notice_days'                      => '7',
		'referral_gift_requires_email_verification' => true,
	);

	/** Engancha el esquema del panel. Lo llama `HWE_Memberships::boot()` con WooCommerce presente. */
	public static function register(): void {
		add_filter( 'hwe_control_center_schema', array( self::class, 'schema' ) );
	}

	// ------------------------------------------------------------------------
	//  Esquema del Control Center
	// ------------------------------------------------------------------------

	/**
	 * Puebla el grupo raíz `memberships` (el núcleo lo declara vacío y reservado al módulo).
	 *
	 * @param mixed $schema
	 * @return mixed
	 */
	public static function schema( $schema ) {
		if (
			! is_array( $schema )
			|| ! isset( $schema['memberships']['children'] )
			|| ! is_array( $schema['memberships']['children'] )
		) {
			return $schema;
		}

		$schema['memberships']['children'] += self::fields();

		return $schema;
	}

	/**
	 * Definición de cada campo de spec §10.1. Públicos: los tres del carné que necesita el
	 * frontend. Los demás son ajustes operativos del backend.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function fields(): array {
		$d = self::DEFAULTS;

		return array(
			'card_enabled'                              => array(
				'type'        => 'boolean',
				'label'       => 'Carné de socio',
				'default'     => $d['card_enabled'],
				'public'      => true,
				'description' => 'Enciende el carné digital con su verificación por QR. Apagado, el resto de ajustes del carné no se aplican.',
			),
			'card_verify_path'                          => array(
				'type'        => 'text',
				'label'       => 'Ruta de verificación del carné',
				'default'     => $d['card_verify_path'],
				'public'      => true,
				'description' => 'Ruta del frontend a la que apunta el QR del carné (p. ej. /verify). Si no empieza por «/», se normaliza al leer.',
			),
			'member_number_prefix'                      => array(
				'type'        => 'text',
				'label'       => 'Prefijo del número de socio',
				'default'     => $d['member_number_prefix'],
				'public'      => true,
				'description' => 'Texto corto que precede al número de socio (p. ej. «SOC-»). Vacío = sin prefijo.',
			),
			'card_token_ttl_hours'                      => array(
				'type'        => 'number',
				'label'       => 'Vigencia del token del carné (horas)',
				'default'     => $d['card_token_ttl_hours'],
				'min'         => self::MIN_TOKEN_TTL_HOURS,
				'max'         => self::MAX_TOKEN_TTL_HOURS,
				// No se publica: es un ajuste operativo del backend.
				'description' => 'Horas que vale el token del carné digital (1–720). Fuera de rango se recorta al leer.',
			),
			'expiring_notice_days'                      => array(
				'type'        => 'number',
				'label'       => 'Días de aviso antes de caducar',
				'default'     => $d['expiring_notice_days'],
				'min'         => 0,
				// No se publica: es un ajuste operativo del backend.
				'description' => 'Con cuántos días de antelación se dispara el aviso de membresía a punto de caducar. 0 = sin aviso.',
			),
			'referral_gift_requires_email_verification' => array(
				'type'        => 'boolean',
				'label'       => 'El regalo al referido exige correo verificado',
				'default'     => $d['referral_gift_requires_email_verification'],
				// No se publica: es un ajuste operativo del backend.
				'description' => 'Encendido, la membresía de regalo al referido solo se concede cuando confirma su correo. Apagado, se concede al registrarse.',
			),
		);
	}

	// ------------------------------------------------------------------------
	//  Lectura saneada
	// ------------------------------------------------------------------------

	/**
	 * Todos los ajustes, ya saneados y con los tipos nativos del módulo (bool, int, string).
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$d = self::DEFAULTS;

		return array(
			'card_enabled'                              => self::toBool( self::read( 'card_enabled', $d['card_enabled'] ) ),
			'card_verify_path'                          => self::verifyPath( self::read( 'card_verify_path', $d['card_verify_path'] ) ),
			'member_number_prefix'                      => self::toText( self::read( 'member_number_prefix', $d['member_number_prefix'] ) ),
			'card_token_ttl_hours'                      => self::clampedInt(
				self::read( 'card_token_ttl_hours', $d['card_token_ttl_hours'] ),
				(int) $d['card_token_ttl_hours'],
				self::MIN_TOKEN_TTL_HOURS,
				self::MAX_TOKEN_TTL_HOURS
			),
			'expiring_notice_days'                      => self::clampedInt( self::read( 'expiring_notice_days', $d['expiring_notice_days'] ), (int) $d['expiring_notice_days'], 0, null ),
			'referral_gift_requires_email_verification' => self::toBool( self::read( 'referral_gift_requires_email_verification', $d['referral_gift_requires_email_verification'] ) ),
		);
	}

	/** El módulo está activo (plugin cargado y, si hay Control Center, presente en `active_plugins`). */
	public static function moduleActive(): bool {
		if ( class_exists( 'HWE\\ControlCenter\\Modules' ) ) {
			return \HWE\ControlCenter\Modules::isActive( self::MODULE_ID );
		}
		return defined( 'HWE_MEMBERSHIPS_VERSION' );
	}

	/** «Carné activo»: el ajuste encendido y el módulo activo. */
	public static function cardActive(): bool {
		return self::moduleActive() && self::get()['card_enabled'];
	}

	/**
	 * Roles que participan del programa. Filtrable con `hwe_memberships_participant_roles`.
	 *
	 * @return list<string>
	 */
	public static function participantRoles(): array {
		$defaults = self::DEFAULT_ROLES;
		$filtered = apply_filters( 'hwe_memberships_participant_roles', $defaults );

		if ( ! is_array( $filtered ) ) {
			error_log(
				'[hwe] memberships: el filtro hwe_memberships_participant_roles devolvió '
				. get_debug_type( $filtered )
				. '; se usan los roles por defecto.'
			);
			return $defaults;
		}

		return self::roleList( $filtered );
	}

	// ------------------------------------------------------------------------
	//  Lectura y saneado de bajo nivel
	// ------------------------------------------------------------------------

	/**
	 * Lee `memberships.<clave>` del Control Center. Sin Control Center (o sin WordPress) devuelve
	 * el default: el módulo nunca falla por una lectura.
	 */
	private static function read( string $key, mixed $default ): mixed {
		if ( ! class_exists( 'HWE\\ControlCenter\\Storage' ) || ! function_exists( 'get_option' ) ) {
			return $default;
		}
		try {
			$value = \HWE\ControlCenter\Storage::get( array( self::MODULE_ID, $key ), $default );
		} catch ( \Throwable $e ) {
			error_log( '[hwe] memberships: no se pudo leer la configuración: ' . $e->getMessage() );
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

	/**
	 * Entero dentro de [min, max]; un valor no numérico o vacío usa el default. `max` null = sin
	 * tope superior.
	 */
	private static function clampedInt( mixed $raw, int $default, int $min, ?int $max ): int {
		if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) ) {
			return $default;
		}
		$value = (int) floor( (float) $raw );
		$value = max( $min, $value );
		return null === $max ? $value : min( $max, $value );
	}

	/**
	 * Ruta del frontend para el QR: siempre absoluta desde la raíz. Un valor vacío cae al
	 * default; los espacios se recortan y las barras iniciales se colapsan para que no pueda
	 * convertirse en una URL protocol-relative (`//otro-dominio`).
	 */
	private static function verifyPath( mixed $raw ): string {
		$path = ltrim( self::toText( $raw ), '/' );
		return '' === $path ? self::DEFAULT_VERIFY_PATH : '/' . $path;
	}

	private static function toText( mixed $raw ): string {
		$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		return function_exists( 'sanitize_text_field' ) ? (string) sanitize_text_field( $value ) : $value;
	}

	/**
	 * Normaliza una lista de roles desde un array. Sin roles válidos devuelve una lista vacía:
	 * nadie participa (falla cerrado).
	 *
	 * @param list<mixed> $raw
	 * @return list<string>
	 */
	private static function roleList( array $raw ): array {
		$roles = array();
		foreach ( $raw as $part ) {
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
