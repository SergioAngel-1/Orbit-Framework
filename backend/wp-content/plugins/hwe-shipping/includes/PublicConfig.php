<?php

namespace HWE\Shipping;

use HWE\Shipping\Rates\Cities;

/**
 * Valores calculados del módulo que el HWE Control Center publica en `/wp-json/hwe/v1/config`, a través del
 * filtro `hwe_control_center_public_config`. Solo lo que el frontend necesita para decidir la interfaz; nunca
 * secretos ni datos de la ciudad (referencias, importes).
 *
 * `shipping.distance_enabled`: hay al menos una ciudad por distancia. Entonces el mapa del pin es obligatorio
 * para esas ciudades (spec §8.1). Con el módulo apagado el plugin no carga y la clave no aparece.
 */
final class PublicConfig {

	public const FILTER = 'hwe_control_center_public_config';

	public static function register(): void {
		add_filter( self::FILTER, [ self::class, 'add' ] );
	}

	/**
	 * @param array<string,mixed> $public Config pública del Control Center.
	 * @return array<string,mixed>
	 */
	public static function add( array $public ): array {
		if ( ! isset( $public['shipping'] ) || ! is_array( $public['shipping'] ) ) {
			$public['shipping'] = [];
		}
		$public['shipping']['distance_enabled'] = Cities::hasDistanceCities();

		return $public;
	}
}
