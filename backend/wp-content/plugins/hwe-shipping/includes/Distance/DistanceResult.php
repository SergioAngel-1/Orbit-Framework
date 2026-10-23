<?php

namespace HWE\Shipping\Distance;

/**
 * Resultado de una distancia: km, duración estimada y de qué proveedor sale. Si `fallback` es
 * verdadero, el cálculo no vino del proveedor elegido sino de la línea recta, y `fallbackReason`
 * dice por qué (código del error). Inmutable.
 */
final class DistanceResult {

	private float $km;
	private int $durationMin;
	private string $provider;
	private bool $fallback;
	private string $fallbackReason;

	public function __construct(
		float $km,
		int $durationMin,
		string $provider,
		bool $fallback = false,
		string $fallbackReason = ''
	) {
		if ( ! is_finite( $km ) || $km < 0 ) {
			throw new \InvalidArgumentException( 'Distancia no válida.' );
		}
		if ( $durationMin < 0 ) {
			throw new \InvalidArgumentException( 'Duración no válida.' );
		}

		$this->km             = $km;
		$this->durationMin    = $durationMin;
		$this->provider       = $provider;
		$this->fallback       = $fallback;
		$this->fallbackReason = $fallbackReason;
	}

	public function km(): float {
		return $this->km;
	}

	public function durationMin(): int {
		return $this->durationMin;
	}

	public function provider(): string {
		return $this->provider;
	}

	public function fallback(): bool {
		return $this->fallback;
	}

	public function fallbackReason(): string {
		return $this->fallbackReason;
	}
}
