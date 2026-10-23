<?php

namespace HWE\Shipping\Rates;

use HWE\Shipping\PostTypes;
use HWE\Shipping\ShippingCost;

/**
 * Repositorio de ciudades configuradas (posts publicados del CPT de tarifas), con caché de objetos.
 *
 * La clave de ciudad es `ShippingCost::normalizeCity` (sin tildes, minúsculas, espacios
 * colapsados): «Córdoba», «CORDOBA» y « cordoba » son la misma ciudad. Una ciudad sin tipo es del
 * modelo anterior: no se devuelve y aparece en `legacyPostIds()` para avisar en wp-admin. Una
 * ciudad con datos rotos o repetida se omite y se registra, nunca se cuela en silencio.
 */
final class Cities {

	public const META_TYPE           = '_hwe_shipping_city_type';
	public const META_OPTIONS        = '_hwe_shipping_city_options';
	public const META_ORIGIN_ADDRESS = '_hwe_shipping_city_origin_address';
	public const META_ORIGIN_LAT     = '_hwe_shipping_city_origin_lat';
	public const META_ORIGIN_LNG     = '_hwe_shipping_city_origin_lng';
	public const META_ORIGIN_LABEL   = '_hwe_shipping_city_origin_label';
	public const META_COVERAGE       = '_hwe_shipping_city_coverage_km';

	private const CACHE_GROUP = 'hwe_shipping';
	private const CACHE_KEY   = 'cities';
	private const CACHE_TTL   = 300; // 5 min; `clearCache` lo invalida al guardar o borrar.

	public static function register(): void {
		add_action( 'save_post_' . PostTypes::RATE_SLUG, [ self::class, 'clearCache' ], 20, 0 );
		add_action( 'deleted_post', [ self::class, 'clearCacheOnDelete' ], 10, 2 );
		add_action( 'trashed_post', [ self::class, 'clearCacheOnDelete' ], 10, 1 );
	}

	/** La ciudad configurada para ese nombre (sin distinguir tildes, mayúsculas ni espacios), o null. */
	public static function forCity( string $city ): ?City {
		$key = ShippingCost::normalizeCity( $city );
		if ( '' === $key ) {
			return null;
		}
		return self::index()['cities'][ $key ] ?? null;
	}

	/** @return City[] */
	public static function all(): array {
		return array_values( self::index()['cities'] );
	}

	public static function hasDistanceCities(): bool {
		foreach ( self::all() as $city ) {
			if ( $city->isDistance() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Nombres (tal como se escribieron) de las ciudades con al menos una opción que aplica envío
	 * gratis, ordenados alfabéticamente sin tildes.
	 *
	 * @return string[]
	 */
	public static function freeEligibleCityNames(): array {
		$names = [];
		foreach ( self::all() as $city ) {
			if ( self::admitsFreeShipping( $city ) ) {
				$names[] = $city->name();
			}
		}
		usort( $names, static fn ( string $a, string $b ): int => strcmp( ShippingCost::normalizeCity( $a ), ShippingCost::normalizeCity( $b ) ) );
		return $names;
	}

	/**
	 * Ciudad normalizada => alguna de sus opciones aplica envío gratis.
	 *
	 * @return array<string,bool>
	 */
	public static function freeEligibilityByCity(): array {
		$map = [];
		foreach ( self::index()['cities'] as $key => $city ) {
			$map[ $key ] = self::admitsFreeShipping( $city );
		}
		return $map;
	}

	/** Ids de los posts publicados del modelo anterior (sin tipo de tarifa). @return int[] */
	public static function legacyPostIds(): array {
		return self::index()['legacy'];
	}

	/**
	 * ¿Hay otra ciudad publicada con el mismo nombre normalizado? `$exceptId` es el post que se
	 * está guardando, que no cuenta contra sí mismo.
	 */
	public static function isNameTaken( string $name, int $exceptId ): bool {
		$key = ShippingCost::normalizeCity( $name );
		if ( '' === $key ) {
			return false;
		}
		$posts = get_posts( [
			'post_type'      => PostTypes::RATE_SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'exclude'        => [ $exceptId ],
		] );
		foreach ( $posts as $post ) {
			if ( ShippingCost::normalizeCity( self::nameOf( $post ) ) === $key ) {
				return true;
			}
		}
		return false;
	}

	/** Nombre visible de una ciudad: el título del post, sin entidades HTML ni espacios sobrantes. */
	public static function nameOf( \WP_Post $post ): string {
		// El título crudo, no `get_the_title`: su `wptexturize` convierte « - » en raya y comillas rectas en
		// tipográficas, y la ciudad dejaría de coincidir con la que escribe el cliente.
		return trim( html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Invalida la caché de ciudades y sube la versión del transient de envíos de WooCommerce: los
	 * carritos abiertos guardan las tarifas calculadas en la sesión con esa versión en la clave.
	 */
	public static function clearCache(): void {
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	public static function clearCacheOnDelete( int $postId, ?\WP_Post $post = null ): void {
		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( $postId );
		if ( PostTypes::RATE_SLUG === $type ) {
			self::clearCache();
		}
	}

	private static function admitsFreeShipping( City $city ): bool {
		foreach ( $city->options() as $option ) {
			if ( $option->isFreeEligible() ) {
				return true;
			}
		}
		return false;
	}

	/** @return array{cities: array<string,City>, legacy: int[]} */
	private static function index(): array {
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$index = [ 'cities' => [], 'legacy' => [] ];
		$posts = get_posts( [
			'post_type'      => PostTypes::RATE_SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		foreach ( $posts as $post ) {
			$type = (string) get_post_meta( $post->ID, self::META_TYPE, true );
			if ( '' === $type ) {
				$index['legacy'][] = (int) $post->ID;
				continue;
			}
			try {
				$city = self::fromPost( $post, $type );
			} catch ( \InvalidArgumentException $e ) {
				error_log( '[hwe] shipping: la ciudad ' . $post->ID . ' no es válida y se ignora: ' . $e->getMessage() );
				continue;
			}
			$key = ShippingCost::normalizeCity( $city->name() );
			if ( isset( $index['cities'][ $key ] ) ) {
				error_log( '[hwe] shipping: la ciudad ' . $post->ID . ' repite a otra ya publicada (' . $key . ') y se ignora.' );
				continue;
			}
			$index['cities'][ $key ] = $city;
		}

		wp_cache_set( self::CACHE_KEY, $index, self::CACHE_GROUP, self::CACHE_TTL );
		return $index;
	}

	/** @throws \InvalidArgumentException si la ciudad guardada no cumple las reglas de City. */
	private static function fromPost( \WP_Post $post, string $type ): City {
		$options  = DeliveryOption::listFromJson( (string) get_post_meta( $post->ID, self::META_OPTIONS, true ) );
		$origin   = null;
		$label    = '';
		$coverage = 0.0;

		if ( City::TYPE_DISTANCE === $type ) {
			$origin   = GeoPoint::tryFrom( get_post_meta( $post->ID, self::META_ORIGIN_LAT, true ), get_post_meta( $post->ID, self::META_ORIGIN_LNG, true ) );
			$label    = (string) get_post_meta( $post->ID, self::META_ORIGIN_LABEL, true );
			$coverage = (float) get_post_meta( $post->ID, self::META_COVERAGE, true );
		}

		return new City( (int) $post->ID, self::nameOf( $post ), $type, $options, $origin, $label, $coverage );
	}
}
