<?php

namespace HWE\Shipping;

/**
 * Resuelve el coste del método `flat_rate` de WooCommerce según la ciudad de
 * destino del paquete. Reemplaza a woocommerce-shipping-config.php (que
 * aplicaba un coste global desde HWE Control Center); ahora el coste depende
 * de la tarifa de envío (CPT) cuyo título coincide con la ciudad, ignorando
 * mayúsculas/tildes. Sin match, el flat_rate conserva su coste ya configurado
 * en wp-admin (la zona de reserva que gestiona `ReserveZone` desde el Control Center).
 *
 * Además honra la casilla nativa «Permitir envío gratuito» del CUPÓN: la zona
 * no tiene método `free_shipping` (el coste lo decide la tarifa por ciudad),
 * así que sin esto la casilla no hacía nada. Con un cupón aplicado que la
 * marca, TODAS las tarifas del paquete quedan a 0. Los métodos de envío del
 * CPT (extras que el cliente elige, `fee_line`) no son la tarifa y se siguen
 * cobrando.
 */
class ShippingCost {

	private const CACHE_GROUP = 'hwe_shipping';
	private const CACHE_KEY   = 'rates_by_city';
	private const FREE_KEY    = 'free_by_city';
	private const CACHE_TTL   = 300; // 5 min — invalidado también al guardar/borrar una tarifa.

	public static function register(): void {
		add_filter( 'woocommerce_package_rates', [ self::class, 'applyCityRate' ], 20, 2 );
		// Después de la tarifa por ciudad: si no, esta volvería a poner el importe.
		add_filter( 'woocommerce_package_rates', [ self::class, 'applyCouponFreeShipping' ], 30, 2 );
		// Prioridad 20: después de `Save::saveRate` (10), que escribe la meta.
		add_action( 'save_post_' . PostTypes::RATE_SLUG, [ self::class, 'clearCache' ], 20, 0 );
		add_action( 'deleted_post', [ self::class, 'clearCacheOnDelete' ], 10, 2 );
		add_action( 'trashed_post', [ self::class, 'clearCacheOnDelete' ], 10, 1 );
	}

	/**
	 * @param array<string,\WC_Shipping_Rate> $rates
	 * @param array{destination?:array{city?:string}} $package
	 * @return array<string,\WC_Shipping_Rate>
	 */
	public static function applyCityRate( array $rates, array $package ): array {
		// Las tarifas por ciudad son domésticas por definición (el CPT las
		// nombra por ciudad, no por país). Sin este check, un paquete
		// internacional cuya ciudad de destino coincidiera por casualidad
		// con el nombre de una ciudad configurada aquí (p. ej. "Córdoba"
		// existe en varios países) heredaría una tarifa en
		// la moneda/escala equivocada. Se compara contra el país de la
		// tienda (`ecommerce.country` del Control Center), no un país
		// hardcodeado: este plugin es genérico.
		$destination_country = strtoupper( trim( (string) ( $package['destination']['country'] ?? '' ) ) );
		$base_country         = self::baseCountry();
		if ( $base_country !== '' && $destination_country !== '' && $destination_country !== $base_country ) {
			return $rates;
		}

		$city = trim( (string) ( $package['destination']['city'] ?? '' ) );
		if ( $city === '' ) {
			return $rates;
		}

		$amount = self::rateForCity( $city );
		if ( $amount === null ) {
			return $rates;
		}

		foreach ( $rates as $rateId => $rate ) {
			if ( $rate->method_id === 'flat_rate' ) {
				$rates[ $rateId ]->cost = $amount;
			}
		}

		return $rates;
	}

	/**
	 * Envío a 0 si el carrito lleva un cupón con «Permitir envío gratuito».
	 * Los cupones del paquete ya pasaron la validación al aplicarse; WooCommerce
	 * los incluye en el hash del paquete, así que aplicar o quitar el cupón
	 * recalcula las tarifas.
	 *
	 * @param array<string,\WC_Shipping_Rate> $rates
	 * @param array{applied_coupons?:string[]} $package
	 * @return array<string,\WC_Shipping_Rate>
	 */
	public static function applyCouponFreeShipping( array $rates, array $package ): array {
		if ( ! self::hasFreeShippingCoupon( (array) ( $package['applied_coupons'] ?? [] ) ) ) {
			return $rates;
		}

		foreach ( $rates as $rate ) {
			$rate->set_cost( 0 );
			$rate->set_taxes( [] );
		}

		return $rates;
	}

