<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweAuth;

use Brain\Monkey\Functions;

/**
 * Los argumentos que DECLARAN las rutas de auth, no solo lo que hacen los handlers.
 *
 * Los tests de los handlers los llaman directamente y se saltan la validación de
 * argumentos que WordPress aplica antes: así pasó que `PUT 2fa-secret` declaraba
 * `secret` como `string` mientras el BFF manda `secret: null` para desactivar, y
 * WordPress respondía 400 antes de llegar al handler. Desactivar el 2FA no funcionó
 * nunca, con ningún código.
 */
final class RouteArgsTest extends HweAuthTestCase
{
    /** @return array<string, array<string, mixed>> */
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

    public function test_el_secreto_de_2fa_admite_null_para_poder_desactivarlo(): void
    {
        $type = $this->registeredRoutes()['/auth/2fa-secret']['args']['secret']['type'];

        // WordPress valida el tipo antes del handler: con `string` a secas, `null` es 400.
        $this->assertIsArray($type);
        $this->assertContains('string', $type);
        $this->assertContains('null', $type);
    }
}
