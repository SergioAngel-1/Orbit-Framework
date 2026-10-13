<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweBffOnly;

use Brain\Monkey\Filters;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * La excepción de la integración externa (módulo `external-integration`): GraphQL, y SOLO
 * GraphQL, atiende a las IPs de `HWE_INTEGRATION_ALLOWED_IPS`, y solo con el módulo activo.
 * La decisión se toma sobre `REMOTE_ADDR`, nunca sobre una cabecera que mande el cliente.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IntegrationGateTest extends HweBffOnlyTestCase
{
    protected function setUp(): void
    {
        define('REST_REQUEST', true);
        define('HWE_REVALIDATION_SECRET', self::SECRET);
        parent::setUp();
    }

    /** El módulo activo enciende la puerta con su filtro. */
    private function moduleActive(): void
    {
        Filters\expectApplied('hwe_integration_gate_enabled')->andReturn(true);
    }

    public function testSinElModuloUnaIpDeLaListaNoAbreNada(): void
    {
        define('HWE_INTEGRATION_ALLOWED_IPS', '198.51.100.7');
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        $this->assertFalse($this->graphqlAllowed());
        $this->assertFalse(hwe_bff_only_is_integration_call());
    }

    public function testConElModuloUnaIpDeLaListaAbreGraphql(): void
    {
        define('HWE_INTEGRATION_ALLOWED_IPS', '198.51.100.7');
        $this->moduleActive();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        $this->assertTrue($this->graphqlAllowed());
    }

    public function testUnaIpAjenaSigueRecibiendoUn404(): void
    {
        define('HWE_INTEGRATION_ALLOWED_IPS', '198.51.100.7');
        $this->moduleActive();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

        $this->assertFalse($this->graphqlAllowed());
    }

    public function testUnaCabeceraForwardedForNoSustituyeAlSocket(): void
    {
        define('HWE_INTEGRATION_ALLOWED_IPS', '198.51.100.7');
        $this->moduleActive();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';

        $this->assertFalse($this->graphqlAllowed());
    }

    public function testLaStoreApiYLaAutenticacionSiguenCerradasParaLaIpAutorizada(): void
    {
        define('HWE_INTEGRATION_ALLOWED_IPS', '198.51.100.7');
        $this->moduleActive();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        foreach (['/wc/store/v1/cart', '/hwe/v1/auth/me', '/hwe/v1/consent/record'] as $route) {
            $this->assertInstanceOf(\WP_Error::class, $this->dispatch($route), $route);
        }
    }

    public function testAdmiteVariasIpsSeparadasPorEspaciosOComas(): void
    {
        define('HWE_INTEGRATION_ALLOWED_IPS', "198.51.100.7, 203.0.113.20\n192.0.2.5");
        $this->moduleActive();

        foreach (['198.51.100.7', '203.0.113.20', '192.0.2.5'] as $ip) {
            $_SERVER['REMOTE_ADDR'] = $ip;
            $this->assertTrue($this->graphqlAllowed(), $ip);
        }
        $_SERVER['REMOTE_ADDR'] = '198.51.100';
        $this->assertFalse($this->graphqlAllowed(), 'una IP parcial no coincide');
    }

    public function testSinLaConstanteOVaciaNadieEntraPorIp(): void
    {
        $this->moduleActive();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        $this->assertFalse($this->graphqlAllowed());
    }

    public function testUnaListaVaciaNoAbreNada(): void
    {
        define('HWE_INTEGRATION_ALLOWED_IPS', '  ');
        $this->moduleActive();
        $_SERVER['REMOTE_ADDR'] = '';

        $this->assertFalse($this->graphqlAllowed());
    }

    public function testElBffConSuSecretoSigueEntrandoSinLaIp(): void
    {
        define('HWE_INTEGRATION_ALLOWED_IPS', '198.51.100.7');
        $this->moduleActive();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->sendSecret(self::SECRET);

        $this->assertTrue($this->graphqlAllowed());
        $this->assertNull($this->dispatch('/wc/store/v1/cart'));
    }
}
