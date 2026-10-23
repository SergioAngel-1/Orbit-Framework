<?php

namespace HWE\Shipping;

use HWE\Shipping\Rates\Pricing;

/**
 * Zona de reserva de WooCommerce: el envío para las ciudades SIN tarifa propia.
 *
 * Las tarifas por ciudad las produce el método de envío del plugin (`CityRateMethod`, id
 * `hwe_city_rate`). Si la tienda no tiene ninguna zona que cubra el país base de `ecommerce.country`,
 * la Store API devuelve `shipping_rates: []` y el checkout no puede cerrar el pedido. Esta clase crea
 * (y mantiene) esa zona: cubre el país base con el método del plugin.
 *
 * Con el módulo activo la zona existe SIEMPRE. `shipping.reserve_enabled` no la crea ni la borra: dice
 * si las ciudades sin configurar reciben la tarifa de reserva (`CityRateMethod`). Apagado, esas
 * ciudades no tienen envío y solo se vende a las configuradas.
 *
 * Se configura en el Control Center → Envío (los campos los declara `SettingsSchema`):
 *   - `shipping.reserve_enabled`: enviar también a las ciudades sin configurar (por defecto, sí);
 *   - `shipping.reserve_name`: nombre de la zona y de la tarifa de reserva (lo que ve el cliente);
 *   - `shipping.reserve_cost`: coste, en la moneda de la tienda (0 = gratis).
 *
 * Solo toca la zona que ella misma guardó (su id va en la opción `hwe_shipping_reserve_zone_id`): las
 * demás zonas de la tienda no se tocan. De esa zona quita los métodos `flat_rate` (el método que creaba
 * antes) y deja el de `hwe_city_rate`. El método lee nombre y coste en cada cálculo, así que no hace
 * falta escribir sus ajustes de WooCommerce.
 */
class ReserveZone {

	public const OPTION        = 'hwe_shipping_reserve_zone_id';
	private const DEFAULT_NAME = 'Envío nacional';

	public static function register(): void {
		add_action( 'hwe_control_center_saved', array( self::class, 'sync' ) );
	}

	/** ¿Se envía también a las ciudades sin configurar? (`shipping.reserve_enabled`, por defecto sí). */
	public static function enabled(): bool {
		return (bool) self::setting( 'reserve_enabled', true );
	}

	/** Nombre de la zona y de la tarifa de reserva. */
	public static function name(): string {
		$name = trim( (string) self::setting( 'reserve_name', self::DEFAULT_NAME ) );

		return '' === $name ? self::DEFAULT_NAME : $name;
	}

	/** Coste de la tarifa de reserva, en la moneda de la tienda, redondeado y no negativo. */
	public static function cost(): float {
		return max( 0.0, Pricing::round( (float) self::setting( 'reserve_cost', 0 ) ) );
	}

	/** Lee un ajuste del Control Center (`shipping.<clave>`) con el valor por defecto del módulo. */
	private static function setting( string $key, mixed $default ): mixed {
		if ( ! class_exists( 'HWE\\ControlCenter\\Storage' ) ) {
			return $default;
		}
		$value = \HWE\ControlCenter\Storage::get( array( 'shipping', $key ) );
		return null === $value || '' === $value ? $default : $value;
	}

	/**
	 * Crea o actualiza la zona de reserva. Idempotente. Sin WooCommerce no hace nada. Un fallo se
	 * registra y no rompe el guardado del panel.
	 */
	public static function sync(): void {
		if ( ! class_exists( 'WC_Shipping_Zone' ) ) {
			return;
		}

		$country = ShippingCost::baseCountry();
		if ( '' === $country ) {
			error_log( '[hwe] shipping: no hay país de la tienda (ecommerce.country): no se crea la zona de reserva.' );
			return;
		}

		try {
			$zone = self::zone();
			$zone->set_zone_name( self::name() );
			$zone->clear_locations();
			$zone->add_location( $country, 'country' );
			$zone->save();
			update_option( self::OPTION, (int) $zone->get_id(), false );

			self::useCityRateMethod( $zone );

			// Los carritos abiertos guardan las tarifas ya calculadas en la sesión.
			if ( class_exists( '\WC_Cache_Helper' ) ) {
				\WC_Cache_Helper::get_transient_version( 'shipping', true );
			}
		} catch ( \Throwable $e ) {
			error_log( '[hwe] shipping: no se pudo sincronizar la zona de reserva: ' . $e->getMessage() );
		}
	}

	/**
	 * Quita de la zona los `flat_rate` y deja exactamente un `hwe_city_rate`. Un método ya presente
	 * se conserva tal cual (sus ajustes son de WooCommerce).
	 */
	private static function useCityRateMethod( \WC_Shipping_Zone $zone ): void {
		$hasCityRate = false;
		foreach ( $zone->get_shipping_methods() as $method ) {
			if ( 'flat_rate' === $method->id ) {
				$zone->delete_shipping_method( $method->instance_id );
			} elseif ( CityRateMethod::ID === $method->id ) {
				$hasCityRate = true;
			}
		}

		if ( ! $hasCityRate ) {
			$zone->add_shipping_method( CityRateMethod::ID );
		}
		$zone->save();
	}

	/** La zona que creó el plugin, o una nueva si no existe (o se borró a mano). */
	private static function zone(): \WC_Shipping_Zone {
		$id = (int) get_option( self::OPTION, 0 );
		if ( $id > 0 ) {
			$zone = new \WC_Shipping_Zone( $id );
			if ( (int) $zone->get_id() === $id ) {
				return $zone;
			}
		}
		return new \WC_Shipping_Zone();
	}
}
