<?php

namespace HWE\Shipping\Rates;

/**
 * Opción de entrega de una ciudad («Estándar», «Express»…), inmutable.
 *
 * El id es estable: se genera al crear la opción y no cambia al renombrarla, para que una tarifa
 * ya seleccionada en un carrito abierto siga apuntando a la misma opción. En una ciudad fija la
 * opción tiene `amount`; en una ciudad por distancia, `pricePerKm` con mínimo y máximo (0 = sin
 * límite). Lo que no cuadra con eso se rechaza al construir la opción.
 *
 * Se guarda en la meta `_hwe_shipping_city_options` como JSON con claves en snake_case.
 */
final class DeliveryOption {

	/** Id estable: minúsculas y dígitos. `D` evita que un salto de línea final lo cuele. */
	public const ID_PATTERN = '/^[a-z0-9]{4,32}$/D';

	private string $id;
	private string $name;
	private bool $freeEligible;
	private ?float $amount;
	private ?float $pricePerKm;
	private float $minCost;
	private float $maxCost;

	public function __construct(
		string $id,
		string $name,
		bool $freeEligible,
		?float $amount,
		?float $pricePerKm,
		float $minCost = 0.0,
		float $maxCost = 0.0
	) {
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			throw new \InvalidArgumentException( 'Id de opción no válido.' );
		}
		$name = trim( $name );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'Opción sin nombre.' );
		}
		foreach ( [ $amount, $pricePerKm, $minCost, $maxCost ] as $price ) {
			if ( null !== $price && ( ! is_finite( $price ) || $price < 0 ) ) {
				throw new \InvalidArgumentException( 'Precio no válido.' );
			}
		}
		if ( $minCost > 0 && $maxCost > 0 && $minCost > $maxCost ) {
			throw new \InvalidArgumentException( 'El mínimo supera al máximo.' );
		}

		$this->id           = $id;
		$this->name         = $name;
		$this->freeEligible = $freeEligible;
		$this->amount       = $amount;
		$this->pricePerKm   = $pricePerKm;
		$this->minCost      = $minCost;
		$this->maxCost      = $maxCost;
	}

	/** Id nuevo: 8 caracteres hexadecimales en minúsculas. */
	public static function newId(): string {
		return bin2hex( random_bytes( 4 ) );
	}

	/**
	 * Opción desde su forma guardada. Lanza `InvalidArgumentException` si está mal formada.
	 *
	 * @param array<string,mixed> $row
	 */
	public static function fromArray( array $row ): self {
		return new self(
			(string) ( $row['id'] ?? '' ),
			(string) ( $row['name'] ?? '' ),
			in_array( $row['free_eligible'] ?? false, [ true, 1, '1' ], true ),
			self::optionalNumber( $row['amount'] ?? null ),
			self::optionalNumber( $row['price_per_km'] ?? null ),
			self::optionalNumber( $row['min_cost'] ?? null ) ?? 0.0,
			self::optionalNumber( $row['max_cost'] ?? null ) ?? 0.0
		);
	}

	/**
	 * Lista de opciones desde el JSON de la meta. Lanza `InvalidArgumentException` si el JSON no
	 * es una lista de opciones válidas (una sola opción mala invalida la ciudad entera).
	 *
	 * @return self[]
	 */
	public static function listFromJson( string $json ): array {
		$rows = json_decode( $json, true );
		if ( ! is_array( $rows ) ) {
			throw new \InvalidArgumentException( 'Opciones de entrega ilegibles.' );
		}
		$options = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw new \InvalidArgumentException( 'Opción de entrega mal formada.' );
			}
			$options[] = self::fromArray( $row );
		}
		return $options;
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'id'            => $this->id,
			'name'          => $this->name,
			'free_eligible' => $this->freeEligible,
			'amount'        => $this->amount,
			'price_per_km'  => $this->pricePerKm,
			'min_cost'      => $this->minCost,
			'max_cost'      => $this->maxCost,
		];
	}

	public function id(): string {
		return $this->id;
	}

	public function name(): string {
		return $this->name;
	}

	public function isFreeEligible(): bool {
		return $this->freeEligible;
	}

	public function amount(): ?float {
		return $this->amount;
	}

	public function pricePerKm(): ?float {
		return $this->pricePerKm;
	}

	public function minCost(): float {
		return $this->minCost;
	}

	public function maxCost(): float {
		return $this->maxCost;
	}

	/** Null para vacío o nulo; lanza si hay algo que no es numérico. */
	private static function optionalNumber( mixed $value ): ?float {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_numeric( $value ) ) {
			throw new \InvalidArgumentException( 'Precio no numérico.' );
		}
		return (float) $value;
	}
}
