<?php

namespace HWE\Shipping\Distance;

use HWE\Shipping\Rates\GeoPoint;

/**
 * Un candidato de geocodificación: coordenada, dirección legible y precisión que informa el
 * proveedor (p. ej. «rooftop», «street», «city»; el texto depende del proveedor). Inmutable.
 *
 * `provider` lo fija `DistanceService` con el id de quien respondió de verdad (el de mapas o
 * `nominatim` en el respaldo): es lo que el editor de la ciudad enseña al validar la dirección.
 */
final class GeocodeResult {

	private GeoPoint $point;
	private string $label;
	private string $precision;
	private string $provider;

	public function __construct( GeoPoint $point, string $label, string $precision = '', string $provider = '' ) {
		$this->point     = $point;
		$this->label     = $label;
		$this->precision = $precision;
		$this->provider  = $provider;
	}

	public function point(): GeoPoint {
		return $this->point;
	}

	public function label(): string {
		return $this->label;
	}

	public function precision(): string {
		return $this->precision;
	}

	/** Id del proveedor que respondió (`google_maps`, `openrouteservice`, `nominatim`…). */
	public function provider(): string {
		return $this->provider;
	}

	/** Copia con el proveedor que la atendió. Un proveedor no declara quién es en cada resultado. */
	public function withProvider( string $provider ): self {
		return new self( $this->point, $this->label, $this->precision, $provider );
	}
}
