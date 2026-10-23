<?php

namespace HWE\Shipping\Distance;

use HWE\Shipping\Rates\GeoPoint;

/**
 * Línea recta sobre la esfera (fórmula de Haversine). Sin red ni clave: es el respaldo de todos
 * los proveedores y el método elegido cuando no hay mapas configurados.
 *
 * La distancia por carretera se estima como la línea recta × (1 + incremento %). La duración se
 * estima a una velocidad media: 30 km/h conduciendo, 5 km/h caminando.
 */
final class Haversine {

	/** Radio medio de la Tierra, en km. */
	private const EARTH_RADIUS_KM = 6371.0088;

	private const SPEED_KMH = [
		'driving' => 30,
		'walking' => 5,
	];

	/** Distancia en línea recta entre dos puntos, en km. */
	public static function km( GeoPoint $a, GeoPoint $b ): float {
		$lat1 = deg2rad( $a->lat() );
		$lat2 = deg2rad( $b->lat() );
		$dLat = $lat2 - $lat1;
		$dLng = deg2rad( $b->lng() - $a->lng() );

		$h = sin( $dLat / 2 ) ** 2 + cos( $lat1 ) * cos( $lat2 ) * sin( $dLng / 2 ) ** 2;
		$h = min( 1.0, max( 0.0, $h ) );

		return 2 * self::EARTH_RADIUS_KM * asin( sqrt( $h ) );
	}

	/**
	 * Estimación de distancia y duración. `$incrementPct` se suma sobre la línea recta (0–200).
	 * `$mode` debe ser `driving` o `walking`; cualquier otro valor es un error de programación.
	 */
	public static function estimate( GeoPoint $a, GeoPoint $b, int $incrementPct, string $mode ): DistanceResult {
		if ( ! isset( self::SPEED_KMH[ $mode ] ) ) {
			throw new \InvalidArgumentException( 'Modo de viaje no válido.' );
		}

		$km       = self::km( $a, $b ) * ( 1 + $incrementPct / 100 );
		$duration = (int) round( $km / self::SPEED_KMH[ $mode ] * 60 );

		return new DistanceResult( $km, $duration, 'straight_line' );
	}
}
