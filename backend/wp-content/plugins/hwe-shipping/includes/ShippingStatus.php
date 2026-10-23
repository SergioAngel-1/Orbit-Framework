<?php

namespace HWE\Shipping;

use HWE\Shipping\Distance\DistanceResult;
use HWE\Shipping\Distance\DistanceService;
use HWE\Shipping\Rates\City;
use HWE\Shipping\Rates\Cities;
use HWE\Shipping\Rates\GeoPoint;

/**
 * Decisión del envío para un paquete: qué tarifas corresponden al destino y, si no hay ninguna, por qué.
 *
 * Es la ÚNICA decisión del módulo. El método de envío (`CityRateMethod`) la consume para producir las
 * tarifas, y `forPackage()` la publica como estado (`cart.extensions.hwe_shipping`, spec §8.3). Ni el
 * método ni el estado vuelven a decidir por su cuenta. Se memoiza por petición: WooCommerce llama al
 * método varias veces por paquete y la distancia no debe pedirse dos veces.
 *
 * Reglas, en orden:
 *   1. destino fuera del país base → `none`;
 *   2. sin calle o sin ciudad → `needs_address`;
 *   3. ciudad sin registro (o del modelo anterior) → `reserve` si la reserva está activa, si no `none`;
 *   4. ciudad fija → `fixed`;
 *   5. ciudad por distancia → sin pin, `needs_location`; fuera del radio, `out_of_coverage`; si no,
 *      `distance` con la distancia desde la referencia de la ciudad.
 *
 * Los importes no salen de aquí: cada opción se cobra con `Pricing` en el método.
 */
final class ShippingStatus {

	/** @var array<string,array<string,mixed>> Decisiones de esta petición, por huella de entrada. */
	private static array $memo = array();

	/**
	 * Estado público del envío para el paquete (forma de la Store API).
	 *
	 * @param array<string,mixed> $package Paquete de `woocommerce_cart_shipping_packages`.
	 * @return array{mode:string,status:string,distance_km?:float,duration_min?:int,fallback?:bool}
	 */
	public static function forPackage( array $package ): array {
		$decision = self::decide( $package );
		$state    = array(
			'mode'   => $decision['mode'],
			'status' => $decision['status'],
		);

		$distance = $decision['distance'];
		if ( 'distance' === $decision['mode'] && 'ok' === $decision['status'] && $distance instanceof DistanceResult ) {
			$state['distance_km']  = round( $distance->km(), 2 );
			$state['duration_min'] = $distance->durationMin();
			$state['fallback']     = $distance->fallback();
		}

		return $state;
	}

	/**
	 * Decisión completa para el método de envío. Claves: `mode`, `status`, `city` (?City),
	 * `distance` (?DistanceResult), `point` (?GeoPoint) y `destination_city` (texto del destino).
	 *
	 * @param array<string,mixed> $package Paquete de WooCommerce.
	 * @return array{mode:string,status:string,city:?City,distance:?DistanceResult,point:?GeoPoint,destination_city:string}
	 */
	public static function decide( array $package ): array {
		$destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : array();
		$point       = DeliveryPoint::forDestination( $destination );

		$key = md5(
			(string) json_encode(
				array(
					self::text( $destination['country'] ?? '' ),
					self::text( $destination['address_1'] ?? '' ),
					ShippingCost::normalizeCity( self::text( $destination['city'] ?? '' ) ),
					null === $point ? null : array( $point->lat(), $point->lng() ),
				)
			)
		);

		if ( ! isset( self::$memo[ $key ] ) ) {
			self::$memo[ $key ] = self::compute( $destination, $point );
		}

		return self::$memo[ $key ];
	}

	/** Olvida las decisiones memoizadas (pruebas, o un cambio de configuración en la misma petición). */
	public static function forget(): void {
		self::$memo = array();
	}

	/**
	 * @param array<string,mixed> $destination
	 * @return array{mode:string,status:string,city:?City,distance:?DistanceResult,point:?GeoPoint,destination_city:string}
	 */
	private static function compute( array $destination, ?GeoPoint $point ): array {
		$country  = strtoupper( trim( self::text( $destination['country'] ?? '' ) ) );
		$base     = ShippingCost::baseCountry();
		$address  = trim( self::text( $destination['address_1'] ?? '' ) );
		$cityName = trim( self::text( $destination['city'] ?? '' ) );

		if ( '' === $base || $country !== $base ) {
			return self::result( 'none', 'ok', $cityName );
		}
		if ( '' === $address || '' === $cityName ) {
			return self::result( 'none', 'needs_address', $cityName );
		}

		$city = Cities::forCity( $cityName );
		if ( null === $city ) {
			return ReserveZone::enabled()
				? self::result( 'reserve', 'ok', $cityName )
				: self::result( 'none', 'ok', $cityName );
		}

		if ( City::TYPE_FIXED === $city->type() ) {
			return self::result( 'fixed', 'ok', $cityName, $city );
		}

		$origin = $city->origin();
		if ( null === $origin ) {
			// El guardado no publica una ciudad por distancia sin referencia; si llega así (datos
			// importados o editados a mano), sin tarifa y registrado, nunca un fatal en el carrito.
			error_log( sprintf( '[hwe] shipping: la ciudad «%s» es por distancia y no tiene dirección de referencia; sin tarifa.', $city->name() ) );
			return self::result( 'none', 'ok', $cityName );
		}
		if ( null === $point ) {
			return self::result( 'distance', 'needs_location', $cityName, $city, null, null );
		}

		// Misma distancia que se cobra: una sola petición por paquete y ciudad.
		$distance = DistanceService::between( $origin, $point );
		$status   = $distance->km() > $city->coverageKm() ? 'out_of_coverage' : 'ok';

		return self::result( 'distance', $status, $cityName, $city, $distance, $point );
	}

	/** @return array{mode:string,status:string,city:?City,distance:?DistanceResult,point:?GeoPoint,destination_city:string} */
	private static function result(
		string $mode,
		string $status,
		string $destinationCity,
		?City $city = null,
		?DistanceResult $distance = null,
		?GeoPoint $point = null
	): array {
		return array(
			'mode'             => $mode,
			'status'           => $status,
			'city'             => $city,
			'distance'         => $distance,
			'point'            => $point,
			'destination_city' => $destinationCity,
		);
	}

	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
