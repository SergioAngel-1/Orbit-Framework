<?php

namespace HWE\Banners;

/**
 * API REST pública del plugin.
 *   GET /wp-json/hwe-banners/v1/banners            → todos los placements
 *   GET /wp-json/hwe-banners/v1/banners/<placement> → un placement
 *
 * Solo expone datos públicos de banners publicados. Cacheable (el frontend usa
 * ISR con tag "banners" e invalida vía webhook al guardar/borrar).
 */
class RestApi {

	private const NAMESPACE = 'hwe-banners/v1';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route( self::NAMESPACE, '/banners', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'getAll' ],
			'permission_callback' => '__return_true',
			'args'                => [ 'lang' => [ 'type' => 'string', 'required' => false ] ],
		] );

		register_rest_route( self::NAMESPACE, '/banners/(?P<placement>[a-z0-9_-]+)', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'getOne' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'placement' => [ 'type' => 'string', 'required' => true ],
				'lang'      => [ 'type' => 'string', 'required' => false ],
			],
		] );
	}

	private static function lang( \WP_REST_Request $request ): string {
		$lang = sanitize_key( (string) ( $request->get_param( 'lang' ) ?: Plugin::defaultLocale() ) );
		return $lang !== '' ? $lang : Plugin::defaultLocale();
	}

	public static function getAll( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( Serializer::all( self::lang( $request ) ), 200 );
	}

	public static function getOne( \WP_REST_Request $request ): \WP_REST_Response {
		$placement = sanitize_key( (string) $request->get_param( 'placement' ) );
		if ( ! Placements::isValid( $placement ) ) {
			return new \WP_REST_Response( [ 'intervalMs' => 6000, 'slides' => [] ], 200 );
		}
		return new \WP_REST_Response( Serializer::placement( $placement, self::lang( $request ) ), 200 );
	}
}
