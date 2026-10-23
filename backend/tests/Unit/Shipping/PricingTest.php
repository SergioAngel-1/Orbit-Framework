<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\Shipping\Rates\DeliveryOption;
use HWE\Shipping\Rates\Pricing;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Precio de una opción de entrega (`Pricing`): la única implementación del coste. Es pura; solo
 * necesita la precisión de la moneda de WooCommerce y el autoload del plugin.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\stubs([
            'wc_get_price_decimals'    => static fn () => 2,
            'plugin_dir_url'           => static fn () => '',
            'register_activation_hook' => static fn () => null,
            'is_admin'                 => static fn () => false,
        ]);
        $this->loadOwnFile('plugins/hwe-shipping/hwe-shipping.php');
    }

    private function fixed(float $amount): DeliveryOption
    {
        return new DeliveryOption('fija0001', 'Estándar', false, $amount, null);
    }

    private function perKm(float $pricePerKm, float $min = 0.0, float $max = 0.0): DeliveryOption
    {
        return new DeliveryOption('km000001', 'Por km', false, null, $pricePerKm, $min, $max);
    }

    public function testUnaOpcionFijaDevuelveSuImporteSinImportarLaDistancia(): void
    {
        $this->assertSame(1500.0, Pricing::cost($this->fixed(1500.0), null));
        $this->assertSame(1500.0, Pricing::cost($this->fixed(1500.0), 12.4));
    }

    public function testPorKilometroEsKmPorPrecioPorKm(): void
    {
        $this->assertSame(4350.0, Pricing::cost($this->perKm(1000.0), 4.35));
    }

    public function testElMinimoElevaElCosteCuandoLaDistanciaEsCorta(): void
    {
        $this->assertSame(3000.0, Pricing::cost($this->perKm(1000.0, 3000.0, 0.0), 1.2));
    }

    public function testElMaximoRecortaElCosteCuandoLaDistanciaEsLarga(): void
    {
        $this->assertSame(5000.0, Pricing::cost($this->perKm(1000.0, 0.0, 5000.0), 10.0));
    }

    public function testUnCosteEntreMinimoYMaximoNoSeTocaYCeroNoLimita(): void
    {
        $this->assertSame(5000.0, Pricing::cost($this->perKm(1000.0, 3000.0, 8000.0), 5.0));
        $this->assertSame(500.0, Pricing::cost($this->perKm(1000.0), 0.5), 'mínimo y máximo a 0 = sin límite');
        $this->assertSame(50000.0, Pricing::cost($this->perKm(1000.0), 50.0), 'sin tope superior');
    }

    public function testElCosteSeRedondeaALosDecimalesDeLaMoneda(): void
    {
        $this->assertSame(1.0, Pricing::cost($this->perKm(0.333), 3.0), '3 × 0,333 = 0,999 → 1,00 con dos decimales');
    }

    public function testConMonedaSinDecimalesSeRedondeaAEnteros(): void
    {
        Functions\when('wc_get_price_decimals')->justReturn(0);

        $this->assertSame(1000.0, Pricing::cost($this->perKm(333.4), 3.0), '1000,2 → 1000 sin decimales');
    }

    public function testUnaOpcionPorDistanciaSinKilometrosEsUnErrorDeProgramacion(): void
    {
        $this->expectException(\LogicException::class);

        Pricing::cost($this->perKm(1000.0), null);
    }

    public function testUnaOpcionSinImporteNiPrecioPorKmEsUnErrorDeProgramacion(): void
    {
        $this->expectException(\LogicException::class);

        Pricing::cost(new DeliveryOption('vacia001', 'Vacía', false, null, null), 3.0);
    }
}
