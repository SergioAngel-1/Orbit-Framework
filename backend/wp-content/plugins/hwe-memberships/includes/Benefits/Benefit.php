<?php
/**
 * Clase base de un tipo de beneficio de membresía (spec §7.1 y §7.7).
 *
 * Un tipo de beneficio declara, en un solo sitio:
 *  · su `key()` estable (la clave con la que el nivel guarda su configuración);
 *  · los `fields()` que se configuran por nivel, con los mismos tipos de campo que el Control
 *    Center más los compuestos que necesita este módulo (`percentage`, `level`, `categories` y
 *    `translatable`);
 *  · de qué módulo depende (`dependsOn()`), si depende de alguno;
 *  · qué expone públicamente (`publicData()`), siempre datos estructurados, nunca frases.
 *
 * La clase base resuelve el ciclo común: la configuración saneada del nivel (`configFor`), si el
 * socio lo tiene ahora (`isActiveFor`: nivel efectivo, no congelado, `enabled` y dependencia
 * activa), su estado público (`stateFor`) y el registro de sus hooks (`register`, solo con el
 * módulo activo y la dependencia disponible).
 *
 * Los textos que ve el cliente se componen en el BFF a partir de la clave y de estos datos; aquí
 * no se concatena ninguna frase.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class HWE_Memberships_Benefit {

	/** Entero. Mismo nombre que el tipo `number` del Control Center. */
	public const FIELD_NUMBER = 'number';

	/** Porcentaje entero de 0 a 100. */
	public const FIELD_PERCENTAGE = 'percentage';

	/** Booleano. Mismo nombre que el tipo `boolean` del Control Center. */
	public const FIELD_BOOLEAN = 'boolean';

	/** Selección entre `options` (valor → etiqueta). Mismo nombre que `select`. */
	public const FIELD_SELECT = 'select';

	/** Id de un nivel de membresía (0 = ninguno). */
	public const FIELD_LEVEL = 'level';

	/** Lista de ids de términos de `product_cat`. */
	public const FIELD_CATEGORIES = 'categories';

	/** Texto de una línea. Mismo nombre que el tipo `text` del Control Center. */
	public const FIELD_TEXT = 'text';

	/** Mapa idioma → texto. Los idiomas salen de la configuración del Control Center. */
	public const FIELD_TRANSLATABLE = 'translatable';

	/** Clave estable del tipo. */
	abstract public function key(): string;

	/**
	 * Campos que se configuran por nivel. Cada campo es un array con `type` y, según el tipo,
	 * `label`, `default`, `min`, `max` u `options`.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function fields(): array {
		return array();
	}

	/**
	 * Dependencia del tipo: `loyalty`, `loyalty:points`, `loyalty:referrals`, `shipping` o
	 * `null` si no depende de ningún otro módulo.
	 */
	public function dependsOn(): ?string {
		return null;
	}

	/**
	 * ¿Está disponible la dependencia? Sin dependencia siempre lo está. Un módulo apagado, una
	 * función ausente o una dependencia desconocida se resuelven como no disponible (falla
	 * cerrada).
	 */
	public function available(): bool {
		$dependency = $this->dependsOn();
		if ( null === $dependency || '' === trim( $dependency ) ) {
			return true;
		}

		$parts   = explode( ':', $dependency, 2 );
		$module  = strtolower( trim( (string) $parts[0] ) );
		$feature = isset( $parts[1] ) ? strtolower( trim( (string) $parts[1] ) ) : '';

		if ( 'loyalty' === $module ) {
			if ( '' === $feature ) {
				return self::moduleActive( 'loyalty' ) || function_exists( 'hwe_loyalty_available' );
			}
			if ( ! function_exists( 'hwe_loyalty_available' ) ) {
				return false;
			}
			try {
				return (bool) hwe_loyalty_available( $feature );
			} catch ( \Throwable $e ) {
				self::log( 'falló la consulta de disponibilidad de loyalty:' . $feature . ' para «' . $this->key() . '».' );
				return false;
			}
		}

		if ( 'shipping' === $module ) {
			return self::moduleActive( 'shipping' );
		}

		self::log( 'el tipo «' . $this->key() . '» declara una dependencia desconocida: «' . $dependency . '».' );
		return false;
	}

	/**
	 * Configuración saneada del tipo para un nivel, o `null` si el nivel no la declara. La
	 * configuración se conserva aunque la dependencia esté apagada; quien decide si se aplica
	 * es `isActiveFor()`.
	 *
	 * @return array<string,mixed>|null
	 */
	public function configFor( int $levelId ): ?array {
		if (
			$levelId <= 0
			|| ! class_exists( 'HWE_Memberships_Levels' )
			|| ! class_exists( 'HWE_Memberships_Benefits' )
		) {
			return null;
		}

		$all = HWE_Memberships_Levels::benefitsConfig( $levelId );
		if ( ! array_key_exists( $this->key(), $all ) ) {
			return null;
		}

		return HWE_Memberships_Benefits::sanitizeConfig( $this, $all[ $this->key() ] );
	}

	/**
	 * ¿Tiene el socio este beneficio ahora? Exige el módulo activo, la dependencia disponible,
	 * nivel efectivo (ni congelado ni sin membresía) y `enabled` en la configuración del nivel.
	 */
	public function isActiveFor( int $userId ): bool {
		if ( $userId <= 0 ) {
			return false;
		}
		if (
			! class_exists( 'HWE_Memberships_Settings' )
			|| ! HWE_Memberships_Settings::moduleActive()
		) {
			return false;
		}
		if ( ! $this->available() || ! class_exists( 'HWE_Memberships_Members' ) ) {
			return false;
		}

		$state = HWE_Memberships_Members::state( $userId );
		if ( HWE_Memberships_Members::STATUS_ACTIVE !== (string) ( $state['status'] ?? '' ) ) {
			return false;
		}

		$levelId = (int) ( $state['level_id'] ?? 0 );
		if ( $levelId <= 0 ) {
			return false;
		}

		$config = $this->configFor( $levelId );

		return null !== $config && ! empty( $config['enabled'] );
	}

	/**
	 * Estado público del beneficio para el socio. La base devuelve lo común (`active`, `frozen`,
	 * `level_id`); un tipo con cupo o contadores añade sus claves aquí.
	 *
	 * @return array<string,mixed>
	 */
	public function stateFor( int $userId ): array {
		if ( ! class_exists( 'HWE_Memberships_Members' ) ) {
			return array(
				'active'   => false,
				'frozen'   => false,
				'level_id' => 0,
			);
		}

		$state  = HWE_Memberships_Members::state( $userId );
		$status = (string) ( $state['status'] ?? '' );
		$frozen = HWE_Memberships_Members::STATUS_FROZEN === $status;
		$level  = $frozen ? 0 : (int) ( $state['level_id'] ?? 0 );

		return array(
			'active'   => $this->isActiveFor( $userId ),
			'frozen'   => $frozen,
			'level_id' => $level,
		);
	}

	/**
	 * Representación pública del tipo para un nivel: su clave y datos estructurados. Nunca
	 * frases compuestas ni HTML.
	 *
	 * @return array<string,mixed>
	 */
	abstract public function publicData( int $levelId ): array;

	/**
	 * Engancha los hooks del tipo. Solo se ejecuta con el módulo activo y la dependencia
	 * disponible; si no, el tipo no existe para el cliente y no debe escuchar nada.
	 */
	public function register(): void {
		if (
			! class_exists( 'HWE_Memberships_Settings' )
			|| ! HWE_Memberships_Settings::moduleActive()
			|| ! $this->available()
		) {
			return;
		}

		try {
			$this->registerHooks();
		} catch ( \Throwable $e ) {
			self::log( 'no se pudieron registrar los hooks del tipo «' . $this->key() . '»: ' . $e->getMessage() );
		}
	}

	/** Hooks propios del tipo. La base no engancha nada. */
	protected function registerHooks(): void {
	}

	/** ¿Está activo un módulo del catálogo? Sin Control Center no se puede afirmar. */
	protected static function moduleActive( string $module ): bool {
		if ( ! class_exists( 'HWE\\ControlCenter\\Modules' ) ) {
			return false;
		}

		try {
			return \HWE\ControlCenter\Modules::isActive( $module );
		} catch ( \Throwable $e ) {
			self::log( 'no se pudo comprobar si el módulo «' . $module . '» está activo.' );
			return false;
		}
	}

	protected static function log( string $message ): void {
		error_log( '[hwe] memberships: ' . $message );
	}
}
