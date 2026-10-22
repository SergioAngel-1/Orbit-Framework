<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweAuth;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * Verificación de email por token (sin sesión) y endpoints de teléfono (tarea 39).
 *
 *  · El enlace del correo se abre muchas veces sin sesión o con otra: el token
 *    identifica al usuario. Reabrir un enlace ya usado responde OK.
 *  · Los endpoints de teléfono solo atienden al BFF y con el flag encendido; el
 *    teléfono es único por cuenta.
 */
final class VerifyEmailAndPhoneTest extends HweAuthTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 42;

    /** @var array<string,mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        // Búsqueda de usuarios por meta, sobre el almacén en memoria del caso base.
        Functions\when('get_users')->alias(function (array $args): array {
            $found = [];
            foreach ($this->meta as $id => $meta) {
                if (($meta[$args['meta_key']] ?? null) === $args['meta_value'] && !in_array($id, (array) ($args['exclude'] ?? []), true)) {
                    $found[] = ($args['fields'] ?? '') === 'ID' ? $id : (object) ['ID' => $id, 'user_email' => "u{$id}@example.com", 'user_login' => "u{$id}"];
                }
            }
            return array_slice($found, 0, (int) ($args['number'] ?? 10));
        });
    }

    private function verify(string $token = self::TOKEN): \WP_REST_Response
    {
        return \HWE_Auth_Endpoints::handleVerifyEmail(new \WP_REST_Request(['token' => $token]));
    }

    // ── verificación ────────────────────────────────────────────────────────

    public function test_sin_sesion_el_token_identifica_al_usuario(): void
    {
        Functions\when('get_current_user_id')->justReturn(0);
        $this->meta[self::OTHER] = ['hwe_email_verification_token' => self::TOKEN, 'hwe_email_verification_token_time' => time()];

        $this->assertSame(200, $this->verify()->get_status());
        $this->assertSame('1', $this->meta[self::OTHER]['hwe_email_verified']);
    }

    public function test_con_la_sesion_de_otro_usuario_verifica_al_dueno_del_token(): void
    {
        $this->setMeta('hwe_email_verification_token', str_repeat('b', 64));
        $this->meta[self::OTHER] = ['hwe_email_verification_token' => self::TOKEN, 'hwe_email_verification_token_time' => time()];

        $this->assertSame(200, $this->verify()->get_status());
        $this->assertSame('1', $this->meta[self::OTHER]['hwe_email_verified']);
        $this->assertNull($this->meta('hwe_email_verified'), 'No verifica al de la sesión.');
    }

    public function test_reabrir_un_enlace_ya_usado_responde_ok(): void
    {
        $this->setMeta('hwe_email_verification_token', self::TOKEN);
        $this->setMeta('hwe_email_verification_token_time', time());

        $this->assertSame(200, $this->verify()->get_status());
        $again = $this->verify();

        $this->assertSame(200, $again->get_status());
        $this->assertTrue($again->get_data()['already_verified']);
    }

    public function test_un_token_desconocido_o_caducado_es_400(): void
    {
        $this->assertSame(400, $this->verify(str_repeat('c', 64))->get_status());

        $this->setMeta('hwe_email_verification_token', self::TOKEN);
        $this->setMeta('hwe_email_verification_token_time', time() - 2 * 86400);
        $this->assertSame(400, $this->verify()->get_status());
        $this->assertNull($this->meta('hwe_email_verification_token'), 'El token caducado se borra.');
    }

    public function test_verificar_el_email_dispara_la_accion_estable_una_sola_vez(): void
    {
        $this->setMeta('hwe_email_verification_token', self::TOKEN);
        $this->setMeta('hwe_email_verification_token_time', time());

        Actions\expectDone('hwe_email_verified')
            ->once()
            ->with(self::USER_ID);

        $this->assertSame(200, $this->verify()->get_status());
    }

    public function test_la_rama_ya_verificado_no_vuelve_a_disparar_la_accion(): void
    {
        $this->setMeta('hwe_email_verified', '1');
        $this->setMeta('hwe_email_verification_token', self::TOKEN);
        $this->setMeta('hwe_email_verification_token_time', time());

        Actions\expectDone('hwe_email_verified')->never();

        $this->assertSame(200, $this->verify()->get_status());
        $this->assertTrue($this->verify()->get_data()['already_verified']);
    }

    // ── teléfono ────────────────────────────────────────────────────────────

    public function test_los_endpoints_de_telefono_exigen_el_flag(): void
    {
        $this->assertFalse(\HWE_Auth_Endpoints::phoneLoginEnabled());

        $this->options['hwe_config'] = ['auth' => ['phone_login_enabled' => true]];
        $this->assertTrue(\HWE_Auth_Endpoints::phoneLoginEnabled());
        $this->assertFalse(
            \HWE_Auth_Endpoints::permitPhoneLogin(new \WP_REST_Request()),
            'Sin el secreto interno, aunque el flag esté encendido.'
        );
    }

    public function test_e164(): void
    {
        $this->assertTrue(\HWE_Auth_Endpoints::isE164('+34600112233'));
        $this->assertFalse(\HWE_Auth_Endpoints::isE164('34600112233'));
        $this->assertFalse(\HWE_Auth_Endpoints::isE164('+0600112233'));
        $this->assertFalse(\HWE_Auth_Endpoints::isE164(['+34600112233']));
    }

    public function test_resolver_el_login_solo_si_el_telefono_es_de_una_sola_cuenta(): void
    {
        $this->meta[self::OTHER] = ['billing_phone' => '+34600112233'];
        $ok = \HWE_Auth_Endpoints::handleResolveLogin(new \WP_REST_Request(['phone' => '+34600112233']));
        $this->assertSame(['login' => 'u42@example.com'], $ok->get_data());

        $this->assertSame(404, \HWE_Auth_Endpoints::handleResolveLogin(new \WP_REST_Request(['phone' => '+34999999999']))->get_status());

        $this->meta[43] = ['billing_phone' => '+34600112233'];
        $this->assertSame(404, \HWE_Auth_Endpoints::handleResolveLogin(new \WP_REST_Request(['phone' => '+34600112233']))->get_status(), 'Compartido: no se adivina.');
    }

    public function test_guardar_el_telefono_rechaza_uno_de_otra_cuenta(): void
    {
        Functions\when('get_userdata')->justReturn((object) ['ID' => self::USER_ID]);
        Functions\when('wp_update_user')->justReturn(self::USER_ID);
        $this->meta[self::OTHER] = ['billing_phone' => '+34600112233'];

        $taken = \HWE_Auth_Endpoints::handleAttachProfile(new \WP_REST_Request(['user_id' => self::USER_ID, 'phone' => '+34600112233']));
        $this->assertSame(409, $taken->get_status());

        $free = \HWE_Auth_Endpoints::handleAttachProfile(new \WP_REST_Request(['user_id' => self::USER_ID, 'phone' => '+34611223344']));
        $this->assertSame(200, $free->get_status());
        $this->assertSame('+34611223344', $this->meta('billing_phone'));
    }
}
