<?php

namespace HWE\Shipping\Distance;

use HWE\Shipping\Rates\GeoPoint;
use HWE\Shipping\ShippingCost;

/**
 * OpenRouteService: distancia por carretera (directions) y geocodificación (search y reverse).
 * La clave va en la cabecera `Authorization`; nunca en la URL ni en un log.
 *
 * Como en Google, una ruta sin distancia o duración positivas es un error, no un resultado.
 */
final class OpenRouteServiceProvider implements Provider {

	public const ID = 'openrouteservice';

	private const BASE_ENDPOINT = 'https://api.openrouteservice.org';

	private const PROFILES = array(
		'driving' => 'driving-car',
		'walking' => 'foot-walking',
	);

	/** Resultados que se piden a la búsqueda (el mapa muestra como mucho cinco). */
	private const SEARCH_SIZE = 5;

	public function id(): string {
		return self::ID;
	}

	public function distance( GeoPoint $from, GeoPoint $to, string $mode ): DistanceResult|\WP_Error {
		$key = Settings::mapsKey();
		if ( '' === $key ) {
			return new \WP_Error( 'provider_unauthorized', 'Falta la clave del proveedor de mapas.' );
		}

		$profile  = self::PROFILES[ $mode ] ?? self::PROFILES['driving'];
		$response = ProviderHttp::post(
			self::BASE_ENDPOINT . '/v2/directions/' . $profile,
			array( 'Authorization' => 'Bearer ' . $key ),
			array(
				'coordinates'  => array(
					array( $from->lng(), $from->lat() ),
					array( $to->lng(), $to->lat() ),
				),
				'instructions' => false,
			)
		);
		if ( is_wp_error( $response ) ) {
			// Un 404 de directions significa que no hay ruta entre los puntos, no que el servicio falle.
			if ( 404 === ( $response->get_error_data()['status'] ?? null ) ) {
				return new \WP_Error( 'route_unavailable', 'No hay una ruta entre el origen y el destino.' );
			}
			return $response;
		}

		$summary = $response['body']['routes'][0]['summary'] ?? null;
		if ( ! is_array( $summary ) ) {
			return new \WP_Error( 'route_unavailable', 'OpenRouteService no devolvió una ruta.' );
		}

		$meters  = $summary['distance'] ?? null;
		$seconds = $summary['duration'] ?? null;
		if ( ! is_numeric( $meters ) || (float) $meters <= 0 || ! is_numeric( $seconds ) || (float) $seconds <= 0 ) {
			return new \WP_Error( 'route_unavailable', 'La ruta devuelta por el proveedor no es utilizable.' );
		}

		return new DistanceResult( (float) $meters / 1000, (int) ceil( (float) $seconds / 60 ), self::ID );
	}

	public function geocode( string $query, string $country, string $language ): array|\WP_Error {
		$key = Settings::mapsKey();
		if ( '' === $key ) {
			return new \WP_Error( 'provider_unauthorized', 'Falta la clave del proveedor de mapas.' );
		}

		$url      = add_query_arg(
			array(
				'text'             => $query,
				'boundary.country' => strtoupper( $country ),
				'size'             => self::SEARCH_SIZE,
			),
			self::BASE_ENDPOINT . '/geocode/search'
		);
		$response = ProviderHttp::get( $url, array( 'Authorization' => 'Bearer ' . $key ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$results = $this->features( $response['body'] );
		return $results ?: new \WP_Error( 'geocoding_no_results', 'OpenRouteService no encontró la dirección.' );
	}

	public function reverse( GeoPoint $point, string $language ): GeocodeResult|\WP_Error {
		$key = Settings::mapsKey();
		if ( '' === $key ) {
			return new \WP_Error( 'provider_unauthorized', 'Falta la clave del proveedor de mapas.' );
		}

		$url      = add_query_arg(
			array(
				'point.lat'        => $point->lat(),
				'point.lon'        => $point->lng(),
				'boundary.country' => strtoupper( ShippingCost::baseCountry() ),
				'size'             => 1,
			),
			self::BASE_ENDPOINT . '/geocode/reverse'
		);
		$response = ProviderHttp::get( $url, array( 'Authorization' => 'Bearer ' . $key ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$results = $this->features( $response['body'] );
		return $results[0] ?? new \WP_Error( 'geocoding_no_results', 'OpenRouteService no encontró una dirección para ese punto.' );
	}

	/**
	 * Candidatos válidos, más confiables primero. Sin coordenadas, el candidato se descarta.
	 *
	 * @param array<mixed> $body
	 * @return GeocodeResult[]
	 */
	private function features( array $body ): array {
		$rows = array();
		foreach ( is_array( $body['features'] ?? null ) ? $body['features'] : array() as $feature ) {
			if ( ! is_array( $feature ) ) {
				continue;
			}
			$coordinates = is_array( $feature['geometry']['coordinates'] ?? null ) ? $feature['geometry']['coordinates'] : array();
			// GeoJSON: [lng, lat].
			$point = GeoPoint::tryFrom( $coordinates[1] ?? null, $coordinates[0] ?? null );
			if ( null === $point ) {
				continue;
			}
			$properties = is_array( $feature['properties'] ?? null ) ? $feature['properties'] : array();
			$rows[]     = array(
				'confidence' => (float) ( $properties['confidence'] ?? 0 ),
				'result'     => new GeocodeResult( $point, (string) ( $properties['label'] ?? '' ), (string) ( $properties['layer'] ?? '' ) ),
			);
		}

		usort( $rows, static fn ( array $a, array $b ): int => $b['confidence'] <=> $a['confidence'] );

		return array_map( static fn ( array $row ): GeocodeResult => $row['result'], $rows );
	}
}
