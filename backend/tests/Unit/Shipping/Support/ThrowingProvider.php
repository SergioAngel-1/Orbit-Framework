<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping\Support;

use HWE\Shipping\Distance\DistanceResult;
use HWE\Shipping\Distance\GeocodeResult;
use HWE\Shipping\Distance\Provider;
use HWE\Shipping\Rates\GeoPoint;

/**
 * Proveedor de prueba que lanza una excepción en todas sus operaciones. El mensaje de la excepción
 * simula un texto que podría contener datos sensibles: el servicio jamás debe registrarlo.
 */
final class ThrowingProvider implements Provider
{
    public const MESSAGE = 'token-secreto-de-prueba-no-registrar';

    public function __construct(private readonly string $id)
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function distance(GeoPoint $from, GeoPoint $to, string $mode): DistanceResult|\WP_Error
    {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function geocode(string $query, string $country, string $language): array|\WP_Error
    {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function reverse(GeoPoint $point, string $language): GeocodeResult|\WP_Error
    {
        throw new \RuntimeException(self::MESSAGE);
    }
}
