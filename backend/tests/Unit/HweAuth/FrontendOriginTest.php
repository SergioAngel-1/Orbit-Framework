<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweAuth;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `frontend_url` (recuperar contraseña) y `verification_url` (verificar email)
 * deben pertenecer a la allowlist de frontends.
 *
 * Sin esa validación, un atacante podía pedir el reseteo de la contraseña de la
 * víctima con un `frontend_url` apuntando a su propio dominio: WordPress generaba
 * una clave de reseteo REAL y la enviaba al correo legítimo con el enlace
 * apuntando al dominio del atacante (envenenamiento del enlace de reseteo, que
 * acaba en toma de cuenta).
 */
final class FrontendOriginTest extends HweAuthTestCase
{
    private const ALLOWED = 'https://tienda.example';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('hwe_allowed_origins')->justReturn([self::ALLOWED, 'http://localhost:3000']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedUrls(): iterable
    {
        yield 'el origen exacto' => ['https://tienda.example'];
        yield 'con ruta' => ['https://tienda.example/verify-email'];
        yield 'con ruta y query' => ['https://tienda.example/reset-password?lang=es'];
        yield 'con mayúsculas en esquema y host' => ['HTTPS://Tienda.Example/x'];
        yield 'el otro origen permitido (desarrollo)' => ['http://localhost:3000/reset-password'];
    }

    #[DataProvider('allowedUrls')]
    public function test_acepta_urls_de_origenes_permitidos(string $url): void
    {
        $this->assertTrue(\HWE_Auth_Endpoints::isAllowedFrontendOrigin($url));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function rejectedUrls(): iterable
    {
        yield 'otro dominio' => ['https://evil.example/reset-password'];
        yield 'mismo host con otro esquema' => ['http://tienda.example'];
        yield 'mismo host con otro puerto' => ['https://tienda.example:8443'];
        yield 'subdominio de otro dominio' => ['https://tienda.example.evil.com'];
        yield 'el permitido como prefijo del host' => ['https://tienda.examples.com'];
        yield 'el permitido dentro de la ruta' => ['https://evil.example/https://tienda.example'];
        yield 'el permitido como usuario (userinfo)' => ['https://tienda.example@evil.example/'];
        yield 'userinfo con el host permitido' => ['https://user:pass@tienda.example/'];
        yield 'el permitido en el fragmento' => ['https://evil.example#@tienda.example'];
        yield 'sin esquema' => ['//tienda.example/x'];
        yield 'solo host' => ['tienda.example'];
        yield 'esquema javascript' => ['javascript:alert(1)'];
        yield 'esquema data' => ['data:text/html,<script>1</script>'];
        yield 'cadena vacía' => [''];
        yield 'null' => [null];
        yield 'un array' => [['https://tienda.example']];
        yield 'un entero' => [123];
    }

    #[DataProvider('rejectedUrls')]
    public function test_rechaza_cualquier_otra_cosa(mixed $url): void
    {
        $this->assertFalse(\HWE_Auth_Endpoints::isAllowedFrontendOrigin($url));
    }

    public function test_si_la_allowlist_esta_vacia_no_se_acepta_nada(): void
    {
        Functions\when('hwe_allowed_origins')->justReturn([]);

        $this->assertFalse(\HWE_Auth_Endpoints::isAllowedFrontendOrigin('https://tienda.example'));
    }

    /** @return array<string, array<string, mixed>> Rutas registradas por `registerRoutes()`. */
    private function registeredRoutes(): array
    {
        $routes = [];
        Functions\when('register_rest_route')->alias(
            function (string $namespace, string $route, array $args) use (&$routes): void {
                $routes[$route] = $args;
            }
        );
        \HWE_Auth_Endpoints::registerRoutes();

        return $routes;
    }

    public function test_la_validacion_esta_cableada_en_forgot_password(): void
    {
        $args = $this->registeredRoutes()['/auth/forgot-password']['args']['frontend_url'];

        $this->assertSame([\HWE_Auth_Endpoints::class, 'isAllowedFrontendOrigin'], $args['validate_callback']);
    }

    public function test_la_validacion_esta_cableada_en_send_verification(): void
    {
        $args = $this->registeredRoutes()['/auth/send-verification']['args']['verification_url'];

        $this->assertSame([\HWE_Auth_Endpoints::class, 'isAllowedFrontendOrigin'], $args['validate_callback']);
    }
}
