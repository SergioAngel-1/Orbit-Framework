<?php

namespace HWE\Shipping;

use HWE\Shipping\Distance\DistanceResult;
use HWE\Shipping\Rates\City;
use HWE\Shipping\Rates\DeliveryOption;
use HWE\Shipping\Rates\GeoPoint;
use HWE\Shipping\Rates\Pricing;

/**
 * Método de envío de WooCommerce `hwe_city_rate`: produce las tarifas del destino a partir de la decisión
 * de `ShippingStatus`. Vive en la zona de reserva (`ReserveZone`) y puede añadirse a otras zonas de la
 * tienda: el cálculo no depende de en qué zona esté.
 *
 * Tarifas (ids estables, `hwe_city_rate:<optionId>`; la de reserva, `hwe_city_rate:reserve`):
 *   - ciudad fija: una por opción, con su importe;
 *   - ciudad por distancia: una por opción, `Pricing::cost(opción, km)`;
 *   - ciudad sin registro: una de reserva (nombre y coste de `shipping.reserve_*`).
 * Cada coste pasa por el filtro `hwe_shipping_rate_cost`. Un coste que el filtro deja no válido (negativo,
 * no numérico) omite esa tarifa y se registra: nunca un envío a 0 por error.
 *
 * Metas de la tarifa (WooCommerce las copia a la línea de envío del pedido y la Store API las expone en
 * `shipping_rates[].meta_data`, sin filtro por prefijo: por eso no llevan `_`):
 *   - para el frontend: `hwe_option_id`, `hwe_city_type` (`fixed`|`distance`|`reserve`), y en distancia
 *     `hwe_distance_km` y `hwe_fallback` (`yes`|`no`);
 *   - para wp-admin y el pedido: proveedor, motivo del respaldo, duración, punto de entrega (lat/lng) y un
 *     enlace al mapa del punto («Ubicación de entrega»). Son visibles para el cliente en la Store API; no
 *     contienen secretos ni datos de la referencia de la ciudad.
 */
class CityRateMethod extends \WC_Shipping_Method {

	public const ID          = 'hwe_city_rate';
	public const RESERVE     = 'reserve';
	public const FILTER_COST = 'hwe_shipping_rate_cost';

	/**
	 * Añade el método al registro de WooCommerce (id => clase). Lo engancha `Plugin::boot()` desde un
	 * cierre, para no cargar la clase al arrancar: extiende `WC_Shipping_Method`, que puede no existir
	 * todavía (este plugin carga antes que WooCommerce).
	 *
	 * @param array<string,string> $methods
	 * @return array<string,string>
	 */
	public static function registerMethod( array $methods ): array {
		$methods[ self::ID ] = self::class;

		return $methods;
	}

	/** @param int|string $instance_id Id de instancia en la zona (0 al registrar el tipo). */
	public function __construct( $instance_id = 0 ) {
		parent::__construct( $instance_id );
		$this->id                 = self::ID;
		$this->method_title       = __( 'Envío por ciudad', 'hwe-shipping' );
		$this->method_description = __( 'Tarifas por ciudad (fija o por distancia) y tarifa de reserva para el resto del país. Las ciudades se configuran en Tarifas de envío; la reserva, en Control Center → Envío.', 'hwe-shipping' );
		$this->supports          = array( 'shipping-zones' );
	}

	/** @param array<string,mixed> $package Paquete de WooCommerce. */
	public function calculate_shipping( $package = array() ): void {
		$package  = is_array( $package ) ? $package : array();
		$decision = ShippingStatus::decide( $package );

		if ( 'ok' !== $decision['status'] ) {
			return;
		}

		if ( 'reserve' === $decision['mode'] ) {
			$this->addRate(
				array(
					'city'      => $decision['destination_city'],
					'option_id' => self::RESERVE,
					'label'     => ReserveZone::name(),
					'cost'      => ReserveZone::cost(),
					'type'      => self::RESERVE,
				)
			);
			return;
		}

		$city = $decision['city'];
		if ( ! $city instanceof City || ! in_array( $decision['mode'], array( 'fixed', 'distance' ), true ) ) {
			return;
		}

		$distance = $decision['distance'];
		foreach ( $city->options() as $option ) {
			$this->addOptionRate( $city, $option, $distance, $decision['point'] );
		}
	}

