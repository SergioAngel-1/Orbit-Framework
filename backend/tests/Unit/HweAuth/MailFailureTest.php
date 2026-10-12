<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweAuth;

use Brain\Monkey\Functions;

/**
 * Un correo que no se puede enviar no pasa en silencio.
 *
 * Tras el registro, el BFF pedía el correo de verificación sin mirar la
 * respuesta, y `send-verification` contestaba 200 aunque `wp_mail()` fallara (SMTP
 * caído o mal configurado): el usuario creía tener un correo en camino que nunca
 * existió. Ahora `send-verification` dice 502, y `forgot-password`, que NO revela
 * si la cuenta existe y por tanto sigue contestando 200, deja el fallo en el log.
 */
final class MailFailureTest extends HweAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $user = (object) [
            'ID' => self::USER_ID,
            'user_login' => 'ana',
            'user_email' => 'ana@example.com',
            'display_name' => 'Ana',
        ];
        Functions\stubs([
            'get_userdata' => $user,
            'get_user_by' => $user,
            'get_password_reset_key' => 'clave-de-reseteo',
            'is_wp_error' => false,
            'get_option' => 'Mi Tienda',
            'wp_specialchars_decode' => null,
            'untrailingslashit' => static fn ($url) => rtrim((string) $url, '/'),
            'add_query_arg' => static fn ($args, $url = '') => $url . '?' . http_build_query((array) $args),
        ]);
    }

    private function sendVerification(): \WP_REST_Response
    {
        return \HWE_Auth_Endpoints::handleSendVerification(
            new \WP_REST_Request(['verification_url' => 'https://tienda.example/verify-email'])
        );
    }

    public function test_send_verification_responde_200_cuando_el_correo_sale(): void
    {
        Functions\when('wp_mail')->justReturn(true);

        $this->assertSame(200, $this->sendVerification()->get_status());
    }

    public function test_send_verification_responde_502_y_deja_traza_cuando_el_correo_falla(): void
    {
        Functions\when('wp_mail')->justReturn(false);
        Functions\expect('error_log')->once()->with(\Mockery::pattern('/^\[hwe\] auth: send-verification/'));

        $response = $this->sendVerification();

        $this->assertSame(502, $response->get_status());
        $this->assertArrayHasKey('error', $response->get_data());
    }

    public function test_el_token_de_verificacion_se_guarda_aunque_el_correo_falle(): void
    {
        // «Reenviar» emite uno nuevo; no se pierde el estado por el fallo del SMTP.
        Functions\when('wp_mail')->justReturn(false);
        Functions\when('error_log')->justReturn(true);

        $this->sendVerification();

        $this->assertNotEmpty($this->meta('hwe_email_verification_token'));
    }

    public function test_send_verification_no_reenvia_si_el_email_ya_esta_verificado(): void
    {
        $this->setMeta('hwe_email_verified', '1');
        Functions\expect('wp_mail')->never();

        $this->assertSame(400, $this->sendVerification()->get_status());
    }

    public function test_forgot_password_responde_200_pero_deja_el_fallo_en_el_log(): void
    {
        Functions\when('wp_mail')->justReturn(false);
        Functions\expect('error_log')->once()->with(\Mockery::pattern('/^\[hwe\] auth: forgot-password/'));

        $response = \HWE_Auth_Endpoints::handleForgotPassword(
            new \WP_REST_Request(['user_login' => 'ana', 'frontend_url' => 'https://tienda.example'])
        );

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['ok' => true], $response->get_data());
    }

    public function test_forgot_password_no_registra_nada_cuando_el_correo_sale(): void
    {
        Functions\when('wp_mail')->justReturn(true);
        Functions\expect('error_log')->never();

        \HWE_Auth_Endpoints::handleForgotPassword(
            new \WP_REST_Request(['user_login' => 'ana', 'frontend_url' => 'https://tienda.example'])
        );

        $this->addToAssertionCount(1);
    }

    public function test_forgot_password_no_revela_si_la_cuenta_existe(): void
    {
        Functions\when('get_user_by')->justReturn(false);
        Functions\expect('wp_mail')->never();

        $response = \HWE_Auth_Endpoints::handleForgotPassword(
            new \WP_REST_Request(['user_login' => 'nadie', 'frontend_url' => 'https://tienda.example'])
        );

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['ok' => true], $response->get_data());
    }
}
