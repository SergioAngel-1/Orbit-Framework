<?php
/**
 * GraphQL de la ficha de escaparate (`HWE_Memberships_Graphql`, spec §6.2 y §10.2).
 *
 * Añade `hweMembership` a `Product` y `ProductCategory`:
 *  · `requiredLevel { slug, rank, name }` — nivel mínimo publicado que abre el producto;
 *  · `opensAt` — fecha de apertura (ISO 8601 UTC) o null;
 *  · `locked` — si la petición actual ve la ficha de escaparate.
 *
 * El catálogo del BFF se lee sin usuario (ISR), así que lo normal es `locked: true` en los
 * productos con requisito. Si una petición llega autenticada, se usa `get_current_user_id()`;
 * el personal de la tienda no tiene ningún atajo.
 *
 * El recorte se hace con `graphql_resolve_field` y una lista BLANCA por tipo: en un producto
 * teaser, todo campo que no esté permitido devuelve null. Eso cubre las descripciones, la
 * galería, los atributos, el stock, las variaciones, `hweFields`, `landing`, el SEO, los campos
 * de otros plugins y la traducción (de la que solo se conservan `name` y `slug`). Las
 * variaciones directas por id se anulan en la raíz (`productVariation`, `node`) si su producto
 * padre es teaser.
 *
 * Con el módulo apagado no se registra nada: el campo no existe y ningún resolver recorta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Graphql {

	/** Tipos de WooGraphQL que representan un producto. */
	private const PRODUCT_TYPES = array(
		'Product',
		'SimpleProduct',
		'VariableProduct',
		'GroupedProduct',
		'ExternalProduct',
	);

	/**
	 * Campos que sí salen de un producto teaser. La lista es blanca: cualquier campo nuevo de
	 * WooCommerce, del núcleo o de otro plugin queda fuera por defecto.
	 *
	 * @var list<string>
	 */
	private const PRODUCT_FIELDS = array(
		'id',
		'databaseId',
		'name',
		'slug',
		'type',
		'image',
		'featuredImage',
		'price',
		'priceRange',
		'regularPrice',
		'salePrice',
		'onSale',
		'productCategories',
		'categories',
		'hweMembership',
		'translation',
	);

	/** De la traducción solo salen el nombre y el slug. */
	private const TRANSLATION_FIELDS = array( 'name', 'slug' );

	/** De una variación solo sale su identificador (nunca nombre, atributos ni stock). */
	private const VARIATION_FIELDS = array( 'id', 'databaseId' );

	/** Avisos emitidos una sola vez por petición. */
	private static array $logged = array();

	/** Engancha el registro de tipos y el recorte. Lo llama `HWE_Memberships::boot()`. */
	public static function register(): void {
		if ( ! HWE_Memberships_Teaser::moduleActive() ) {
			return;
		}

		add_action( 'graphql_register_types', array( self::class, 'registerTypes' ), 20, 1 );
		add_filter( 'graphql_resolve_field', array( self::class, 'resolveField' ), 999, 7 );
	}

	/** Registra el tipo del requisito y el campo en Product y ProductCategory. */
	public static function registerTypes(): void {
		if (
			! HWE_Memberships_Teaser::moduleActive()
			|| ! function_exists( 'register_graphql_object_type' )
			|| ! function_exists( 'register_graphql_field' )
		) {
			return;
		}

		register_graphql_object_type(
			'HweMembershipLevel',
			array(
				'description' => __( 'Nivel mínimo de membresía que abre un producto o una categoría.', 'hwe' ),
				'fields'      => array(
					'slug' => array( 'type' => 'String' ),
					'rank' => array( 'type' => 'Int' ),
					'name' => array( 'type' => 'String' ),
				),
			)
		);

		register_graphql_object_type(
			'HweMembership',
			array(
				'description' => __( 'Requisito de acceso del módulo de membresías para la petición actual.', 'hwe' ),
				'fields'      => array(
					'requiredLevel' => array( 'type' => 'HweMembershipLevel' ),
					'opensAt'       => array( 'type' => 'String' ),
					'locked'        => array( 'type' => 'Boolean' ),
				),
			)
		);

		register_graphql_field(
			'Product',
			'hweMembership',
			array(
				'type'        => 'HweMembership',
				'description' => __( 'Requisito de acceso y marca `locked` del producto para la petición actual.', 'hwe' ),
				'resolve'     => array( self::class, 'resolveProductMembership' ),
			)
		);

		// `Product` es una interfaz en WooGraphQL: se declara también en cada tipo concreto
		// para que el campo exista se consulte como se consulte (fragmento de interfaz o de
		// tipo concreto).
		foreach ( self::PRODUCT_TYPES as $productType ) {
			if ( 'Product' === $productType ) {
				continue;
			}
			register_graphql_field(
				$productType,
				'hweMembership',
				array(
					'type'        => 'HweMembership',
					'description' => __( 'Requisito de acceso y marca `locked` del producto para la petición actual.', 'hwe' ),
					'resolve'     => array( self::class, 'resolveProductMembership' ),
				)
			);
		}

		register_graphql_field(
			'ProductCategory',
			'hweMembership',
			array(
				'type'        => 'HweMembership',
				'description' => __( 'Nivel mínimo que exige la categoría (la descripción de la categoría sigue siendo pública).', 'hwe' ),
				'resolve'     => array( self::class, 'resolveCategoryMembership' ),
			)
		);
	}

	/**
	 * `graphql_resolve_field`: aplica la lista blanca cuando el producto (o su variación) es
	 * teaser. Devuelve null para lo no permitido; nunca lanza un error que confirme contenido.
	 *
	 * @param mixed $result
	 * @param mixed $source
	 * @param mixed $args
	 * @param mixed $context
	 * @param mixed $info
	 * @param mixed $typeName
	 * @param mixed $fieldKey
	 * @return mixed
	 */
	public static function resolveField( $result, $source, $args, $context, $info, $typeName = '', $fieldKey = '' ) {
		$typeName = self::typeName( $typeName, $info );
		$fieldKey = self::fieldKey( $fieldKey, $info );
		if ( '' === $typeName || '' === $fieldKey ) {
			return $result;
		}

		if ( 'RootQuery' === $typeName ) {
			if ( ! in_array( $fieldKey, array( 'productVariation', 'node', 'product' ), true ) ) {
				return $result;
			}
			if ( ! HWE_Memberships_Teaser::moduleActive() ) {
				return $result;
			}

			return self::resolveRootQuery( $result, $args, $fieldKey );
		}

		// La traducción que devolvió un producto teaser lleva una marca interna: sus campos
		// ocultos (descripción, SEO…) se anulan también.
		if ( 'HweProductTranslation' === $typeName && is_array( $source ) && ! empty( $source['_hwe_teaser'] ) ) {
			if ( ! HWE_Memberships_Teaser::moduleActive() ) {
				return $result;
			}

			return in_array( $fieldKey, self::TRANSLATION_FIELDS, true ) ? $result : null;
		}

		if ( ! in_array( $typeName, self::PRODUCT_TYPES, true ) && ! self::isVariationType( $typeName ) ) {
			return $result;
		}
		if ( ! HWE_Memberships_Teaser::moduleActive() ) {
			return $result;
		}

		if ( in_array( $typeName, self::PRODUCT_TYPES, true ) ) {
			// Una variación puede llegar etiquetada como `Product` (interfaz): no se puede
			// tratar como producto padre ni leer su meta (no tiene): se resuelve por su padre.
			if ( self::sourceIsVariation( $source ) ) {
				$parentId = self::variationParentId( $source );
				if ( $parentId > 0 && ! HWE_Memberships_Teaser::isTeaser( $parentId, HWE_Memberships_Teaser::requestUserId() ) ) {
					return $result;
				}
				if ( $parentId <= 0 ) {
					self::logOnce( 'variation-parent', 'no se pudo identificar el producto padre de una variación en GraphQL; se recorta por precaución.' );
				}

				return in_array( $fieldKey, self::VARIATION_FIELDS, true ) ? $result : null;
			}

			$productId = self::productIdFromSource( $source, false );
			if ( $productId <= 0 ) {
				// Sin id no se puede evaluar: falla cerrado salvo los identificadores.
				self::logOnce( 'product-id', 'no se pudo identificar el producto en GraphQL; se recorta por precaución.' );
				return in_array( $fieldKey, array( 'id', 'databaseId' ), true ) ? $result : null;
			}
			if ( ! HWE_Memberships_Teaser::isTeaser( $productId, HWE_Memberships_Teaser::requestUserId() ) ) {
				return $result;
			}

			if ( 'translation' === $fieldKey ) {
				return self::trimTranslation( $result );
			}

			return in_array( $fieldKey, self::PRODUCT_FIELDS, true ) ? $result : null;
		}

		if ( self::isVariationType( $typeName ) ) {
			$parentId = self::variationParentId( $source );
			if ( $parentId > 0 && ! HWE_Memberships_Teaser::isTeaser( $parentId, HWE_Memberships_Teaser::requestUserId() ) ) {
				return $result;
			}

			return in_array( $fieldKey, self::VARIATION_FIELDS, true ) ? $result : null;
		}

		return $result;
	}

	/**
	 * Resuelve `Product.hweMembership`.
	 *
	 * @param mixed $product
	 * @param mixed $args
	 * @param mixed $context
	 * @param mixed $info
	 * @return array<string,mixed>|null
	 */
	public static function resolveProductMembership( $product, $args = array(), $context = null, $info = null ): ?array {
		if ( ! HWE_Memberships_Teaser::moduleActive() ) {
			return null;
		}

		$productId = self::sourceIsVariation( $product )
			? self::variationParentId( $product )
			: self::productIdFromSource( $product, false );
		if ( $productId <= 0 ) {
			return null;
		}

		return self::membershipPayload( $productId, HWE_Memberships_Teaser::isTeaser( $productId, HWE_Memberships_Teaser::requestUserId() ) );
	}

	/**
	 * Resuelve `ProductCategory.hweMembership`. La categoría no está bloqueada: su nombre,
	 * slug, imagen y descripción son públicos; solo se publica el requisito.
	 *
	 * @param mixed $category
	 * @param mixed $args
	 * @param mixed $context
	 * @param mixed $info
	 * @return array<string,mixed>|null
	 */
	public static function resolveCategoryMembership( $category, $args = array(), $context = null, $info = null ): ?array {
		if ( ! HWE_Memberships_Teaser::moduleActive() ) {
			return null;
		}

		$termId = self::termIdFromSource( $category );
		if ( $termId <= 0 ) {
			return null;
		}

		$requirement = HWE_Memberships_Access::categoryRequirement( $termId );

		return array(
			'requiredLevel' => self::levelPayload( $requirement ),
			'opensAt'       => null,
			'locked'        => false,
		);
	}

	// ------------------------------------------------------------------------
	//  Raíz: variaciones directas
	// ------------------------------------------------------------------------

	/**
	 * Anula las variaciones directas (`productVariation`, `node`) cuyo producto padre es teaser.
	 *
	 * @param mixed $result
	 * @param mixed $args
	 * @return mixed
	 */
	private static function resolveRootQuery( $result, $args, string $fieldKey ) {
		if ( ! in_array( $fieldKey, array( 'productVariation', 'node', 'product' ), true ) ) {
			return $result;
		}

		$objectId = self::databaseIdFromArgs( $args );
		if ( $objectId <= 0 || 'product_variation' !== self::postType( $objectId ) ) {
			return $result;
		}

		$parentId = self::parentOfPost( $objectId );
		if ( $parentId <= 0 ) {
			self::logOnce( 'root-variation-parent', 'no se pudo identificar el producto padre de una variación en la raíz de GraphQL; se anula por precaución.' );
			return null;
		}
		if ( HWE_Memberships_Teaser::isTeaser( $parentId, HWE_Memberships_Teaser::requestUserId() ) ) {
			return null;
		}

		return $result;
	}

	// ------------------------------------------------------------------------
	//  Payload y traducción
	// ------------------------------------------------------------------------

	/**
	 * @return array{requiredLevel:?array{slug:string,rank:int,name:string},opensAt:?string,locked:bool}
	 */
	private static function membershipPayload( int $productId, bool $locked ): array {
		$requirement = HWE_Memberships_Access::requirementFor( $productId );

		return array(
			'requiredLevel' => self::levelPayload( $requirement ),
			'opensAt'       => $requirement['opens_at'],
			'locked'        => $locked,
		);
	}

	/**
	 * @param array<string,mixed> $requirement
	 * @return array{slug:string,rank:int,name:string}|null
	 */
	private static function levelPayload( array $requirement ): ?array {
		if ( (int) $requirement['min_rank'] < 1 || (int) $requirement['level_id'] < 1 ) {
			return null;
		}

		return array(
			'slug' => (string) $requirement['level_slug'],
			'rank' => (int) $requirement['min_rank'],
			'name' => (string) $requirement['level_name'],
		);
	}

	/**
	 * De la traducción de un producto teaser solo quedan nombre y slug. El marcador interno
	 * permite que el filtro de `HweProductTranslation` anule el resto de campos.
	 *
	 * @param mixed $result
	 * @return mixed
	 */
	private static function trimTranslation( $result ) {
		if ( null === $result ) {
			return null;
		}
		if ( ! is_array( $result ) ) {
			// Una traducción que no sea el array del contrato no se puede recortar: se anula
			// entera para no dejar salir su descripción.
			return null;
		}

		$out = array( '_hwe_teaser' => true );
		foreach ( self::TRANSLATION_FIELDS as $field ) {
			if ( array_key_exists( $field, $result ) ) {
				$out[ $field ] = $result[ $field ];
			}
		}

		return $out;
	}

	// ------------------------------------------------------------------------
	//  Identificadores
	// ------------------------------------------------------------------------

	private static function typeName( mixed $typeName, mixed $info ): string {
		if ( is_string( $typeName ) && '' !== $typeName ) {
			return $typeName;
		}

		return is_object( $info ) && isset( $info->parentType->name )
			? (string) $info->parentType->name
			: '';
	}

	private static function fieldKey( mixed $fieldKey, mixed $info ): string {
		if ( is_string( $fieldKey ) && '' !== $fieldKey ) {
			return $fieldKey;
		}

		return is_object( $info ) && isset( $info->fieldName )
			? (string) $info->fieldName
			: '';
	}

	/** Id de producto (o de la variación, según `$variation`) a partir de la fuente GraphQL. */
	private static function productIdFromSource( mixed $source, bool $variation ): int {
		if ( is_object( $source ) ) {
			if ( $variation ) {
				$parent = self::variationParentId( $source );
				if ( $parent > 0 ) {
					return $parent;
				}
			}
			foreach ( array( 'databaseId', 'database_id', 'productId', 'product_id', 'ID' ) as $key ) {
				if ( isset( $source->$key ) && is_numeric( $source->$key ) && (int) $source->$key > 0 ) {
					return (int) $source->$key;
				}
			}
			if ( method_exists( $source, 'get_id' ) ) {
				$id = (int) $source->get_id();
				if ( $id > 0 ) {
					return $id;
				}
			}
			if ( isset( $source->id ) && is_numeric( $source->id ) && (int) $source->id > 0 ) {
				return (int) $source->id;
			}
		}

		if ( is_array( $source ) ) {
			if ( $variation ) {
				$parent = self::variationParentId( $source );
				if ( $parent > 0 ) {
					return $parent;
				}
			}
			foreach ( array( 'databaseId', 'ID', 'productId', 'product_id', 'id' ) as $key ) {
				if ( isset( $source[ $key ] ) && is_numeric( $source[ $key ] ) && (int) $source[ $key ] > 0 ) {
					return (int) $source[ $key ];
				}
			}
		}

		return 0;
	}

	/** Producto padre de una variación; 0 si no se puede determinar. */
	private static function variationParentId( mixed $source ): int {
		if ( is_object( $source ) ) {
			foreach ( array( 'parentId', 'parent_id', 'parentID' ) as $key ) {
				if ( isset( $source->$key ) && is_numeric( $source->$key ) && (int) $source->$key > 0 ) {
					return (int) $source->$key;
				}
			}
			if ( method_exists( $source, 'get_parent_id' ) ) {
				$id = (int) $source->get_parent_id();
				if ( $id > 0 ) {
					return $id;
				}
			}
			if ( isset( $source->databaseId ) && is_numeric( $source->databaseId ) ) {
				return self::parentOfPost( (int) $source->databaseId );
			}
		}

		if ( is_array( $source ) ) {
			foreach ( array( 'parentId', 'parent_id', 'parentID', 'parent' ) as $key ) {
				if ( isset( $source[ $key ] ) && is_numeric( $source[ $key ] ) && (int) $source[ $key ] > 0 ) {
					return (int) $source[ $key ];
				}
			}
			if ( isset( $source['databaseId'] ) && is_numeric( $source['databaseId'] ) ) {
				return self::parentOfPost( (int) $source['databaseId'] );
			}
		}

		return 0;
	}

	/** Id de término a partir de la fuente GraphQL. */
	private static function termIdFromSource( mixed $source ): int {
		if ( is_object( $source ) ) {
			foreach ( array( 'term_id', 'databaseId', 'ID' ) as $key ) {
				if ( isset( $source->$key ) && is_numeric( $source->$key ) && (int) $source->$key > 0 ) {
					return (int) $source->$key;
				}
			}
			if ( isset( $source->id ) && is_numeric( $source->id ) && (int) $source->id > 0 ) {
				return (int) $source->id;
			}
		}

		if ( is_array( $source ) ) {
			foreach ( array( 'term_id', 'databaseId', 'ID', 'id' ) as $key ) {
				if ( isset( $source[ $key ] ) && is_numeric( $source[ $key ] ) && (int) $source[ $key ] > 0 ) {
					return (int) $source[ $key ];
				}
			}
		}

		return 0;
	}

	/** Id de base de datos a partir de los argumentos (`id` puede ser global). */
	private static function databaseIdFromArgs( mixed $args ): int {
		$id = is_array( $args ) ? ( $args['id'] ?? $args['databaseId'] ?? 0 ) : 0;
		if ( is_numeric( $id ) ) {
			return max( 0, (int) $id );
		}
		if ( is_string( $id ) && '' !== $id && class_exists( '\\WPGraphQL\\Utils\\Utils' ) ) {
			return max( 0, (int) \WPGraphQL\Utils\Utils::get_database_id_from_id( $id ) );
		}

		return 0;
	}

	private static function postType( int $objectId ): string {
		if ( $objectId <= 0 ) {
			return '';
		}
		if ( function_exists( 'get_post_type' ) ) {
			return (string) get_post_type( $objectId );
		}
		if ( function_exists( 'get_post' ) ) {
			$post = get_post( $objectId );
			return is_object( $post ) ? (string) ( $post->post_type ?? '' ) : '';
		}

		return '';
	}

	private static function parentOfPost( int $objectId ): int {
		if ( $objectId <= 0 ) {
			return 0;
		}
		if ( function_exists( 'get_post' ) ) {
			$post = get_post( $objectId );
			if ( is_object( $post ) ) {
				return (int) ( $post->post_parent ?? 0 );
			}
		}
		if ( function_exists( 'wp_get_post_parent_id' ) ) {
			return (int) wp_get_post_parent_id( $objectId );
		}

		return 0;
	}

	/** ¿La fuente GraphQL representa una variación? */
	private static function sourceIsVariation( mixed $source ): bool {
		if ( is_object( $source ) ) {
			if ( method_exists( $source, 'get_type' ) && 'variation' === (string) $source->get_type() ) {
				return true;
			}
			foreach ( array( 'parentId', 'parent_id', 'parentID' ) as $key ) {
				if ( isset( $source->$key ) && is_numeric( $source->$key ) && (int) $source->$key > 0 ) {
					return true;
				}
			}
			if ( method_exists( $source, 'get_parent_id' ) ) {
				return (int) $source->get_parent_id() > 0;
			}
		}

		if ( is_array( $source ) ) {
			if ( 'variation' === (string) ( $source['type'] ?? '' ) ) {
				return true;
			}
			foreach ( array( 'parentId', 'parent_id', 'parentID', 'parent' ) as $key ) {
				if ( isset( $source[ $key ] ) && is_numeric( $source[ $key ] ) && (int) $source[ $key ] > 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	/** ¿El tipo es una variación de WooGraphQL? */
	private static function isVariationType( string $typeName ): bool {
		return 'ProductVariation' === $typeName || str_contains( $typeName, 'ProductVariation' );
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
