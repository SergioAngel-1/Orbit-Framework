<?php

namespace HWE\Shipping\Distance;

use HWE\Shipping\Rates\GeoPoint;

/**
 * Un candidato de geocodificación: coordenada, dirección legible y precisión que informa el
 * proveedor (p. ej. «rooftop», «street», «city»; el texto depende del proveedor). Inmutable.
 */
final class GeocodeResult {

	private GeoPoint $point;
	private string $label;
	private string $precision;

	public function __construct( GeoPoint $point, string $label, string $precision = '' ) {
		$this->point     = $point;
		$this->label     = $label;
		$this->precision = $precision;
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
}
