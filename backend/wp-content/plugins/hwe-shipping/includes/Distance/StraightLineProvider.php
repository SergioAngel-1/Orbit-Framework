<?php

namespace HWE\Shipping\Distance;

use HWE\Shipping\Rates\GeoPoint;

/**
 * Línea recta (Haversine, sin red) para la distancia, y Nominatim (OpenStreetMap, sin clave) para
 * geocodificar. Nominatim es además el buscador de respaldo cuando el proveedor de mapas falla.
 *
 * Su política de uso exige identificarse: el `User-Agent` lleva la URL del sitio, nunca un correo
 * escrito a mano. Ante errores se pausa: 30 s por error de red, 2 min por 5xx y 15 min por 429.
 * Durante la pausa no sale ninguna petición y la llamada devuelve `geocoding_backoff`.
 */
final class StraightLineProvider implements Provider {

	public const ID = 'straight_line';

	private const SEARCH_ENDPOINT  = 'https://nominatim.openstreetmap.org/search';
	private const REVERSE_ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';
	private const BACKOFF_KEY      = 'hwe_shipping_nominatim_backoff';
	private const TIMEOUT          = 8;
	private const SEARCH_LIMIT     = 5;

	private const BACKOFF_NETWORK_SECONDS = 30;
	private const BACKOFF_SERVER_SECONDS  = 2 * MINUTE_IN_SECONDS;
	private const BACKOFF_RATE_SECONDS    = 15 * MINUTE_IN_SECONDS;

	public function id(): string {
		return self::ID;
	}

	public function distance( GeoPoint $from, GeoPoint $to, string $mode ): DistanceResult|\WP_Error {
		return Haversine::estimate( $from, $to, Settings::incrementPct(), 'walking' === $mode ? 'walking' : 'driving' );
	}

	public function geocode( string $query, string $country, string $language ): array|\WP_Error {
		$paused = $this->paused();
		if ( null !== $paused ) {
			return $paused;
		}

		$url  = add_query_arg(
			array(
				'q'               => $query,
				'format'          => 'jsonv2',
				'addressdetails'  => 0,
				'limit'           => self::SEARCH_LIMIT,
				'countrycodes'    => strtolower( $country ),
				'accept-language' => $language,
			),
			self::SEARCH_ENDPOINT
		);
		$body = $this->request( $url );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$results = array();
		foreach ( $body as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$point = GeoPoint::tryFrom( $row['lat'] ?? null, $row['lon'] ?? null );
			if ( null !== $point ) {
				$results[] = new GeocodeResult( $point, (string) ( $row['display_name'] ?? '' ), (string) ( $row['type'] ?? '' ) );
			}
		}

		return $results ?: new \WP_Error( 'geocoding_no_results', 'No se encontró la dirección.' );
	}

	public function reverse( GeoPoint $point, string $language ): GeocodeResult|\WP_Error {
		$paused = $this->paused();
		if ( null !== $paused ) {
			return $paused;
		}

		$url  = add_query_arg(
			array(
				'lat'             => $point->lat(),
				'lon'             => $point->lng(),
				'format'          => 'jsonv2',
				'zoom'            => 18,
				'addressdetails'  => 0,
				'accept-language' => $language,
			),
			self::REVERSE_ENDPOINT
		);
		$body = $this->request( $url );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$label = is_string( $body['display_name'] ?? null ) ? trim( $body['display_name'] ) : '';
		if ( '' === $label ) {
			return new \WP_Error( 'geocoding_no_results', 'No se encontró una dirección para ese punto.' );
		}

		return new GeocodeResult( $point, $label, (string) ( $body['type'] ?? '' ) );
	}

	/** Error de pausa si Nominatim está en backoff, o null si puede llamar. */
	private function paused(): ?\WP_Error {
		if ( false === get_transient( self::BACKOFF_KEY ) ) {
			return null;
		}
		return new \WP_Error( 'geocoding_backoff', 'El servicio de geocodificación está en pausa; inténtalo en unos minutos.' );
	}

	/**
	 * Una petición a Nominatim. Devuelve el cuerpo decodificado o un `WP_Error` y, según el fallo,
	 * pone la pausa correspondiente.
	 *
	 * @return array<mixed>|\WP_Error
	 */
	private function request( string $url ): array|\WP_Error {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'User-Agent' => 'HWE-Shipping/' . HWE_SHIPPING_VERSION . ' (' . home_url( '/' ) . ')',
					'Accept'     => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->fail( 'provider_unavailable', 'No se pudo conectar con el servicio de geocodificación.', self::BACKOFF_NETWORK_SECONDS, 'red' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $status ) {
			return $this->fail( 'provider_rate_limited', 'El servicio de geocodificación alcanzó su límite de uso.', self::BACKOFF_RATE_SECONDS, 'HTTP 429' );
		}
		if ( $status >= 500 ) {
			return $this->fail( 'provider_unavailable', 'El servicio de geocodificación no está disponible en este momento.', self::BACKOFF_SERVER_SECONDS, 'HTTP ' . $status );
		}
		if ( 200 !== $status ) {
			return new \WP_Error( 'provider_unavailable', 'El servicio de geocodificación devolvió un error.', array( 'status' => $status ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'provider_unavailable', 'El servicio de geocodificación devolvió una respuesta que no es JSON.' );
		}

		return $body;
	}

	/** Registra la pausa y devuelve el error de la petición fallida. */
	private function fail( string $code, string $message, int $seconds, string $cause ): \WP_Error {
		set_transient( self::BACKOFF_KEY, $code, $seconds );
		error_log( sprintf( '[hwe] shipping: Nominatim en pausa %d s (%s: %s).', $seconds, $cause, $code ) );
		return new \WP_Error( $code, $message );
	}
}
