<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweAuth;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Clave de cifrado del secreto 2FA: sin clave de reserva.
 *
 * Antes, si no había ninguna constante de wp-config.php, se degradaba EN SILENCIO
 * a la literal `hwe-insecure-2fa-key`: una clave pública (vive en el código
 * fuente) e idéntica en cualquier instalación que cayera ahí, de modo que el
 * secreto TOTP quedaba cifrado con una clave predecible. Ahora falla cerrado y
 * lo dice en el log.
 *
 * Las constantes son globales al proceso: cada test corre en el suyo.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CryptoKeyTest extends HweAuthTestCase
{
    private function activateRequest(): \WP_REST_Request
    {
        return new \WP_REST_Request([
            'secret' => self::SECRET,
            'code' => $this->currentCode(self::SECRET),
        ]);
    }

    public function test_sin_ninguna_clave_activar_2fa_responde_500_y_no_guarda_nada(): void
    {
        Functions\expect('error_log')->atLeast()->once()->with(\Mockery::pattern('/^\[hwe\] auth: /'));

        $response = \HWE_Auth_Endpoints::handleUpdate2faSecret($this->activateRequest());

        $this->assertSame(500, $response->get_status());
        $this->assertNull($this->meta('hwe_2fa_secret'), 'no se guarda un secreto "cifrado" vacío como si fuera válido');
        $this->assertNull($this->meta('hwe_2fa_enabled'));
        $this->assertNull($this->meta('hwe_2fa_recovery'));
    }

    public function test_el_mensaje_del_log_no_revela_ninguna_clave_ni_el_secreto(): void
    {
        $logged = [];
        Functions\when('error_log')->alias(function (string $message) use (&$logged): bool {
            $logged[] = $message;
            return true;
        });

        \HWE_Auth_Endpoints::handleUpdate2faSecret($this->activateRequest());

        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString(self::SECRET, implode("\n", $logged));
        $this->assertStringNotContainsString('hwe-insecure-2fa-key', implode("\n", $logged));
    }

    public function test_sin_ninguna_clave_no_se_puede_descifrar_ni_un_valor_ya_guardado(): void
    {
        Functions\when('error_log')->justReturn(true);
        $this->setMeta('hwe_2fa_secret', 'v1:' . base64_encode(str_repeat('a', 60)));

        $data = \HWE_Auth_Endpoints::handleGet2faSecret(new \WP_REST_Request())->get_data();

        $this->assertNull($data['secret']);
        $this->assertTrue($data['enabled'], 'sigue constando como activado');
    }

    public function test_un_valor_cifrado_con_la_clave_literal_antigua_no_se_descifra(): void
    {
        // Lo que producía la versión anterior sin ninguna constante: AES-GCM con
        // la clave derivada de la literal pública. Hoy no debe poder abrirse.
        Functions\when('error_log')->justReturn(true);
        $key = hash('sha256', 'hwe-2fa:hwe-insecure-2fa-key', true);
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt(self::SECRET, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        $this->setMeta('hwe_2fa_secret', 'v1:' . base64_encode($iv . $tag . $ct));

        $data = \HWE_Auth_Endpoints::handleGet2faSecret(new \WP_REST_Request())->get_data();

        $this->assertNull($data['secret']);
    }

    public function test_un_valor_legado_en_texto_plano_sigue_leyendose(): void
    {
        // Compatibilidad con instalaciones anteriores al cifrado: no pasa por la clave.
        $this->setMeta('hwe_2fa_secret', self::SECRET);

        $data = \HWE_Auth_Endpoints::handleGet2faSecret(new \WP_REST_Request())->get_data();

        $this->assertSame(self::SECRET, $data['secret']);
    }

    public function test_con_la_clave_de_2fa_activar_y_leer_es_una_ida_y_vuelta(): void
    {
        define('HWE_2FA_KEY', 'clave-de-pruebas-2fa-0123456789abcdef');

        $this->assertSame(200, \HWE_Auth_Endpoints::handleUpdate2faSecret($this->activateRequest())->get_status());
        $data = \HWE_Auth_Endpoints::handleGet2faSecret(new \WP_REST_Request())->get_data();

        $this->assertSame(self::SECRET, $data['secret']);
        $this->assertTrue($data['enabled']);
    }

    public function test_basta_una_salt_de_wordpress_como_clave(): void
    {
        define('AUTH_KEY', 'salt-de-wordpress-de-pruebas-0123456789');

        $this->assertSame(200, \HWE_Auth_Endpoints::handleUpdate2faSecret($this->activateRequest())->get_status());
    }

    public function test_un_secreto_cifrado_con_una_clave_no_se_abre_con_otra(): void
    {
        define('HWE_2FA_KEY', 'clave-de-pruebas-2fa-0123456789abcdef');
        \HWE_Auth_Endpoints::handleUpdate2faSecret($this->activateRequest());
        $stored = $this->meta('hwe_2fa_secret');

        // Mismo valor, leído como si la clave hubiera cambiado: se simula alterando el valor.
        $this->setMeta('hwe_2fa_secret', substr($stored, 0, -4) . 'AAAA');
        $data = \HWE_Auth_Endpoints::handleGet2faSecret(new \WP_REST_Request())->get_data();

        $this->assertNull($data['secret'], 'el cifrado es autenticado: un valor alterado no se descifra');
    }
}
