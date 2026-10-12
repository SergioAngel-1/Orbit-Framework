<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweBffOnly;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Con `HWE_BFF_TRUST_PRIVATE_NETWORK` activada se vuelve a aceptar la heurística de red. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PrivateNetworkEnabledTest extends HweBffOnlyTestCase
{
    protected function setUp(): void
    {
        define('HWE_REVALIDATION_SECRET', self::SECRET);
        define('HWE_BFF_TRUST_PRIVATE_NETWORK', true);
        define('REST_REQUEST', true);
        parent::setUp();
    }

    public function testAPrivateAddressWithoutForwardingPasses(): void
    {
        $_SERVER['REMOTE_ADDR'] = '172.18.0.5';

        $this->assertTrue($this->graphqlAllowed());
        $this->assertNull($this->dispatch('/wc/store/v1/cart'));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function notInternal(): iterable
    {
        yield 'con X-Forwarded-For (viene del proxy público)' => [[
            'REMOTE_ADDR' => '172.18.0.5',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]];
        yield 'IP pública' => [['REMOTE_ADDR' => '203.0.113.9']];
        yield 'sin REMOTE_ADDR' => [[]];
        yield 'REMOTE_ADDR que no es una IP' => [['REMOTE_ADDR' => 'no-es-una-ip']];
    }

    /** @param array<string, string> $server */
    #[DataProvider('notInternal')]
    public function testOtherOriginsStillNeedTheSecret(array $server): void
    {
        $_SERVER = $server;

        $this->assertFalse($this->graphqlAllowed());
        $this->assertInstanceOf(\WP_Error::class, $this->dispatch('/wc/store/v1/cart'));
    }
}
