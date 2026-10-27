<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\RateLimit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * `wp-login.php` es el único login humano permitido (headless-config.php) y no tenía
 * ninguna defensa: `rate-limit.php` solo cubría `/graphql` y `/wp-json/`. Sin límite, un
 * atacante prueba contraseñas sin fricción, y con `WP_ADMIN_USER=admin` por defecto el
 * usuario ya se conoce.
 *
 * Se limitan los INTENTOS (POST con credenciales), no las visitas a la página (GET).
 * Un archivo con constantes globales solo puede cargarse una vez por proceso.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LoginBruteForceTest extends RateLimitTestCase
{
    public function testTheSixthLoginAttemptInTheWindowIsRejected(): void
    {
        $this->assertSame(5, $this->allowedBeforeBlocking('POST', '/wp-login.php', 20));
    }

    public function testTheRejectionCarriesAPositiveRetryAfterWithinTheWindow(): void
    {
        $this->allowedBeforeBlocking('POST', '/wp-login.php', 5);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/wp-login.php';
        try {
            \hwe_rate_limit_init();
            $this->fail('El sexto intento debía rechazarse.');
        } catch (RateLimitedException $e) {
            $this->assertGreaterThan(0, $e->retryAfter);
            $this->assertLessThanOrEqual(300, $e->retryAfter);
        }
    }

    public function testLoadingTheLoginPageIsNeverCounted(): void
    {
        $this->assertSame(50, $this->allowedBeforeBlocking('GET', '/wp-login.php', 50));
        // ...y las visitas no gastan el presupuesto de intentos.
        $this->assertSame(5, $this->allowedBeforeBlocking('POST', '/wp-login.php', 20));
    }

    /** @return iterable<string, array{string}> */
    public static function loginUrls(): iterable
    {
        yield 'con parámetros' => ['/wp-login.php?redirect_to=%2Fwp-admin%2F'];
        yield 'detrás de index.php' => ['/index.php/wp-login.php'];
        yield 'en subdirectorio' => ['/wp/wp-login.php'];
        yield 'recuperar contraseña (acción de wp-login.php)' => ['/wp-login.php?action=lostpassword'];
        // Regresión: un marcador de API en la query no puede mover el intento de
        // credenciales a otro presupuesto (el cubo de login es el único válido).
        yield 'login con marcador REST en la query' => ['/wp-login.php?x=/wp-json/'];
        yield 'login con marcador GraphQL en la query' => ['/wp-login.php?x=/graphql'];
        yield 'recuperar contraseña con marcador REST' => ['/wp-login.php?action=lostpassword&x=/wp-json/'];
        yield 'login con rest_route en la query' => ['/wp-login.php?rest_route=/wp/v2/users'];
        // Forma con PATH_INFO: algunos servidores siguen despachando wp-login.php.
        yield 'login con PATH_INFO y marcador REST' => ['/wp-login.php/extra?x=/wp-json/'];
    }

    /** @dataProvider loginUrls */
    #[\PHPUnit\Framework\Attributes\DataProvider('loginUrls')]
    public function testEveryFormOfTheLoginUrlIsLimited(string $uri): void
    {
        $this->assertSame(5, $this->allowedBeforeBlocking('POST', $uri, 20));
    }

    public function testTheLimitIsPerIp(): void
    {
        $this->allowedBeforeBlocking('POST', '/wp-login.php', 20, '203.0.113.9');

        $this->assertTrue($this->request('POST', '/wp-login.php', '198.51.100.4'));
    }

    public function testSpoofingTheLeftmostForwardedForDoesNotResetTheLimit(): void
    {
        // Con un proxy de confianza (Caddy) la IP real es la ÚLTIMA de X-Forwarded-For;
        // el primer valor lo controla el cliente.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/wp-login.php';
        $_SERVER['REMOTE_ADDR'] = '172.18.0.2';

        $passed = 0;
        for ($i = 0; $i < 20; $i++) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = "10.0.0.{$i}, 203.0.113.9";
            try {
                \hwe_rate_limit_init();
                $passed++;
            } catch (RateLimitedException) {
                break;
            }
        }

        $this->assertSame(5, $passed);
    }

    public function testTheLoginBudgetIsSeparateFromTheApiBudgets(): void
    {
        $this->allowedBeforeBlocking('POST', '/wp-login.php', 20);

        $this->assertTrue($this->request('POST', '/wp-json/wp/v2/posts'));
        $this->assertTrue($this->request('POST', '/graphql'));
    }

    public function testAMarkerInTheQueryChargesTheLoginBucketOnly(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/wp-login.php?x=/wp-json/';
        \hwe_rate_limit_init();

        $ipHash = md5('203.0.113.9');
        $this->assertArrayHasKey('hwe_rl_login_' . $ipHash, $this->transients);
        $this->assertArrayNotHasKey('hwe_rl_rest_' . $ipHash, $this->transients);
        $this->assertArrayNotHasKey('hwe_rl_gql_' . $ipHash, $this->transients);
    }

    public function testTheMovedLoginWithAnApiMarkerStillChargesTheLoginBudget(): void
    {
        if (!defined('HWE_LOGIN_SLUG')) {
            define('HWE_LOGIN_SLUG', 'panel-acceso');
        }
        $this->loadOwnFile('mu-plugins/hwe-login-slug.php');

        $this->assertSame(5, $this->allowedBeforeBlocking('POST', '/panel-acceso?x=/wp-json/', 20));

        $ipHash = md5('203.0.113.9');
        $this->assertArrayHasKey('hwe_rl_login_' . $ipHash, $this->transients);
        $this->assertArrayNotHasKey('hwe_rl_rest_' . $ipHash, $this->transients);
    }
}
