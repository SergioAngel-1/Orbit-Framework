<?php

namespace HWE\Shipping;

use HWE\Shipping\Admin\OrderShippingMeta;
use HWE\Shipping\Rates\City;
use HWE\Shipping\Rates\Cities;

/**
 * Envío gratis por regla: mínimo de compra + alcance (categorías/productos) +
 * ciudades y opciones de entrega.
 *
 * La REGLA no vive en este plugin: la entrega quien la administre por el
 * filtro `hwe_shipping_free_rule` (HWE Control Center → Tienda → Envío gratis),
 * con la forma
 * `{ enabled, min_amount, categories: slug[], products: slug[] }`. Lo que es de
 * aquí es QUÉ opciones de entrega lo admiten: el envío gratis se marca por
 * opción (`freeEligible`), no por ciudad. Una ciudad es elegible si alguna de
 * sus opciones lo marca, y la regla deja a 0 solo las tarifas de esas opciones
 * (`hwe_city_rate:<optionId>`); las demás se cobran y la reserva nunca es gratis.
 *
 * Una sola función (`evaluate`) decide, y la usan los dos consumidores:
 *   - `applyToRates`: pone a 0 las tarifas de las opciones marcadas (lo que se cobra).
 *   - la extensión `hwe_free_shipping` del carrito de la Store API: el estado
 *     ya calculado («te faltan $X») y los ids de tarifa que la regla deja a 0
 *     (`rate_ids`), para que el frontend no reimplemente la regla.
 *
 * Qué suma para el mínimo: los artículos del alcance que necesitan envío, por
 * su importe YA descontado (cupones). Si la suma llega, es gratis el envío de
 * todo el pedido. Sin categorías ni productos en la regla, aplica a todo.
 */
class FreeShipping {

	public const EXTENSION = 'hwe_free_shipping';

	public static function register(): void {
		// Entre la tarifa por ciudad (20) y el cupón de envío gratis (30).
		add_filter( 'woocommerce_package_rates', [ self::class, 'applyToRates' ], 25, 2 );
		add_action( 'woocommerce_blocks_loaded', [ self::class, 'registerStoreApiData' ] );
	}

	/**
	 * @param array<string,\WC_Shipping_Rate> $rates
	 * @param array<string,mixed>             $package
	 * @return array<string,\WC_Shipping_Rate>
	 */
	public static function applyToRates( array $rates, array $package ): array {
		$state = self::evaluate(
			(array) ( $package['contents'] ?? [] ),
			(string) ( $package['destination']['country'] ?? '' ),
			(string) ( $package['destination']['city'] ?? '' )
		);
		if ( ! $state['qualifies'] ) {
			return $rates;
		}

		// El motivo viaja con la tarifa: WooCommerce copia la meta de la tarifa
		// elegida a la línea de envío del pedido, así que wp-admin la enseña
		// bajo «Envío» y un envío a 0 no parece un error de configuración.
		$reason = $state['threshold'] > 0
			? sprintf(
				/* translators: %s: importe mínimo de compra, con formato de moneda */
				__( 'Compra mínima de %s alcanzada', 'hwe-shipping' ),
				html_entity_decode( wp_strip_all_tags( wc_price( $state['threshold'] ) ), ENT_QUOTES, 'UTF-8' )
			)
			: __( 'Regla de envío gratis de la tienda', 'hwe-shipping' );

		// Solo las opciones marcadas de la ciudad de destino. La decisión del envío ya dice cuál es la
		// ciudad: no se vuelve a resolver aquí.
		$free = self::freeRateIds( ShippingStatus::decide( $package )['city'] );

		foreach ( $rates as $rate ) {
			if ( ! in_array( $rate->get_id(), $free, true ) ) {
				continue;
			}
			$rate->set_cost( 0 );
			$rate->set_taxes( [] );
			// Solo para wp-admin (`_`): el cliente ya lo sabe por `rate_ids` y el coste a 0.
			$rate->add_meta_data( OrderShippingMeta::FREE_REASON, $reason );
		}

		return $rates;
	}

