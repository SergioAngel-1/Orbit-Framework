<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweBffOnly;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Con el secreto interno compartido, el BFF pasa; sin él (o con uno incorrecto), no.
 *
 * Antes de este mu-plugin, GraphQL y la Store API respondían a cualquiera que llegara
 * a PHP por parámetro (`/?graphql`, `?rest_route=/wc/store/…`). La versión anterior
 * identificaba al BFF por la red (sin X-Forwarded-For + IP privada): una suposición,
 * no una credencial.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SharedSecretTest extends HweBffOnlyTestCase
{
    protected function setUp(): void
    {
        define('HWE_REVALIDATION_SECRET', self::SECRET);
        define('REST_REQUEST', true);
        parent::setUp();
    }

    /** @return iterable<string, array{string}> */
    public static function bffRoutes(): iterable
    {
        yield 'Store API: carrito' => ['/wc/store/v1/cart'];
        yield 'Store API: checkout' => ['/wc/store/v1/checkout'];
        yield 'Store API: batch' => ['/wc/store/v1/batch'];
        yield 'auth propia: me' => ['/hwe/v1/auth/me'];
        yield 'auth propia: 2fa-status' => ['/hwe/v1/auth/2fa-status/7'];
        yield 'auth propia: forgot-password' => ['/hwe/v1/auth/forgot-password'];
        yield 'consentimiento' => ['/hwe/v1/consent/log'];
    }

    // ── Con el secreto correcto ────────────────────────────────────────────

    public function testGraphqlWithTheSecretPasses(): void
    {
        $this->sendSecret(self::SECRET);

        $this->assertTrue($this->graphqlAllowed());
    }

    #[DataProvider('bffRoutes')]
    public function testBffRoutesWithTheSecretPass(string $route): void
    {
        $this->sendSecret(self::SECRET);

        $this->assertNull($this->dispatch($route));
    }

    // ── Sin secreto ────────────────────────────────────────────────────────

    public function testGraphqlWithoutTheSecretIsAnsweredWithA404AndEnds(): void
    {
        $status = null;
        Functions\when('status_header')->alias(function (int $code) use (&$status): void {
            $status = $code;
        });
        Functions\when('nocache_headers')->justReturn(null);

        ob_start();
        try {
            ($this->graphqlFilter)(true);
            $this->fail('La petición debía terminar.');
        } catch (TerminatedException) {
            // esperado
        } finally {
            $body = (string) ob_get_clean();
        }

        $this->assertSame(404, $status);
        $this->assertSame(['errors' => [['message' => 'Not Found']]], json_decode($body, true));
    }

    #[DataProvider('bffRoutes')]
    public function testBffRoutesWithoutTheSecretAreA404LikeAMissingRoute(string $route): void
    {
        $result = $this->dispatch($route);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('rest_no_route', $result->get_error_code());
        $this->assertSame(['status' => 404], $result->get_error_data());
    }

    public function testAnEmptyHeaderIsNotTheSecret(): void
    {
        $this->sendSecret('');

        $this->assertFalse($this->graphqlAllowed());
        $this->assertInstanceOf(\WP_Error::class, $this->dispatch('/wc/store/v1/cart'));
    }

    // ── Con un secreto incorrecto ──────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function wrongSecrets(): iterable
    {
        yield 'otro valor' => ['otro-secreto-completamente-distinto'];
        yield 'el correcto con un carácter de más' => [self::SECRET . 'x'];
        yield 'el correcto truncado' => [substr(self::SECRET, 0, -1)];
        yield 'el correcto en mayúsculas' => [strtoupper(self::SECRET)];
        yield 'solo un prefijo' => [substr(self::SECRET, 0, 6)];
    }

    #[DataProvider('wrongSecrets')]
    public function testAWrongSecretIsRejectedEverywhere(string $wrong): void
    {
        $this->sendSecret($wrong);

        $this->assertFalse($this->graphqlAllowed());
        $this->assertInstanceOf(\WP_Error::class, $this->dispatch('/wc/store/v1/cart'));
        $this->assertInstanceOf(\WP_Error::class, $this->dispatch('/hwe/v1/auth/me'));
    }

    public function testAWrongSecretLeavesATraceWithoutRevealingAnySecret(): void
    {
        $logged = [];
        Functions\when('error_log')->alias(function (string $message) use (&$logged): bool {
            $logged[] = $message;
            return true;
        });
        $this->sendSecret('otro-secreto-completamente-distinto');

        $this->graphqlAllowed();

        $this->assertCount(1, $logged);
        $this->assertStringContainsString('[hwe] bff-only:', $logged[0]);
        $this->assertStringNotContainsString(self::SECRET, $logged[0]);
        $this->assertStringNotContainsString('otro-secreto-completamente-distinto', $logged[0]);
    }

    public function testRepeatedRejectionsAreLoggedOnceNotOncePerRequest(): void
    {
        $logged = 0;
        Functions\when('error_log')->alias(function () use (&$logged): bool {
            $logged++;
            return true;
        });
        $this->sendSecret('otro-secreto-completamente-distinto');

        for ($i = 0; $i < 20; $i++) {
            $this->graphqlAllowed();
        }

        $this->assertSame(1, $logged);
    }

    public function testNoHeaderAtAllIsNotLoggedSoScannersDoNotFloodTheLog(): void
    {
        $logged = 0;
        Functions\when('error_log')->alias(function () use (&$logged): bool {
            $logged++;
            return true;
        });

        $this->graphqlAllowed();
        $this->dispatch('/wc/store/v1/cart');

        $this->assertSame(0, $logged);
    }
}
