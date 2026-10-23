<?php

namespace HWE\Shipping;

/**
 * Zona de reserva de WooCommerce: el envío para el resto del país.
 *
 * Las tarifas por ciudad (`ShippingCost`) solo SOBREESCRIBEN el coste del método `flat_rate`
 * de una zona de WooCommerce; si la tienda no tiene ninguna zona que cubra el país, la Store
 * API devuelve `shipping_rates: []` y el checkout cierra pedidos con envío 0. Esta clase crea
 * (y mantiene) esa zona desde el Control Center: una zona que cubre el país base de la tienda
 * (`ecommerce.country`), con un único método `flat_rate` cuyo coste es el envío de las ciudades
 * SIN tarifa propia.
 *
 * Se configura en el Control Center → Envío (los campos los declara `SettingsSchema`):
 *   - `shipping.reserve_enabled`: crear y mantener la zona (por defecto, sí);
 *   - `shipping.reserve_name`: nombre de la zona y del método (lo que ve el cliente);
 *   - `shipping.reserve_cost`: coste, en la moneda de la tienda (0 = gratis).
 *
 * Solo toca la zona que ella misma creó (su id va en la opción `hwe_shipping_reserve_zone_id`):
 * las demás zonas de la tienda no se tocan. Apagarla no borra la zona. El Control Center es la
 * fuente de verdad: un cambio a mano del coste de ese método se pisa al guardar el panel.
 */
class ReserveZone {

	public const OPTION = 'hwe_shipping_reserve_zone_id';

	public static function register(): void {
		add_action( 'hwe_control_center_saved', array( self::class, 'sync' ) );
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
	 * Crea o actualiza la zona de reserva. Idempotente. Sin WooCommerce o con la zona apagada
	 * no hace nada. Un fallo se registra y no rompe el guardado del panel.
	 */
	public static function sync(): void {
		if ( ! class_exists( 'WC_Shipping_Zone' ) ) {
			return;
		}
		if ( ! (bool) self::setting( 'reserve_enabled', true ) ) {
			return;
		}

		$country = ShippingCost::baseCountry();
		if ( '' === $country ) {
			error_log( '[hwe] shipping: no hay país de la tienda (ecommerce.country): no se crea la zona de reserva.' );
			return;
		}
		$name = trim( (string) self::setting( 'reserve_name', 'Envío nacional' ) );
		$name = '' === $name ? 'Envío nacional' : $name;
		$cost = max( 0, (int) round( (float) self::setting( 'reserve_cost', 0 ) ) );

		try {
			$zone = self::zone();
			$zone->set_zone_name( $name );
			$zone->clear_locations();
			$zone->add_location( $country, 'country' );
			$zone->save();
			update_option( self::OPTION, (int) $zone->get_id(), false );

			$method = null;
			foreach ( $zone->get_shipping_methods() as $candidate ) {
				if ( 'flat_rate' === $candidate->id ) {
					$method = $candidate;
					break;
				}
			}
			if ( null === $method ) {
				$zone->add_shipping_method( 'flat_rate' );
				$zone->save();
				foreach ( $zone->get_shipping_methods() as $candidate ) {
					if ( 'flat_rate' === $candidate->id ) {
						$method = $candidate;
						break;
					}
				}
			}
			if ( null === $method ) {
				error_log( '[hwe] shipping: no se pudo añadir el método de tarifa plana a la zona de reserva.' );
				return;
			}

			$key      = 'woocommerce_flat_rate_' . $method->instance_id . '_settings';
			$settings = get_option( $key, array() );
			update_option(
				$key,
				array_merge(
					is_array( $settings ) ? $settings : array(),
					array(
						'title'      => $name,
						'tax_status' => 'none',
						'cost'       => (string) $cost,
					)
				)
			);

			// Los carritos abiertos guardan las tarifas ya calculadas en la sesión.
			if ( class_exists( '\WC_Cache_Helper' ) ) {
				\WC_Cache_Helper::get_transient_version( 'shipping', true );
			}
		} catch ( \Throwable $e ) {
			error_log( '[hwe] shipping: no se pudo sincronizar la zona de reserva: ' . $e->getMessage() );
		}
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