	/**
	 * Ids de tarifa (`hwe_city_rate:<optionId>`) de las opciones de la ciudad que aplican envío gratis.
	 * Sin ciudad (sin registro, fuera del país, sin dirección) no hay ninguna: la reserva nunca es gratis.
	 *
	 * @return string[]
	 */
	public static function freeRateIds( ?City $city ): array {
		if ( null === $city ) {
			return [];
		}

		$ids = [];
		foreach ( $city->options() as $option ) {
			if ( $option->isFreeEligible() ) {
				$ids[] = CityRateMethod::ID . ':' . $option->id();
			}
		}

		return $ids;
	}

	/**
	 * Estado de la regla para un conjunto de líneas de carrito y un destino.
	 * Importes en unidades MAYORES de la moneda.
	 *
	 * @param array<string|int,array<string,mixed>> $items Líneas de carrito de WooCommerce.
	 * @return array{enabled:bool,threshold:float,eligible_subtotal:float,remaining:float,qualifies:bool,city:string,all_cities:bool,scoped:bool}
	 */
	public static function evaluate( array $items, string $country, string $city ): array {
		$off = [
			'enabled'           => false,
			'threshold'         => 0.0,
			'eligible_subtotal' => 0.0,
			'remaining'         => 0.0,
			'qualifies'         => false,
			'city'              => 'unknown',
			'all_cities'        => false,
			'scoped'            => false,
		];

		$rule = self::rule();
		if ( ! $rule['enabled'] ) {
			return $off;
		}

		$cities = Cities::freeEligibilityByCity();
		if ( ! in_array( true, $cities, true ) ) {
			// Ninguna ciudad lo admite: la regla no puede cumplirse nunca.
			return $off;
		}

		[ $subtotal, $has_eligible ] = self::eligibleSubtotal( $items, $rule );
		if ( ! $has_eligible ) {
			return $off;
		}

		$city_status = self::cityStatus( $cities, $country, $city );
		$threshold   = $rule['min_amount'];
		$reached     = $subtotal >= $threshold;

		return [
			'enabled'           => true,
			'threshold'         => $threshold,
			'eligible_subtotal' => $subtotal,
			'remaining'         => $reached ? 0.0 : $threshold - $subtotal,
			'qualifies'         => $reached && $city_status === 'eligible',
			'city'              => $city_status,
			'all_cities'        => ! in_array( false, $cities, true ),
			'scoped'            => $rule['categories'] !== [] || $rule['products'] !== [],
		];
	}

	/** @return array{enabled:bool,min_amount:float,categories:string[],products:string[]} */
	public static function rule(): array {
		$rule = (array) apply_filters( 'hwe_shipping_free_rule', [] );

		return [
			'enabled'    => ! empty( $rule['enabled'] ),
			'min_amount' => max( 0.0, (float) ( $rule['min_amount'] ?? 0 ) ),
			'categories' => array_values( array_filter( array_map( 'strval', (array) ( $rule['categories'] ?? [] ) ) ) ),
			'products'   => array_values( array_filter( array_map( 'strval', (array) ( $rule['products'] ?? [] ) ) ) ),
		];
	}

	/**
	 * @param array<string,bool> $cities ciudad normalizada => admite envío gratis
	 * @return 'eligible'|'not_eligible'|'unknown'
	 */
	private static function cityStatus( array $cities, string $country, string $city ): string {
		// Mismo criterio que la tarifa por ciudad: son domésticas por definición.
		$country = strtoupper( trim( $country ) );
		$base    = ShippingCost::baseCountry();
		if ( $base !== '' && $country !== '' && $country !== $base ) {
			return 'not_eligible';
		}

		$key = ShippingCost::normalizeCity( $city );
		if ( $key === '' ) {
			return 'unknown';
		}

		// Una ciudad sin tarifa configurada nunca es gratis.
		return ! empty( $cities[ $key ] ) ? 'eligible' : 'not_eligible';
	}

	/**
	 * Suma de las líneas del alcance que necesitan envío, tras descuentos.
	 *
	 * @param array<string|int,array<string,mixed>>                  $items
	 * @param array{categories:string[],products:string[]}&array<string,mixed> $rule
	 * @return array{0:float,1:bool} [suma, ¿hay alguna línea del alcance?]
	 */
	private static function eligibleSubtotal( array $items, array $rule ): array {
		$scoped       = $rule['categories'] !== [] || $rule['products'] !== [];
		$category_ids = $scoped ? self::categoryIds( $rule['categories'] ) : [];
		$with_tax     = function_exists( 'WC' ) && WC()->cart && WC()->cart->display_prices_including_tax();

		$sum   = 0.0;
		$found = false;
		foreach ( $items as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product || ! $product->needs_shipping() ) {
				continue;
			}
			// La categoría y el slug son del producto padre, no de la variación.
			$product_id = (int) ( $item['product_id'] ?? $product->get_id() );
			if ( $scoped && ! self::inScope( $product_id, $category_ids, $rule['products'] ) ) {
				continue;
			}
			$found = true;
			$sum  += (float) ( $item['line_total'] ?? 0 );
			if ( $with_tax ) {
				$sum += (float) ( $item['line_tax'] ?? 0 );
			}
		}

