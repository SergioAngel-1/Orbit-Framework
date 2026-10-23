<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\Shipping\Distance\AddressNormalizer;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Normalizador de direcciones para el geocodificador (`AddressNormalizer::forGeocoding`). Las
 * reglas viven en el paquete de país (`geocoding`), no en el código: aquí se prueban con los
 * paquetes reales del núcleo, `CO` y `generic`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AddressNormalizerTest extends TestCase
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

    /** @return array<string,mixed> El paquete `CO` del núcleo. */
    private function colombia(): array
    {
        return require HWE_WP_CONTENT_DIR . '/mu-plugins/hwe-geo/packs/co.php';
    }

    /** @return array<string,mixed> El paquete `generic` del núcleo. */
    private function generic(): array
    {
        return require HWE_WP_CONTENT_DIR . '/mu-plugins/hwe-geo/packs/generic.php';
    }

    public function testExpandeLaViaYDescartaBisCardinalesLetrasYAlMenosElComplemento(): void
    {
        $raw = 'Cl 45 A BIS Sur # 23 B - 12 C Norte, Apto 301';

        $this->assertSame('Calle 45 23 12', AddressNormalizer::forGeocoding($raw, $this->colombia()));
    }

    public function testConservaLaCiudadTrasLaComaYQuitaSoloElComplemento(): void
    {
        $this->assertSame(
            'Carrera 7 32-16, Bogotá',
            AddressNormalizer::forGeocoding('Cra 7 # 32-16, Bogotá', $this->colombia())
        );
        $this->assertSame(
            'Calle 26 5-6, Cali',
            AddressNormalizer::forGeocoding('Cll. 26 # 5-6, Torre 2, Cali', $this->colombia())
        );
    }

    public function testLasAbreviaturasNoDistinguenMayusculas(): void
    {
        $this->assertSame('Avenida 68 10-20', AddressNormalizer::forGeocoding('AVDA 68 # 10-20', $this->colombia()));
        $this->assertSame('Diagonal 1 2-3', AddressNormalizer::forGeocoding('dg 1 # 2-3', $this->colombia()));
    }

    public function testUnaComaFinalNoDejaSegmentosVacios(): void
    {
        $this->assertSame('Calle 1', AddressNormalizer::forGeocoding('Cl 1 #,', $this->colombia()));
    }

    public function testElPaqueteGenericoSoloLimpiaEspacios(): void
    {
        $raw = "  Calle   10  #  5-6 ,  Apto 2  ";

        $this->assertSame('Calle 10 # 5-6, Apto 2', AddressNormalizer::forGeocoding($raw, $this->generic()));
        $this->assertSame('Cl 45 A', AddressNormalizer::forGeocoding('Cl 45 A', $this->generic()));
    }

    public function testLaLetraSueltaTrasUnNumeroSeDescarta(): void
    {
        $this->assertSame('Calle 10 5-20', AddressNormalizer::forGeocoding('Calle 10 A # 5-20', $this->colombia()));
    }

    public function testLaLetraSueltaSinNumeroDelanteSeConserva(): void
    {
        $this->assertSame('Diagonal A 12', AddressNormalizer::forGeocoding('Diagonal A 12', $this->colombia()));
        $this->assertSame('Juan Y Pedro 7', AddressNormalizer::forGeocoding('Juan Y Pedro 7', $this->colombia()));
    }

    public function testLosDemasTokensDescartablesSiguenQuitandoseEnCualquierPosicion(): void
    {
        $this->assertSame('Calle 10 5-20', AddressNormalizer::forGeocoding('Calle 10 BIS # 5-20', $this->colombia()));
        $this->assertSame('Calle 10', AddressNormalizer::forGeocoding('Calle Norte 10', $this->colombia()));
    }

    public function testElGenericoNoDescartaLetrasSueltas(): void
    {
        $this->assertSame('Calle 10 A # 5-20', AddressNormalizer::forGeocoding('Calle 10 A # 5-20', $this->generic()));
    }

    public function testUnaEntradaVaciaDaCadenaVacia(): void
    {
        $this->assertSame('', AddressNormalizer::forGeocoding('   ', $this->colombia()));
        $this->assertSame('', AddressNormalizer::forGeocoding('', $this->generic()));
    }
}
