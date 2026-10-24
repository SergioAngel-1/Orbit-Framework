<?php
/**
 * Descuento de miembro (spec §7.2).
 *
 * El nivel configura un porcentaje (0–100) y el alcance: todo el catálogo o una lista de
 * categorías. La herencia se respeta: elegir una categoría cubre también sus descendientes. El
 * descuento se aplica al **precio de línea** del carrito del socio con el beneficio activo, sobre
 * el precio que la tienda cobraría (oferta incluida); nunca a las líneas de membresía. Así los
 * impuestos se calculan sobre lo rebajado, el cupón se aplica después y la base de ganancias de
 * `loyalty` lo refleja sola.
 *
 * Esta clase solo decide el porcentaje y el alcance. La mecánica del carrito, el pedido y el
 * precio para el BFF viven en `HWE_Memberships_Pricing`; la extensión de la Store API recibe el
 * total y el detalle por línea por el filtro de `HWE_Memberships_StoreApi`.
 *
 * Sin nivel efectivo, congelado, con el beneficio apagado o con un porcentaje 0 no hay descuento.
 * Si las categorías del producto no se pueden leer, el producto queda fuera del alcance (falla
 * cerrada en dinero) y se registra.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Benefit_Member_Discount extends HWE_Memberships_Benefit {

	/** Clave estable del tipo. */
	public const KEY = 'member_discount';

	/** Alcance: todo el catálogo. */
	public const SCOPE_ALL = 'all';

	/** Alcance: solo las categorías elegidas (con sus descendientes). */
	public const SCOPE_CATEGORIES = 'categories';

	/** Avisos ya emitidos en esta petición. */
	private static array $logged = array();

	public function key(): string {
		return self::KEY;
	}

	public function fields(): array {
		return array(
			'percent'    => array(
				'type'    => self::FIELD_PERCENTAGE,
				'label'   => function_exists( '__' ) ? __( 'Descuento (%)', 'hwe' ) : 'Descuento (%)',
				'default' => 0,
				'min'     => 0,
				'max'     => 100,
			),
			'scope'      => array(
				'type'    => self::FIELD_SELECT,
				'label'   => function_exists( '__' ) ? __( 'Alcance', 'hwe' ) : 'Alcance',
				'default' => self::SCOPE_ALL,
				'options' => array(
					self::SCOPE_ALL        => function_exists( '__' ) ? __( 'Todo el catálogo', 'hwe' ) : 'Todo el catálogo',
					self::SCOPE_CATEGORIES => function_exists( '__' ) ? __( 'Solo las categorías elegidas', 'hwe' ) : 'Solo las categorías elegidas',
				),
			),
			'categories' => array(
				'type'    => self::FIELD_CATEGORIES,
				'label'   => function_exists( '__' ) ? __( 'Categorías con descuento', 'hwe' ) : 'Categorías con descuento',
				'default' => array(),
			),
		);
	}

	/**
	 * Configuración efectiva del socio, o `null` si no tiene el beneficio activo. Incluye el
	 * nivel, el porcentaje ya recortado y el alcance saneado.
	 *
	 * @return array{level_id:int,percent:int,scope:string,categories:list<int>}|null
	 */
	public function activeConfig( int $userId ): ?array {
		if (
			$userId <= 0
			|| ! class_exists( 'HWE_Memberships_Settings' )
			|| ! class_exists( 'HWE_Memberships_Members' )
		) {
			return null;
		}

		try {
			$active = HWE_Memberships_Settings::moduleActive();
		} catch ( \Throwable $e ) {
			self::logOnce( 'settings', 'no se pudo comprobar si el módulo está activo; no se aplica el descuento.' );
			return null;
		}
		if ( ! $active ) {
			return null;
		}

		try {
			$state = HWE_Memberships_Members::state( $userId );
		} catch ( \Throwable $e ) {
			self::logOnce( 'state:' . $userId, 'no se pudo leer el estado del socio ' . $userId . ' para el descuento; no se aplica.' );
			return null;
		}

		if (
			HWE_Memberships_Members::STATUS_ACTIVE !== (string) ( $state['status'] ?? '' )
			|| (int) ( $state['level_id'] ?? 0 ) <= 0
		) {
			return null;
		}

		$levelId = (int) $state['level_id'];
		try {
			$config = $this->configFor( $levelId );
		} catch ( \Throwable $e ) {
			self::logOnce( 'config:' . $levelId, 'no se pudo leer la configuración del nivel ' . $levelId . ' para el descuento; no se aplica.' );
			return null;
		}
		if ( null === $config || empty( $config['enabled'] ) ) {
			return null;
		}

		$percent = max( 0, min( 100, (int) ( $config['percent'] ?? 0 ) ) );
		if ( $percent <= 0 ) {
			return null;
		}

		$scope = (string) ( $config['scope'] ?? self::SCOPE_ALL );
		if ( ! in_array( $scope, array( self::SCOPE_ALL, self::SCOPE_CATEGORIES ), true ) ) {
			$scope = self::SCOPE_ALL;
		}

		$categories = array();
		foreach ( (array) ( $config['categories'] ?? array() ) as $categoryId ) {
			if ( is_scalar( $categoryId ) && (int) $categoryId > 0 ) {
				$categories[ (int) $categoryId ] = true;
			}
		}

		return array(
			'level_id'   => $levelId,
			'percent'    => $percent,
			'scope'      => $scope,
			'categories' => array_keys( $categories ),
		);
	}

	/**
	 * ¿El producto entra en el alcance del descuento? En `categories` se cumple si alguna de sus
	 * categorías o de sus ancestros está elegida. Un fallo de lectura deja el producto fuera.
	 *
	 * @param array{level_id?:int,percent?:int,scope?:string,categories?:list<int>} $config
	 */
	public function appliesTo( array $config, int $productId ): bool {
		if ( $productId <= 0 ) {
			return false;
		}

		$scope = (string) ( $config['scope'] ?? self::SCOPE_ALL );
		if ( self::SCOPE_ALL === $scope ) {
			return true;
		}
		if ( self::SCOPE_CATEGORIES !== $scope ) {
			return false;
		}

		$selected = array();
		foreach ( (array) ( $config['categories'] ?? array() ) as $categoryId ) {
			if ( is_scalar( $categoryId ) && (int) $categoryId > 0 ) {
				$selected[ (int) $categoryId ] = true;
			}
		}
		if ( array() === $selected ) {
			return false;
		}

		if ( ! class_exists( 'HWE_Memberships_Access' ) || ! method_exists( 'HWE_Memberships_Access', 'categoryChainIds' ) ) {
			self::logOnce( 'access', 'no está disponible la lectura de categorías; el producto ' . $productId . ' queda fuera del descuento.' );
			return false;
		}

		try {
			$chain = HWE_Memberships_Access::categoryChainIds( $productId );
		} catch ( \Throwable $e ) {
			self::logOnce( 'categories:' . $productId, 'no se pudieron leer las categorías del producto ' . $productId . '; queda fuera del descuento.' );
			return false;
		}

		foreach ( (array) $chain as $categoryId ) {
			if ( isset( $selected[ (int) $categoryId ] ) ) {
				return true;
			}
		}

		return false;
	}

	public function publicData( int $levelId ): array {
		$config = $this->configFor( $levelId );

		return array(
			'key'        => $this->key(),
			'percent'    => null === $config ? 0 : max( 0, min( 100, (int) ( $config['percent'] ?? 0 ) ) ),
			'scope'      => null === $config ? self::SCOPE_ALL : (string) ( $config['scope'] ?? self::SCOPE_ALL ),
			'categories' => null === $config ? array() : array_values( array_map( 'intval', (array) ( $config['categories'] ?? array() ) ) ),
		);
	}

	public function stateFor( int $userId ): array {
		$state  = parent::stateFor( $userId );
		$config = null;

		if ( $state['active'] && (int) $state['level_id'] > 0 ) {
			$config = $this->configFor( (int) $state['level_id'] );
		}

		$state['percent'] = null === $config ? 0 : max( 0, min( 100, (int) ( $config['percent'] ?? 0 ) ) );
		$state['scope']   = null === $config ? self::SCOPE_ALL : (string) ( $config['scope'] ?? self::SCOPE_ALL );

		return $state;
	}

	/** Registra un aviso una sola vez por petición. */
	private static function logOnce( string $key, string $message ): void {
		if ( isset( self::$logged[ $key ] ) ) {
			return;
		}
		self::$logged[ $key ] = true;
		self::log( $message );
	}
}