		return [ $sum, $found ];
	}

	/**
	 * @param int[]    $category_ids
	 * @param string[] $product_slugs
	 */
	private static function inScope( int $product_id, array $category_ids, array $product_slugs ): bool {
		if ( $product_slugs !== [] && in_array( (string) get_post_field( 'post_name', $product_id ), $product_slugs, true ) ) {
			return true;
		}
		if ( $category_ids === [] ) {
			return false;
		}
		// `wc_get_product_cat_ids` suma los ancestros: una categoría de la regla
		// cubre así a toda su descendencia.
		return array_intersect( wc_get_product_cat_ids( $product_id ), $category_ids ) !== [];
	}

	/**
	 * @param string[] $slugs
	 * @return int[]
	 */
	private static function categoryIds( array $slugs ): array {
		if ( $slugs === [] ) {
			return [];
		}
		$ids = get_terms( [
			'taxonomy'   => 'product_cat',
			'slug'       => $slugs,
			'hide_empty' => false,
			'fields'     => 'ids',
		] );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	/**
	 * A qué aplica la regla, con nombres, para explicárselo al comprador: las
	 * categorías elegidas (cada una con TODA su descendencia, que también suma),
	 * los productos sueltos y las CIUDADES que lo admiten, por su nombre.
	 * Categorías y productos, vacíos con la regla apagada o sin alcance; las
	 * ciudades, vacías con la regla apagada.
	 *
	 * `translations` es un mapa idioma → { name, slug } con las traducciones de
	 * `hwe-content-i18n` (metas `_hwe_name_<idioma>` y `_hwe_slug_<idioma>`) de los idiomas
	 * secundarios del sitio; solo trae los idiomas que tienen algo traducido.
	 *
	 * @return array{categories:array<int,array<string,mixed>>,products:array<int,array<string,string>>,cities:string[]}
	 */
	public static function scope(): array {
		$rule  = self::rule();
		$scope = [ 'categories' => [], 'products' => [], 'cities' => [] ];
		if ( ! $rule['enabled'] ) {
			return $scope;
		}

		$scope['cities'] = Cities::freeEligibleCityNames();

		$selected = [];
		foreach ( $rule['categories'] as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $term instanceof \WP_Term ) {
				$selected[ $term->term_id ] = $term;
			}
		}
		foreach ( $selected as $term ) {
			// Una categoría que ya cuelga de otra elegida sale como su hija.
			if ( array_intersect( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ), array_keys( $selected ) ) !== [] ) {
				continue;
			}
			$children = get_terms( [
				'taxonomy'   => 'product_cat',
				'child_of'   => $term->term_id,
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			] );
			$entry             = self::scopeTerm( $term );
			$entry['children'] = is_array( $children ) ? array_map( [ self::class, 'scopeTerm' ], array_values( $children ) ) : [];
			$scope['categories'][] = $entry;
		}

		if ( $rule['products'] !== [] ) {
			$posts = get_posts( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'post_name__in'  => $rule['products'],
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			] );
			foreach ( $posts as $post ) {
				$scope['products'][] = [
					'slug'         => (string) $post->post_name,
					'name'         => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
					'translations' => self::translations(
						static fn( string $field, string $suffix ): string => (string) get_post_meta( $post->ID, hwe_i18n_meta_key( $field, $suffix ), true )
					),
				];
			}
		}

		return $scope;
	}

	/** @return array<string,string> */
	private static function scopeTerm( \WP_Term $term ): array {
		return [
			'slug'         => (string) $term->slug,
			'name'         => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
			'translations' => self::translations(
				static fn( string $field, string $suffix ): string => (string) get_term_meta( $term->term_id, hwe_i18n_meta_key( $field, $suffix ), true )
			),
		];
	}

	/**
	 * Traducciones de nombre y slug a los idiomas secundarios del sitio (`hwe-content-i18n`).
	 * Sin ese mu-plugin, o con un solo idioma, el mapa está vacío.
	 *
	 * @param callable(string,string):string $read Lee la meta de un campo (`name`, `slug`) en un idioma.
	 * @return array<string,array{name:string,slug:string}>
	 */
	private static function translations( callable $read ): array {
		if ( ! function_exists( 'hwe_i18n_locales' ) || ! function_exists( 'hwe_i18n_meta_key' ) ) {
			return [];
		}
		$out = [];
		foreach ( hwe_i18n_locales() as $suffix ) {
			$name = trim( $read( 'name', $suffix ) );
			$slug = trim( $read( 'slug', $suffix ) );
			if ( '' !== $name || '' !== $slug ) {
				$out[ $suffix ] = [ 'name' => $name, 'slug' => $slug ];
			}
		}
		return $out;
	}

	/* --------------------------------------------------------------------- */
	/* Store API: estado en `cart.extensions.hwe_free_shipping`               */
	/* --------------------------------------------------------------------- */

	public static function registerStoreApiData(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data( [
			'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
			'namespace'       => self::EXTENSION,
			'data_callback'   => [ self::class, 'cartState' ],
			'schema_callback' => [ self::class, 'cartStateSchema' ],
			'schema_type'     => ARRAY_A,
		] );
	}

	/**
	 * Estado para el carrito de la sesión. Importes en unidades MENORES, como
	 * el resto de la Store API.
	 *
	 * @return array<string,mixed>
	 */
	public static function cartState(): array {
		$cart     = function_exists( 'WC' ) ? WC()->cart : null;
		$customer = function_exists( 'WC' ) ? WC()->customer : null;

		$country = $customer ? (string) $customer->get_shipping_country() : '';
		$city    = $customer ? (string) $customer->get_shipping_city() : '';

		$state = self::evaluate( $cart ? $cart->get_cart() : [], $country, $city );

		foreach ( [ 'threshold', 'eligible_subtotal', 'remaining' ] as $key ) {
			$state[ $key ] = self::toMinorUnits( $state[ $key ] );
		}

		// Las tarifas que la regla deja a 0 en este destino (vacío si la regla no se cumple). La ciudad
		// sale de la misma decisión que el método de envío, sobre el primer paquete del carrito.
		$package           = $state['qualifies'] ? StoreApiExtension::firstPackage() : null;
		$state['rate_ids'] = null === $package ? [] : self::freeRateIds( ShippingStatus::decide( $package )['city'] );

		return $state;
	}

	/** @return array<string,array<string,mixed>> */
	public static function cartStateSchema(): array {
		$money = static fn( string $description ): array => [
			'description' => $description,
			'type'        => 'string',
			'context'     => [ 'view', 'edit' ],
			'readonly'    => true,
		];
		$flag  = static fn( string $description ): array => [
			'description' => $description,
			'type'        => 'boolean',
			'context'     => [ 'view', 'edit' ],
			'readonly'    => true,
		];

		return [
			'enabled'           => $flag( 'La regla de envío gratis está activa y este carrito puede alcanzarla.' ),
			'threshold'         => $money( 'Valor mínimo de compra, en unidades menores.' ),
			'eligible_subtotal' => $money( 'Suma de los artículos que cuentan para el mínimo, en unidades menores.' ),
			'remaining'         => $money( 'Lo que falta para el mínimo, en unidades menores.' ),
			'qualifies'         => $flag( 'El pedido ya tiene envío gratis.' ),
			'rate_ids'          => [
				'description' => 'Ids de las tarifas (hwe_city_rate:<optionId>) que la regla deja a 0 en este carrito.',
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'city'              => [
				'description' => 'Si la ciudad de destino admite envío gratis.',
				'type'        => 'string',
				'enum'        => [ 'eligible', 'not_eligible', 'unknown' ],
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'all_cities'        => $flag( 'Todas las ciudades con tarifa admiten envío gratis.' ),
			'scoped'            => $flag( 'Solo una parte del catálogo suma para el mínimo.' ),
		];
	}

	private static function toMinorUnits( float $amount ): string {
		return (string) (int) round( $amount * ( 10 ** wc_get_price_decimals() ) );
	}
}
