<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\RateLimit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Las llamadas del BFF no comparten el presupuesto por IP de `/wp-json/` ni de
 * `/graphql`.
 *
 * En producción el BFF habla con WordPress por la red interna: `REMOTE_ADDR` es
 * la IP del contenedor del frontend, la misma para todos los compradores. Sin una
 * excepción, unas pocas decenas de usuarios a la vez agotaban el límite y
 * WordPress devolvía 429 a toda la tienda (Store API, fidelización, pedidos).
 *
 * La excepción es una CREDENCIAL (`X-HWE-Internal-Secret` con
 * `HWE_REVALIDATION_SECRET`), la misma que ya exige `hwe-bff-only.php`: sin
 * cabecera, con un valor incorrecto o sin la constante definida, se cuenta como
 * hoy. El login nunca se exime.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BffExemptionTest extends RateLimitTestCase
{
    private const SECRET = 'secreto-interno-de-prueba-0123456789';

    private function defineSecret(): void
    {
        if (!defined('HWE_REVALIDATION_SECRET')) {
            define('HWE_REVALIDATION_SECRET', self::SECRET);
        }
    }

    public function testRestWithTheValidSecretIsNeverRejected(): void
    {
        $this->defineSecret();
        $this->withSecret(self::SECRET);

        // 121 peticiones: una más que el presupuesto por IP.
        $this->assertSame(121, $this->allowedBeforeBlocking('GET', '/wp-json/wp/v2/posts', 121));
        // ...y el presupuesto sigue intacto para la Store API.
        $this->assertTrue($this->request('GET', '/wp-json/wc/store/v1/cart'));
    }

    public function testGraphqlWithTheValidSecretIsNeverRejected(): void
    {
        $this->defineSecret();
        $this->withSecret(self::SECRET);

        $this->assertSame(61, $this->allowedBeforeBlocking('POST', '/graphql', 61));
    }

    public function testWithoutTheSecretTheRestLimitIsUnchanged(): void
    {
        $this->defineSecret();

        $this->assertSame(120, $this->allowedBeforeBlocking('GET', '/wp-json/wp/v2/posts', 200));
    }

    public function testAGraphqlRequestWithoutTheSecretStillHasTheOldLimit(): void
    {
        $this->defineSecret();

        $this->assertSame(60, $this->allowedBeforeBlocking('POST', '/graphql', 100));
    }

    public function testAWrongSecretCountsLikeNoSecret(): void
    {
        $this->defineSecret();
        $this->withSecret('secreto-equivocado-de-prueba');

        $this->assertSame(120, $this->allowedBeforeBlocking('GET', '/wp-json/wp/v2/posts', 200));
    }

    public function testWithoutTheConstantDefinedTheRequestIsCounted(): void
    {
        // La cabecera llega, pero WordPress no tiene con qué compararla: falla cerrado.
        $this->withSecret(self::SECRET);

        $this->assertSame(120, $this->allowedBeforeBlocking('GET', '/wp-json/wp/v2/posts', 200));
    }

    public function testLoginAttemptsAreLimitedEvenWithTheValidSecret(): void
    {
        $this->defineSecret();
        $this->withSecret(self::SECRET);

        $this->assertSame(5, $this->allowedBeforeBlocking('POST', '/wp-login.php', 20));
    }
}
