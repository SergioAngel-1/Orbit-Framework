<?php
/**
 * Ficha de escaparate de los productos bloqueados (`HWE_Memberships_Teaser`, spec §6.2).
 *
 * WordPress recorta EN ORIGEN lo que un visitante (o un socio sin el nivel) no puede ver. Esta
 * clase decide el recorte de las vías REST y ofrece la lista blanca y el requisito público que
 * comparten esas vías y GraphQL:
 *  · Store API `wc/store/v1/products` (listado y ficha), variaciones y respuesta del carrito;
 *  · WC REST `wc/v3/products` **solo** cuando la petición es del BFF (secreto interno): la
 *    petición del BFF es la que alimenta un endpoint público, así que se trata como visitante.
 *    El panel y las integraciones con ck/cs siguen viendo la ficha completa;
 *  · GraphQL (lo aplica `HWE_Memberships_Graphql`).
 *
 * La lista es BLANCA: solo salen id, nombre, slug, tipo, imagen principal, precio público (rango
 * en variables), categorías, el requisito y la marca `locked`. Cualquier campo que añada otro
 * plugin en el futuro se recorta por defecto.
 *
 * Con el módulo apagado no se registra ningún filtro y todas las vías devuelven la ficha
 * completa (los datos guardados se conservan).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Teaser {

	/** Avisos emitidos una sola vez por petición. */
	private static array $logged = array();

	/** Engancha los filtros REST. Lo llama `HWE_Memberships::boot()`. */
	public static function register(): void {
		if ( ! self::moduleActive() ) {
			return;
		}

		add_filter( 'rest_request_after_callbacks', array( self::class, 'filterRestResponse' ), 999, 3 );
		add_filter( 'woocommerce_rest_prepare_product_object', array( self::class, 'prepareWcProduct' ), 999, 3 );
		add_filter( 'woocommerce_rest_prepare_product_variation_object', array( self::class, 'prepareWcVariation' ), 999, 3 );
	}

	/** ¿Hay que recortar este producto para este usuario? `0` = visitante sin sesión. */
	public static function isTeaser( int $productId, int $userId ): bool {
		if ( $productId <= 0 || ! self::moduleActive() ) {
			return false;
		}

		return ! HWE_Memberships_Access::canView( $userId, $productId );
	}

	/**
	 * Lista blanca conceptual de la ficha de escaparate (nombre de campo genérico, no el de una
	 * API concreta). Las claves de cada respuesta se mapean en `trimStoreProduct()`,
	 * `trimWcProduct()` y `HWE_Memberships_Graphql`.
	 *
	 * Los campos operativos (`status`, `purchasable`, `stock_status`, `manage_stock`,
	 * `stock_quantity`, `backorders_allowed`) solo se conservan en `wc/v3` para la petición del
	 * BFF: no son contenido del producto y son los que necesita «volver a pedir» para decidir la
	 * cantidad. La Store API mantiene la lista estricta: allí la autorización real la hace el
	 * carrito con el JWT del socio.
	 *
	 * @return list<string>
	 */
	public static function fields(): array {
		return array(
			'id',
			'name',
			'slug',
			'type',
			'image',
			'price',
			'price_range',
			'categories',
			'hwe_membership',
			'locked',
			'status',
			'purchasable',
			'stock_status',
			'manage_stock',
			'stock_quantity',
			'backorders_allowed',
		);
	}

	/**
	 * Datos públicos del requisito: nivel mínimo (slug, rango y nombre) y fecha de apertura. No
	 * incluye el motivo del bloqueo ni datos internos; el BFF compone las frases.
	 *
	 * @return array{required_level:?array{slug:string,rank:int,name:string},opens_at:?string}
	 */
	public static function requirementPayload( int $productId ): array {
		$requirement = HWE_Memberships_Access::requirementFor( $productId );
		$level       = null;

		if ( (int) $requirement['min_rank'] > 0 && (int) $requirement['level_id'] > 0 ) {
			$level = array(
				'slug' => (string) $requirement['level_slug'],
				'rank' => (int) $requirement['min_rank'],
				'name' => (string) $requirement['level_name'],
			);
		}

		return array(
			'required_level' => $level,
			'opens_at'       => $requirement['opens_at'],
		);
	}

	/** Usuario de la petición actual; `0` si no hay sesión. */
	public static function requestUserId(): int {
		if ( ! function_exists( 'get_current_user_id' ) ) {
			return 0;
		}

		return max( 0, (int) get_current_user_id() );
	}

	/** ¿Está activo el módulo? Sin Control Center manda la constante del plugin cargado. */
	public static function moduleActive(): bool {
		if ( class_exists( 'HWE_Memberships_Settings' ) ) {
			try {
				return HWE_Memberships_Settings::moduleActive();
			} catch ( \Throwable $e ) {
				self::logOnce( 'module-active', 'no se pudo comprobar si el módulo está activo; se usa la constante del plugin.' );
				return defined( 'HWE_MEMBERSHIPS_VERSION' );
			}
		}

		return defined( 'HWE_MEMBERSHIPS_VERSION' );
	}

	// ------------------------------------------------------------------------
	//  Store API (wc/store/v1)
	// ------------------------------------------------------------------------

	/**
	 * Recorta un producto de la Store API. Una variación se reduce a su identificador y a la
	 * marca de bloqueo: ni nombre, ni atributos, ni stock.
	 *
	 * @param array<string,mixed> $product
	 * @return array<string,mixed>
	 */
	public static function trimStoreProduct( array $product, int $userId ): array {
		$id       = (int) ( $product['id'] ?? 0 );
		$type     = (string) ( $product['type'] ?? '' );
		$parentId = (int) ( $product['parent'] ?? $product['parent_id'] ?? 0 );

		if ( $id <= 0 ) {
			return $product;
		}

		if ( 'variation' === $type ) {
			if ( $parentId <= 0 ) {
				self::logOnce( 'store-variation-parent', 'una variación de la Store API llegó sin producto padre; se recorta por precaución.' );
				return array(
					'id'     => $id,
					'type'   => 'variation',
					'locked' => true,
				);
			}
			if ( ! self::isTeaser( $parentId, $userId ) ) {
				return $product;
			}

			return array(
				'id'             => $id,
				'type'           => 'variation',
				'parent'         => $parentId,
				'locked'         => true,
				'hwe_membership' => self::requirementPayload( $parentId ),
			);
		}

		if ( ! self::isTeaser( $id, $userId ) ) {
			return $product;
		}

		$out = array();
		foreach ( array( 'id', 'name', 'slug', 'type' ) as $key ) {
			if ( array_key_exists( $key, $product ) ) {
				$out[ $key ] = $product[ $key ];
			}
		}
		if ( array_key_exists( 'images', $product ) ) {
			$out['images'] = self::firstImage( $product['images'] );
		}
		if ( array_key_exists( 'prices', $product ) ) {
			$out['prices'] = $product['prices'];
		}
		if ( array_key_exists( 'categories', $product ) ) {
			$out['categories'] = $product['categories'];
		}

		$out['locked']         = true;
		$out['hwe_membership'] = self::requirementPayload( $id );

		return $out;
	}

	/**
	 * Recorta una respuesta de producto de la Store API: un producto o una lista.
	 *
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	public static function trimStoreProducts( array $data, int $userId ): array {
		if ( isset( $data['id'] ) ) {
			return self::trimStoreProduct( $data, $userId );
		}

		foreach ( $data as $key => $item ) {
			if ( is_array( $item ) ) {
				$data[ $key ] = self::trimStoreProduct( $item, $userId );
			}
		}

		return $data;
	}

	/**
	 * Recorta una línea de carrito: conserva la mecánica de la línea (clave, cantidad, precios,
	 * totales y extensiones) y quita atributos, resumen, descripciones y SKU.
	 *
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>
	 */
	public static function trimCartItem( array $item, int $userId ): array {
		$productId = (int) ( $item['id'] ?? 0 );
		if ( $productId <= 0 || ! self::isTeaser( $productId, $userId ) ) {
			return $item;
		}

		$out = array();
		foreach ( array( 'key', 'id', 'quantity', 'name' ) as $key ) {
			if ( array_key_exists( $key, $item ) ) {
				$out[ $key ] = $item[ $key ];
			}
		}
		if ( array_key_exists( 'images', $item ) ) {
			$out['images'] = self::firstImage( $item['images'] );
		}
		foreach ( array( 'prices', 'totals', 'extensions' ) as $key ) {
			if ( array_key_exists( $key, $item ) ) {
				$out[ $key ] = $item[ $key ];
			}
		}

		$out['locked']         = true;
		$out['hwe_membership'] = self::requirementPayload( $productId );

		return $out;
	}

	/**
	 * Recorta las líneas de una respuesta de carrito. El resto de la respuesta (totales,
	 * cupones, extensiones de otros módulos) no se toca.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public static function trimCartResponse( array $data, int $userId ): array {
		if ( ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return $data;
		}

		foreach ( $data['items'] as $key => $item ) {
			if ( is_array( $item ) ) {
				$data['items'][ $key ] = self::trimCartItem( $item, $userId );
			}
		}

		return $data;
	}

	/**
	 * `rest_request_after_callbacks`: recorta la Store API en origen. Solo actúa en las rutas de
	 * producto y carrito; cualquier otra respuesta se devuelve intacta. Los errores y las
	 * respuestas que no son de WordPress no se tocan.
	 *
	 * @param mixed $response
	 * @param mixed $handler
	 * @param mixed $request
	 * @return mixed
	 */
	public static function filterRestResponse( $response, $handler, $request ) {
		if ( ! self::moduleActive() ) {
			return $response;
		}

		$route = is_object( $request ) && method_exists( $request, 'get_route' )
			? (string) $request->get_route()
			: '';
		if ( '' === $route || ! self::isStoreRoute( $route ) ) {
			return $response;
		}
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'set_data' ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$userId = self::requestUserId();

		if ( self::isCartRoute( $route ) ) {
			$response->set_data( self::trimCartResponse( $data, $userId ) );
			return $response;
		}

		$response->set_data( self::trimStoreProducts( $data, $userId ) );

		return $response;
	}

	// ------------------------------------------------------------------------
	//  WC REST v3 (peticiones del BFF)
	// ------------------------------------------------------------------------

	/**
	 * `woocommerce_rest_prepare_product_object`: si la petición trae el secreto interno del BFF,
	 * se aplica la ficha de escaparate como visitante (userId 0), porque esa respuesta alimenta
	 * un endpoint público. El panel y las integraciones con ck/cs no se tocan.
	 *
	 * @param mixed $response
	 * @param mixed $product
	 * @param mixed $request
	 * @return mixed
	 */
	public static function prepareWcProduct( $response, $product, $request ) {
		return self::prepareWcResponse( $response, $product, false );
	}

	/** Igual que `prepareWcProduct()` para una variación: solo identificador y bloqueo. */
	public static function prepareWcVariation( $response, $product, $request ) {
		return self::prepareWcResponse( $response, $product, true );
	}

	/**
	 * @param mixed $response
	 * @param mixed $product
	 * @return mixed
	 */
	private static function prepareWcResponse( $response, $product, bool $variation ) {
		if ( ! self::moduleActive() || ! self::isBffRequest() ) {
			return $response;
		}
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'set_data' ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}
		if ( empty( $data['id'] ) && is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$data['id'] = (int) $product->get_id();
		}

		$response->set_data( self::trimWcProduct( $data, 0, $variation ) );

		return $response;
	}

	/**
	 * Recorta un producto de `wc/v3`. Se conservan los campos operativos que necesita el BFF
	 * (`status`, `purchasable`, `stock_status`, `manage_stock`, `stock_quantity` y
	 * `backorders_allowed`): no son contenido del producto y «volver a pedir» los usa para
	 * decidir la cantidad. En variaciones solo quedan id, producto padre, tipo y el requisito:
	 * ni nombre, ni SKU, ni atributos, ni precio, ni imagen.
	 *
	 * @param array<string,mixed> $product
	 * @return array<string,mixed>
	 */
	public static function trimWcProduct( array $product, int $userId, bool $variation = false ): array {
		$id       = (int) ( $product['id'] ?? 0 );
		$type     = (string) ( $product['type'] ?? '' );
		$parentId = (int) ( $product['parent_id'] ?? 0 );
		$isVar    = $variation || 'variation' === $type;

		if ( $id <= 0 ) {
			return $product;
		}

		if ( $isVar ) {
			$parent = $parentId > 0 ? $parentId : self::parentProductId( $id );
			if ( $parent <= 0 ) {
				self::logOnce( 'wc-variation-parent', 'una variación de wc/v3 llegó sin producto padre; se recorta por precaución.' );
				return array(
					'id'     => $id,
					'type'   => 'variation',
					'locked' => true,
				);
			}
			if ( ! self::isTeaser( $parent, $userId ) ) {
				return $product;
			}

			return array(
				'id'             => $id,
				'parent_id'      => $parent,
				'type'           => 'variation',
				'locked'         => true,
				'hwe_membership' => self::requirementPayload( $parent ),
			);
		}

		if ( ! self::isTeaser( $id, $userId ) ) {
			return $product;
		}

		$out = array();
		foreach ( array( 'id', 'status', 'purchasable', 'stock_status', 'manage_stock', 'stock_quantity', 'backorders_allowed', 'name', 'slug', 'type', 'price', 'regular_price', 'sale_price', 'on_sale' ) as $key ) {
			if ( array_key_exists( $key, $product ) ) {
				$out[ $key ] = $product[ $key ];
			}
		}
		if ( array_key_exists( 'categories', $product ) ) {
			$out['categories'] = $product['categories'];
		}
		if ( array_key_exists( 'images', $product ) ) {
			$out['images'] = self::firstImage( $product['images'] );
		}

		$out['locked']         = true;
		$out['hwe_membership'] = self::requirementPayload( $id );

		return $out;
	}

	/** La petición trae el secreto interno válido del BFF. Si no se puede saber, se recorta. */
	private static function isBffRequest(): bool {
		if ( ! function_exists( 'hwe_bff_only_has_valid_secret' ) ) {
			self::logOnce( 'no-secret-check', 'no está disponible la comprobación del secreto interno; las respuestas wc/v3 se recortan por precaución.' );
			return true;
		}

		try {
			return (bool) hwe_bff_only_has_valid_secret();
		} catch ( \Throwable $e ) {
			self::logOnce( 'secret-check', 'la comprobación del secreto interno falló; las respuestas wc/v3 se recortan por precaución.' );
			return true;
		}
	}

	/** Producto padre de una variación, o 0 si no se puede determinar. */
	private static function parentProductId( int $variationId ): int {
		if ( $variationId <= 0 ) {
			return 0;
		}

		if ( function_exists( 'get_post' ) ) {
			$post = get_post( $variationId );
			if ( is_object( $post ) && 'product_variation' === (string) ( $post->post_type ?? '' ) ) {
				return (int) ( $post->post_parent ?? 0 );
			}
		}
		if ( function_exists( 'wp_get_post_parent_id' ) ) {
			return (int) wp_get_post_parent_id( $variationId );
		}

		return 0;
	}

	// ------------------------------------------------------------------------
	//  Utilidades
	// ------------------------------------------------------------------------

	/** Solo la primera imagen de una lista (la principal); el resto es galería. */
	private static function firstImage( mixed $images ): array {
		if ( ! is_array( $images ) || array() === $images ) {
			return array();
		}

		$first = reset( $images );

		return is_array( $first ) ? array( $first ) : array();
	}

	/** ¿La ruta es de la Store API de producto o carrito? */
	private static function isStoreRoute( string $route ): bool {
		return self::isCartRoute( $route )
			|| 1 === preg_match( '#^/wc/store/v1/products(?:/\d+)?/?$#', $route );
	}

	private static function isCartRoute( string $route ): bool {
		return 1 === preg_match( '#^/wc/store/v1/cart(?:/|$)#', $route );
	}

	/** Registra un aviso una sola vez por petición. */
	private static function logOnce( string $key, string $message ): void {
		if ( isset( self::$logged[ $key ] ) ) {
			return;
		}
		self::$logged[ $key ] = true;
		error_log( '[hwe] memberships: ' . $message );
	}
}
