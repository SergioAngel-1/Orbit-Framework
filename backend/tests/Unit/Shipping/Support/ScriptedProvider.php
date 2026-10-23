<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping\Support;

use HWE\Shipping\Distance\DistanceResult;
use HWE\Shipping\Distance\GeocodeResult;
use HWE\Shipping\Distance\Provider;
use HWE\Shipping\Rates\GeoPoint;

/**
 * Proveedor de prueba que implementa la interfaz real: responde lo que le digan los tests y
 * cuenta cuántas veces se le llama. Sirve para comprobar la caché y el respaldo del servicio sin
 * tocar la red.
 */
final class ScriptedProvider implements Provider
{
    public int $distanceCalls = 0;
    public int $reverseCalls  = 0;

    /** @var list<string> */
    public array $geocodeQueries = [];

    /**
     * @param DistanceResult|\WP_Error   $distance Respuesta de `distance()`.
     * @param GeocodeResult[]|\WP_Error  $geocode  Respuesta de `geocode()`.
     * @param GeocodeResult|\WP_Error    $reverse  Respuesta de `reverse()`.
     */
    public function __construct(
        private readonly string $id,
        private readonly mixed $distance,
        private readonly mixed $geocode = [],
        private readonly mixed $reverse = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function distance(GeoPoint $from, GeoPoint $to, string $mode): DistanceResult|\WP_Error
    {
        $this->distanceCalls++;
        return $this->distance;
    }

    public function geocode(string $query, string $country, string $language): array|\WP_Error
    {
        $this->geocodeQueries[] = $query;
        return $this->geocode;
    }

    public function reverse(GeoPoint $point, string $language): GeocodeResult|\WP_Error
    {
        $this->reverseCalls++;
        return $this->reverse ?? new \WP_Error('provider_unavailable', 'Sin respuesta preparada.');
    }
}
