<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweAuth;

use Brain\Monkey\Functions;

/**
 * «Cerrar sesión en todos los dispositivos» y el cambio de contraseña rotan el
 * secreto JWT del usuario (`graphql_jwt_auth_secret`) esperando que eso invalide sus
 * refresh tokens. El plugin WPGraphQL JWT Authentication 0.7.0 NO lo hace: el refresh
 * token lleva `user_secret`, pero `validate_token` solo mira el indicador «revocado»,
 * nunca lo compara con el secreto vigente. Un refresh token viejo seguía renovando
 * sesión tras `logout-all` (duran 365 días).
 *
 * El filtro `graphql_jwt_auth_validate_token` lo compara de verdad.
 */
final class RefreshTokenSecretTest extends HweAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('is_wp_error')->alias(static fn ($value): bool => $value instanceof \WP_Error);
        Functions\when('wp_generate_password')->alias(
            static fn (int $length = 12): string => str_repeat('n', $length)
        );
    }

    private function token(?string $userSecret, int $userId = self::USER_ID): object
    {
        $user = ['id' => $userId];
        if ($userSecret !== null) {
            $user['user_secret'] = $userSecret;
        }

        return json_decode(json_encode(['iss' => 'x', 'data' => ['user' => $user]]));
    }

    public function test_un_refresh_token_con_el_secreto_vigente_se_acepta(): void
    {
        $this->setMeta('graphql_jwt_auth_secret', 'graphql_jwt_vigente');
        $token = $this->token('graphql_jwt_vigente');

        $this->assertSame($token, \HWE_Auth_Endpoints::enforceRefreshTokenSecret($token));
    }

    public function test_un_refresh_token_con_un_secreto_anterior_se_rechaza(): void
    {
        $this->setMeta('graphql_jwt_auth_secret', 'graphql_jwt_nuevo');

        $result = \HWE_Auth_Endpoints::enforceRefreshTokenSecret($this->token('graphql_jwt_viejo'));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid-jwt', $result->get_error_code());
    }

    public function test_tras_logout_all_el_refresh_token_de_antes_deja_de_valer(): void
    {
        $this->setMeta('graphql_jwt_auth_secret', 'graphql_jwt_antes');
        $before = $this->token('graphql_jwt_antes');
        $this->assertSame($before, \HWE_Auth_Endpoints::enforceRefreshTokenSecret($before));

        $response = \HWE_Auth_Endpoints::handleLogoutAll(new \WP_REST_Request([]));

        $this->assertSame(200, $response->get_status());
        $this->assertInstanceOf(
            \WP_Error::class,
            \HWE_Auth_Endpoints::enforceRefreshTokenSecret($before)
        );
    }

    public function test_un_usuario_sin_secreto_guardado_no_puede_renovar(): void
    {
        $result = \HWE_Auth_Endpoints::enforceRefreshTokenSecret($this->token('lo-que-sea'));

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_el_secreto_de_otro_usuario_no_vale(): void
    {
        $this->meta[99]['graphql_jwt_auth_secret'] = 'graphql_jwt_ajeno';
        $this->setMeta('graphql_jwt_auth_secret', 'graphql_jwt_mio');

        $result = \HWE_Auth_Endpoints::enforceRefreshTokenSecret($this->token('graphql_jwt_mio', 99));

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_un_access_token_no_lleva_secreto_y_pasa_sin_tocarse(): void
    {
        $token = $this->token(null);

        $this->assertSame($token, \HWE_Auth_Endpoints::enforceRefreshTokenSecret($token));
    }

    public function test_un_error_anterior_del_filtro_se_respeta(): void
    {
        $error = new \WP_Error('invalid-jwt', 'ya era inválido');

        $this->assertSame($error, \HWE_Auth_Endpoints::enforceRefreshTokenSecret($error));
    }

    public function test_el_filtro_esta_registrado(): void
    {
        Functions\expect('add_filter')
            ->atLeast()->once()
            ->with('graphql_jwt_auth_validate_token', [\HWE_Auth_Endpoints::class, 'enforceRefreshTokenSecret']);
        Functions\when('add_action')->justReturn(true);

        \HWE_Auth_Endpoints::register();

        $this->assertTrue(true);
    }
}