	/** Una tarifa por opción de la ciudad: coste de `Pricing` (fija o por distancia). */
	private function addOptionRate( City $city, DeliveryOption $option, ?DistanceResult $distance, ?GeoPoint $point ): void {
		try {
			$km   = $distance?->km();
			$cost = Pricing::cost( $option, $km );
		} catch ( \Throwable $error ) {
			error_log( sprintf( '[hwe] shipping: la opción «%s» de «%s» no tiene precio válido; se omite.', $option->id(), $city->name() ) );
			return;
		}

		$this->addRate(
			array(
				'city'      => $city->name(),
				'option_id' => $option->id(),
				'label'     => $option->name(),
				'cost'      => $cost,
				'type'      => $city->type(),
				'km'        => $km,
				'distance'  => $distance,
				'point'     => $point,
			)
		);
	}

	/**
	 * Aplica el filtro de coste y añade la tarifa. Un coste no válido omite la tarifa (falla cerrado).
	 *
	 * @param array<string,mixed> $rate Datos de la tarifa: city, option_id, label, cost, type, km, distance, point.
	 */
	private function addRate( array $rate ): void {
		$context = array(
			'city'      => (string) $rate['city'],
			'option_id' => (string) $rate['option_id'],
			'type'      => (string) $rate['type'],
			'km'        => $rate['km'] ?? null,
		);

		$cost = apply_filters( self::FILTER_COST, (float) $rate['cost'], $context );
		if ( ! is_numeric( $cost ) || ! is_finite( (float) $cost ) || (float) $cost < 0 ) {
			error_log( sprintf( '[hwe] shipping: hwe_shipping_rate_cost devolvió un coste no válido para la opción «%s»; la tarifa se omite.', $context['option_id'] ) );
			return;
		}

		$this->add_rate(
			array(
				'id'        => self::ID . ':' . $context['option_id'],
				'label'     => (string) $rate['label'],
				'cost'      => Pricing::round( (float) $cost ),
				'meta_data' => self::meta( $context['option_id'], $context['type'], $rate['distance'] ?? null, $rate['point'] ?? null ),
			)
		);
	}

	/**
	 * Metas de la tarifa (ver la cabecera de la clase). Las de distancia solo existen en tarifas por distancia.
	 *
	 * @return array<string,string>
	 */
	private static function meta( string $optionId, string $type, ?DistanceResult $distance, ?GeoPoint $point ): array {
		$meta = array(
			'hwe_option_id'  => $optionId,
			'hwe_city_type'  => $type,
		);
		if ( null === $distance || null === $point ) {
			return $meta;
		}

		$meta['hwe_distance_km'] = (string) round( $distance->km(), 2 );
		$meta['hwe_fallback']    = $distance->fallback() ? 'yes' : 'no';

		$meta[ __( 'Proveedor de distancia', 'hwe-shipping' ) ] = $distance->provider();
		if ( $distance->fallback() ) {
			$meta[ __( 'Motivo del respaldo', 'hwe-shipping' ) ] = $distance->fallbackReason();
		}
		$meta[ __( 'Duración estimada', 'hwe-shipping' ) ] = $distance->durationMin() . ' min';
		$meta[ __( 'Punto de entrega', 'hwe-shipping' ) ]  = sprintf( '%.6F, %.6F', $point->lat(), $point->lng() );
		$meta[ __( 'Ubicación de entrega', 'hwe-shipping' ) ] = sprintf(
			'https://www.openstreetmap.org/?mlat=%1$.6F&mlon=%2$.6F#map=17/%1$.6F/%2$.6F',
			$point->lat(),
			$point->lng()
		);

		return $meta;
	}
}
