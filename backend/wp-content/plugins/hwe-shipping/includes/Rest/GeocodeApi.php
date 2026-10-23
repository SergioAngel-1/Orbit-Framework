<?php

namespace HWE\Shipping\Rest;

use HWE\Shipping\Distance\DistanceService;
use HWE\Shipping\Distance\GeocodeResult;
use HWE\Shipping\Rates\GeoPoint;

/**
 * Geocodificación para el mapa del BFF (rutas internas de WordPress).
 *
 *   POST hwe-shipping/v1/geocode          { query }     → { results: [{ lat, lng, label }] }, máx. 5
 *   POST hwe-shipping/v1/reverse-geocode  { lat, lng }  → { label }
 *
 * Solo las llama el BFF con `X-HWE-Internal-Secret`: sin el secreto (o si la función de comprobación
 * no está cargada) responde 401 y no se toca ni la red ni la caché. La clave del proveedor no sale
 * de aquí, y la respuesta tampoco dice qué proveedor respondió ni con qué precisión: el BFF no lo
 * necesita y no debe saberlo.
 *
 * Los errores llevan solo un código estable, nunca el mensaje del proveedor:
 *   422 `no_results` (la búsqueda no encontró nada), `invalid_query`, `invalid_point`.
 *   503 `geocoding_backoff` (Nominatim en pausa), `provider_unavailable` (cualquier otro fallo).
 * Un «sin resultados» es normal y no se registra; los fallos sí, con `error_log('[hwe] shipping: …')`.
 *
 * La búsqueda pasa por `DistanceService` (caché de 7 días, proveedor de mapas o Nominatim con su
 * backoff): aquí solo se valida la entrada, se limita la respuesta y se traduce el error.
 * Todas las respuestas van con `Cache-Control: no-store`.
 */
final class GeocodeApi {

	private const NAMESPACE   = 'hwe-shipping/v1';
	private const MAX_QUERY   = 200;
	private const MAX_RESULTS = 5;

	private const NO_RESULTS           = 'no_results';
	private const INVALID_QUERY        = 'invalid_query';
	private const INVALID_POINT        = 'invalid_point';
	private const BACKOFF              = 'geocoding_backoff';
	private const PROVIDER_UNAVAILABLE = 'provider_unavailable';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/geocode',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'geocode' ),
				'permission_callback' => array( self::class, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/reverse-geocode',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'reverse' ),
				'permission_callback' => array( self::class, 'permission' ),
			)
		);
	}

	/** Secreto interno del BFF. Sin él, o sin la función que lo comprueba, cerrado con 401. */
	public static function permission( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! function_exists( 'hwe_has_internal_secret' ) || ! hwe_has_internal_secret( $request ) ) {
			return new \WP_Error( 'hwe_shipping_unauthorized', 'Falta el secreto interno del BFF.', array( 'status' => 401 ) );
		}
		return true;
	}

	/** Búsqueda de una dirección: hasta cinco candidatos, mejor primero. */
	public static function geocode( \WP_REST_Request $request ): \WP_REST_Response {
		$query = self::query( $request );
		if ( null === $query ) {
			return self::respond( array( 'code' => self::INVALID_QUERY ), 422 );
		}

		$results = DistanceService::geocode( $query );
		if ( $results instanceof \WP_Error ) {
			return self::failure( (string) $results->get_error_code() );
		}
		if ( array() === $results ) {
			return self::failure( self::NO_RESULTS );
		}

		$candidates = array_map(
			static fn ( GeocodeResult $result ): array => array(
				'lat'   => $result->point()->lat(),
				'lng'   => $result->point()->lng(),
				'label' => $result->label(),
			),
			array_slice( $results, 0, self::MAX_RESULTS )
		);

		return self::respond( array( 'results' => $candidates ), 200 );
	}

	/** Dirección legible de un punto (para confirmar el pin). */
	public static function reverse( \WP_REST_Request $request ): \WP_REST_Response {
		$point = GeoPoint::tryFrom( $request->get_param( 'lat' ), $request->get_param( 'lng' ) );
		if ( null === $point ) {
			return self::respond( array( 'code' => self::INVALID_POINT ), 422 );
		}

		$result = DistanceService::reverse( $point );
		if ( $result instanceof \WP_Error ) {
			return self::failure( (string) $result->get_error_code() );
		}

		$label = trim( $result->label() );
		if ( '' === $label ) {
			return self::failure( self::NO_RESULTS );
		}

		return self::respond( array( 'label' => $label ), 200 );
	}

	/**
	 * Consulta recortada y con los espacios colapsados, como `MapsAdminApi::address`. Null si no es
	 * texto, está vacía o supera el máximo.
	 */
	private static function query( \WP_REST_Request $request ): ?string {
		$raw = $request->get_param( 'query' );
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$clean = preg_replace( '/\s+/u', ' ', trim( $raw ) );
		$clean = is_string( $clean ) ? trim( $clean ) : '';
		if ( '' === $clean || self::length( $clean ) > self::MAX_QUERY ) {
			return null;
		}

		return $clean;
	}

	/** Traduce un error del servicio a un código estable y un estado HTTP. */
	private static function failure( string $code ): \WP_REST_Response {
		// `geocoding_no_results` es el código de los proveedores; `no_results`, el de esta API.
		if ( in_array( $code, array( self::NO_RESULTS, 'geocoding_no_results' ), true ) ) {
			return self::respond( array( 'code' => self::NO_RESULTS ), 422 );
		}

		$stable = self::BACKOFF === $code ? self::BACKOFF : self::PROVIDER_UNAVAILABLE;
		error_log( sprintf( '[hwe] shipping: geocodificación interna no disponible (%s).', $code ) );

		return self::respond( array( 'code' => $stable ), 503 );
	}

	/** @param array<string,mixed> $body */
	private static function respond( array $body, int $status ): \WP_REST_Response {
		$response = new \WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $value ) : strlen( $value );
	}
}
