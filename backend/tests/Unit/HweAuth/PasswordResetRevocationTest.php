<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweAuth;

use Brain\Monkey\Functions;
use Mockery;

/**
 * Un reset de contraseña es una recuperación de cuenta: debe cerrar las sesiones
 * anteriores. Antes, `handleResetPassword()` solo llamaba a `reset_password()` y no
 * rotaba `graphql_jwt_auth_secret`, así que un refresh token copiado antes del reset
 * seguía renovando sesión (duran 365 días) y el atacante conservaba la cuenta que la
 * víctima creía recuperada.
 *
 * La rotación vive en `revokeRefreshTokens()` (compartida con `logout-all`) y también
 * se engancha a la acción `wp_set_password`, por la que pasa el reset nativo de
 * `wp-login.php`, que no cruza el BFF.
 */
final class PasswordResetRevocationTest extends HweAuthTestCase
{
    private const OLD_SECRET = 'secreto-antes-del-reset';

    /** @var list<int> */
    private array $resetCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        \WP_Session_Tokens::$destroyed = [];
        $this->resetCalls = [];

        Functions\when('is_wp_error')->alias(static fn ($value): bool => $value instanceof \WP_Error);
        Functions\when('check_password_reset_key')->alias(
            static fn (string $key, string $login) => $key === 'clave-valida'
                ? (object) ['ID' => self::USER_ID, 'user_login' => $login]
                : new \WP_Error('invalid_key', 'no vale')
        );
        Functions\when('reset_password')->alias(function ($user, string $newPassword): void {
            $this->resetCalls[] = (int) $user->ID;
        });
        Functions\when('wp_generate_password')->alias(
            static fn (int $length = 12): string => 'secreto-nuevo-' . $length
        );
    }

    private function token(string $userSecret): object
    {
        return json_decode(json_encode([
            'iss' => 'x',
            'data' => ['user' => ['id' => self::USER_ID, 'user_secret' => $userSecret]],
        ]));
    }

    private function reset(): \WP_REST_Response
    {
        return \HWE_Auth_Endpoints::handleResetPassword(new \WP_REST_Request([
            'key' => 'clave-valida',
            'login' => 'cliente',
            'new_password' => 'nueva-clave-123',
        ]));
    }

    public function test_tras_el_reset_el_refresh_token_de_antes_deja_de_valer(): void
    {
        $this->setMeta('graphql_jwt_auth_secret', self::OLD_SECRET);
        $before = $this->token(self::OLD_SECRET);
        $this->assertSame($before, \HWE_Auth_Endpoints::enforceRefreshTokenSecret($before));

        $response = $this->reset();

        $this->assertSame(200, $response->get_status());
        $this->assertSame([self::USER_ID], $this->resetCalls);
        $this->assertInstanceOf(
            \WP_Error::class,
            \HWE_Auth_Endpoints::enforceRefreshTokenSecret($before)
        );
    }

    public function test_el_reset_destruye_las_sesiones_nativas_de_wordpress(): void
    {
        $this->setMeta('graphql_jwt_auth_secret', self::OLD_SECRET);

        $this->assertSame(200, $this->reset()->get_status());

        $this->assertContains(self::USER_ID, \WP_Session_Tokens::$destroyed);
    }

    public function test_si_la_rotacion_falla_el_reset_no_reporta_exito_y_lo_registra(): void
    {
        $this->setMeta('graphql_jwt_auth_secret', self::OLD_SECRET);
        Functions\when('update_user_meta')->justReturn(false);
        Functions\expect('error_log')
            ->once()
            ->with(Mockery::pattern('/^\[hwe\] auth: reset-password: no se pudo rotar el secreto JWT/'));

        $response = $this->reset();

        $this->assertSame(500, $response->get_status());
    }

    public function test_la_accion_wp_set_password_tambien_cierra_las_sesiones(): void
    {
        $this->setMeta('graphql_jwt_auth_secret', self::OLD_SECRET);
        $before = $this->token(self::OLD_SECRET);
        $hooks = [];
        Functions\when('add_action')->alias(
            function (string $hook, $callback) use (&$hooks): void {
                $hooks[$hook][] = $callback;
            }
        );
        Functions\when('add_filter')->justReturn(true);

        \HWE_Auth_Endpoints::register();

        $this->assertArrayHasKey('wp_set_password', $hooks);
        $this->assertNotEmpty($hooks['wp_set_password']);
        ($hooks['wp_set_password'][0])('clave-nueva', self::USER_ID);

        $this->assertInstanceOf(
            \WP_Error::class,
            \HWE_Auth_Endpoints::enforceRefreshTokenSecret($before)
        );
        $this->assertContains(self::USER_ID, \WP_Session_Tokens::$destroyed);
    }
}
