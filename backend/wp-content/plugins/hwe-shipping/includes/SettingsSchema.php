<?php

namespace HWE\Shipping;

use HWE\Shipping\Distance\Settings;

/**
 * Campos del módulo en el grupo «Envío» del Control Center (el grupo es del núcleo y viene vacío).
 *
 * - Zona de reserva (`reserve_*`): ver `ReserveZone`.
 * - Distancia (`distance_*`): solo aplican a las ciudades con envío por distancia. El Control Center
 *   no tiene campos condicionales, así que la descripción lo dice. Los valores por defecto son los
 *   que lee `Settings`.
 */
class SettingsSchema {

	public static function register(): void {
		add_filter( 'hwe_control_center_schema', array( self::class, 'schema' ) );
	}

	/**
	 * @param mixed $schema
	 * @return mixed
	 */
	public static function schema( $schema ) {
		if ( ! is_array( $schema ) || ! isset( $schema['shipping']['children'] ) || ! is_array( $schema['shipping']['children'] ) ) {
			return $schema;
		}
		$schema['shipping']['children'] += array(
			'reserve_enabled'                  => array(
				'type'        => 'boolean',
				'label'       => 'Zona de reserva de WooCommerce',
				'default'     => true,
				'description' => 'Crea y mantiene una zona de envío que cubre el país de la tienda, con un único método de tarifa plana: el envío de las ciudades que no tienen tarifa propia. Sin una zona que cubra el país, el carrito no tiene envío.',
			),
			'reserve_name'                     => array(
				'type'        => 'text',
				'label'       => 'Nombre del envío de reserva',
				'default'     => 'Envío nacional',
				'description' => 'Lo que ve el cliente en el carrito para las ciudades sin tarifa propia.',
			),
			'reserve_cost'                     => array(
				'type'        => 'number',
				'label'       => 'Coste del envío de reserva',
				'default'     => '0',
				'min'         => 0,
				'description' => 'En la moneda de la tienda, sin puntos ni decimales. 0 = envío gratis para las ciudades sin tarifa propia.',
			),
			'distance_method'                  => array(
				'type'        => 'select',
				'label'       => 'Distancia — Método de cálculo',
				'default'     => Settings::DEFAULT_METHOD,
				'options'     => array(
					'maps'          => 'Mapas (proveedor de Integraciones → Mapas)',
					'straight_line' => 'Línea recta',
				),
				'description' => 'Solo aplica a las ciudades con envío por distancia. Con «Mapas» se usa el proveedor y la clave de Integraciones → Mapas; si faltan, se calcula en línea recta y wp-admin avisa.',
			),
			'distance_travel_mode'             => array(
				'type'        => 'select',
				'label'       => 'Distancia — Modo de viaje',
				'default'     => Settings::DEFAULT_TRAVEL_MODE,
				'options'     => array(
					'driving' => 'Conduciendo',
					'walking' => 'Caminando',
				),
				'description' => 'Solo aplica a las ciudades con envío por distancia. Lo usan el proveedor de mapas y la estimación de duración en línea recta.',
			),
			'distance_straight_line_increment' => array(
				'type'        => 'number',
				'label'       => 'Distancia — Incremento de la línea recta (%)',
				'default'     => Settings::DEFAULT_INCREMENT_PCT,
				'min'         => 0,
				'max'         => 200,
				'description' => 'Porcentaje que se suma a la línea recta para estimar la ruta. Se aplica también cuando la línea recta es el respaldo de un proveedor que falla.',
			),
			'distance_cache_ttl_hours'         => array(
				'type'        => 'number',
				'label'       => 'Distancia — Caché (horas)',
				'default'     => Settings::DEFAULT_CACHE_TTL_HOURS,
				'min'         => 1,
				'description' => 'Horas que se guarda una distancia calculada antes de volver a pedirla.',
			),
		);
		return $schema;
	}
}
