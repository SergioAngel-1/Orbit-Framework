<?php

namespace HWE\Shipping\Admin;

use HWE\Shipping\Distance\DistanceService;
use HWE\Shipping\Distance\GeocodeResult;
use HWE\Shipping\Distance\Settings;
use HWE\Shipping\ShippingCost;

/**
 * Rutas REST de wp-admin para las herramientas de mapas.
 *
 *   POST hwe-shipping/v1/admin/geocode    «Validar» del editor de ciudad: geocodifica la dirección
 *                                          de referencia con la caché y el respaldo del servicio.
 *   POST hwe-shipping/v1/admin/maps-test  «Probar conexión» del Control Center: llama al proveedor
 *                                          configurado TAL CUAL, sin caché ni respaldo, y clasifica
 *                                          el resultado con una pista accionable.
 *
 * Solo para quien gestiona la tienda (`manage_woocommerce`) y con el nonce `wp_rest`, que
 * `wp.apiFetch` ya envía en wp-admin. Las respuestas no se cachean (`no-store`).
 *
 * La clave del proveedor no sale de aquí: ni en el cuerpo, ni en la URL, ni en el log; la petición
 * la pone el proveedor en su cabecera. La prueba de conexión tampoco cae a Nominatim: si el
 * proveedor elegido falla, ese fallo es la respuesta (una caída al respaldo daría un «todo bien»
 * falso).
 */
class MapsAdminApi {

	private const NAMESPACE   = 'hwe-shipping/v1';
	private const CAPABILITY  = 'manage_woocommerce';
	private const NONCE       = 'wp_rest';
	private const MAX_ADDRESS = 200;

	/** Códigos de la prueba de conexión que se pueden devolver al panel. */
	private const RESULT_OK           = 'ok';
	private const RESULT_MISSING_KEY  = 'missing_key';
	private const RESULT_UNAUTHORIZED = 'unauthorized';
	private const RESULT_API_DISABLED = 'api_not_enabled';
	private const RESULT_RATE_LIMITED = 'rate_limited';
	private const RESULT_NETWORK      = 'network';
	private const RESULT_NO_RESULTS   = 'no_results';

