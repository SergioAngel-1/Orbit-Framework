<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweAuth;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * PUT /hwe/v1/auth/2fa-secret: activar y desactivar el 2FA exige PRUEBA de
 * posesión (un código TOTP válido o un código de recuperación), no solo un
 * access-token.
 *
 * El endpoint es alcanzable sin pasar por el BFF, así que un token robado bastaba
 * por sí solo para activar o desactivar el 2FA de la víctima. Estos tests llaman
 * al handler directamente, sin BFF por delante, que es justo lo que haría un
 * atacante.
 *
 * Cada test corre en su proceso porque define la clave de cifrado (constante).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TwoFactorSecretTest extends HweAuthTestCase
{
    protected function setUp(): void
    {
        define('HWE_2FA_KEY', 'clave-de-pruebas-2fa-0123456789abcdef');
        parent::setUp();
    }

    /** Deja el 2FA activo con SECRET y devuelve los códigos de recuperación en claro. */
    private function activate(): array
    {
        $response = \HWE_Auth_Endpoints::handleUpdate2faSecret(new \WP_REST_Request([
            'secret' => self::SECRET,
            'code' => $this->currentCode(self::SECRET),
        ]));
        $this->assertSame(200, $response->get_status());

        return $response->get_data()['recovery_codes'];
    }

    private function put(array $params): \WP_REST_Response
    {
        return \HWE_Auth_Endpoints::handleUpdate2faSecret(new \WP_REST_Request($params));
    }

    // ── Activar ────────────────────────────────────────────────────────────

    public function test_activar_sin_codigo_se_rechaza_y_no_guarda_nada(): void
    {
        $response = $this->put(['secret' => self::SECRET]);

        $this->assertSame(403, $response->get_status());
        $this->assertNull($this->meta('hwe_2fa_secret'));
        $this->assertNull($this->meta('hwe_2fa_enabled'));
    }

    public function test_activar_con_un_codigo_incorrecto_se_rechaza(): void
    {
        $response = $this->put(['secret' => self::SECRET, 'code' => $this->wrongCode(self::SECRET)]);

        $this->assertSame(403, $response->get_status());
        $this->assertNull($this->meta('hwe_2fa_secret'));
    }

    public function test_activar_con_el_codigo_de_otro_secreto_se_rechaza(): void
    {
        // El atacante no puede registrar un secreto que no controla el autenticador.
        $otherSecret = 'KRSXG5CTMVRXEZLU';
        $response = $this->put(['secret' => self::SECRET, 'code' => $this->currentCode($otherSecret)]);

        $this->assertSame(403, $response->get_status());
    }

    public function test_activar_con_un_codigo_valido_cifra_el_secreto_y_devuelve_los_codigos_de_recuperacion(): void
    {
        $response = $this->put(['secret' => self::SECRET, 'code' => $this->currentCode(self::SECRET)]);

        $this->assertSame(200, $response->get_status());
        $codes = $response->get_data()['recovery_codes'];
        $this->assertCount(10, $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{5}-[0-9a-f]{5}$/', $code);
        }

        // El secreto se guarda CIFRADO, nunca en claro.
        $stored = $this->meta('hwe_2fa_secret');
        $this->assertStringStartsWith('v1:', $stored);
        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->assertSame('1', $this->meta('hwe_2fa_enabled'));

        // De los códigos de recuperación solo se guardan los hashes.
        $hashes = $this->meta('hwe_2fa_recovery');
        $this->assertCount(10, $hashes);
        $this->assertContains(hash('sha256', str_replace('-', '', $codes[0])), $hashes);
        $this->assertStringNotContainsString($codes[0], json_encode($this->meta));
    }

    public function test_activar_con_el_2fa_ya_activo_se_rechaza_para_no_sobrescribir_el_secreto(): void
    {
        // Con un token robado, el atacante podría registrar SU secreto (y su código
        // válido) encima del de la víctima y quedarse con el segundo factor.
        $this->activate();
        $victimStored = $this->meta('hwe_2fa_secret');

        $attackerSecret = 'KRSXG5CTMVRXEZLU';
        $response = $this->put(['secret' => $attackerSecret, 'code' => $this->currentCode($attackerSecret)]);

        $this->assertSame(409, $response->get_status());
        $this->assertSame($victimStored, $this->meta('hwe_2fa_secret'), 'el secreto activo no cambia');
    }

    // ── Desactivar ─────────────────────────────────────────────────────────

    public function test_desactivar_sin_prueba_se_rechaza_y_no_borra_nada(): void
    {
        $this->activate();

        $response = $this->put([]);

        $this->assertSame(403, $response->get_status());
        $this->assertNotNull($this->meta('hwe_2fa_secret'));
        $this->assertSame('1', $this->meta('hwe_2fa_enabled'));
    }

    public function test_desactivar_con_secret_vacio_tambien_exige_prueba(): void
    {
        $this->activate();

        $this->assertSame(403, $this->put(['secret' => ''])->get_status());
        $this->assertSame(403, $this->put(['secret' => null])->get_status());
        $this->assertNotNull($this->meta('hwe_2fa_secret'));
    }

    public function test_desactivar_con_un_codigo_totp_incorrecto_se_rechaza(): void
    {
        $this->activate();

        $response = $this->put(['code' => $this->wrongCode(self::SECRET)]);

        $this->assertSame(403, $response->get_status());
        $this->assertNotNull($this->meta('hwe_2fa_secret'));
    }

    public function test_desactivar_con_un_codigo_totp_valido_del_secreto_activo_limpia_todo(): void
    {
        $this->activate();

        $response = $this->put(['code' => $this->currentCode(self::SECRET)]);

        $this->assertSame(200, $response->get_status());
        $this->assertNull($this->meta('hwe_2fa_secret'));
        $this->assertNull($this->meta('hwe_2fa_recovery'));
        $this->assertSame('0', $this->meta('hwe_2fa_enabled'));
    }

    public function test_desactivar_con_un_codigo_de_recuperacion_valido_funciona_y_lo_consume(): void
    {
        $codes = $this->activate();

        $response = $this->put(['recovery_code' => $codes[0]]);

        $this->assertSame(200, $response->get_status());
        $this->assertNull($this->meta('hwe_2fa_secret'));
    }

    public function test_desactivar_con_un_codigo_de_recuperacion_inventado_se_rechaza(): void
    {
        $this->activate();

        $response = $this->put(['recovery_code' => 'aaaaa-bbbbb']);

        $this->assertSame(403, $response->get_status());
        $this->assertNotNull($this->meta('hwe_2fa_secret'));
    }

    public function test_un_codigo_de_recuperacion_no_vale_como_codigo_totp_ni_al_reves(): void
    {
        $codes = $this->activate();

        // Un código de recuperación enviado como `code` (TOTP) no sirve.
        $this->assertSame(403, $this->put(['code' => $codes[0]])->get_status());
        // Un TOTP vigente enviado como `recovery_code` tampoco.
        $this->assertSame(403, $this->put(['recovery_code' => $this->currentCode(self::SECRET)])->get_status());
        $this->assertNotNull($this->meta('hwe_2fa_secret'));
    }

    public function test_si_el_secreto_activo_no_se_puede_descifrar_el_totp_no_vale_pero_la_recuperacion_si(): void
    {
        $codes = $this->activate();
        // Simula un valor corrupto (o cifrado con una clave que se rotó).
        $this->setMeta('hwe_2fa_secret', 'v1:' . base64_encode(str_repeat('x', 40)));

        $this->assertSame(403, $this->put(['code' => $this->currentCode(self::SECRET)])->get_status());
        $this->assertSame(200, $this->put(['recovery_code' => $codes[1]])->get_status());
    }

    // ── Códigos de recuperación ────────────────────────────────────────────

    public function test_un_codigo_de_recuperacion_solo_sirve_una_vez(): void
    {
        $codes = $this->activate();
        $request = new \WP_REST_Request(['code' => $codes[2]]);

        $first = \HWE_Auth_Endpoints::handleConsumeRecoveryCode($request);
        $second = \HWE_Auth_Endpoints::handleConsumeRecoveryCode($request);

        $this->assertSame(200, $first->get_status());
        $this->assertSame(9, $first->get_data()['remaining']);
        $this->assertSame(401, $second->get_status());
        $this->assertCount(9, $this->meta('hwe_2fa_recovery'));
    }

    public function test_el_codigo_de_recuperacion_se_acepta_con_o_sin_guion_y_en_mayusculas(): void
    {
        $codes = $this->activate();
        $plain = strtoupper(str_replace('-', '', $codes[3]));

        $response = \HWE_Auth_Endpoints::handleConsumeRecoveryCode(new \WP_REST_Request(['code' => $plain]));

        $this->assertSame(200, $response->get_status());
    }

    public function test_sin_codigos_de_recuperacion_responde_400(): void
    {
        $response = \HWE_Auth_Endpoints::handleConsumeRecoveryCode(new \WP_REST_Request(['code' => 'aaaaa-bbbbb']));

        $this->assertSame(400, $response->get_status());
    }

    public function test_un_codigo_vacio_nunca_se_consume(): void
    {
        $this->activate();

        $response = \HWE_Auth_Endpoints::handleConsumeRecoveryCode(new \WP_REST_Request(['code' => '']));

        $this->assertSame(401, $response->get_status());
        $this->assertCount(10, $this->meta('hwe_2fa_recovery'));
    }
}
