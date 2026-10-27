<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweAuth;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Códigos de recuperación 2FA: entropía de 128 bits y verificador con pimienta de
 * servidor.
 *
 * Antes se generaban con `random_bytes(5)` (40 bits) y se guardaba
 * `hash('sha256', $code)` sin sal ni pimienta. Quien obtenía una copia de
 * `wp_usermeta` (backup, réplica, volcado) podía recorrer 2^40 offline y fabricar
 * el segundo factor. El verificador ahora es un HMAC-SHA-256 con una clave
 * derivada de la misma clave fuera de la BD que cifra el secreto TOTP.
 *
 * Las constantes son globales al proceso: cada test corre en el suyo.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TwoFactorRecoveryCodeTest extends HweAuthTestCase
{
    protected function setUp(): void
    {
        define('HWE_2FA_KEY', 'clave-de-pruebas-2fa-0123456789abcdef');
        parent::setUp();
    }

    /** Deja el 2FA activo y devuelve los códigos de recuperación en claro. */
    private function activate(): array
    {
        $response = \HWE_Auth_Endpoints::handleUpdate2faSecret(new \WP_REST_Request([
            'secret' => self::SECRET,
            'code' => $this->currentCode(self::SECRET),
        ]));
        $this->assertSame(200, $response->get_status());

        return $response->get_data()['recovery_codes'];
    }

    public function test_los_codigos_tienen_128_bits_y_se_muestran_en_grupos_de_4(): void
    {
        $codes = $this->activate();

        $this->assertCount(10, $codes);
        foreach ($codes as $code) {
            // 32 caracteres hex = 16 bytes = 128 bits, en 8 grupos de 4.
            $this->assertMatchesRegularExpression('/^[0-9a-f]{4}(-[0-9a-f]{4}){7}$/', $code);
            $this->assertSame(32, strlen(str_replace('-', '', $code)));
        }
    }

    public function test_lo_guardado_no_es_sha256_del_codigo_sin_pimienta(): void
    {
        $codes  = $this->activate();
        $hashes = $this->meta('hwe_2fa_recovery');

        $this->assertCount(10, $hashes);
        foreach ($codes as $i => $code) {
            $plain = str_replace('-', '', $code);

            // Un atacante con la base de datos no puede recomputar el verificador
            // sin la clave del servidor: el hash guardado no es el SHA-256 desnudo.
            $this->assertNotSame(hash('sha256', $plain), $hashes[$i]);
            $this->assertNotContains(hash('sha256', $plain), $hashes);
        }
    }

    public function test_un_hash_del_formato_antiguo_de_40_bits_ya_no_valida(): void
    {
        $this->activate();

        // Simula una instalación que aún conserva hashes antiguos (10 hex, sin
        // pimienta): el verificador nuevo no debe aceptarlos ni consumirlos.
        $legacy = strtolower(bin2hex(random_bytes(5)));
        $this->setMeta('hwe_2fa_recovery', [hash('sha256', $legacy)]);

        $response = \HWE_Auth_Endpoints::handleConsumeRecoveryCode(new \WP_REST_Request([
            'code' => substr($legacy, 0, 5) . '-' . substr($legacy, 5),
        ]));

        $this->assertSame(401, $response->get_status());
        $this->assertCount(1, $this->meta('hwe_2fa_recovery'), 'el código antiguo ni siquiera se consume');
    }

    public function test_la_migracion_invalida_los_codigos_antiguos_una_sola_vez(): void
    {
        $options = [];
        $deleted = [];

        Functions\when('get_option')->alias(
            static function (string $name, mixed $default = false) use (&$options) {
                return $options[$name] ?? $default;
            }
        );
        Functions\when('update_option')->alias(
            static function (string $name, mixed $value, bool $autoload = true) use (&$options): bool {
                $options[$name] = $value;
                return true;
            }
        );
        Functions\when('delete_metadata')->alias(
            static function (string $type, int $objectId, string $key, mixed $value = '', bool $deleteAll = false) use (&$deleted): bool {
                $deleted[] = [$type, $objectId, $key, $deleteAll];
                return true;
            }
        );

        \HWE_Auth_Endpoints::migrateRecoveryCodesV2();
        \HWE_Auth_Endpoints::migrateRecoveryCodesV2();

        $this->assertSame(
            [['user', 0, 'hwe_2fa_recovery', true]],
            $deleted,
            'la migración borra los códigos de todos los usuarios y es idempotente'
        );
        $this->assertSame('1', $options['hwe_2fa_recovery_v2_migrated'] ?? null);
    }
}
