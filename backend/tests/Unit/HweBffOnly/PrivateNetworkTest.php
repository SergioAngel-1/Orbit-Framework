<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweBffOnly;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * La heurística de red (sin X-Forwarded-For + IP privada) NO identifica al BFF por
 * defecto: es una suposición sobre la red, no una credencial, y confía en cualquier
 * contenedor, VPN o proxy mal configurado que comparta red con WordPress. Solo se activa
 * con `HWE_BFF_TRUST_PRIVATE_NETWORK`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PrivateNetworkTest extends HweBffOnlyTestCase
{
    protected function setUp(): void
    {
        define('HWE_REVALIDATION_SECRET', self::SECRET);
        define('REST_REQUEST', true);
        parent::setUp();
    }

    /** @return iterable<string, array{string}> */
    public static function privateAddresses(): iterable
    {
        yield 'red de Docker' => ['172.18.0.5'];
        yield 'RFC 1918 (10/8)' => ['10.0.0.8'];
        yield 'RFC 1918 (192.168/16)' => ['192.168.1.20'];
        yield 'loopback' => ['127.0.0.1'];
    }

    #[DataProvider('privateAddresses')]
    public function testByDefaultAPrivateAddressDoesNotIdentifyTheBff(string $remote): void
    {
        $_SERVER['REMOTE_ADDR'] = $remote;

        $this->assertFalse($this->graphqlAllowed());
        $this->assertInstanceOf(\WP_Error::class, $this->dispatch('/wc/store/v1/cart'));
    }
}
