<?php

namespace HWE\Banners;

/**
 * API REST pública.
 *   GET /wp-json/hwe-banners/v1/banners?location=&lang=
 *   GET /wp-json/hwe-banners/v1/banners/<type>?location=&lang=
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
			'args'                => [
				'lang'     => [ 'type' => 'string', 'required' => false ],
				'location' => [ 'type' => 'string', 'required' => false ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/banners/(?P<type>[a-z0-9_-]+)', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'getOne' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'type'     => [ 'type' => 'string', 'required' => true ],
				'lang'     => [ 'type' => 'string', 'required' => false ],
				'location' => [ 'type' => 'string', 'required' => false ],
			],
		] );
	}

	private static function lang( \WP_REST_Request $request ): string {
		$lang = sanitize_key( (string) ( $request->get_param( 'lang' ) ?: Plugin::defaultLocale() ) );
		return $lang !== '' ? $lang : Plugin::defaultLocale();
	}

	private static function location( \WP_REST_Request $request ): string {
		$loc = sanitize_key( (string) ( $request->get_param( 'location' ) ?: Locations::defaultSlug() ) );
		if ( ! Locations::isValid( $loc ) ) {
			return Locations::defaultSlug();
		}
		return $loc;
	}

	public static function getAll( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			Serializer::forLocation( self::location( $request ), self::lang( $request ) ),
			200
		);
	}

	public static function getOne( \WP_REST_Request $request ): \WP_REST_Response {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		// Un `type` que no existe en absoluto es un error del cliente (típico:
		// typo en el parámetro) y antes devolvía 200 con slides vacíos igual
		// que el caso de "tipo válido pero no permitido en esta location" —
		// ocultando el typo en vez de que el consumidor lo detecte.
		if ( ! BannerTypes::isValid( $type ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'invalid_banner_type', 'message' => 'Tipo de banner no válido.' ],
				404
			);
		}
		$loc = self::location( $request );
		if ( ! BannerTypes::isLocationAllowed( $type, $loc ) ) {
			// Esto SÍ es un caso normal (mismo criterio que Serializer::forLocation()):
			// un tipo válido simplemente no se muestra en esta location.
			return new \WP_REST_Response( [ 'intervalMs' => 6000, 'slides' => [] ], 200 );
		}
		return new \WP_REST_Response(
			Serializer::cell( $type, $loc, self::lang( $request ) ),
			200
		);
	}
}
