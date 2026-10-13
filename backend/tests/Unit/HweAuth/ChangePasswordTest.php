<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweAuth;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * POST hwe/v1/auth/change-password: WordPress verifica la contraseña ACTUAL y fija
 * la nueva.
 *
 * Antes el BFF cambiaba la contraseña con `POST wp/v2/users/<id>`, pero
 * `security.php` retira esa ruta (anti-enumeración) a quien no puede listar usuarios:
 * un cliente recibía 404 y el BFF respondía «No se pudo cambiar la contraseña».
 * Ningún cliente podía cambiar su contraseña desde «Mi cuenta».
 */
final class ChangePasswordTest extends HweAuthTestCase
{
    private const CURRENT = 'contrasena-vieja';
    private const NEW = 'contrasena-nueva-123';

    /** @var array<string,mixed> */
    private array $transients = [];

    /** @var list<array<string,mixed>> */
    private array $updates = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->transients = [];
        $this->updates = [];

        Functions\when('is_wp_error')->alias(static fn ($v): bool => $v instanceof \WP_Error);
        Functions\when('get_userdata')->alias(
            static fn (int $id) => $id === self::USER_ID ? (object) ['ID' => $id, 'user_pass' => 'hash-actual'] : false
        );
        Functions\when('wp_check_password')->alias(
            static fn (string $plain, string $hash, $id = ''): bool => $plain === self::CURRENT && $hash === 'hash-actual'
        );
        Functions\when('wp_update_user')->alias(function (array $data) {
            $this->updates[] = $data;
            return $data['ID'];
        });
        Functions\when('get_transient')->alias(fn (string $k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function (string $k, $v): bool {
            $this->transients[$k] = $v;
            return true;
        });
        Functions\when('delete_transient')->alias(function (string $k): bool {
            unset($this->transients[$k]);
            return true;
        });
    }

    private function change(string $current = self::CURRENT, string $new = self::NEW): \WP_REST_Response
    {
        return \HWE_Auth_Endpoints::handleChangePassword(
            new \WP_REST_Request(['current_password' => $current, 'new_password' => $new])
        );
    }

    public function test_con_la_contrasena_actual_correcta_fija_la_nueva(): void
    {
        $response = $this->change();

        $this->assertSame(200, $response->get_status());
        $this->assertSame([['ID' => self::USER_ID, 'user_pass' => self::NEW]], $this->updates);
    }

    public function test_una_contrasena_actual_incorrecta_es_403_y_no_cambia_nada(): void
    {
        $response = $this->change('otra');

        $this->assertSame(403, $response->get_status());
        $this->assertSame([], $this->updates);
    }

    public function test_sin_sesion_es_401(): void
    {
        Functions\when('get_current_user_id')->justReturn(0);

        $this->assertSame(401, $this->change()->get_status());
        $this->assertSame([], $this->updates);
    }

    public function test_una_contrasena_nueva_corta_es_422(): void
    {
        $response = $this->change(self::CURRENT, 'corta');

        $this->assertSame(422, $response->get_status());
        $this->assertSame([], $this->updates);
    }

    public function test_cinco_fallos_bloquean_hasta_con_la_contrasena_correcta(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(403, $this->change('mal')->get_status());
        }

        $locked = $this->change();

        $this->assertSame(429, $locked->get_status());
        $this->assertSame([], $this->updates);
    }

    public function test_un_cambio_correcto_borra_el_contador_de_fallos(): void
    {
        $this->change('mal');
        $this->change('mal');
        $this->assertSame(200, $this->change()->get_status());

        $this->assertSame([], $this->transients);
    }

    public function test_si_wordpress_no_guarda_la_contrasena_es_500(): void
    {
        Functions\when('wp_update_user')->justReturn(new \WP_Error('x', 'no se pudo'));
        Functions\expect('error_log')->once()->with(\Mockery::pattern('/^\[hwe\] auth: no se pudo cambiar la contraseña/'));

        $this->assertSame(500, $this->change()->get_status());
    }

    public function test_la_ruta_solo_atiende_al_bff_con_sesion(): void
    {
        $routes = [];
        Functions\when('register_rest_route')->alias(
            function (string $ns, string $route, array $args) use (&$routes): void {
                $routes[$route] = $args;
            }
        );
        \HWE_Auth_Endpoints::registerRoutes();

        $this->assertArrayHasKey('/auth/change-password', $routes);
        $route = $routes['/auth/change-password'];
        $this->assertSame('POST', $route['methods']);
        $this->assertSame(
            [\HWE_Auth_Endpoints::class, 'permitInternalWithSession'],
            $route['permission_callback']
        );
        $this->assertTrue($route['args']['current_password']['required']);
        $this->assertTrue($route['args']['new_password']['required']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_el_permiso_exige_el_secreto_interno_Y_una_sesion(): void
    {
        define('HWE_REVALIDATION_SECRET', 's3cret');
        $withSecret = new \WP_REST_Request([], ['X-HWE-Internal-Secret' => 's3cret']);
        $wrongSecret = new \WP_REST_Request([], ['X-HWE-Internal-Secret' => 'otro']);
        $noSecret = new \WP_REST_Request([]);

        Functions\when('is_user_logged_in')->justReturn(true);
        $this->assertTrue(\HWE_Auth_Endpoints::permitInternalWithSession($withSecret));
        $this->assertFalse(\HWE_Auth_Endpoints::permitInternalWithSession($wrongSecret));
        $this->assertFalse(\HWE_Auth_Endpoints::permitInternalWithSession($noSecret));

        // El secreto solo no basta: hace falta además la sesión del usuario.
        Functions\when('is_user_logged_in')->justReturn(false);
        $this->assertFalse(\HWE_Auth_Endpoints::permitInternalWithSession($withSecret));
    }
}
