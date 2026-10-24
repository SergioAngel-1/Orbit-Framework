<?php
/**
 * Acceso anticipado (spec §7.6).
 *
 * Beneficio de tipo serie sin campos propios: el nivel solo lo enciende (`enabled`). Mientras la
 * fecha de apertura de un producto esté en el futuro, solo los socios con este beneficio activo y
 * con el rango requerido ven la ficha completa y pueden comprarlo; los demás ven la ficha de
 * escaparate con la fecha. Pasada la fecha, el producto vuelve a su requisito normal.
 *
 * La fecha y la decisión viven en `HWE_Memberships_Access`; aquí solo se declara la clave y su
 * representación pública (datos estructurados, nunca frases).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Benefit_Early_Access extends HWE_Memberships_Benefit {

	/** Clave estable del tipo. */
	public const KEY = 'early_access';

	public function key(): string {
		return self::KEY;
	}

	/** No tiene campos propios: el nivel solo enciende o apaga el beneficio. */
	public function fields(): array {
		return array();
	}

	public function publicData( int $levelId ): array {
		$config = $this->configFor( $levelId );

		return array(
			'key'     => $this->key(),
			'enabled' => null !== $config && ! empty( $config['enabled'] ),
		);
	}
}
