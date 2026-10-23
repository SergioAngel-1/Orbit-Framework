<?php

namespace HWE\Shipping\Distance;

use HWE\Shipping\Rates\GeoPoint;
use HWE\Shipping\ShippingCost;

/**
 * Google: distancia por carretera con Routes API (`computeRouteMatrix`, con tráfico en conducción)
 * y geocodificación con Geocoding API v4. La clave va en la cabecera `X-Goog-Api-Key`; nunca en la
 * URL ni en un log.
 *
 * Una ruta solo cuenta si la respuesta trae `ROUTE_EXISTS`, distancia > 0 y una duración legible.
 * Cualquier otra cosa es un error: un 200 absurdo no puede dar un coste de 0.
 */
final class GoogleProvider implements Provider {

	public const ID = 'google_maps';

	private const ROUTES_ENDPOINT  = 'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix';
	private const GEOCODE_ENDPOINT = 'https://geocode.googleapis.com/v4/geocode/';
	private const FIELD_MASK       = 'originIndex,destinationIndex,duration,distanceMeters,status,condition';

	/** Mejor granularidad primero. Lo que no esté aquí puntúa 0. */
	private const GRANULARITY_RANK = array(
		'ROOFTOP'            => 4,
		'RANGE_INTERPOLATED' => 3,
		'GEOMETRIC_CENTER'   => 2,
		'APPROXIMATE'        => 1,
	);

	public function id(): string {
		return self::ID;
	}

	public function distance( GeoPoint $from, GeoPoint $to, string $mode ): DistanceResult|\WP_Error {
		$key = Settings::mapsKey();
		if ( '' === $key ) {
			return new \WP_Error( 'provider_unauthorized', 'Falta la clave del proveedor de mapas.' );
		}

		$travel  = 'walking' === $mode ? 'WALK' : 'DRIVE';
		$payload = array(
			'origins'      => array( $this->waypoint( $from ) ),
			'destinations' => array( $this->waypoint( $to ) ),
			'travelMode'   => $travel,
			'regionCode'   => ShippingCost::baseCountry(),
			'units'        => 'METRIC',
		);
		if ( 'DRIVE' === $travel ) {
			$payload['routingPreference'] = 'TRAFFIC_AWARE';
		}

		$response = ProviderHttp::post(
			self::ROUTES_ENDPOINT,
			array(
				'X-Goog-Api-Key'   => $key,
				'X-Goog-FieldMask' => self::FIELD_MASK,
			),
			$payload
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$element = $response['body'][0] ?? null;
		if ( ! is_array( $element ) || 'ROUTE_EXISTS' !== ( $element['condition'] ?? '' ) ) {
			return new \WP_Error( 'route_unavailable', 'No hay una ruta entre el origen y el destino.' );
		}

		$meters  = $element['distanceMeters'] ?? null;
		$seconds = self::seconds( $element['duration'] ?? null );
		if ( ! is_numeric( $meters ) || (float) $meters <= 0 || null === $seconds ) {
			return new \WP_Error( 'route_unavailable', 'La ruta devuelta por el proveedor no es utilizable.' );
		}

		return new DistanceResult( (float) $meters / 1000, (int) ceil( $seconds / 60 ), self::ID );
	}

	public function geocode( string $query, string $country, string $language ): array|\WP_Error {
		$key = Settings::mapsKey();
		if ( '' === $key ) {
			return new \WP_Error( 'provider_unauthorized', 'Falta la clave del proveedor de mapas.' );
		}

		$url      = add_query_arg(
			array(
				'languageCode' => $language,
				'regionCode'   => strtoupper( $country ),
			),
			self::GEOCODE_ENDPOINT . 'address/' . rawurlencode( $query )
		);
		$response = ProviderHttp::get( $url, array( 'X-Goog-Api-Key' => $key ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$results = $this->results( $response['body'] );
		return $results ?: new \WP_Error( 'geocoding_no_results', 'El proveedor de mapas no encontró la dirección.' );
	}

	public function reverse( GeoPoint $point, string $language ): GeocodeResult|\WP_Error {
		$key = Settings::mapsKey();
		if ( '' === $key ) {
			return new \WP_Error( 'provider_unauthorized', 'Falta la clave del proveedor de mapas.' );
		}

		$url      = add_query_arg(
			array( 'languageCode' => $language ),
			// `sprintf` y no concatenación: un float diminuto se escribiría «1.0E-5» en la URL.
			self::GEOCODE_ENDPOINT . 'location/' . sprintf( '%.7F,%.7F', $point->lat(), $point->lng() )
		);
		$response = ProviderHttp::get( $url, array( 'X-Goog-Api-Key' => $key ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$results = $this->results( $response['body'] );
		return $results[0] ?? new \WP_Error( 'geocoding_no_results', 'El proveedor de mapas no encontró una dirección para ese punto.' );
	}

	/** @return array<string,mixed> */
	private function waypoint( GeoPoint $point ): array {
		return array(
			'waypoint' => array(
				'location' => array(
					'latLng' => array(
						'latitude'  => $point->lat(),
						'longitude' => $point->lng(),
					),
				),
			),
		);
	}

	/**
	 * Resultados válidos, mejor granularidad primero. Un resultado sin coordenadas se descarta.
	 *
	 * @param array<mixed> $body
	 * @return GeocodeResult[]
	 */
	private function results( array $body ): array {
		$rows = array();
		foreach ( is_array( $body['results'] ?? null ) ? $body['results'] : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$point = GeoPoint::tryFrom( $row['location']['latitude'] ?? null, $row['location']['longitude'] ?? null );
			if ( null === $point ) {
				continue;
			}
			$granularity = (string) ( $row['granularity'] ?? '' );
			$rows[]      = array(
				'rank'   => self::GRANULARITY_RANK[ $granularity ] ?? 0,
				'result' => new GeocodeResult( $point, (string) ( $row['formattedAddress'] ?? '' ), $granularity ),
			);
		}

		// `usort` es estable desde PHP 8: a igual granularidad se mantiene el orden del proveedor.
		usort( $rows, static fn ( array $a, array $b ): int => $b['rank'] <=> $a['rank'] );

		return array_map( static fn ( array $row ): GeocodeResult => $row['result'], $rows );
	}

	/** «610s» → 610.0; cualquier otra cosa (o cero) → null. */
	private static function seconds( mixed $raw ): ?float {
		if ( is_string( $raw ) && 1 === preg_match( '/^(\d+(?:\.\d+)?)s$/', $raw, $matches ) ) {
			$seconds = (float) $matches[1];
			return $seconds > 0 ? $seconds : null;
		}
		return null;
	}
}
