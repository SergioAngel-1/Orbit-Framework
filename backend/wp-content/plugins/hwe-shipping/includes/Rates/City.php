<?php

namespace HWE\Shipping\Rates;

/**
 * Ciudad configurada: un único tipo (fija o por distancia) y al menos una opción de entrega.
 * Inmutable; el constructor impone lo que el guardado ya exige, por si los datos llegan de otro
 * sitio (una meta editada a mano, por ejemplo).
 *
 * Una ciudad por distancia necesita un punto de referencia y un radio de cobertura > 0. Una ciudad
 * fija no usa el origen: sus opciones llevan `amount`, las de distancia, `pricePerKm`.
 */
final class City {

	public const TYPE_FIXED    = 'fixed';
	public const TYPE_DISTANCE = 'distance';

	private int $postId;
	private string $name;
	private string $type;
	/** @var DeliveryOption[] */
	private array $options;
	private ?GeoPoint $origin;
	private string $originLabel;
	private float $coverageKm;

	/** @param DeliveryOption[] $options */
	public function __construct(
		int $postId,
		string $name,
		string $type,
		array $options,
		?GeoPoint $origin,
		string $originLabel,
		float $coverageKm
	) {
		$name = trim( $name );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'Ciudad sin nombre.' );
		}
		if ( self::TYPE_FIXED !== $type && self::TYPE_DISTANCE !== $type ) {
			throw new \InvalidArgumentException( 'Tipo de tarifa no válido.' );
		}
		if ( [] === $options ) {
			throw new \InvalidArgumentException( 'Ciudad sin opciones de entrega.' );
		}
		foreach ( $options as $option ) {
			if ( ! $option instanceof DeliveryOption ) {
				throw new \InvalidArgumentException( 'Opción de entrega no válida.' );
			}
			if ( self::TYPE_FIXED === $type && null === $option->amount() ) {
				throw new \InvalidArgumentException( 'Opción fija sin importe.' );
			}
			if ( self::TYPE_DISTANCE === $type && null === $option->pricePerKm() ) {
				throw new \InvalidArgumentException( 'Opción por distancia sin precio por km.' );
			}
		}
		if ( self::TYPE_DISTANCE === $type && ( null === $origin || ! is_finite( $coverageKm ) || $coverageKm <= 0 ) ) {
			throw new \InvalidArgumentException( 'Ciudad por distancia sin referencia o sin radio válido.' );
		}

		$this->postId      = $postId;
		$this->name        = $name;
		$this->type        = $type;
		$this->options     = array_values( $options );
		$this->origin      = self::TYPE_DISTANCE === $type ? $origin : null;
		$this->originLabel = self::TYPE_DISTANCE === $type ? $originLabel : '';
		$this->coverageKm  = self::TYPE_DISTANCE === $type ? $coverageKm : 0.0;
	}

	public function postId(): int {
		return $this->postId;
	}

	public function name(): string {
		return $this->name;
	}

	public function type(): string {
		return $this->type;
	}

	public function isDistance(): bool {
		return self::TYPE_DISTANCE === $this->type;
	}

	/** @return DeliveryOption[] */
	public function options(): array {
		return $this->options;
	}

	public function origin(): ?GeoPoint {
		return $this->origin;
	}

	public function originLabel(): string {
		return $this->originLabel;
	}

	public function coverageKm(): float {
		return $this->coverageKm;
	}
}
