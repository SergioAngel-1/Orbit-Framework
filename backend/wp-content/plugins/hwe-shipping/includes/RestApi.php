<?php

namespace HWE\Shipping;

/**
 * API REST pública de solo lectura.
 *   GET /wp-json/hwe-shipping/v1/methods
 *   GET /wp-json/hwe-shipping/v1/rates
 *   GET /wp-json/hwe-shipping/v1/free-shipping/scope
 *
 * El COSTE de las tarifas NO se expone por REST: lo resuelve directamente
 * ShippingCost (server-side, dentro de WooCommerce) — el checkout nunca
 * necesita pedirlo, porque la Store API ya devuelve el coste correcto en
 * `shipping_rates`. `/rates` solo expone los NOMBRES de ciudad con tarifa
 * configurada, para que el frontend pueda ofrecerlos como opciones en el
 * selector de ciudad del formulario de dirección (nunca el importe).
 */
class RestApi {

	private const NAMESPACE = 'hwe-shipping/v1';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route( self::NAMESPACE, '/methods', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'getMethods' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/free-shipping/scope', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'getFreeShippingScope' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/rates', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'getRates' ],
			'permission_callback' => '__return_true',
		] );
	}

	/**
	 * A qué aplica el envío gratis (categorías con su descendencia, productos
	 * y ciudades), para el aviso del carrito y su «?». Público: es lo mismo que
	 * se le enseña a cualquier comprador.
	 */
	public static function getFreeShippingScope( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( FreeShipping::scope(), 200 );
	}

	public static function getMethods( \WP_REST_Request $request ): \WP_REST_Response {
		$posts = get_posts( [
			'post_type'      => PostTypes::METHOD_SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => [
				[
					'key'     => '_hwe_shipping_method_enabled',
					'value'   => '0',
					'compare' => '!=',
				],
			],
		] );

		$out = [];
		foreach ( $posts as $post ) {
			$out[] = [
				'id'          => $post->ID,
				'name'        => get_the_title( $post ),
				'added_value' => (int) get_post_meta( $post->ID, '_hwe_shipping_method_added_value', true ),
			];
		}

		return new \WP_REST_Response( $out, 200 );
	}

	/** Nombres de ciudad (solo publicadas), ordenados alfabéticamente. Sin importe. */
	public static function getRates( \WP_REST_Request $request ): \WP_REST_Response {
		$posts = get_posts( [
			'post_type'      => PostTypes::RATE_SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		] );

		$out = array_map( 'get_the_title', $posts );

		return new \WP_REST_Response( $out, 200 );
	}
}
