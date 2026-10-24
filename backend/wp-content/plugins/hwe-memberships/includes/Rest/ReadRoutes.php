<?php
/**
 * Rutas internas de lectura del módulo (`hwe/v1/memberships/…`).
 *
 * Solo las consume el BFF, con `X-HWE-Internal-Secret`; `hwe-bff-only` cierra todo
 * `/hwe/v1/memberships/` al navegador. El `user_id` lo pone el BFF desde la sesión verificada y
 * nunca viaja desde el cliente. Cinco lecturas, todas `GET` y todas sin escribir en la base de
 * datos (la caducidad se calcula por fechas):
 *
 *  · `memberships/levels?locale`  → niveles publicados y visibles, con su nombre, descripción y
 *    slug resueltos al idioma pedido (cae al nativo), `slugs` por idioma secundario, rango,
 *    icono, color, comprabilidad, antigüedad mínima, los beneficios configurados y los productos
 *    con los que se compra el nivel.
 *  · `memberships/me?user_id`     → estado del socio (`Members::state`), beneficios activos con
 *    su `stateFor()`, regalos restantes del referidor y elegibilidad de compra por nivel.
 *  · `memberships/history?user_id&page&per_page` → historial paginado (nuevo primero).
 *  · `memberships/prices?user_id&ids` → «precio para ti» de una lista acotada de productos, con
 *    la MISMA función que el carrito (`Pricing::priceFor`).
 *  · `memberships/product?user_id&slug|id&locale` → ficha completa si el socio cumple el
 *    requisito (`Access::canView`); si no, EXACTAMENTE el recorte de `Teaser` (misma función que
 *    la Store API y GraphQL), para que el BFF no pueda distinguir por forma.
 *
 * **Nada de frases.** WordPress no compone textos de cliente: expone claves y datos
 * estructurados (`type` + `data` en el historial, `key` + `public` en los beneficios) y el BFF
 * arma las frases en el idioma de la petición (spec §7.1 y §10.3).
 *
 * **Forma y códigos.** Todas las fechas salen en ISO 8601 UTC (`Y-m-d\TH:i:s\Z`). Un `user_id`
 * ausente, no numérico o ≤ 0 responde 400 `{ error: "invalid_request" }`. Un usuario que no
 * existe responde 200 con la respuesta vacía coherente (`me` sin nivel, `history` con una página
 * vacía, `prices` con lo que se pueda cotizar): ninguna ruta sirve para enumerar cuentas. Un
 * fallo de lectura responde 500 `{ error: "internal_error" }` y se registra: nunca un estado
 * inventado.
 *
 * **Topes.** `history`: `per_page` por defecto 20 y máximo 100, `page` ≥ 1. `prices`: 50 ids
 * únicos como máximo (más ⇒ 400) y los valores no numéricos se descartan. `product`: la ficha de
 * un producto variable publica como mucho 50 variaciones. `prices` cotiza con `priceFor()`: un id
 * que no corresponde a un producto publicado (inexistente, borrador, privado o pendiente) devuelve
 * la cotización a cero (no es un error), porque la
 * lista de ids llega del propio catálogo del BFF.
 *
 * Contrato interno (lo consume el BFF, Tarea 18):
 *
 * ```
 * levels  → { items: [ { id, name, description, slug, slugs, rank, icon, color,
 *                        purchasable, min_account_age_days,
 *                        benefits: [ publicData… ], products: [ { id, price, days } ] } ] }
 * me      → { status: none|active|frozen, level: { id, rank }|null, ends_at, member_since,
 *             member_number, queue: [ { level_id, rank, seconds, days, starts_at, ends_at,
 *                                       undated, infinite } ],
 *             benefits: [ { key, level_id, public, state } ],
 *             gifts: { max, used, remaining },
 *             purchasable: [ { level_id, ok, reason, days_left, min_account_age_days,
 *                              account_age_days, current_level_id } ] }
 * history → { items: [ { id, type, level_from, level_to, data, created_at } ], total, pages }
 * prices  → { items: [ { id, regular, price, member_price, percent } ] }
 * product → ficha completa o ficha de escaparate (mismas claves que el catálogo público)
 * ```
 *
 * `days` de `levels.products` es `null` cuando la membresía es indefinida. `slugs` es el mapa de
 * idiomas secundarios (el nativo es `slug`). `me.level` lleva solo id y rango: la etiqueta del
 * nivel sale de `levels`, que el BFF ya tiene cacheada por ISR. `me.member_number` es el número
 * guardado, sin el prefijo visible: el carné lo aplica (`Settings::member_number_prefix`, público
 * en la config del sitio) y el BFF debe anteponerlo si lo muestra. En `history`, `data` es una
 * lista blanca por tipo de evento (ver `EVENT_DATA`): nunca lleva la clave idempotente,
 * referencias internas ni ids de terceros.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_ReadRoutes {

	public const NAMESPACE = 'hwe/v1';

	/** Página por defecto y tope duro del historial. */
	public const PER_PAGE_DEFAULT = 20;
	public const PER_PAGE_MAX     = 100;

	/** Tope de ids que acepta `prices` en una sola lectura. */
	public const MAX_PRICE_IDS = 50;

	/** Tope de variaciones que publica la ficha de un producto variable. */
	public const MAX_VARIATIONS = 50;

	/** Clave del beneficio que aporta los regalos por referido. */
	private const GIFT_BENEFIT = 'referral_gift';

	/**
	 * Claves de `data` que viajan al BFF por tipo de evento, con su conversión.
	 *
	 * Es una lista blanca: los tipos conocidos no arrastran la clave idempotente (`key`), la
	 * referencia interna (`ref`), el lote (`batch`) ni ids de terceros (`referee`, `referrer`), y
	 * un tipo desconocido (añadido en el futuro) viaja con `data` vacía en lugar de exponer datos
	 * que el BFF no sabe interpretar. `date` convierte las fechas guardadas en UTC a ISO 8601.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const EVENT_DATA = array(
		'activated'                  => array(
			'origin'    => 'string',
			'days'      => 'int',
			'starts_at' => 'date',
			'ends_at'   => 'date',
			'status'    => 'string',
		),
		'renewed'                    => array(
			'origin'    => 'string',
			'days'      => 'int',
			'starts_at' => 'date',
			'ends_at'   => 'date',
			'status'    => 'string',
		),
		'upgraded'                   => array(
			'origin'    => 'string',
			'days'      => 'int',
			'starts_at' => 'date',
			'ends_at'   => 'date',
			'status'    => 'string',
		),
		'deferred'                   => array(
			'origin'    => 'string',
			'days'      => 'int',
			'starts_at' => 'date',
			'ends_at'   => 'date',
			'status'    => 'string',
		),
		'granted_by_admin'           => array(
			'origin'    => 'string',
			'days'      => 'int',
			'starts_at' => 'date',
			'ends_at'   => 'date',
			'status'    => 'string',
		),
		'referral_gift_granted'      => array(
			'origin'    => 'string',
			'days'      => 'int',
			'starts_at' => 'date',
			'ends_at'   => 'date',
			'status'    => 'string',
		),
		'revoked'                    => array(
			'reason' => 'string',
			'active' => 'bool',
		),
		'removed_by_admin'           => array( 'reason' => 'string' ),
		'expiring'                   => array( 'days' => 'int' ),
		'expired'                    => array(),
		'frozen'                     => array(),
		'unfrozen'                   => array(),
		'points_skipped'             => array(
			'points' => 'int',
			'reason' => 'string',
			'origin' => 'string',
		),
		'referral_gift_limit_reached' => array(
			'gift_level_id'  => 'int',
			'days'           => 'int',
			'max_per_period' => 'int',
			'used'           => 'int',
		),
	);

	/** Engancha el registro de rutas. La llama `HWE_Memberships::boot()`. */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'registerRoutes' ) );
	}

	public static function registerRoutes(): void {
		$routes = array(
			'/memberships/levels'  => 'handleLevels',
			'/memberships/me'      => 'handleMe',
			'/memberships/history' => 'handleHistory',
			'/memberships/prices'  => 'handlePrices',
			'/memberships/product' => 'handleProduct',
		);

		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => 'GET',
					'permission_callback' => array( self::class, 'permission' ),
					'callback'            => array( self::class, $callback ),
				)
			);
		}
	}

	/**
	 * Secreto interno + módulo activo. Sin el secreto devuelve `false`; con el módulo apagado,
	 * el mismo `WP_Error` que una ruta inexistente (404), para no revelar que existe.
	 *
	 * @param mixed $request
	 * @return bool|\WP_Error
	 */
	public static function permission( $request ) {
		if ( ! function_exists( 'hwe_has_internal_secret' ) ) {
			return false;
		}

		try {
			if ( ! hwe_has_internal_secret( $request ) ) {
				return false;
			}
		} catch ( \Throwable $e ) {
			// Sin poder comprobar el secreto, la ruta no atiende.
			return false;
		}

		$active = false;
		if ( class_exists( 'HWE_Memberships_Settings' ) ) {
			try {
				$active = HWE_Memberships_Settings::moduleActive();
			} catch ( \Throwable $e ) {
				// No se puede confirmar que el módulo esté encendido: se falla cerrada.
				$active = false;
			}
		}

		if ( ! $active ) {
			return new WP_Error(
				'rest_no_route',
				__( 'No route was found matching the URL and request method.' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	// ------------------------------------------------------------------------
	//  Niveles
	// ------------------------------------------------------------------------

	/** Niveles publicados con beneficios y productos de compra. */
	public static function handleLevels( $request ): WP_REST_Response {
		$locale = self::localeKey( $request );

		return self::respond(
			'levels',
			static function () use ( $locale ): array {
				return array( 'items' => self::levels( $locale ) );
			}
		);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private static function levels( string $locale ): array {
		if ( ! class_exists( 'HWE_Memberships_Levels' ) ) {
			throw new RuntimeException( 'no está disponible la lectura de niveles' );
		}

		$levels = HWE_Memberships_Levels::all();
		if ( ! is_array( $levels ) ) {
			throw new RuntimeException( 'la lectura de niveles no devolvió una lista' );
		}

		$products = self::purchaseProducts( $levels );
		$items    = array();

		foreach ( $levels as $level ) {
			if ( ! is_array( $level ) ) {
				continue;
			}
			$levelId = (int) ( $level['id'] ?? 0 );
			if ( $levelId <= 0 ) {
				continue;
			}

			$items[] = array(
				'id'                   => $levelId,
				'name'                 => self::localized( $level, 'name', $locale ),
				'description'          => self::localized( $level, 'description', $locale ),
				'slug'                 => self::localized( $level, 'slug', $locale ),
				'slugs'                => self::slugs( $level ),
				'rank'                 => (int) ( $level['rank'] ?? 0 ),
				'icon'                 => is_scalar( $level['icon'] ?? null ) ? (string) $level['icon'] : '',
				'color'                => is_scalar( $level['color'] ?? null ) ? (string) $level['color'] : '',
				'purchasable'          => ! empty( $level['purchasable'] ),
				'min_account_age_days' => max( 0, (int) ( $level['min_account_age_days'] ?? 0 ) ),
				'benefits'             => self::levelBenefits( $levelId ),
				'products'             => $products[ $levelId ] ?? array(),
			);
		}

		return $items;
	}

	/** Texto del nivel en el idioma pedido (sufijo), con el nativo como respaldo. */
	private static function localized( array $level, string $field, string $locale ): string {
		$base = isset( $level[ $field ] ) && is_scalar( $level[ $field ] ) ? (string) $level[ $field ] : '';

		if ( '' === $locale ) {
			return $base;
		}

		$translations = isset( $level['translations'] ) && is_array( $level['translations'] )
			? $level['translations']
			: array();
		$entry = $translations[ $locale ] ?? null;

		if ( ! is_array( $entry ) || ! isset( $entry[ $field ] ) || ! is_string( $entry[ $field ] ) ) {
			return $base;
		}

		return '' !== trim( $entry[ $field ] ) ? $entry[ $field ] : $base;
	}

	/**
	 * Slug por idioma secundario. El nativo es `slug`; aquí solo viajan los traducidos (o el
	 * nativo como respaldo de cada idioma).
	 *
	 * @return array<string,string>
	 */
	private static function slugs( array $level ): array {
		$out = array();

		foreach ( (array) ( $level['slugs'] ?? array() ) as $locale => $slug ) {
			if ( ! is_string( $locale ) || ! is_scalar( $slug ) ) {
				continue;
			}
			$slug = trim( (string) $slug );
			if ( '' !== $slug ) {
				$out[ $locale ] = $slug;
			}
		}

		return $out;
	}

	/**
	 * Beneficios configurados y disponibles de un nivel, con su representación pública
	 * (clave + datos, nunca frases). Un tipo cuya dependencia está apagada no sale.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function levelBenefits( int $levelId ): array {
		if ( ! class_exists( 'HWE_Memberships_Benefits' ) ) {
			return array();
		}

		$out = array();

		foreach ( HWE_Memberships_Benefits::all() as $key => $benefit ) {
			try {
				if ( ! $benefit->available() ) {
					continue;
				}
				$config = $benefit->configFor( $levelId );
				if ( null === $config || empty( $config['enabled'] ) ) {
					continue;
				}
				$out[] = $benefit->publicData( $levelId );
			} catch ( \Throwable $e ) {
				self::log(
					'sin el beneficio «' . (string) $key . '» del nivel ' . $levelId . ': falló su lectura.'
				);
			}
		}

		return $out;
	}

	/**
	 * Productos publicados de compra de membresía, agrupados por nivel. Una sola consulta de ids
	 * y una sola carga de productos para TODOS los niveles: nunca una consulta por nivel.
	 *
	 * @param list<mixed> $levels Niveles visibles.
	 * @return array<int,list<array{id:int,price:string,days:?int}>>
	 */
	private static function purchaseProducts( array $levels ): array {
		$visible = array();
		foreach ( $levels as $level ) {
			$levelId = is_array( $level ) ? (int) ( $level['id'] ?? 0 ) : 0;
			if ( $levelId > 0 ) {
				$visible[ $levelId ] = true;
			}
		}

		if (
			array() === $visible
			|| ! class_exists( 'HWE_Memberships_Products' )
			|| ! function_exists( 'get_posts' )
			|| ! function_exists( 'wc_get_products' )
		) {
			return array();
		}

		try {
			$ids = get_posts(
				array(
					'post_type'   => 'product',
					'post_status' => 'publish',
					'numberposts' => -1,
					'fields'      => 'ids',
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'meta_key'    => HWE_Memberships_Products::LEVEL_META,
				)
			);
		} catch ( \Throwable $e ) {
			self::log( 'no se pudieron listar los productos de membresía; los niveles van sin ellos.' );
			return array();
		}

		if ( ! is_array( $ids ) ) {
			self::log( 'el listado de productos de membresía no devolvió una lista.' );
			return array();
		}

		$ids = array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static fn ( int $id ): bool => $id > 0
			)
		);
		if ( array() === $ids ) {
			return array();
		}

		try {
			$products = wc_get_products(
				array(
					'include' => $ids,
					'limit'   => -1,
					'status'  => 'publish',
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'objects',
				)
			);
		} catch ( \Throwable $e ) {
			self::log( 'no se pudieron cargar los productos de membresía; los niveles van sin ellos.' );
			return array();
		}

		if ( ! is_array( $products ) ) {
			self::log( 'la carga de productos de membresía no devolvió una lista.' );
			return array();
		}

		$out = array();
		foreach ( $products as $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
				continue;
			}
			$productId  = (int) $product->get_id();
			$membership = $productId > 0 ? HWE_Memberships_Products::membershipOf( $productId ) : null;
			if ( null === $membership ) {
				continue;
			}

			$levelId = (int) ( $membership['level_id'] ?? 0 );
			if ( ! isset( $visible[ $levelId ] ) ) {
				continue;
			}

			$out[ $levelId ][] = array(
				'id'    => $productId,
				'price' => self::decimal( self::productText( $product, 'get_price', '0' ) ),
				'days'  => null === ( $membership['days'] ?? null ) ? null : max( 0, (int) $membership['days'] ),
			);
		}

		return $out;
	}

	// ------------------------------------------------------------------------
	//  Estado del socio
	// ------------------------------------------------------------------------

	/** Estado del socio, beneficios activos, regalos restantes y elegibilidad de compra. */
	public static function handleMe( $request ): WP_REST_Response {
		$userId = self::userId( $request );
		if ( $userId <= 0 ) {
			return self::invalid();
		}

		return self::respond(
			'me',
			static function () use ( $userId ): array {
				return self::memberState( $userId );
			}
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function memberState( int $userId ): array {
		// Un usuario inexistente no se distingue de uno sin membresía más allá de lo público:
		// respuesta vacía y ni una consulta, para que la ruta no sirva para enumerar cuentas.
		if ( ! self::userExists( $userId ) ) {
			return self::emptyMemberState();
		}

		if ( ! class_exists( 'HWE_Memberships_Members' ) ) {
			throw new RuntimeException( 'no está disponible la lectura del estado del socio' );
		}

		$state    = HWE_Memberships_Members::state( $userId );
		$benefits = self::activeBenefits( $userId );

		return array(
			'status'        => self::status( (string) ( $state['status'] ?? '' ) ),
			'level'         => self::levelRef( (int) ( $state['level_id'] ?? 0 ), (int) ( $state['rank'] ?? 0 ) ),
			'ends_at'       => self::isoDate( $state['ends_at'] ?? null ),
			'member_since'  => self::isoDate( $state['member_since'] ?? null ),
			'member_number' => is_scalar( $state['member_number'] ?? null ) ? (string) $state['member_number'] : '',
			'queue'         => self::queue( $state['queue'] ?? array() ),
			'benefits'      => $benefits,
			'gifts'         => self::gifts( $benefits ),
			'purchasable'   => self::purchasable( $userId ),
		);
	}

	/**
	 * @return array{status:string,level:null,ends_at:null,member_since:null,member_number:string,queue:list<mixed>,benefits:list<mixed>,gifts:array{max:int,used:int,remaining:int},purchasable:list<mixed>}
	 */
	private static function emptyMemberState(): array {
		return array(
			'status'        => 'none',
			'level'         => null,
			'ends_at'       => null,
			'member_since'  => null,
			'member_number' => '',
			'queue'         => array(),
			'benefits'      => array(),
			'gifts'         => array(
				'max'       => 0,
				'used'      => 0,
				'remaining' => 0,
			),
			'purchasable'   => array(),
		);
	}

	/** Solo los tres estados del contrato; cualquier otro se trata como `none`. */
	private static function status( string $status ): string {
		$allowed = array(
			HWE_Memberships_Members::STATUS_NONE,
			HWE_Memberships_Members::STATUS_ACTIVE,
			HWE_Memberships_Members::STATUS_FROZEN,
		);

		return in_array( $status, $allowed, true ) ? $status : 'none';
	}

	/**
	 * Nivel efectivo: solo id y rango. La etiqueta sale de `levels`, que el BFF ya cachea.
	 *
	 * @return array{id:int,rank:int}|null
	 */
	private static function levelRef( int $levelId, int $rank ): ?array {
		if ( $levelId <= 0 || $rank < 1 ) {
			return null;
		}

		return array(
			'id'   => $levelId,
			'rank' => $rank,
		);
	}

	/**
	 * Cola programada por nivel con las fechas en ISO 8601 UTC.
	 *
	 * @param mixed $queue
	 * @return list<array<string,mixed>>
	 */
	private static function queue( $queue ): array {
		$out = array();

		foreach ( (array) $queue as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$out[] = array(
				'level_id'  => (int) ( $entry['level_id'] ?? 0 ),
				'rank'      => (int) ( $entry['rank'] ?? 0 ),
				'seconds'   => max( 0, (int) ( $entry['seconds'] ?? 0 ) ),
				'days'      => (float) ( $entry['days'] ?? 0 ),
				'starts_at' => self::isoDate( $entry['starts_at'] ?? null ),
				'ends_at'   => self::isoDate( $entry['ends_at'] ?? null ),
				'undated'   => ! empty( $entry['undated'] ),
				'infinite'  => ! empty( $entry['infinite'] ),
			);
		}

		return $out;
	}

	/**
	 * Beneficios activos del socio, en orden de registro, con su representación pública y su
	 * estado (cupos, porcentaje…) ya calculados por `stateFor()`.
	 *
	 * @return list<array{key:string,level_id:int,public:array<string,mixed>,state:array<string,mixed>}>
	 */
	private static function activeBenefits( int $userId ): array {
		if ( ! class_exists( 'HWE_Memberships_Benefits' ) ) {
			return array();
		}

		$out = array();

		foreach ( (array) HWE_Memberships_Benefits::activeFor( $userId ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$out[] = array(
				'key'      => is_scalar( $entry['key'] ?? null ) ? (string) $entry['key'] : '',
				'level_id' => (int) ( $entry['level_id'] ?? 0 ),
				'public'   => is_array( $entry['public'] ?? null ) ? $entry['public'] : array(),
				'state'    => is_array( $entry['state'] ?? null ) ? $entry['state'] : array(),
			);
		}

		return $out;
	}

	/**
	 * Regalos que le quedan al referidor en el periodo de su concesión activa. Se toma del estado
	 * ya calculado del beneficio `referral_gift` (no se lee dos veces); sin el beneficio, ceros.
	 *
	 * @param list<array{key:string,level_id:int,public:array<string,mixed>,state:array<string,mixed>}> $benefits
	 * @return array{max:int,used:int,remaining:int}
	 */
	private static function gifts( array $benefits ): array {
		$gifts = array(
			'max'       => 0,
			'used'      => 0,
			'remaining' => 0,
		);

		foreach ( $benefits as $benefit ) {
			if ( self::GIFT_BENEFIT !== ( $benefit['key'] ?? '' ) ) {
				continue;
			}
			$state = $benefit['state'];
			$gifts = array(
				'max'       => max( 0, (int) ( $state['gifts_max'] ?? 0 ) ),
				'used'      => max( 0, (int) ( $state['gifts_used'] ?? 0 ) ),
				'remaining' => max( 0, (int) ( $state['gifts_remaining'] ?? 0 ) ),
			);
			break;
		}

		return $gifts;
	}

	/**
	 * Elegibilidad de compra de los niveles comprables y publicados. Los motivos son los estables
	 * de `HWE_Memberships_Purchase`; el texto lo compone el BFF.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function purchasable( int $userId ): array {
		if (
			! class_exists( 'HWE_Memberships_Levels' )
			|| ! class_exists( 'HWE_Memberships_Purchase' )
		) {
			return array();
		}

		$out = array();

		foreach ( HWE_Memberships_Levels::all() as $level ) {
			if ( ! is_array( $level ) || empty( $level['purchasable'] ) ) {
				continue;
			}
			$levelId = (int) ( $level['id'] ?? 0 );
			if ( $levelId <= 0 ) {
				continue;
			}

			$result = HWE_Memberships_Purchase::canPurchase( $userId, $levelId );

			$out[] = array(
				'level_id'             => (int) ( $result['level_id'] ?? $levelId ),
				'ok'                   => ! empty( $result['ok'] ),
				'reason'               => is_scalar( $result['reason'] ?? null ) ? (string) $result['reason'] : '',
				'days_left'            => max( 0, (int) ( $result['days_left'] ?? 0 ) ),
				'min_account_age_days' => max( 0, (int) ( $result['min_account_age_days'] ?? 0 ) ),
				'account_age_days'     => null === ( $result['account_age_days'] ?? null )
					? null
					: max( 0, (int) $result['account_age_days'] ),
				'current_level_id'     => (int) ( $result['current_level_id'] ?? 0 ),
			);
		}

		return $out;
	}

	// ------------------------------------------------------------------------
	//  Historial
	// ------------------------------------------------------------------------

	/** Historial del propio usuario, del más nuevo al más antiguo. */
	public static function handleHistory( $request ): WP_REST_Response {
		$userId = self::userId( $request );
		if ( $userId <= 0 ) {
			return self::invalid();
		}

		list( $page, $perPage ) = self::pagination( $request );

		return self::respond(
			'history',
			static function () use ( $userId, $page, $perPage ): array {
				return self::history( $userId, $page, $perPage );
			}
		);
	}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int,pages:int}
	 */
	private static function history( int $userId, int $page, int $perPage ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			throw new RuntimeException( 'no hay acceso a la base de datos para leer el historial' );
		}

		$table  = class_exists( 'HWE_Memberships_Schema' )
			? HWE_Memberships_Schema::eventsTable()
			: $wpdb->prefix . 'hwe_memberships_events';
		$offset = max( 0, ( $page - 1 ) * $perPage );

		$sql  = 'SELECT id, type, level_from, level_to, data, created_at FROM ' . $table
			. ' WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $userId, $perPage, $offset ) );

		if ( ! is_array( $rows ) || self::databaseFailed( $wpdb ) ) {
			throw new RuntimeException( 'la consulta del historial del usuario ' . $userId . ' falló' );
		}

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE user_id = %d', $userId )
		);
		if ( self::databaseFailed( $wpdb ) ) {
			throw new RuntimeException( 'el conteo del historial del usuario ' . $userId . ' falló' );
		}
		$total = max( 0, $total );

		$items = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) ) {
				continue;
			}
			$type    = is_scalar( $row->type ?? null ) ? (string) $row->type : '';
			$items[] = array(
				'id'         => (int) ( $row->id ?? 0 ),
				'type'       => $type,
				'level_from' => (int) ( $row->level_from ?? 0 ),
				'level_to'   => (int) ( $row->level_to ?? 0 ),
				'data'       => self::publicEventData( $type, self::decodeData( $row->data ?? null ) ),
				'created_at' => self::isoDate( $row->created_at ?? null ),
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => self::pages( $total, $perPage ),
		);
	}

	/**
	 * `data` público de un evento: la lista blanca de `EVENT_DATA` para su tipo, con las fechas en
	 * ISO 8601 UTC. Un tipo desconocido viaja con `data` vacía.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private static function publicEventData( string $type, array $data ): array {
		$fields = self::EVENT_DATA[ $type ] ?? array();
		$out    = array();

		foreach ( $fields as $key => $cast ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			$value = $data[ $key ];

			if ( 'int' === $cast ) {
				if ( is_scalar( $value ) && is_numeric( $value ) ) {
					$out[ $key ] = (int) $value;
				}
				continue;
			}

			if ( 'bool' === $cast ) {
				$out[ $key ] = self::toBool( $value );
				continue;
			}

			if ( 'date' === $cast ) {
				$iso = self::isoDate( $value );
				if ( null !== $iso ) {
					$out[ $key ] = $iso;
				}
				continue;
			}

			if ( is_scalar( $value ) ) {
				$out[ $key ] = (string) $value;
			}
		}

		return $out;
	}

	/**
	 * @param mixed $raw
	 * @return array<string,mixed>
	 */
	private static function decodeData( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	// ------------------------------------------------------------------------
	//  Precios
	// ------------------------------------------------------------------------

	/** «Precio para ti» de una lista acotada de productos, con la función que usa el carrito. */
	public static function handlePrices( $request ): WP_REST_Response {
		$userId = self::userId( $request );
		if ( $userId <= 0 ) {
			return self::invalid();
		}

		$ids = self::priceIds( $request );
		if ( null === $ids ) {
			return self::invalid();
		}

		return self::respond(
			'prices',
			static function () use ( $userId, $ids ): array {
				return array( 'items' => self::prices( $userId, $ids ) );
			}
		);
	}

	/**
	 * @param list<int> $ids
	 * @return list<array{id:int,regular:string,price:string,member_price:string,percent:int}>
	 */
	private static function prices( int $userId, array $ids ): array {
		if ( array() === $ids || ! class_exists( 'HWE_Memberships_Pricing' ) ) {
			return array();
		}

		$out = array();

		foreach ( $ids as $productId ) {
			// Solo lo publicado: la ruta es de cualquier cliente con sesión y no puede cotizar un
			// borrador, un producto privado o pendiente (se cotiza a cero, como un id ajeno).
			$quote = self::isPublishedProduct( $productId )
				? HWE_Memberships_Pricing::priceFor( $userId, $productId )
				: array();

			$out[] = array(
				'id'           => $productId,
				'regular'      => self::decimal( $quote['regular'] ?? 0 ),
				'price'        => self::decimal( $quote['price'] ?? 0 ),
				'member_price' => self::decimal( $quote['member_price'] ?? 0 ),
				'percent'      => max( 0, min( 100, (int) ( $quote['percent'] ?? 0 ) ) ),
			);
		}

		return $out;
	}

	/**
	 * Ids pedidos, sin repetidos y en el orden de la petición. `null` si superan el tope (entrada
	 * inválida); los valores no numéricos se descartan.
	 *
	 * @param mixed $request
	 * @return list<int>|null
	 */
	private static function priceIds( $request ): ?array {
		$raw = $request->get_param( 'ids' );

		if ( null === $raw || '' === $raw ) {
			return array();
		}

		if ( is_string( $raw ) ) {
			$items = preg_split( '/[\s,]+/', $raw );
			$items = is_array( $items ) ? $items : array();
		} elseif ( is_array( $raw ) ) {
			$items = $raw;
		} else {
			return array();
		}

		$ids = array();
		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) || ! preg_match( '/^\d+$/', trim( (string) $item ) ) ) {
				continue;
			}
			$productId = (int) $item;
			if ( $productId > 0 && ! in_array( $productId, $ids, true ) ) {
				$ids[] = $productId;
			}
		}

		return count( $ids ) > self::MAX_PRICE_IDS ? null : $ids;
	}

	// ------------------------------------------------------------------------
	//  Ficha de producto
	// ------------------------------------------------------------------------

	/**
	 * Ficha completa si el socio cumple el requisito; si no, exactamente el recorte de `Teaser`.
	 * Un producto inexistente o no publicado responde 404 con el mismo cuerpo que un id que no
	 * es un producto, para no distinguir «no existe» de «no es visible».
	 */
	public static function handleProduct( $request ): WP_REST_Response {
		$userId = self::userId( $request );
		if ( $userId <= 0 ) {
			return self::invalid();
		}

		$productId = self::resolveProduct( $request );
		if ( false === $productId ) {
			return self::invalid();
		}
		if ( 0 === $productId ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$locale = self::localeKey( $request );

		return self::respond(
			'product',
			static function () use ( $userId, $productId, $locale ): array {
				$payload = self::productPayload( $productId, $locale );
				if ( null === $payload ) {
					throw new RuntimeException( 'el producto ' . $productId . ' no se pudo cargar' );
				}

				if ( self::canView( $userId, $productId ) ) {
					return $payload;
				}

				if ( ! class_exists( 'HWE_Memberships_Teaser' ) ) {
					throw new RuntimeException( 'no está disponible la ficha de escaparate' );
				}

				return HWE_Memberships_Teaser::trimWcProduct( $payload, $userId );
			}
		);
	}

	/**
	 * Id del producto publicado pedido por `id` o `slug` (el id manda si llegan los dos), `0` si
	 * no existe o no está publicado, `false` si la entrada no es válida.
	 *
	 * @param mixed $request
	 * @return int|false
	 */
	private static function resolveProduct( $request ) {
		$rawId = $request->get_param( 'id' );
		$rawSlug = $request->get_param( 'slug' );

		$hasId   = null !== $rawId && '' !== $rawId;
		$hasSlug = is_scalar( $rawSlug ) && '' !== trim( (string) $rawSlug );

		if ( ! $hasId && ! $hasSlug ) {
			return false;
		}

		$productId = 0;

		if ( $hasId ) {
			if ( ! is_scalar( $rawId ) || ! preg_match( '/^\d+$/', trim( (string) $rawId ) ) ) {
				return false;
			}
			$productId = (int) $rawId;
			if ( $productId <= 0 ) {
				return false;
			}
		} else {
			$productId = self::productIdBySlug( trim( (string) $rawSlug ) );
		}

		if ( $productId <= 0 || ! self::isPublishedProduct( $productId ) ) {
			return 0;
		}

		return $productId;
	}

	/** Id de un producto por su slug; `0` si no hay ninguno. */
	private static function productIdBySlug( string $slug ): int {
		if ( '' === $slug || ! function_exists( 'get_page_by_path' ) ) {
			return 0;
		}

		$post = get_page_by_path( $slug, 'OBJECT', 'product' );

		return is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
	}

	/** ¿Es un producto publicado? Nunca se sirven borradores, privados, pendientes ni programados. */
	private static function isPublishedProduct( int $productId ): bool {
		if ( $productId <= 0 || ! function_exists( 'get_post' ) ) {
			return false;
		}

		$post = get_post( $productId );

		return is_object( $post )
			&& 'product' === (string) ( $post->post_type ?? '' )
			&& 'publish' === (string) ( $post->post_status ?? '' );
	}

	/** ¿Puede el socio ver la ficha completa? Sin poder evaluarlo, se trata como bloqueada. */
	private static function canView( int $userId, int $productId ): bool {
		if ( ! class_exists( 'HWE_Memberships_Access' ) ) {
			return false;
		}

		try {
			return (bool) HWE_Memberships_Access::canView( $userId, $productId );
		} catch ( \Throwable $e ) {
			self::log( 'no se pudo evaluar el acceso del producto ' . $productId . '; se trata como bloqueado.' );
			return false;
		}
	}

	/**
	 * Ficha completa del producto publicado, con la forma del catálogo (nombre, descripciones,
	 * galería, categorías, atributos, variaciones y los campos declarativos de la instancia).
	 * Deliberadamente usa las mismas claves que el recorte de `Teaser`: la ficha de escaparate es
	 * un subconjunto de esta.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function productPayload( int $productId, string $locale ): ?array {
		if ( $productId <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $productId );
		if ( ! is_object( $product ) ) {
			return null;
		}

		$id   = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : $productId;
		$type = self::productText( $product, 'get_type', 'simple' );

		return array(
			'id'                   => $id,
			'name'                 => self::translatedProduct( $id, 'title', self::productText( $product, 'get_name' ), $locale ),
			'slug'                 => self::translatedProduct( $id, 'slug', self::productText( $product, 'get_slug' ), $locale ),
			'type'                 => $type,
			'status'               => self::productText( $product, 'get_status', 'publish' ),
			'purchasable'          => self::productBool( $product, 'is_purchasable', true ),
			'on_sale'              => self::productBool( $product, 'is_on_sale' ),
			'price'                => self::decimal( self::productText( $product, 'get_price', '0' ) ),
			'regular_price'        => self::decimal( self::productText( $product, 'get_regular_price', '0' ) ),
			'sale_price'           => self::nullableDecimal( self::productText( $product, 'get_sale_price' ) ),
			'stock_status'         => self::productText( $product, 'get_stock_status', 'instock' ),
			'manage_stock'         => self::productBool( $product, 'get_manage_stock' ),
			'stock_quantity'       => self::productInt( $product, 'get_stock_quantity' ),
			'backorders_allowed'   => self::productBool( $product, 'backorders_allowed' ),
			'short_description'    => self::translatedProduct( $id, 'excerpt', self::productText( $product, 'get_short_description' ), $locale ),
			'description'          => self::translatedProduct( $id, 'content', self::productText( $product, 'get_description' ), $locale ),
			'categories'           => self::productCategories( $product ),
			'images'               => self::productImages( $product ),
			'attributes'           => self::productAttributes( $product ),
			'variations'           => 'variable' === $type ? self::productVariations( $product ) : array(),
			'hweFields'            => self::instanceFields( $id, $locale ),
			'hwe_membership'       => self::requirementPayload( $id ),
			'locked'               => false,
		);
	}

	/** Texto del producto en el idioma pedido (sufijo), con el nativo como respaldo. */
	private static function translatedProduct( int $productId, string $field, string $base, string $locale ): string {
		if ( '' === $locale || ! function_exists( 'hwe_i18n_get' ) ) {
			return $base;
		}

		$value = hwe_i18n_get( 'post', $productId, $field, $locale );

		return is_string( $value ) && '' !== trim( $value ) ? $value : $base;
	}

	/**
	 * @return list<array{id:int,name:string,slug:string}>
	 */
	private static function productCategories( object $product ): array {
		$ids = method_exists( $product, 'get_category_ids' ) ? (array) $product->get_category_ids() : array();
		$out = array();

		foreach ( $ids as $termId ) {
			if ( ! is_scalar( $termId ) || (int) $termId <= 0 ) {
				continue;
			}
			$termId = (int) $termId;
			$term   = function_exists( 'get_term' ) ? get_term( $termId, 'product_cat' ) : null;

			$out[] = array(
				'id'   => $termId,
				'name' => is_object( $term ) && isset( $term->name ) ? (string) $term->name : '',
				'slug' => is_object( $term ) && isset( $term->slug ) ? (string) $term->slug : '',
			);
		}

		return $out;
	}

	/**
	 * Imagen principal primero y después la galería, sin repetir.
	 *
	 * @return list<array{id:int,src:string,alt:string}>
	 */
	private static function productImages( object $product ): array {
		$ids  = array();
		$main = self::productInt( $product, 'get_image_id' );
		if ( null !== $main && $main > 0 ) {
			$ids[] = $main;
		}
		if ( method_exists( $product, 'get_gallery_image_ids' ) ) {
			foreach ( (array) $product->get_gallery_image_ids() as $galleryId ) {
				$galleryId = (int) $galleryId;
				if ( $galleryId > 0 && ! in_array( $galleryId, $ids, true ) ) {
					$ids[] = $galleryId;
				}
			}
		}

		$out = array();
		foreach ( $ids as $imageId ) {
			$out[] = self::image( $imageId );
		}

		return $out;
	}

	/**
	 * @return array{id:int,src:string,alt:string}
	 */
	private static function image( int $imageId ): array {
		$src = '';
		if ( function_exists( 'wp_get_attachment_image_url' ) ) {
			$src = (string) wp_get_attachment_image_url( $imageId, 'full' );
		}
		if ( '' === $src && function_exists( 'wp_get_attachment_url' ) ) {
			$src = (string) wp_get_attachment_url( $imageId );
		}

		$alt = function_exists( 'get_post_meta' )
			? get_post_meta( $imageId, '_wp_attachment_image_alt', true )
			: '';

		return array(
			'id'  => $imageId,
			'src' => $src,
			'alt' => is_scalar( $alt ) ? (string) $alt : '',
		);
	}

	/**
	 * Atributos del producto (los objetos `WC_Product_Attribute`, nunca los de una variación).
	 *
	 * @return list<array{id:int,name:string,options:list<string>,visible:bool,variation:bool}>
	 */
	private static function productAttributes( object $product ): array {
		if ( ! method_exists( $product, 'get_attributes' ) ) {
			return array();
		}

		$out = array();
		foreach ( (array) $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) ) {
				continue;
			}

			$options = method_exists( $attribute, 'get_options' )
				? array_values( array_map( 'strval', (array) $attribute->get_options() ) )
				: array();

			$out[] = array(
				'id'        => method_exists( $attribute, 'get_id' ) ? (int) $attribute->get_id() : 0,
				'name'      => (string) $attribute->get_name(),
				'options'   => $options,
				'visible'   => method_exists( $attribute, 'get_visible' ) ? (bool) $attribute->get_visible() : true,
				'variation' => method_exists( $attribute, 'get_variation' ) ? (bool) $attribute->get_variation() : false,
			);
		}

		return $out;
	}

	/**
	 * Variaciones publicadas del producto variable, con precio, stock y sus atributos. Se corta
	 * en `MAX_VARIATIONS` para que una ficha enorme no infle la respuesta.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function productVariations( object $product ): array {
		if ( ! method_exists( $product, 'get_children' ) || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$out = array();
		foreach ( (array) $product->get_children() as $childId ) {
			if ( count( $out ) >= self::MAX_VARIATIONS ) {
				break;
			}

			$childId   = (int) $childId;
			$variation = $childId > 0 ? wc_get_product( $childId ) : false;
			if ( ! is_object( $variation ) ) {
				continue;
			}

			$out[] = array(
				'id'                 => method_exists( $variation, 'get_id' ) ? (int) $variation->get_id() : $childId,
				'price'              => self::decimal( self::productText( $variation, 'get_price', '0' ) ),
				'regular_price'      => self::decimal( self::productText( $variation, 'get_regular_price', '0' ) ),
				'sale_price'         => self::nullableDecimal( self::productText( $variation, 'get_sale_price' ) ),
				'on_sale'            => self::productBool( $variation, 'is_on_sale' ),
				'stock_status'       => self::productText( $variation, 'get_stock_status', 'instock' ),
				'manage_stock'       => self::productBool( $variation, 'get_manage_stock' ),
				'stock_quantity'     => self::productInt( $variation, 'get_stock_quantity' ),
				'backorders_allowed' => self::productBool( $variation, 'backorders_allowed' ),
				'attributes'         => self::variationAttributes( $variation ),
			);
		}

		return $out;
	}

	/**
	 * Atributos de una variación: `WC_Product_Variation::get_attributes()` devuelve un mapa
	 * nombre → valor, no objetos.
	 *
	 * @return list<array{name:string,option:string}>
	 */
	private static function variationAttributes( object $variation ): array {
		if ( ! method_exists( $variation, 'get_attributes' ) ) {
			return array();
		}

		$out = array();
		foreach ( (array) $variation->get_attributes() as $name => $value ) {
			if ( ! is_string( $name ) || '' === $name || ! is_scalar( $value ) ) {
				continue;
			}
			$out[] = array(
				'name'   => $name,
				'option' => (string) $value,
			);
		}

		return $out;
	}

	/**
	 * Campos de producto declarativos de la instancia (`hwe-product-fields`), si el mu-plugin
	 * está presente.
	 *
	 * @return list<array{key:string,value:string}>
	 */
	private static function instanceFields( int $productId, string $locale ): array {
		if ( ! function_exists( 'hwe_product_fields_values' ) ) {
			return array();
		}

		$values = hwe_product_fields_values( $productId, $locale );
		if ( ! is_array( $values ) ) {
			return array();
		}

		$out = array();
		foreach ( $values as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['key'] ) || ! is_scalar( $entry['key'] ) ) {
				continue;
			}
			$out[] = array(
				'key'   => (string) $entry['key'],
				'value' => is_scalar( $entry['value'] ?? null ) ? (string) $entry['value'] : '',
			);
		}

		return $out;
	}

	/**
	 * Requisito público del producto, con la forma que ya usan la Store API, `wc/v3` y GraphQL.
	 *
	 * @return array<string,mixed>
	 */
	private static function requirementPayload( int $productId ): array {
		if ( class_exists( 'HWE_Memberships_Teaser' ) && method_exists( 'HWE_Memberships_Teaser', 'requirementPayload' ) ) {
			return HWE_Memberships_Teaser::requirementPayload( $productId );
		}

		return array(
			'required_level' => null,
			'opens_at'       => null,
		);
	}

	// ------------------------------------------------------------------------
	//  Entrada, salida y utilidades
	// ------------------------------------------------------------------------

	/** `user_id` obligatorio y > 0; 0 si falta, no es escalar o no es numérico. */
	private static function userId( $request ): int {
		$raw = $request->get_param( 'user_id' );

		if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) ) {
			return 0;
		}
		$value = trim( (string) $raw );
		if ( ! preg_match( '/^\d+$/', $value ) ) {
			return 0;
		}

		return (int) $value;
	}

	/** ¿Existe la cuenta? Un id inexistente no produce error, solo una respuesta vacía. */
	private static function userExists( int $userId ): bool {
		if ( $userId <= 0 || ! function_exists( 'get_userdata' ) ) {
			return false;
		}

		return is_object( get_userdata( $userId ) );
	}

	/**
	 * Idioma pedido, normalizado al sufijo de las traducciones (`pt-BR` → `pt_br`). Vacío si no
	 * llega o no es un código válido: se sirve el contenido nativo.
	 *
	 * @param mixed $request
	 */
	private static function localeKey( $request ): string {
		$raw = $request->get_param( 'locale' );
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$value = trim( (string) $raw );
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'hwe_i18n_locale_suffix' ) ) {
			$suffix = (string) hwe_i18n_locale_suffix( $value );
			if ( '' !== $suffix ) {
				return $suffix;
			}
		}

		$suffix = str_replace( '-', '_', strtolower( $value ) );

		return 1 === preg_match( '/^[a-z]{2,3}(?:_[a-z0-9]{2,8})?$/', $suffix ) ? $suffix : '';
	}

	/**
	 * Página ≥ 1 y tamaño entre 1 y `PER_PAGE_MAX`. Sin `per_page`, `PER_PAGE_DEFAULT`.
	 *
	 * @param mixed $request
	 * @return array{0:int,1:int}
	 */
	private static function pagination( $request ): array {
		$rawPage = $request->get_param( 'page' );
		$page    = is_scalar( $rawPage ) && is_numeric( $rawPage ) ? (int) $rawPage : 1;
		$page    = max( 1, $page );

		$rawPerPage = $request->get_param( 'per_page' );
		if ( null === $rawPerPage || '' === $rawPerPage ) {
			$perPage = self::PER_PAGE_DEFAULT;
		} else {
			$perPage = is_scalar( $rawPerPage ) && is_numeric( $rawPerPage )
				? (int) $rawPerPage
				: self::PER_PAGE_DEFAULT;
			$perPage = max( 1, min( self::PER_PAGE_MAX, $perPage ) );
		}

		return array( $page, $perPage );
	}

	/** Total de páginas; 0 sin resultados. */
	private static function pages( int $total, int $perPage ): int {
		return $total > 0 ? (int) ceil( $total / max( 1, $perPage ) ) : 0;
	}

	/**
	 * ¿Falló la última consulta de `$wpdb`? WordPress deja el mensaje en `last_error` y lo limpia
	 * al empezar cada consulta.
	 *
	 * @param mixed $wpdb
	 */
	private static function databaseFailed( $wpdb ): bool {
		return is_object( $wpdb )
			&& isset( $wpdb->last_error )
			&& '' !== (string) $wpdb->last_error;
	}

	/**
	 * Ejecuta una lectura y traduce cualquier fallo a un 500 tipado con su registro: nunca se
	 * inventa un estado.
	 *
	 * @param callable():array<string,mixed> $build
	 */
	private static function respond( string $event, callable $build ): WP_REST_Response {
		try {
			return new WP_REST_Response( $build(), 200 );
		} catch ( \Throwable $e ) {
			self::log( 'la lectura «' . $event . '» falló: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'error' => 'internal_error' ), 500 );
		}
	}

	private static function invalid(): WP_REST_Response {
		return new WP_REST_Response( array( 'error' => 'invalid_request' ), 400 );
	}

	/** Escalar de un producto con nombre de método variable; `$default` si no existe. */
	private static function productText( object $product, string $method, string $default = '' ): string {
		if ( ! method_exists( $product, $method ) ) {
			return $default;
		}

		$value = $product->$method();

		return is_scalar( $value ) ? (string) $value : $default;
	}

	/** Booleano de un producto con nombre de método variable. */
	private static function productBool( object $product, string $method, bool $default = false ): bool {
		if ( ! method_exists( $product, $method ) ) {
			return $default;
		}

		$value = $product->$method();

		return is_scalar( $value ) ? (bool) $value : $default;
	}

	/** Entero de un producto con nombre de método variable; `null` si no hay valor. */
	private static function productInt( object $product, string $method ): ?int {
		if ( ! method_exists( $product, $method ) ) {
			return null;
		}

		$value = $product->$method();

		return is_numeric( $value ) ? (int) $value : null;
	}

	/** Importe decimal de WooCommerce como string; un valor ausente es `0`. */
	private static function decimal( mixed $value ): string {
		$amount = is_numeric( $value ) ? (float) $value : 0.0;

		return function_exists( 'wc_format_decimal' )
			? (string) wc_format_decimal( $amount, self::decimals() )
			: number_format( $amount, self::decimals(), '.', '' );
	}

	/** Importe decimal o `null` cuando el producto no tiene ese precio. */
	private static function nullableDecimal( mixed $value ): ?string {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) || ! is_numeric( $value ) ) {
			return null;
		}

		return self::decimal( $value );
	}

	private static function decimals(): int {
		return function_exists( 'wc_get_price_decimals' ) ? max( 0, (int) wc_get_price_decimals() ) : 2;
	}

	/**
	 * Fecha en ISO 8601 UTC. Acepta `DateTimeImmutable` o una cadena (MySQL o ISO); `null` si no
	 * es una fecha válida.
	 */
	private static function isoDate( mixed $value ): ?string {
		if ( $value instanceof DateTimeImmutable ) {
			return $value->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );
		if ( '' === $value || str_starts_with( $value, '0000-00-00' ) ) {
			return null;
		}

		try {
			$date = new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
		} catch ( \Throwable $e ) {
			return null;
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/** Boleano tolerante con lo que guarda la base de datos o un JSON. */
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

	/** Registra un fallo con el prefijo del módulo; nunca datos personales. */
	private static function log( string $message ): void {
		error_log( '[hwe] memberships: ' . $message );
	}
}