	/** Motivos de Google que significan «la clave vale, pero la API no está habilitada». */
	private const DISABLED_REASONS = array( 'SERVICE_DISABLED', 'API_KEY_SERVICE_BLOCKED' );

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/geocode',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'geocode' ),
				'permission_callback' => array( self::class, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/maps-test',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'mapsTest' ),
				'permission_callback' => array( self::class, 'permission' ),
			)
		);
	}

	/** Capacidad de la tienda más el nonce de `wp_rest`; sin ambos, cerrado con 403. */
	public static function permission( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return self::forbidden();
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $nonce ) || '' === $nonce || false === wp_verify_nonce( $nonce, self::NONCE ) ) {
			return self::forbidden();
		}
		return true;
	}

	/** «Validar»: dirección → mejor candidato, con la caché y el respaldo a Nominatim del servicio. */
	public static function geocode( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$address = self::address( $request );
		if ( $address instanceof \WP_Error ) {
			return $address;
		}

		$results = DistanceService::geocode( $address );
		if ( $results instanceof \WP_Error ) {
			return self::geocodeError( (string) $results->get_error_code() );
		}
		if ( array() === $results ) {
			return self::geocodeError( 'geocoding_no_results' );
		}

		$best = $results[0];
		return self::noStore(
			new \WP_REST_Response(
				array(
					'lat'       => $best->point()->lat(),
					'lng'       => $best->point()->lng(),
					'label'     => $best->label(),
					'precision' => $best->precision(),
					'provider'  => $best->provider(),
				),
				200
			)
		);
	}

	/**
	 * «Probar conexión»: una llamada real al proveedor elegido con la dirección de prueba.
	 *
	 * Siempre responde 200 con `{ok, provider, code, message, hint}`: el panel decide qué enseñar y
	 * el `code` es estable (no se traduce aquí, se clasifica allí). Sin proveedor o sin clave no se
	 * hace ninguna petición HTTP.
	 */
	public static function mapsTest( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$address = self::address( $request );
		if ( $address instanceof \WP_Error ) {
			return $address;
		}

		$providerId = Settings::mapsProvider();
		if ( 'none' === $providerId || '' === Settings::mapsKey() ) {
			error_log( sprintf( '[hwe] shipping: prueba de mapas sin proveedor o clave configurados (proveedor «%s»).', $providerId ) );
			return self::testResponse( $providerId, self::RESULT_MISSING_KEY );
		}

		$provider = DistanceService::providers()[ $providerId ] ?? null;
		if ( null === $provider ) {
			error_log( sprintf( '[hwe] shipping: prueba de mapas: el proveedor «%s» no está registrado.', $providerId ) );
			return self::testResponse( $providerId, self::RESULT_NETWORK );
		}

		try {
			$result = $provider->geocode( $address, ShippingCost::baseCountry(), DistanceService::language() );
		} catch ( \Throwable $error ) {
			// Solo la clase: el mensaje de una excepción puede traer datos de la petición.
			error_log( sprintf( '[hwe] shipping: prueba de mapas con «%s» lanzó %s.', $providerId, $error::class ) );
			return self::testResponse( $providerId, self::RESULT_NETWORK );
		}

		if ( self::hasResults( $result ) ) {
			return self::testResponse( $providerId, self::RESULT_OK );
		}

		$code       = $result instanceof \WP_Error ? (string) $result->get_error_code() : 'geocoding_no_results';
		$reason     = $result instanceof \WP_Error && is_array( $result->get_error_data() ) ? (string) ( $result->get_error_data()['reason'] ?? '' ) : '';
		$resultCode = self::classify( $code, $reason );
		error_log(
			sprintf(
				'[hwe] shipping: prueba de mapas con «%s» falló (%s; error %s%s).',
				$providerId,
				$resultCode,
				$code,
				'' !== $reason ? ', motivo ' . $reason : ''
			)
		);

		return self::testResponse( $providerId, $resultCode );
	}

	/** Traduce el código del proveedor a uno de los resultados que entiende el panel. */
	private static function classify( string $code, string $reason ): string {
		if ( 'geocoding_no_results' === $code ) {
			return self::RESULT_NO_RESULTS;
		}
		if ( 'provider_rate_limited' === $code || 'geocoding_backoff' === $code ) {
			return self::RESULT_RATE_LIMITED;
		}
		if ( 'provider_unauthorized' === $code ) {
			return in_array( $reason, self::DISABLED_REASONS, true ) ? self::RESULT_API_DISABLED : self::RESULT_UNAUTHORIZED;
		}
		return self::RESULT_NETWORK;
	}

	/**
	 * ¿El proveedor devolvió al menos un candidato usable? Un array con basura no es un «ok»:
	 * la prueba de conexión solo da por buena una respuesta que sirva para geocodificar.
	 *
	 * @param mixed $result
	 */
	private static function hasResults( mixed $result ): bool {
		if ( ! is_array( $result ) ) {
			return false;
		}
		foreach ( $result as $candidate ) {
			if ( $candidate instanceof GeocodeResult ) {
				return true;
			}
		}
		return false;
	}

	/** Respuesta de la prueba de conexión: 200 siempre, `no-store` y textos propios en español. */
	private static function testResponse( string $provider, string $code ): \WP_REST_Response {
		return self::noStore(
			new \WP_REST_Response(
				array(
					'ok'       => self::RESULT_OK === $code,
					'provider' => $provider,
					'code'     => $code,
					'message'  => self::message( $code, $provider ),
					'hint'     => self::hint( $code, $provider ),
				),
				200
			)
		);
	}

	private static function geocodeError( string $code ): \WP_Error {
		$message = 'geocoding_no_results' === $code
			? 'No se encontró la dirección de referencia. Prueba con una dirección más completa.'
			: 'No se pudo resolver la dirección de referencia con el proveedor de mapas.';
		$status  = 'geocoding_no_results' === $code ? 422 : self::errorStatus( $code );

		if ( 'geocoding_no_results' !== $code ) {
			error_log( sprintf( '[hwe] shipping: «Validar» no pudo geocodificar (%s).', $code ) );
		}

		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/** Estado HTTP de un fallo del proveedor al validar: 503 si es temporal, 502 si es de origen. */
	private static function errorStatus( string $code ): int {
		return in_array( $code, array( 'provider_rate_limited', 'geocoding_backoff', 'provider_unavailable' ), true ) ? 503 : 502;
	}

	/** Dirección de la petición, recortada y con los espacios colapsados. 422 si no es usable. */
	private static function address( \WP_REST_Request $request ): string|\WP_Error {
		$raw = $request->get_param( 'address' );
		if ( ! is_string( $raw ) ) {
			return self::invalidAddress();
		}

		$clean = preg_replace( '/\s+/u', ' ', trim( $raw ) );
		$clean = is_string( $clean ) ? trim( $clean ) : '';
		if ( '' === $clean || self::length( $clean ) > self::MAX_ADDRESS ) {
			return self::invalidAddress();
		}

		return $clean;
	}

	private static function invalidAddress(): \WP_Error {
		return new \WP_Error(
			'hwe_shipping_invalid_address',
			sprintf( 'Escribe una dirección de prueba (texto de hasta %d caracteres).', self::MAX_ADDRESS ),
			array( 'status' => 422 )
		);
	}

	private static function forbidden(): \WP_Error {
		return new \WP_Error( 'rest_forbidden', 'No tienes permiso para usar las herramientas de mapas.', array( 'status' => 403 ) );
	}

	private static function noStore( \WP_REST_Response $response ): \WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $value ) : strlen( $value );
	}

	/** Texto propio por resultado; nunca el mensaje crudo del proveedor. */
	private static function message( string $code, string $provider ): string {
		switch ( $code ) {
			case self::RESULT_OK:
				return 'La conexión con el proveedor de mapas funciona: respondió a la dirección de prueba.';
			case self::RESULT_MISSING_KEY:
				return 'none' === $provider
					? 'No hay ningún proveedor de mapas elegido en Integraciones → Mapas.'
					: 'Falta la clave del proveedor de mapas en Integraciones → Mapas.';
			case self::RESULT_UNAUTHORIZED:
				return 'El proveedor de mapas rechazó la clave configurada.';
			case self::RESULT_API_DISABLED:
				return 'La clave es válida, pero la API de mapas no está habilitada en el proyecto del proveedor.';
			case self::RESULT_RATE_LIMITED:
				return 'El proveedor de mapas alcanzó su límite de uso o está en pausa.';
			case self::RESULT_NO_RESULTS:
				return 'El proveedor de mapas respondió, pero no encontró la dirección de prueba.';
			case self::RESULT_NETWORK:
				return 'No se pudo completar la llamada al proveedor de mapas.';
		}
		return 'No se pudo comprobar la conexión con el proveedor de mapas.';
	}

	/** Pista accionable (sin nombrar ningún país ni repetir la clave). */
	private static function hint( string $code, string $provider ): string {
		$google = 'google_maps' === $provider;

		switch ( $code ) {
			case self::RESULT_OK:
				return 'No hace falta cambiar nada; el proveedor y la clave responden.';
			case self::RESULT_MISSING_KEY:
				return 'Elige el proveedor y guarda su clave en Integraciones → Mapas: se guarda cifrada y solo la usa el servidor.';
			case self::RESULT_UNAUTHORIZED:
				return $google
					? 'Revisa la clave en Integraciones → Mapas: debe ser una clave del proyecto con las APIs de geocodificación y de rutas habilitadas, restringida por la IP del servidor (no por referente ni por aplicación).'
					: 'Revisa la clave en Integraciones → Mapas: debe ser un token activo del proveedor y con la API de geocodificación habilitada.';
			case self::RESULT_API_DISABLED:
				return $google
					? 'Habilita en el proyecto de la clave las APIs de geocodificación y de rutas, espera unos minutos a que se propague y vuelve a probar.'
					: 'Activa en el plan del proveedor la API de geocodificación y vuelve a probar.';
			case self::RESULT_RATE_LIMITED:
				return 'Revisa la cuota o el plan del proveedor de mapas y vuelve a probar más tarde.';
			case self::RESULT_NO_RESULTS:
				return 'La conexión funciona: escribe una dirección de prueba más completa o prueba con otra.';
			case self::RESULT_NETWORK:
				return 'Comprueba que el servidor tiene salida a internet y que el proveedor no está caído; el detalle queda en el registro de errores.';
		}
		return 'Revisa Integraciones → Mapas y el registro de errores del servidor.';
	}
}
