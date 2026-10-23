<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\Shipping\Distance\DistanceResult;
use HWE\Shipping\Distance\Haversine;
use HWE\Shipping\Rates\GeoPoint;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Línea recta (`Haversine`): la distancia sobre la esfera, el incremento y la duración estimada
 * por modo de viaje. Es el respaldo de todos los proveedores, así que sus números importan.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HaversineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\stubs([
            'plugin_dir_url'           => static fn () => '',
            'register_activation_hook' => static fn () => null,
            'is_admin'                 => static fn () => false,
        ]);
        $this->loadOwnFile('plugins/hwe-shipping/hwe-shipping.php');
    }

    public function testLaDistanciaEntreDosCiudadesConocidasEsRazonable(): void
    {
        // París → Londres: unos 343,6 km en línea recta.
        $paris  = new GeoPoint(48.8566, 2.3522);
        $london = new GeoPoint(51.5074, -0.1278);

        $this->assertEqualsWithDelta(343.6, Haversine::km($paris, $london), 1.0);
    }

    public function testUnGradoDeEcuadorEquivaleAUnos111Kilometros(): void
    {
        $this->assertEqualsWithDelta(111.195, Haversine::km(new GeoPoint(0.0, 0.0), new GeoPoint(0.0, 1.0)), 0.01);
    }

    public function testUnPuntoAUnoMismoDistanciaCero(): void
    {
        $this->assertSame(0.0, Haversine::km(new GeoPoint(4.0, -74.0), new GeoPoint(4.0, -74.0)));
    }

    public function testLaEstimacionAplicaElIncrementoSobreLaLineaRecta(): void
    {
        $result = Haversine::estimate(new GeoPoint(0.0, 0.0), new GeoPoint(0.0, 1.0), 30, 'driving');

        $this->assertInstanceOf(DistanceResult::class, $result);
        $this->assertEqualsWithDelta(111.195 * 1.3, $result->km(), 0.01);
        $this->assertSame('straight_line', $result->provider());
        $this->assertFalse($result->fallback(), 'la línea recta es el método elegido, no un respaldo');
        $this->assertSame('', $result->fallbackReason());
    }

    public function testUnIncrementoCeroNoAñadeNada(): void
    {
        $result = Haversine::estimate(new GeoPoint(0.0, 0.0), new GeoPoint(0.0, 1.0), 0, 'driving');

        $this->assertEqualsWithDelta(111.195, $result->km(), 0.01);
    }

    public function testLaDuracionConducindoEsAUnaVelocidadMediaDe30KilometrosPorHora(): void
    {
        // 111,195 km a 30 km/h = 222,4 min → 222.
        $result = Haversine::estimate(new GeoPoint(0.0, 0.0), new GeoPoint(0.0, 1.0), 0, 'driving');

        $this->assertSame(222, $result->durationMin());
    }

    public function testLaDuracionCaminandoEsAUnaVelocidadMediaDe5KilometrosPorHora(): void
    {
        // 111,195 km a 5 km/h = 1334,3 min → 1334.
        $result = Haversine::estimate(new GeoPoint(0.0, 0.0), new GeoPoint(0.0, 1.0), 0, 'walking');

        $this->assertSame(1334, $result->durationMin());
    }

    public function testUnModoDeViajeDesconocidoEsUnErrorDeProgramacion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Haversine::estimate(new GeoPoint(0.0, 0.0), new GeoPoint(0.0, 1.0), 30, 'volando');
    }
}