	/** @param string[] $codes */
	public static function hasFreeShippingCoupon( array $codes ): bool {
		foreach ( $codes as $code ) {
			$coupon = new \WC_Coupon( (string) $code );
			if ( $coupon->get_id() && $coupon->get_free_shipping() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * País base de la tienda: `ecommerce.country` del Control Center (con el valor por defecto
	 * del esquema si no se ha guardado). Sin Control Center, el país base de WooCommerce.
	 */
	public static function baseCountry(): string {
		if ( function_exists( 'hwe_geo_store_country' ) ) {
			$country = hwe_geo_store_country();
			if ( '' !== $country ) {
				return $country;
			}
		}
		return strtoupper( (string) ( function_exists( 'wc_get_base_location' ) ? ( wc_get_base_location()['country'] ?? '' ) : '' ) );
	}

	/** Importe de la tarifa para una ciudad, o null si no hay tarifa configurada. */
	private static function rateForCity( string $city ): ?float {
		$map = self::ratesByCity();
		$key = self::normalizeCity( $city );
		return $map[ $key ] ?? null;
	}

	/** @return array<string,float> ciudad normalizada => importe */
	private static function ratesByCity(): array {
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$posts = get_posts( [
			'post_type'      => PostTypes::RATE_SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		] );

		$map = [];
		foreach ( $posts as $post ) {
			$key = self::normalizeCity( get_the_title( $post ) );
			if ( $key === '' ) {
				continue;
			}
			$raw = get_post_meta( $post->ID, '_hwe_shipping_rate_amount', true );
			if ( $raw === '' ) {
				// Meta ausente o vacía: no hay tarifa real para esta ciudad,
				// se omite del mapa para que applyCityRate use el fallback
				// del coste base de la zona (no confundir con un '0' explícito,
				// que Save::saveRate sí persiste como envío gratis intencional).
				continue;
			}
			$map[ $key ] = (float) $raw;
		}

		wp_cache_set( self::CACHE_KEY, $map, self::CACHE_GROUP, self::CACHE_TTL );
		return $map;
	}

	/**
	 * Qué ciudades con tarifa admiten el envío gratis por regla (casilla
	 * «Aplica envío gratis» de la tarifa). Lo consume `FreeShipping`.
	 *
	 * @return array<string,bool> ciudad normalizada => admite envío gratis
	 */
	public static function freeShippingCities(): array {
		$cached = wp_cache_get( self::FREE_KEY, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$posts = get_posts( [
			'post_type'      => PostTypes::RATE_SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		] );

		$map = [];
		foreach ( $posts as $post ) {
			$key = self::normalizeCity( get_the_title( $post ) );
			if ( $key !== '' ) {
				$map[ $key ] = get_post_meta( $post->ID, Save::RATE_FREE_META, true ) === '1';
			}
		}

		wp_cache_set( self::FREE_KEY, $map, self::CACHE_GROUP, self::CACHE_TTL );
		return $map;
	}

	/**
	 * Nombres (tal como se escribieron) de las ciudades que admiten el envío
	 * gratis por regla, en orden alfabético: para decírselo al comprador.
	 *
	 * @return string[]
	 */
	public static function freeShippingCityNames(): array {
		$posts = get_posts( [
			'post_type'      => PostTypes::RATE_SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_key'       => Save::RATE_FREE_META,
			'meta_value'     => '1',
		] );

		$names = [];
		foreach ( $posts as $post ) {
			$name = trim( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
			if ( $name !== '' ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/** Minúsculas, sin tildes, espacios colapsados. */
	public static function normalizeCity( string $city ): string {
		$plain = remove_accents( $city );
		$plain = strtolower( trim( $plain ) );
		return preg_replace( '/\s+/', ' ', $plain ) ?? $plain;
	}

	public static function clearCache(): void {
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
		wp_cache_delete( self::FREE_KEY, self::CACHE_GROUP );
		// WooCommerce guarda en la sesión las tarifas calculadas de cada paquete,
		// con esta versión en la clave: sin subirla, un carrito abierto seguiría
		// con la tarifa (o el envío gratis) de antes del cambio.
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	public static function clearCacheOnDelete( int $postId, ?\WP_Post $post = null ): void {
		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( $postId );
		if ( $type === PostTypes::RATE_SLUG ) {
			self::clearCache();
		}
	}
}
