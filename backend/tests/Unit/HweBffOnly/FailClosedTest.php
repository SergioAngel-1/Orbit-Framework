<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweBffOnly;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Sin `HWE_REVALIDATION_SECRET` no hay forma de identificar al BFF: falla CERRADO (solo
 * WP-CLI) y lo registra, en vez de aceptar a cualquiera. Un secreto vacío no es un
 * secreto: «cabecera vacía == secreto vacío» no puede abrir la puerta.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FailClosedTest extends HweBffOnlyTestCase
{
    protected function setUp(): void
    {
        define('REST_REQUEST', true);
        parent::setUp();
    }

    public function testWithoutTheConstantNothingGetsIn(): void
    {
        $this->sendSecret(self::SECRET);

        $this->assertFalse($this->graphqlAllowed());
        $this->assertInstanceOf(\WP_Error::class, $this->dispatch('/wc/store/v1/cart'));
    }

    public function testWithoutTheConstantTheMisconfigurationIsLoggedOnce(): void
    {
        $logged = [];
        Functions\when('error_log')->alias(function (string $message) use (&$logged): bool {
            $logged[] = $message;
            return true;
        });

        for ($i = 0; $i < 10; $i++) {
            $this->graphqlAllowed();
            $this->dispatch('/hwe/v1/auth/me');
        }

        $this->assertCount(1, $logged);
        $this->assertStringContainsString('HWE_REVALIDATION_SECRET', $logged[0]);
    }
}
