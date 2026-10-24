<?php
/**
 * Requisitos de acceso al catálogo (`HWE_Memberships_Access`, spec §6.1, §6.3 y §7.6).
 *
 * Es la ÚNICA fuente del requisito efectivo de un producto. Combina, en una sola lectura:
 *  · el rango mínimo propio del producto (`_hwe_membership_min_rank`);
 *  · el mayor rango de toda la cadena de categorías del producto (cada categoría exige el
 *    mayor rango de sí misma y de sus ancestros; cambiar un padre no reescribe las hijas
 *    porque la herencia se calcula al leer);
 *  · la fecha de apertura al público (`_hwe_membership_opens_at`, UTC), que solo viaja mientras
 *    está en el futuro.
 *
 * `requirementFor()` devuelve el array que consumen la ficha de escaparate, la Store API y el
 * BFF: `min_rank`, `level_id`, `level_slug`, `level_name`, `opens_at` (ISO 8601 UTC o nulo) y
 * `blocked`. `blocked` solo es `true` cuando el requisito no se pudo evaluar: en ese caso todo el
 * mundo queda fuera (falla cerrada) y el fallo se registra. Un rango sin nivel publicado también
 * bloquea a todos, pero es un requisito conocido (`blocked` queda en `false`).
 *
 * El filtro `hwe_memberships_product_requirement` recibe el array calculado y el id del producto.
 * Solo puede ENDURECER: un rango menor o una fecha anterior/ausente se ignora y se registra. Los
 * campos de nivel del filtro no se aceptan: se recalculan a partir del rango efectivo.
 *
 * Otras reglas:
 *  · Caché por petición (`flush()`/`flushProduct()`); se invalida al guardar producto, categoría
 *    o nivel. Una lectura nunca escribe en la base de datos.
 *  · Guardar un requisito revalida el tag ISR `products`; guardar un nivel revalida además
 *    `memberships`. Los tags se declaran en `hwe_revalidation_tags` de forma idempotente.
 *  · La fecha de apertura programa UN evento `hwe_memberships_early_access_open` por producto,
 *    se reprograma si cambia y se retira si se borra. Al llegar la fecha se invalida la caché y
 *    se revalida `products`.
 *  · Módulo apagado: no hay requisito y todo es accesible (los datos guardados se conservan).
 *  · El personal de la tienda no tiene ningún atajo en la tienda: esta clase nunca consulta
 *    capacidades ni roles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Access {

	/** Meta del rango mínimo, en el producto o en el término de `product_cat` (0 = público). */
	public const MIN_RANK_META = '_hwe_membership_min_rank';

	/** Meta de la fecha de apertura al público, en UTC (`Y-m-d H:i:s`). */
	public const OPENS_AT_META = '_hwe_membership_opens_at';

	/** Hook del evento único de apertura de un producto. */
	public const HOOK = 'hwe_memberships_early_access_open';

	/** Clave del beneficio que permite ver antes de la fecha de apertura. */
	public const EARLY_ACCESS = 'early_access';

	/** Filtro que puede endurecer el requisito de un producto. */
	public const FILTER = 'hwe_memberships_product_requirement';

	/** Caché por petición de requisitos y de niveles publicados. */
	private static array $cache = array();

	/** Avisos ya emitidos en esta petición. */
	private static array $logged = array();

	/** Productos cuyo cambio ya revalidó `products` en esta petición. */
	private static array $productsTriggered = array();

	/** Engancha la revalidación, la invalidación y el evento de apertura. */
	public static function register(): void {
		if ( ! self::moduleActive() ) {
			return;
		}

		add_filter( 'hwe_revalidation_tags', array( self::class, 'tags' ) );
		add_action( self::HOOK, array( self::class, 'onEarlyAccessOpen' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( self::class, 'onProductSaved' ), 20, 1 );
		add_action( 'woocommerce_new_product', array( self::class, 'onProductSaved' ), 20, 1 );
		add_action( 'added_post_meta', array( self::class, 'onProductMetaChanged' ), 20, 4 );
		add_action( 'updated_post_meta', array( self::class, 'onProductMetaChanged' ), 20, 4 );
		add_action( 'deleted_post_meta', array( self::class, 'onProductMetaChanged' ), 20, 4 );
		add_action( 'save_post_' . HWE_Memberships_Levels::POST_TYPE, array( self::class, 'onLevelSaved' ), 20, 1 );
		add_action( 'created_product_cat', array( self::class, 'onTermChanged' ), 20, 1 );
		add_action( 'edited_product_cat', array( self::class, 'onTermChanged' ), 20, 1 );
		add_action( 'delete_product_cat', array( self::class, 'onTermChanged' ), 20, 1 );
		add_action( 'deleted_post', array( self::class, 'onPostDeleted' ), 20, 2 );
	}

	// ------------------------------------------------------------------------
	//  Lectura del requisito
	// ------------------------------------------------------------------------

	/**
	 * Requisito efectivo de un producto.
	 *
	 * @return array{min_rank:int,level_id:int,level_slug:string,level_name:string,opens_at:?string,blocked:bool}
	 */
	public static function requirementFor( int $productId ): array {
		if ( $productId <= 0 || ! self::moduleActive() ) {
			return self::noRequirement();
		}

		$key = 'product:' . $productId;
		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}

		try {
			$requirement = self::computeProductRequirement( $productId );
		} catch ( \Throwable $e ) {
			self::logOnce(
				'product:' . $productId,
				'no se pudo evaluar el requisito del producto ' . $productId . '; se bloquea.'
			);
			$requirement = self::blockedRequirement();
		}

		self::$cache[ $key ] = $requirement;

		return $requirement;
	}

	/**
	 * Requisito efectivo de una categoría: el mayor rango de ella misma y de toda su cadena de
	 * ancestros. No lleva fecha de apertura (es una propiedad del producto).
	 *
	 * @return array{min_rank:int,level_id:int,level_slug:string,level_name:string,opens_at:null,blocked:bool}
	 */
	public static function categoryRequirement( int $termId ): array {
		if ( $termId <= 0 || ! self::moduleActive() ) {
			return self::noRequirement();
		}

		$key = 'category:' . $termId;
		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}

		try {
			$requirement = self::computeCategoryRequirement( $termId );
		} catch ( \Throwable $e ) {
			self::logOnce(
				'category:' . $termId,
				'no se pudo evaluar el requisito de la categoría ' . $termId . '; se bloquea.'
			);
			$requirement = self::blockedRequirement();
		}

		self::$cache[ $key ] = $requirement;

		return $requirement;
	}

	/**
	 * ¿Puede el usuario ver el producto completo? `0` = visitante sin sesión: solo ve los
	 * productos sin requisito y sin apertura futura.
	 */
	public static function canView( int $userId, int $productId ): bool {
		if ( ! self::moduleActive() ) {
			return true;
		}
		if ( $productId <= 0 ) {
			return false;
		}

		$requirement = self::requirementFor( $productId );
		if ( ! empty( $requirement['blocked'] ) ) {
			return false;
		}

		$minRank = (int) $requirement['min_rank'];
		$opensAt = $requirement['opens_at'];

		// Un rango que no alcanza ningún nivel publicado bloquea a todos: aunque el socio
		// tuviera un rango mayor por una concesión antigua, no hay nivel que lo represente.
		if ( $minRank > 0 && (int) $requirement['level_id'] <= 0 ) {
			return false;
		}

		if ( null === $opensAt ) {
			return self::meetsRank( $userId, $minRank );
		}

		// Antes de la fecha: solo un nivel CON el beneficio y con el rango requerido.
		return self::hasEarlyAccess( $userId ) && self::meetsRank( $userId, $minRank );
	}

	/**
	 * Motivo estable del bloqueo, o `null` si el usuario puede verlo. El motivo es
	 * `membership_early_access` mientras la fecha de apertura esté en el futuro y
	 * `membership_required` en el resto de los casos.
	 *
	 * @return array{reason:string,min_rank:int,level_id:int,level_slug:string,level_name:string,opens_at:?string}|null
	 */
	public static function lockReason( int $userId, int $productId ): ?array {
		if ( self::canView( $userId, $productId ) ) {
			return null;
		}

		$requirement = self::requirementFor( $productId );

		return array(
			'reason'     => null === $requirement['opens_at'] ? 'membership_required' : 'membership_early_access',
			'min_rank'   => (int) $requirement['min_rank'],
			'level_id'   => (int) $requirement['level_id'],
			'level_slug' => (string) $requirement['level_slug'],
			'level_name' => (string) $requirement['level_name'],
			'opens_at'   => $requirement['opens_at'],
		);
	}

	/** Rango mínimo propio de un producto (sin categorías); 0 si no tiene. */
	public static function productMinRank( int $productId ): int {
		if ( $productId <= 0 ) {
			return 0;
		}
		try {
			return self::ownRank( $productId, 'post' );
		} catch ( \Throwable $e ) {
			self::logOnce( 'own-rank:' . $productId, 'no se pudo leer el rango del producto ' . $productId . '.' );
			return 0;
		}
	}

	// ------------------------------------------------------------------------
	//  Evaluación
	// ------------------------------------------------------------------------

	/**
	 * @return array{min_rank:int,level_id:int,level_slug:string,level_name:string,opens_at:?string,blocked:bool}
	 */
	private static function computeProductRequirement( int $productId ): array {
		$minRank = self::ownRank( $productId, 'post' );

		foreach ( self::productCategoryIds( $productId ) as $termId ) {
			$category = self::categoryRequirement( $termId );
			if ( ! empty( $category['blocked'] ) ) {
				throw new \RuntimeException( 'una categoría del producto no se pudo evaluar' );
			}
			$minRank = max( $minRank, (int) $category['min_rank'] );
		}

		$requirement = self::buildRequirement( $minRank, self::futureOpensAt( $productId ) );

		return self::applyRequirementFilter( $requirement, $productId );
	}

	/**
	 * @return array{min_rank:int,level_id:int,level_slug:string,level_name:string,opens_at:null,blocked:bool}
	 */
	private static function computeCategoryRequirement( int $termId ): array {
		$minRank = self::ownRank( $termId, 'term' );

		foreach ( self::ancestorIds( $termId ) as $ancestorId ) {
			$minRank = max( $minRank, self::ownRank( $ancestorId, 'term' ) );
		}

		return self::buildRequirement( $minRank, null );
	}

	/**
	 * Construye el array del contrato a partir del rango efectivo y de una fecha ya validada.
	 *
	 * @return array{min_rank:int,level_id:int,level_slug:string,level_name:string,opens_at:?string,blocked:bool}
	 */
	private static function buildRequirement( int $minRank, ?string $opensAt ): array {
		$requirement             = self::noRequirement();
		$requirement['min_rank'] = max( 0, $minRank );
		$requirement['opens_at'] = $opensAt;

		if ( $requirement['min_rank'] < 1 ) {
			return $requirement;
		}

		$level = self::levelForRank( $requirement['min_rank'] );
		if ( null === $level ) {
			self::logOnce(
				'no-level:' . $requirement['min_rank'],
				'el rango mínimo ' . $requirement['min_rank'] . ' no corresponde a ningún nivel publicado; el producto queda bloqueado para todos.'
			);
			return $requirement;
		}

		$requirement['level_id']   = (int) $level['id'];
		$requirement['level_slug'] = (string) $level['slug'];
		$requirement['level_name'] = (string) $level['name'];

		return $requirement;
	}

	/**
	 * Aplica el filtro de la instancia. Solo se aceptan endurecimientos del rango y de la fecha;
	 * lo demás se ignora y se registra.
	 *
	 * @param array{min_rank:int,level_id:int,level_slug:string,level_name:string,opens_at:?string,blocked:bool} $base
	 * @return array{min_rank:int,level_id:int,level_slug:string,level_name:string,opens_at:?string,blocked:bool}
	 */
	private static function applyRequirementFilter( array $base, int $productId ): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $base;
		}

		try {
			$filtered = apply_filters( self::FILTER, $base, $productId );
		} catch ( \Throwable $e ) {
			self::logOnce(
				'filter:throw:' . $productId,
				'el filtro ' . self::FILTER . ' lanzó una excepción para el producto ' . $productId . '; se usa el requisito calculado.'
			);
			return $base;
		}

		if ( ! is_array( $filtered ) ) {
			self::logOnce(
				'filter:type:' . $productId,
				'el filtro ' . self::FILTER . ' debe devolver un array; se ignora para el producto ' . $productId . '.'
			);
			return $base;
		}

		$out = $base;

		if ( array_key_exists( 'min_rank', $filtered ) ) {
			$rank = self::filterRank( $filtered['min_rank'] );
			if ( null === $rank ) {
				self::logOnce(
					'filter:rank:invalid:' . $productId,
					'el filtro ' . self::FILTER . ' devolvió un rango no válido para el producto ' . $productId . '; se ignora.'
				);
			} elseif ( $rank < $base['min_rank'] ) {
				self::logOnce(
					'filter:rank:lax:' . $productId,
					'el filtro ' . self::FILTER . ' intentó bajar el rango mínimo del producto ' . $productId . '; se ignora.'
				);
			} elseif ( $rank > $base['min_rank'] ) {
				$out = self::buildRequirement( $rank, $out['opens_at'] );
			}
		}

		if ( array_key_exists( 'opens_at', $filtered ) ) {
			$raw = $filtered['opens_at'];

			if ( null === $raw || ( is_string( $raw ) && '' === trim( $raw ) ) ) {
				if ( null !== $base['opens_at'] ) {
					self::logOnce(
						'filter:opens:remove:' . $productId,
						'el filtro ' . self::FILTER . ' intentó retirar la fecha de apertura del producto ' . $productId . '; se ignora.'
					);
				}
			} else {
				$opensAt = self::normalizeFilterDate( $raw );
				if ( null === $opensAt ) {
					self::logOnce(
						'filter:opens:invalid:' . $productId,
						'el filtro ' . self::FILTER . ' devolvió una fecha no válida para el producto ' . $productId . '; se ignora.'
					);
				} elseif ( null === $base['opens_at'] ) {
					$out['opens_at'] = $opensAt;
				} elseif ( self::isoTimestamp( $opensAt ) >= self::isoTimestamp( (string) $base['opens_at'] ) ) {
					$out['opens_at'] = $opensAt;
				} else {
					self::logOnce(
						'filter:opens:lax:' . $productId,
						'el filtro ' . self::FILTER . ' intentó adelantar la fecha de apertura del producto ' . $productId . '; se ignora.'
					);
				}
			}
		}

		return $out;
	}

	/** Rango devuelto por el filtro: entero ≥ 0, o `null` si no es válido. */
	private static function filterRank( mixed $raw ): ?int {
		if ( ! is_scalar( $raw ) || ! preg_match( '/^\d+$/', trim( (string) $raw ) ) ) {
			return null;
		}

		return (int) $raw;
	}

	/** Convierte una fecha del filtro (ISO 8601 o MySQL) a ISO 8601 UTC; `null` si no es válida. */
	private static function normalizeFilterDate( mixed $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$value = trim( $raw );
		if ( '' === $value ) {
			return null;
		}

		try {
			$date = new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
		} catch ( \Throwable $e ) {
			return null;
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	private static function isoTimestamp( string $iso ): int {
		try {
			return ( new DateTimeImmutable( $iso ) )->getTimestamp();
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	// ------------------------------------------------------------------------
	//  Niveles, categorías y rangos
	// ------------------------------------------------------------------------

	/**
	 * Nivel publicado con ese rango; si no existe, el menor publicado con rango mayor. `null`
	 * si no hay ninguno (el producto queda bloqueado para todos).
	 *
	 * @return array<string,mixed>|null
	 */
	private static function levelForRank( int $rank ): ?array {
		if ( $rank < 1 ) {
			return null;
		}

		$fallback = null;
		foreach ( self::levels() as $level ) {
			$levelRank = (int) ( $level['rank'] ?? 0 );
			if ( $levelRank < 1 ) {
				continue;
			}
			if ( $levelRank === $rank ) {
				return $level;
			}
			if ( $levelRank > $rank && ( null === $fallback || $levelRank < (int) $fallback['rank'] ) ) {
				$fallback = $level;
			}
		}

		return $fallback;
	}

	/**
	 * Niveles publicados, cacheados por petición.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function levels(): array {
		if ( array_key_exists( 'levels', self::$cache ) ) {
			return self::$cache['levels'];
		}

		if ( ! class_exists( 'HWE_Memberships_Levels' ) ) {
			throw new \RuntimeException( 'no está disponible la lectura de niveles' );
		}

		$levels = HWE_Memberships_Levels::all();
		if ( ! is_array( $levels ) ) {
			throw new \RuntimeException( 'la lectura de niveles no devolvió una lista' );
		}

		self::$cache['levels'] = array_values( $levels );

		return self::$cache['levels'];
	}

	/**
	 * Rango mínimo propio de un post o término.
	 */
	private static function ownRank( int $id, string $kind ): int {
		if ( $id <= 0 ) {
			return 0;
		}

		$getter = 'term' === $kind ? 'get_term_meta' : 'get_post_meta';
		if ( ! function_exists( $getter ) ) {
			throw new \RuntimeException( 'no hay lectura de metas para el requisito' );
		}

		$raw = $getter( $id, self::MIN_RANK_META, true );
		if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) ) {
			return 0;
		}

		return max( 0, (int) floor( (float) $raw ) );
	}

	/**
	 * Ids de las categorías del producto. Un fallo de lectura no se convierte en «sin
	 * categorías»: se propaga para que el producto quede bloqueado.
	 *
	 * @return list<int>
	 */
	private static function productCategoryIds( int $productId ): array {
		if ( ! function_exists( 'wp_get_post_terms' ) ) {
			throw new \RuntimeException( 'no hay lectura de categorías de producto' );
		}

		$terms = wp_get_post_terms( $productId, 'product_cat', array( 'fields' => 'ids' ) );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) {
			throw new \RuntimeException( 'la lectura de categorías devolvió un error' );
		}
		if ( ! is_array( $terms ) ) {
			throw new \RuntimeException( 'la lectura de categorías no devolvió una lista' );
		}

		$ids = array();
		foreach ( $terms as $term ) {
			if ( is_scalar( $term ) && (int) $term > 0 ) {
				$ids[ (int) $term ] = true;
			}
		}

		return array_keys( $ids );
	}

	/**
	 * Ids de los ancestros de una categoría. Si la jerarquía no se puede leer, se propaga el
	 * fallo: evaluar solo la categoría propia podría abrir un producto heredado.
	 *
	 * @return list<int>
	 */
	private static function ancestorIds( int $termId ): array {
		if ( function_exists( 'get_ancestors' ) ) {
			$ancestors = get_ancestors( $termId, 'product_cat', 'taxonomy' );
			if ( ! is_array( $ancestors ) ) {
				throw new \RuntimeException( 'la lectura de ancestros no devolvió una lista' );
			}

			$ids = array();
			foreach ( $ancestors as $ancestor ) {
				if ( is_scalar( $ancestor ) && (int) $ancestor > 0 && (int) $ancestor !== $termId ) {
					$ids[ (int) $ancestor ] = true;
				}
			}

			return array_keys( $ids );
		}

		if ( ! function_exists( 'get_term' ) ) {
			throw new \RuntimeException( 'no hay lectura de la jerarquía de categorías' );
		}

		$ids   = array();
		$seen  = array( $termId => true );
		$term  = get_term( $termId, 'product_cat' );
		$depth = 0;
		while ( is_object( $term ) && (int) ( $term->parent ?? 0 ) > 0 && $depth < 64 ) {
			$parentId = (int) $term->parent;
			if ( isset( $seen[ $parentId ] ) ) {
				throw new \RuntimeException( 'la jerarquía de categorías tiene un ciclo' );
			}
			$seen[ $parentId ] = true;
			$ids[]             = $parentId;
			$term              = get_term( $parentId, 'product_cat' );
			++$depth;
		}

		return $ids;
	}

	// ------------------------------------------------------------------------
	//  Acceso anticipado: beneficio, fecha y evento programado
	// ------------------------------------------------------------------------

	/** ¿El socio tiene el beneficio `early_access` activo? Sin usuario, no. */
	private static function hasEarlyAccess( int $userId ): bool {
		if ( $userId <= 0 || ! class_exists( 'HWE_Memberships_Benefits' ) ) {
			return false;
		}

		$benefit = HWE_Memberships_Benefits::get( self::EARLY_ACCESS );
		if ( null === $benefit ) {
			self::logOnce( 'benefit:early-access', 'no está registrado el tipo de beneficio early_access.' );
			return false;
		}

		try {
			return $benefit->isActiveFor( $userId );
		} catch ( \Throwable $e ) {
			self::logOnce( 'benefit:early-access:user:' . $userId, 'no se pudo evaluar el beneficio early_access del usuario ' . $userId . '.' );
			return false;
		}
	}

	/** ¿El rango efectivo del usuario alcanza el mínimo? Un visitante nunca alcanza un rango > 0. */
	private static function meetsRank( int $userId, int $minRank ): bool {
		if ( $minRank < 1 ) {
			return true;
		}
		if ( $userId <= 0 || ! class_exists( 'HWE_Memberships_Members' ) ) {
			return false;
		}

		try {
			return HWE_Memberships_Members::effectiveRank( $userId ) >= $minRank;
		} catch ( \Throwable $e ) {
			self::logOnce( 'rank:user:' . $userId, 'no se pudo leer el rango del usuario ' . $userId . '.' );
			return false;
		}
	}

	/** Fecha de apertura futura del producto en ISO 8601 UTC, o `null`. */
	private static function futureOpensAt( int $productId ): ?string {
		$raw = self::meta( $productId, self::OPENS_AT_META );
		if ( '' === $raw ) {
			return null;
		}

		$timestamp = self::storedTimestamp( $raw );
		if ( null === $timestamp ) {
			self::logOnce( 'opens:invalid:' . $productId, 'la fecha de apertura del producto ' . $productId . ' no es válida; se ignora.' );
			return null;
		}

		return $timestamp > time()
			? gmdate( 'Y-m-d\TH:i:s\Z', $timestamp )
			: null;
	}

	/** Timestamp del evento de apertura pendiente para un producto, o `null` si no hay. */
	private static function scheduledTimestamp( int $productId ): ?int {
		$raw = self::meta( $productId, self::OPENS_AT_META );
		if ( '' === $raw ) {
			return null;
		}

		$timestamp = self::storedTimestamp( $raw );
		if ( null === $timestamp ) {
			self::logOnce( 'opens:invalid:' . $productId, 'la fecha de apertura del producto ' . $productId . ' no es válida; se retira su evento.' );
			return null;
		}

		return $timestamp > time() ? $timestamp : null;
	}

	/**
	 * Deja UN único evento de apertura para el producto: lo programa si hay fecha futura, lo
	 * reprograma si cambió y lo retira si la fecha se borró o ya pasó.
	 */
	public static function syncEarlyAccess( int $productId ): void {
		if ( $productId <= 0 || ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}

		$timestamp = self::scheduledTimestamp( $productId );
		$next      = wp_next_scheduled( self::HOOK, array( $productId ) );

		if ( null === $timestamp ) {
			if ( false !== $next && null !== $next ) {
				wp_clear_scheduled_hook( self::HOOK, array( $productId ) );
			}
			return;
		}

		if ( false !== $next && null !== $next && (int) $next === $timestamp ) {
			return;
		}

		if ( false !== $next && null !== $next ) {
			wp_clear_scheduled_hook( self::HOOK, array( $productId ) );
		}

		$scheduled = wp_schedule_single_event( $timestamp, self::HOOK, array( $productId ) );
		if (
			false === $scheduled
			|| ( function_exists( 'is_wp_error' ) && is_wp_error( $scheduled ) )
		) {
			self::logOnce( 'schedule:' . $productId, 'no se pudo programar la apertura del producto ' . $productId . '.' );
		}
	}

	/**
	 * Al llegar la fecha de apertura: se invalida la caché de la petición y se revalida el
	 * catálogo para que el producto deje de mostrar la ficha de escaparate.
	 */
	public static function onEarlyAccessOpen( int $productId ): void {
		if ( ! self::moduleActive() || $productId <= 0 ) {
			return;
		}

		self::flushProduct( $productId );
		self::trigger( 'products' );
	}

	// ------------------------------------------------------------------------
	//  Invalidación y revalidación
	// ------------------------------------------------------------------------

	/** Guardado de un producto: invalida su caché, sincroniza el evento y revalida si aplica. */
	public static function onProductSaved( int $productId ): void {
		if ( ! self::moduleActive() || $productId <= 0 ) {
			return;
		}

		self::flushProduct( $productId );

		try {
			self::syncEarlyAccess( $productId );
		} catch ( \Throwable $e ) {
			self::logOnce( 'sync:' . $productId, 'no se pudo sincronizar la apertura del producto ' . $productId . '.' );
		}

		if ( self::productHasRequirement( $productId ) || self::requirementFieldsPosted() ) {
			self::triggerProducts( $productId );
		}
	}

	/**
	 * Cambio de una meta del módulo en un producto (incluye importaciones y REST, que no pasan
	 * por el formulario): invalida la caché, sincroniza la fecha y revalida el catálogo.
	 */
	public static function onProductMetaChanged( mixed $metaId, int $objectId, string $metaKey, mixed $metaValue = null ): void {
		if (
			$objectId <= 0
			|| ! in_array( $metaKey, array( self::MIN_RANK_META, self::OPENS_AT_META ), true )
			|| ! self::moduleActive()
		) {
			return;
		}

		$type = function_exists( 'get_post_type' ) ? (string) get_post_type( $objectId ) : '';
		if ( 'product' !== $type ) {
			return;
		}

		self::flushProduct( $objectId );

		if ( self::OPENS_AT_META === $metaKey ) {
			try {
				self::syncEarlyAccess( $objectId );
			} catch ( \Throwable $e ) {
				self::logOnce( 'sync:' . $objectId, 'no se pudo sincronizar la apertura del producto ' . $objectId . '.' );
			}
		}

		self::triggerProducts( $objectId );
	}

	/** Cambio de una categoría: los requisitos de sus productos dependen de la cadena entera. */
	public static function onTermChanged( int $termId ): void {
		if ( ! self::moduleActive() || $termId <= 0 ) {
			return;
		}

		self::flush();
		self::trigger( 'products' );
	}

	/** Cambio de un nivel: cambia el nivel al que apunta cada rango. */
	public static function onLevelSaved( int $postId ): void {
		if ( ! self::moduleActive() || $postId <= 0 ) {
			return;
		}

		self::flush();
		self::trigger( 'products' );
		self::trigger( 'memberships' );
	}

	/** Borrado de un producto: retira su evento de apertura. */
	public static function onPostDeleted( int $postId, mixed $post = null ): void {
		if ( $postId <= 0 ) {
			return;
		}

		$type = is_object( $post ) && isset( $post->post_type )
			? (string) $post->post_type
			: ( function_exists( 'get_post_type' ) ? (string) get_post_type( $postId ) : '' );
		if ( 'product' !== $type ) {
			return;
		}

		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::HOOK, array( $postId ) );
		}
	}

	/** Tags de revalidación del módulo, sin duplicados. */
	public static function tags( mixed $tags ): array {
		$tags = is_array( $tags ) ? array_values( $tags ) : array();
		foreach ( array( 'products', 'memberships' ) as $tag ) {
			if ( ! in_array( $tag, $tags, true ) ) {
				$tags[] = $tag;
			}
		}

		return $tags;
	}

	/** Invalida toda la caché de requisitos de la petición. */
	public static function flush(): void {
		self::$cache = array();
	}

	/** Invalida la caché de un producto concreto. */
	public static function flushProduct( int $productId ): void {
		unset( self::$cache[ 'product:' . $productId ] );
	}

	/** ¿El producto tiene un requisito propio guardado? */
	private static function productHasRequirement( int $productId ): bool {
		try {
			if ( self::ownRank( $productId, 'post' ) > 0 ) {
				return true;
			}
			return '' !== self::meta( $productId, self::OPENS_AT_META );
		} catch ( \Throwable $e ) {
			self::logOnce( 'has-requirement:' . $productId, 'no se pudo comprobar el requisito del producto ' . $productId . '; se revalida por precaución.' );
			return true;
		}
	}

	/** ¿La petición trae los campos del requisito? (Edición del producto en wp-admin.) */
	private static function requirementFieldsPosted(): bool {
		if ( ! isset( $_POST ) || ! is_array( $_POST ) ) {
			return false;
		}

		return array_key_exists( 'hwe_membership_min_rank', $_POST )
			|| array_key_exists( 'hwe_membership_opens_at', $_POST );
	}

	private static function trigger( string $tag ): void {
		if ( ! class_exists( 'HWE\\ControlCenter\\Revalidation' ) ) {
			self::logOnce( 'revalidation:' . $tag, 'no está disponible la revalidación del Control Center; no se revalida «' . $tag . '».' );
			return;
		}

		try {
			\HWE\ControlCenter\Revalidation::trigger( $tag );
		} catch ( \Throwable $e ) {
			self::logOnce( 'revalidation:throw:' . $tag, 'la revalidación del tag «' . $tag . '» falló.' );
		}
	}

	/** Revalida `products` una sola vez por producto y petición (meta + guardado del producto). */
	private static function triggerProducts( int $productId ): void {
		if ( isset( self::$productsTriggered[ $productId ] ) ) {
			return;
		}
		self::$productsTriggered[ $productId ] = true;
		self::trigger( 'products' );
	}

	// ------------------------------------------------------------------------
	//  Fechas, metas y utilidades
	// ------------------------------------------------------------------------

	/** Lee una meta escalar del producto; `''` si no existe o el valor no es escalar. */
	private static function meta( int $productId, string $key ): string {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return '';
		}
		$value = get_post_meta( $productId, $key, true );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/** Timestamp UTC de una fecha almacenada (`Y-m-d H:i:s` o ISO 8601), o `null`. */
	private static function storedTimestamp( string $raw ): ?int {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}

		try {
			return ( new DateTimeImmutable( $raw, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Sanea una fecha escrita en wp-admin (hora local del sitio) y la devuelve en UTC
	 * (`Y-m-d H:i:s`). `''` si está vacía o no es una fecha válida.
	 */
	public static function sanitizeOpensAt( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$normalized = preg_replace( '/Z$/i', '', str_replace( 'T', ' ', $raw ) );
		try {
			$date = new DateTimeImmutable( (string) $normalized, self::siteTimezone() );
		} catch ( \Throwable $e ) {
			return '';
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/** Fecha UTC almacenada → valor de un input `datetime-local` (hora local del sitio). */
	public static function localInput( string $storedUtc ): string {
		if ( '' === trim( $storedUtc ) ) {
			return '';
		}

		try {
			$date = new DateTimeImmutable( $storedUtc, new DateTimeZone( 'UTC' ) );
		} catch ( \Throwable $e ) {
			return '';
		}

		return $date->setTimezone( self::siteTimezone() )->format( 'Y-m-d\TH:i' );
	}

	private static function siteTimezone(): DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			try {
				$timezone = wp_timezone();
				if ( $timezone instanceof DateTimeZone ) {
					return $timezone;
				}
			} catch ( \Throwable $e ) {
				self::logOnce( 'timezone', 'no se pudo leer la zona horaria del sitio; se usa UTC.' );
			}
		}

		return new DateTimeZone( 'UTC' );
	}

	/** El módulo está activo. Sin Control Center manda la constante del plugin cargado. */
	private static function moduleActive(): bool {
		if ( class_exists( 'HWE_Memberships_Settings' ) ) {
			try {
				return HWE_Memberships_Settings::moduleActive();
			} catch ( \Throwable $e ) {
				// No se puede confirmar que el módulo esté apagado: manda la constante del
				// plugin cargado, que es la señal de que el código está en ejecución.
				self::logOnce( 'module-active', 'no se pudo comprobar si el módulo está activo; se usa la constante del plugin.' );
				return defined( 'HWE_MEMBERSHIPS_VERSION' );
			}
		}

		return defined( 'HWE_MEMBERSHIPS_VERSION' );
	}

	/** @return array{min_rank:int,level_id:int,level_slug:string,level_name:string,opens_at:null,blocked:bool} */
	private static function noRequirement(): array {
		return array(
			'min_rank'   => 0,
			'level_id'   => 0,
			'level_slug' => '',
			'level_name' => '',
			'opens_at'   => null,
			'blocked'    => false,
		);
	}

	/** @return array{min_rank:int,level_id:int,level_slug:string,level_name:string,opens_at:null,blocked:bool} */
	private static function blockedRequirement(): array {
		$requirement            = self::noRequirement();
		$requirement['blocked'] = true;

		return $requirement;
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
