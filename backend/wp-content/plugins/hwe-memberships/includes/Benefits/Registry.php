<?php
/**
 * Registro único de tipos de beneficio (spec §7.1 y §7.7).
 *
 * Los tipos del núcleo viven en `coreClasses()` (un solo sitio para añadir los que llegan en
 * las tareas de descuentos, envíos, puntos, referidos y acceso anticipado). Una instancia añade
 * los suyos con el filtro `hwe_memberships_benefit_types`, que recibe el mapa `clave → objeto`
 * y debe devolver el mismo mapa ampliado.
 *
 * Reglas del contrato (igual que el registro de tipos de campo del Control Center):
 *  · una entrada que no extiende `HWE_Memberships_Benefit` se descarta y se registra;
 *  · una entrada sin clave válida o cuya clave no coincide con la del mapa se descarta;
 *  · una clave del núcleo (o reservada para una tarea futura) no se puede pisar;
 *  · una clave repetida se queda con la primera declaración;
 *  · un filtro que no devuelve un mapa deja los tipos del núcleo intactos.
 *
 * El saneado de la configuración por nivel usa los campos declarados por cada tipo. Los valores
 * fuera de rango se recortan (números, porcentajes) o se rechazan con aviso (selecciones, tipos
 * de campo desconocidos). Una clave de tipo que este despliegue no conoce se conserva tal cual:
 * así no se pierde la configuración de un tipo de instancia que ahora no está cargado.
 *
 * Los textos que ve el cliente no se componen aquí: `activeFor()` devuelve la clave, los datos
 * estructurados del tipo y su estado.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Benefits {

	/**
	 * Claves del núcleo que todavía no tienen clase (Tareas 9, 12, 13 y 15). Se reservan desde
	 * ya para que un tipo de instancia no pueda ocuparlas antes de que existan.
	 *
	 * @var list<string>
	 */
	private const RESERVED_KEYS = array(
		'member_discount',
		'free_deliveries',
		'activation_points',
		'referral_bonus',
		'referral_gift',
		'early_access',
	);

	/** Formato de una clave de tipo. */
	private const KEY_PATTERN = '/^[a-z0-9_]+$/';

	/** Avisos ya emitidos en esta petición, para no repetirlos en cada lectura. */
	private static array $logged = array();

	/**
	 * Clases de los tipos del núcleo. Las tareas siguientes añaden aquí las suyas; las claves
	 * reservadas de `RESERVED_KEYS` se pueden quitar cuando la clase exista.
	 *
	 * @return list<class-string<HWE_Memberships_Benefit>>
	 */
	private static function coreClasses(): array {
		return array(
			HWE_Memberships_Benefit_Info_Perk::class,
		);
	}

	/**
	 * Mapa `clave → tipo` efectivo: núcleo + filtro.
	 *
	 * @return array<string,HWE_Memberships_Benefit>
	 */
	public static function all(): array {
		$core = array();

		foreach ( self::coreClasses() as $class ) {
			try {
				$benefit = new $class();
			} catch ( \Throwable $e ) {
				self::logOnce( 'core-class:' . $class, 'no se pudo instanciar el tipo de beneficio del núcleo «' . $class . '».' );
				continue;
			}

			if ( ! $benefit instanceof HWE_Memberships_Benefit ) {
				self::logOnce( 'core-base:' . $class, 'el tipo de beneficio del núcleo «' . $class . '» no extiende la clase base; se descarta.' );
				continue;
			}

			try {
				$rawKey = $benefit->key();
			} catch ( \Throwable $e ) {
				self::logOnce( 'core-key:' . $class, 'el tipo de beneficio del núcleo «' . $class . '» falló al declarar su clave; se descarta.' );
				continue;
			}

			$key = self::normalizeKey( $rawKey );
			if ( '' === $key || $key !== $rawKey ) {
				self::logOnce( 'core-key:' . $class, 'el tipo de beneficio del núcleo «' . $class . '» no tiene una clave válida; se descarta.' );
				continue;
			}

			$core[ $key ] = $benefit;
		}

		if ( function_exists( 'apply_filters' ) ) {
			try {
				$filtered = apply_filters( 'hwe_memberships_benefit_types', $core );
			} catch ( \Throwable $e ) {
				self::logOnce( 'filter:threw', 'el filtro hwe_memberships_benefit_types lanzó una excepción; se usan los tipos del núcleo.' );
				$filtered = $core;
			}
		} else {
			$filtered = $core;
		}

		if ( ! is_array( $filtered ) ) {
			self::logOnce(
				'filter:not-array',
				'el filtro hwe_memberships_benefit_types debe devolver un mapa clave → tipo (devolvió '
				. get_debug_type( $filtered )
				. '); se usan los tipos del núcleo.'
			);
			return $core;
		}

		$out      = $core;
		$reserved = array_fill_keys( array_merge( self::RESERVED_KEYS, array_keys( $core ) ), true );

		foreach ( $core as $key => $benefit ) {
			if ( ! array_key_exists( $key, $filtered ) ) {
				self::logOnce( 'removed:' . $key, 'el tipo «' . $key . '» del núcleo fue eliminado por hwe_memberships_benefit_types; se restaura.' );
			}
		}

		foreach ( $filtered as $id => $benefit ) {
			if ( ! is_string( $id ) ) {
				self::logOnce( 'entry:id:' . get_debug_type( $id ), 'entrada descartada de hwe_memberships_benefit_types: el mapa debe usar la clave del tipo como índice.' );
				continue;
			}
			if ( ! $benefit instanceof HWE_Memberships_Benefit ) {
				self::logOnce( 'entry:base:' . $id, 'entrada descartada de hwe_memberships_benefit_types: «' . $id . '» no extiende HWE_Memberships_Benefit.' );
				continue;
			}

			try {
				$rawKey = $benefit->key();
			} catch ( \Throwable $e ) {
				self::logOnce( 'entry:key:' . $id, 'entrada descartada de hwe_memberships_benefit_types: el tipo falló al declarar su clave.' );
				continue;
			}

			$key = self::normalizeKey( $rawKey );
			if ( '' === $key || $key !== $rawKey ) {
				self::logOnce( 'entry:key:' . $id, 'entrada descartada de hwe_memberships_benefit_types: el tipo no tiene una clave válida.' );
				continue;
			}
			if ( $id !== $key ) {
				self::logOnce( 'entry:mismatch:' . $id, 'entrada descartada de hwe_memberships_benefit_types: la clave del mapa «' . $id . '» no coincide con la del tipo «' . $key . '».' );
				continue;
			}

			if ( isset( $core[ $key ] ) ) {
				if ( $benefit === $core[ $key ] ) {
					continue; // El filtro devolvió el tipo del núcleo sin tocarlo.
				}
				self::logOnce( 'entry:core:' . $key, 'entrada descartada de hwe_memberships_benefit_types: «' . $key . '» es una clave del núcleo y no se puede pisar.' );
				continue;
			}

			if ( isset( $reserved[ $key ] ) ) {
				self::logOnce( 'entry:reserved:' . $key, 'entrada descartada de hwe_memberships_benefit_types: «' . $key . '» está reservada para un tipo del núcleo.' );
				continue;
			}

			if ( isset( $out[ $key ] ) ) {
				self::logOnce( 'entry:duplicate:' . $key, 'entrada descartada de hwe_memberships_benefit_types: «' . $key . '» ya estaba declarada.' );
				continue;
			}

			$out[ $key ] = $benefit;
		}

		return $out;
	}

	/**
	 * Claves del núcleo, incluidas las reservadas para las tareas que aún no han llegado.
	 *
	 * @return list<string>
	 */
	public static function coreKeys(): array {
		$keys = self::RESERVED_KEYS;

		foreach ( self::coreClasses() as $class ) {
			try {
				$benefit = new $class();
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( $benefit instanceof HWE_Memberships_Benefit ) {
				try {
					$rawKey = $benefit->key();
				} catch ( \Throwable $e ) {
					continue;
				}
				$key = self::normalizeKey( $rawKey );
				if ( '' !== $key && $key === $rawKey ) {
					$keys[] = $key;
				}
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/** Un tipo por clave; `null` si no existe. */
	public static function get( string $key ): ?HWE_Memberships_Benefit {
		$key = self::normalizeKey( $key );
		if ( '' === $key ) {
			return null;
		}

		return self::all()[ $key ] ?? null;
	}

	/**
	 * Sanea la configuración de beneficios de un nivel. Las claves de tipos registrados se
	 * sanean según sus campos; las que este despliegue no conoce se conservan intactas.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public static function sanitizeLevelConfig( array $raw ): array {
		$clean = $raw;

		foreach ( self::all() as $key => $benefit ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			try {
				$clean[ $key ] = self::sanitizeConfig( $benefit, $raw[ $key ] );
			} catch ( \Throwable $e ) {
				self::logOnce( 'sanitize:' . $key, 'el tipo «' . $key . '» falló al sanear su configuración; se conserva la recibida.' );
			}
		}

		return $clean;
	}

	/**
	 * Sanea la configuración de un tipo concreto. Siempre devuelve `enabled` y un valor por
	 * cada campo declarado; los campos desconocidos se descartan.
	 *
	 * @return array<string,mixed>
	 */
	public static function sanitizeConfig( HWE_Memberships_Benefit $benefit, mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			self::logOnce(
				'config:' . $benefit->key(),
				'la configuración del tipo «' . $benefit->key() . '» no es un mapa; se guarda desactivada.'
			);
			$raw = array();
		}

		$clean = array( 'enabled' => self::toBool( $raw['enabled'] ?? false ) );

		try {
			$fields = $benefit->fields();
		} catch ( \Throwable $e ) {
			self::logOnce( 'fields:' . $benefit->key(), 'el tipo «' . $benefit->key() . '» falló al declarar sus campos; se guarda desactivado.' );
			$fields = array();
		}

		if ( ! is_array( $fields ) ) {
			self::logOnce( 'fields-map:' . $benefit->key(), 'los campos del tipo «' . $benefit->key() . '» no son un mapa; se guarda desactivado.' );
			$fields = array();
		}

		foreach ( $fields as $name => $field ) {
			if ( ! is_string( $name ) || '' === $name || ! is_array( $field ) ) {
				self::logOnce(
					'field:' . $benefit->key() . ':' . ( is_scalar( $name ) ? (string) $name : get_debug_type( $name ) ),
					'campo mal formado en el tipo «' . $benefit->key() . '»; se descarta.'
				);
				continue;
			}

			$value = array_key_exists( $name, $raw )
				? self::sanitizeField( $field, $raw[ $name ] )
				: self::defaultValue( $field );

			if ( null === $value ) {
				continue;
			}

			$clean[ $name ] = $value;
		}

		return $clean;
	}

	/**
	 * Beneficios activos del socio, con su estado público. La clave es la del tipo.
	 *
	 * @return array<string,array{key:string,level_id:int,public:array<string,mixed>,state:array<string,mixed>}>
	 */
	public static function activeFor( int $userId ): array {
		if ( $userId <= 0 ) {
			return array();
		}
		if (
			! class_exists( 'HWE_Memberships_Settings' )
			|| ! HWE_Memberships_Settings::moduleActive()
		) {
			return array();
		}

		$active = array();

		foreach ( self::all() as $key => $benefit ) {
			try {
				if ( ! $benefit->isActiveFor( $userId ) ) {
					continue;
				}

				$state   = $benefit->stateFor( $userId );
				$levelId = (int) ( $state['level_id'] ?? 0 );
				if ( $levelId <= 0 ) {
					continue;
				}

				$active[ $key ] = array(
					'key'      => $key,
					'level_id' => $levelId,
					'public'   => $benefit->publicData( $levelId ),
					'state'    => $state,
				);
			} catch ( \Throwable $e ) {
				self::logOnce( 'active:' . $key, 'el tipo «' . $key . '» falló al calcular su estado; se omite.' );
			}
		}

		return $active;
	}

	/** Engancha los hooks de cada tipo. Solo con el módulo activo. */
	public static function register(): void {
		if (
			! class_exists( 'HWE_Memberships_Settings' )
			|| ! HWE_Memberships_Settings::moduleActive()
		) {
			return;
		}

		foreach ( self::all() as $key => $benefit ) {
			try {
				$benefit->register();
			} catch ( \Throwable $e ) {
				self::logOnce( 'register:' . $key, 'el tipo «' . $key . '» falló al registrar sus hooks; se omite.' );
			}
		}
	}

	// ------------------------------------------------------------------------
	//  Saneado de campos
	// ------------------------------------------------------------------------

	/**
	 * Sanea un valor según el tipo de campo declarado.
	 *
	 * @param array<string,mixed> $field
	 */
	private static function sanitizeField( array $field, mixed $raw ): mixed {
		$type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : '';

		switch ( $type ) {
			case HWE_Memberships_Benefit::FIELD_NUMBER:
				return self::sanitizeInteger( $field, $raw );

			case HWE_Memberships_Benefit::FIELD_PERCENTAGE:
				$field['min'] = 0;
				$field['max'] = 100;
				return self::sanitizeInteger( $field, $raw );

			case HWE_Memberships_Benefit::FIELD_LEVEL:
				$field['min'] = 0;
				return self::sanitizeInteger( $field, $raw );

			case HWE_Memberships_Benefit::FIELD_BOOLEAN:
				return self::toBool( $raw );

			case HWE_Memberships_Benefit::FIELD_SELECT:
				return self::sanitizeSelect( $field, $raw );

			case HWE_Memberships_Benefit::FIELD_CATEGORIES:
				return self::sanitizeCategories( $raw );

			case HWE_Memberships_Benefit::FIELD_TEXT:
				return self::sanitizeText( $raw, $field );

			case HWE_Memberships_Benefit::FIELD_TRANSLATABLE:
				return self::sanitizeTranslations( $raw, $field );
		}

		self::logOnce(
			'field-type:' . $type,
			'campo de beneficio con tipo «' . $type . '» desconocido; se descarta.'
		);

		return null;
	}

	/** Valor por defecto de un campo cuando el nivel no lo trae. */
	private static function defaultValue( array $field ): mixed {
		$type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : '';

		switch ( $type ) {
			case HWE_Memberships_Benefit::FIELD_NUMBER:
				return self::clampInteger( self::integerOr( $field['default'] ?? 0, 0 ), $field );
			case HWE_Memberships_Benefit::FIELD_PERCENTAGE:
				$field['min'] = 0;
				$field['max'] = 100;
				return self::clampInteger( self::integerOr( $field['default'] ?? 0, 0 ), $field );
			case HWE_Memberships_Benefit::FIELD_LEVEL:
				$field['min'] = 0;
				return self::clampInteger( self::integerOr( $field['default'] ?? 0, 0 ), $field );
			case HWE_Memberships_Benefit::FIELD_BOOLEAN:
				return self::toBool( $field['default'] ?? false );
			case HWE_Memberships_Benefit::FIELD_SELECT:
				return self::sanitizeSelect( $field, $field['default'] ?? '' );
			case HWE_Memberships_Benefit::FIELD_CATEGORIES:
				return array();
			case HWE_Memberships_Benefit::FIELD_TEXT:
				return self::sanitizeText( $field['default'] ?? '', $field );
			case HWE_Memberships_Benefit::FIELD_TRANSLATABLE:
				return array();
		}

		return null;
	}

	/** Entero con recorte por `min`/`max`; un valor no entero cae al default. */
	private static function sanitizeInteger( array $field, mixed $raw ): int {
		$default = self::clampInteger( self::integerOr( $field['default'] ?? 0, 0 ), $field );

		if ( ! is_scalar( $raw ) || ! preg_match( '/^-?\d+$/', trim( (string) $raw ) ) ) {
			if ( is_scalar( $raw ) && '' !== trim( (string) $raw ) ) {
				self::logOnce(
					'number:' . self::fieldLabel( $field ),
					'un valor numérico no válido se descartó y se usó el default.'
				);
			}
			return $default;
		}

		$value   = (int) $raw;
		$clamped = self::clampInteger( $value, $field );
		if ( $clamped !== $value ) {
			self::logOnce(
				'range:' . self::fieldLabel( $field ),
				'un valor fuera de rango se recortó.'
			);
		}

		return $clamped;
	}

	/** @param array<string,mixed> $field */
	private static function clampInteger( int $value, array $field ): int {
		if ( isset( $field['min'] ) && is_numeric( $field['min'] ) ) {
			$value = max( (int) $field['min'], $value );
		}
		if ( isset( $field['max'] ) && is_numeric( $field['max'] ) ) {
			$value = min( (int) $field['max'], $value );
		}
		return $value;
	}

	private static function integerOr( mixed $raw, int $default ): int {
		return is_scalar( $raw ) && is_numeric( $raw ) ? (int) $raw : $default;
	}

	/** Etiqueta segura del campo para los avisos (nunca datos de la petición). */
	private static function fieldLabel( array $field ): string {
		$label = $field['label'] ?? $field['type'] ?? 'campo';

		return is_scalar( $label ) ? (string) $label : 'campo';
	}

	/** @param array<string,mixed> $field */
	private static function sanitizeSelect( array $field, mixed $raw ): string {
		$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		$default = isset( $field['default'] ) && is_scalar( $field['default'] ) ? (string) $field['default'] : '';
		$value   = is_scalar( $raw ) ? trim( (string) $raw ) : '';

		if ( '' === $value ) {
			return array_key_exists( $default, $options ) ? $default : '';
		}

		if ( ! array_key_exists( $value, $options ) ) {
			self::logOnce(
				'select:' . self::fieldLabel( $field ),
				'un valor fuera de las opciones se descartó y se usó el default.'
			);
			return array_key_exists( $default, $options ) ? $default : '';
		}

		return $value;
	}

	/**
	 * Lista de ids de `product_cat`: acepta un array o una cadena separada por comas, espacios
	 * o barras. Descarta lo que no sea un entero positivo y ordena.
	 *
	 * @return list<int>
	 */
	private static function sanitizeCategories( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$items = preg_split( '/[\s,|]+/', $raw ) ?: array();
		} elseif ( is_array( $raw ) ) {
			$items = $raw;
		} else {
			$items = array();
		}

		$ids = array();
		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) || ! preg_match( '/^\d+$/', trim( (string) $item ) ) ) {
				continue;
			}
			$id = (int) $item;
			if ( $id > 0 ) {
				$ids[ $id ] = true;
			}
		}

		$ids = array_keys( $ids );
		sort( $ids );

		return $ids;
	}

	/** @param array<string,mixed> $field */
	private static function sanitizeText( mixed $raw, array $field ): string {
		$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( function_exists( 'sanitize_text_field' ) ) {
			$value = (string) sanitize_text_field( $value );
		}

		$max = isset( $field['max'] ) && is_numeric( $field['max'] ) ? max( 0, (int) $field['max'] ) : 0;
		if ( $max > 0 && self::length( $value ) > $max ) {
			$value = self::substring( $value, 0, $max );
		}

		return $value;
	}

	/**
	 * Mapa idioma → texto. Solo se aceptan los idiomas configurados (principal + secundarios)
	 * y se descartan los valores vacíos.
	 *
	 * @param array<string,mixed> $field
	 * @return array<string,string>
	 */
	private static function sanitizeTranslations( mixed $raw, array $field ): array {
		if ( ! is_array( $raw ) ) {
			if ( is_scalar( $raw ) && '' !== trim( (string) $raw ) ) {
				self::logOnce(
					'translatable:' . self::fieldLabel( $field ),
					'un texto traducible que no es un mapa de idiomas se descartó.'
				);
			}
			return array();
		}

		$allowed = array_fill_keys( self::locales(), true );
		$out     = array();

		foreach ( $raw as $locale => $value ) {
			if ( ! is_string( $locale ) || ! is_scalar( $value ) ) {
				continue;
			}

			$suffix = self::localeSuffix( $locale );
			if ( '' === $suffix || ! isset( $allowed[ $suffix ] ) ) {
				self::logOnce( 'locale:' . $suffix, 'un idioma no configurado se descartó de un texto traducible.' );
				continue;
			}

			$text = self::sanitizeText( $value, $field );
			if ( '' === $text ) {
				continue;
			}

			$out[ $suffix ] = $text;
		}

		return $out;
	}

	// ------------------------------------------------------------------------
	//  Idiomas y utilidades
	// ------------------------------------------------------------------------

	/**
	 * Idiomas del contenido: el principal de `brand.locale` y los secundarios de `i18n.locales`
	 * (o los que publique `hwe_i18n_locales()` si `hwe-content-i18n` está cargado).
	 *
	 * @return list<string>
	 */
	public static function locales(): array {
		$primary   = 'es';
		$secondary = array();

		if ( class_exists( 'HWE\\ControlCenter\\Storage' ) && function_exists( 'get_option' ) ) {
			try {
				$value = \HWE\ControlCenter\Storage::get( array( 'brand', 'locale' ), 'es' );
				if ( is_string( $value ) ) {
					$suffix = self::localeSuffix( $value );
					if ( '' !== $suffix ) {
						$primary = $suffix;
					}
				}
			} catch ( \Throwable $e ) {
				self::logOnce( 'locales:primary', 'no se pudo leer el idioma principal; se usa el de respaldo.' );
			}
		}

		if ( function_exists( 'hwe_i18n_locales' ) ) {
			$raw = hwe_i18n_locales();
			if ( is_array( $raw ) ) {
				$secondary = $raw;
			}
		} elseif ( class_exists( 'HWE\\ControlCenter\\Storage' ) && function_exists( 'get_option' ) ) {
			try {
				$raw = \HWE\ControlCenter\Storage::get( array( 'i18n', 'locales' ), '' );
				if ( is_string( $raw ) ) {
					$secondary = preg_split( '/[\s,]+/', $raw ) ?: array();
				} elseif ( is_array( $raw ) ) {
					$secondary = $raw;
				}
			} catch ( \Throwable $e ) {
				self::logOnce( 'locales:secondary', 'no se pudieron leer los idiomas secundarios; se usa solo el principal.' );
			}
		}

		$locales = array( $primary );
		foreach ( $secondary as $locale ) {
			if ( ! is_string( $locale ) ) {
				continue;
			}
			$suffix = self::localeSuffix( $locale );
			if ( '' !== $suffix && ! in_array( $suffix, $locales, true ) ) {
				$locales[] = $suffix;
			}
		}

		return $locales;
	}

	private static function normalizeKey( string $key ): string {
		$key = strtolower( trim( $key ) );
		return 1 === preg_match( self::KEY_PATTERN, $key ) ? $key : '';
	}

	/** Idioma → sufijo de clave (`pt-BR` → `pt_br`). */
	private static function localeSuffix( string $locale ): string {
		if ( function_exists( 'hwe_i18n_locale_suffix' ) ) {
			return (string) hwe_i18n_locale_suffix( $locale );
		}

		$suffix = str_replace( '-', '_', strtolower( trim( $locale ) ) );

		return 1 === preg_match( '/^[a-z]{2,3}(?:_[a-z0-9]{2,8})?$/', $suffix ) ? $suffix : '';
	}

	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $value ) : strlen( $value );
	}

	private static function substring( string $value, int $start, int $length ): string {
		return function_exists( 'mb_substr' ) ? (string) mb_substr( $value, $start, $length ) : substr( $value, $start, $length );
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

	/** Registra un aviso una sola vez por petición (las lecturas pueden repetirse). */
	private static function logOnce( string $key, string $message ): void {
		if ( isset( self::$logged[ $key ] ) ) {
			return;
		}
		self::$logged[ $key ] = true;
		error_log( '[hwe] memberships: ' . $message );
	}
}
