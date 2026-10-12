<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Functions;
use HWE\ControlCenter\SecretsStorage;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Cifrado de los secretos del Control Center (credenciales de pasarela, SMTP…).
 *
 * Antes, si no había ninguna constante de wp-config.php (HWE_SECRETS_KEY, AUTH_KEY,
 * SECURE_AUTH_KEY), se degradaba EN SILENCIO a la literal `hwe-fallback-insecure-key`:
 * una clave pública (vive en el código fuente) e idéntica en cualquier instalación
 * que cayera ahí, de modo que las credenciales de las pasarelas de pago y del SMTP
 * quedaban cifradas con una clave predecible. Ahora falla cerrado y lo registra.
 *
 * Las constantes son globales al proceso y no se pueden deshacer: cada test corre en
 * el suyo.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SecretsStorageTest extends TestCase
{
    private const OPTION = 'hwe_config_secrets';
    private const SECRET = 'sk_live_NO-DEBE-APARECER-EN-NINGUN-LOG';

    /** @var array<string, mixed> */
    private array $options = [];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    private function logged(): string
    {
        return implode("\n", $this->logged);
    }

    /** @return array<string, mixed> */
    private function tree(): array
    {
        return ['payments' => ['wompi' => ['secret_key' => self::SECRET, 'public_key' => 'pk_1'], 'bold' => ['api_key' => 'b_2']]];
    }

    // ── Con clave: ida y vuelta ────────────────────────────────────────────

    public function test_ida_y_vuelta_con_la_clave_dedicada(): void
    {
        define('HWE_SECRETS_KEY', 'clave-dedicada-de-pruebas-0123456789');

        SecretsStorage::setAll($this->tree());

        $this->assertSame($this->tree(), SecretsStorage::getAll());
        $this->assertSame([], $this->logged);
    }

    public function test_sirve_una_salt_de_wordpress_como_clave(): void
    {
        define('AUTH_KEY', 'salt-auth-key-de-pruebas-0123456789');

        SecretsStorage::setAll($this->tree());

        $this->assertSame(self::SECRET, SecretsStorage::get(['payments', 'wompi', 'secret_key']));
    }

    public function test_sirve_la_secure_auth_key_como_ultima_opcion(): void
    {
        define('SECURE_AUTH_KEY', 'salt-secure-auth-key-de-pruebas-0123');

        SecretsStorage::setAll($this->tree());

        $this->assertSame(self::SECRET, SecretsStorage::get(['payments', 'wompi', 'secret_key']));
    }

    public function test_lo_guardado_va_cifrado_en_formato_v2_y_nunca_en_claro(): void
    {
        define('HWE_SECRETS_KEY', 'clave-dedicada-de-pruebas-0123456789');

        SecretsStorage::setAll($this->tree());

        $stored = $this->options[self::OPTION]['payments']['wompi']['secret_key'];
        $this->assertStringStartsWith('v2:', $stored);
        $this->assertStringNotContainsString(self::SECRET, json_encode($this->options));
    }

    public function test_get_devuelve_el_valor_por_defecto_si_la_ruta_no_existe(): void
    {
        define('HWE_SECRETS_KEY', 'clave-dedicada-de-pruebas-0123456789');
        SecretsStorage::setAll($this->tree());

        $this->assertSame('por-defecto', SecretsStorage::get(['payments', 'nope', 'x'], 'por-defecto'));
        $this->assertSame('', SecretsStorage::get(['payments']), 'una rama no es un secreto');
    }

    public function test_un_secreto_vacio_se_guarda_vacio_sin_error(): void
    {
        define('HWE_SECRETS_KEY', 'clave-dedicada-de-pruebas-0123456789');

        $this->assertTrue(SecretsStorage::setAll(['payments' => ['wompi' => ['secret_key' => '']]]));

        $this->assertSame('', SecretsStorage::get(['payments', 'wompi', 'secret_key']));
        $this->assertSame([], $this->logged);
    }

    public function test_mergeNonEmpty_conserva_el_secreto_existente_si_el_nuevo_viene_vacio(): void
    {
        define('HWE_SECRETS_KEY', 'clave-dedicada-de-pruebas-0123456789');
        SecretsStorage::setAll($this->tree());

        SecretsStorage::mergeNonEmpty(['payments' => ['wompi' => ['secret_key' => '', 'public_key' => 'pk_nueva']]]);

        $this->assertSame(self::SECRET, SecretsStorage::get(['payments', 'wompi', 'secret_key']));
        $this->assertSame('pk_nueva', SecretsStorage::get(['payments', 'wompi', 'public_key']));
        $this->assertSame('b_2', SecretsStorage::get(['payments', 'bold', 'api_key']));
    }

    // ── Sin clave: falla cerrado ───────────────────────────────────────────

    /** Un valor v2 tal como lo guardaba la versión anterior sin ninguna constante. */
    private function encryptedWithOldLiteralKey(string $plain): string
    {
        $key = hash('sha256', 'hwe-secrets-v2:hwe-fallback-insecure-key', true);
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return 'v2:' . base64_encode($iv . $tag . $ct);
    }

    public function test_sin_ninguna_clave_no_se_descifra_ni_lo_cifrado_con_la_clave_literal_antigua(): void
    {
        $this->options[self::OPTION] = ['payments' => ['wompi' => ['secret_key' => $this->encryptedWithOldLiteralKey(self::SECRET)]]];

        $this->assertSame('', SecretsStorage::get(['payments', 'wompi', 'secret_key']));
        $this->assertSame(['payments' => ['wompi' => ['secret_key' => '']]], SecretsStorage::getAll());
    }

    public function test_sin_ninguna_clave_lo_registra_con_el_prefijo_hwe_y_sin_revelar_nada(): void
    {
        $this->options[self::OPTION] = ['payments' => ['wompi' => ['secret_key' => $this->encryptedWithOldLiteralKey(self::SECRET)]]];

        SecretsStorage::getAll();

        $this->assertNotEmpty($this->logged);
        $this->assertMatchesRegularExpression('/^\[hwe\] control-center: /', $this->logged[0]);
        $this->assertStringNotContainsString(self::SECRET, $this->logged());
        $this->assertStringNotContainsString('hwe-fallback-insecure-key', $this->logged());
    }

    public function test_sin_clave_el_aviso_se_registra_una_vez_por_peticion_no_una_por_secreto(): void
    {
        // El árbol de secretos se lee muchas veces por petición y cada hoja pasa por el
        // cifrado: sin deduplicar, una instalación sin clave inundaría el log.
        $tree = [];
        foreach (range(1, 6) as $i) {
            $tree["pasarela$i"] = ['secret' => $this->encryptedWithOldLiteralKey("secreto-$i")];
        }
        $this->options[self::OPTION] = $tree;

        SecretsStorage::getAll();
        SecretsStorage::getAll();

        $noKey = array_filter($this->logged, fn (string $line) => str_contains($line, 'ninguna clave'));
        $this->assertCount(1, $noKey);
    }

    public function test_sin_clave_guardar_NO_sobrescribe_los_secretos_ya_guardados(): void
    {
        // Si la clave solo falta por una mala configuración del entorno, guardar con
        // cadenas vacías destruiría para siempre credenciales que aún se podrían leer
        // al restaurar la clave.
        $previous = ['payments' => ['wompi' => ['secret_key' => $this->encryptedWithOldLiteralKey('sk_previo')]]];
        $this->options[self::OPTION] = $previous;

        $saved = SecretsStorage::setAll($this->tree());

        $this->assertFalse($saved);
        $this->assertSame($previous, $this->options[self::OPTION], 'la opción guardada no cambia');
        $this->assertStringContainsString('no se guardó', $this->logged());
    }

    public function test_sin_clave_mergeNonEmpty_tampoco_toca_lo_guardado(): void
    {
        $previous = ['payments' => ['wompi' => ['secret_key' => $this->encryptedWithOldLiteralKey('sk_previo')]]];
        $this->options[self::OPTION] = $previous;

        SecretsStorage::mergeNonEmpty(['payments' => ['wompi' => ['public_key' => 'pk_nueva']]]);

        $this->assertSame($previous, $this->options[self::OPTION]);
    }

    public function test_sin_clave_no_se_escribe_nunca_ni_siquiera_con_solo_vacios(): void
    {
        // Escribir vacíos encima de lo guardado lo destruiría igual que escribir basura.
        $previous = ['payments' => ['wompi' => ['secret_key' => $this->encryptedWithOldLiteralKey('sk_previo')]]];
        $this->options[self::OPTION] = $previous;

        $saved = SecretsStorage::setAll(['payments' => ['wompi' => ['secret_key' => '']]]);
        SecretsStorage::mergeNonEmpty(['payments' => ['wompi' => ['secret_key' => '']]]);

        $this->assertFalse($saved);
        $this->assertSame($previous, $this->options[self::OPTION]);
    }

    // ── Formato legacy (AES-CBC) ───────────────────────────────────────────

    /** Un valor legacy (CBC, sin prefijo) cifrado con la clave derivada de `$source`. */
    private function legacyEncrypted(string $plain, string $source): string
    {
        $key = hash('sha256', 'hwe-secrets:' . $source, true);
        $iv = random_bytes(16);

        return base64_encode($iv . openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv));
    }

    public function test_un_valor_legacy_se_sigue_leyendo_con_la_clave_jwt(): void
    {
        define('GRAPHQL_JWT_AUTH_SECRET_KEY', 'jwt-secret-de-pruebas-0123456789abcdef');
        define('HWE_SECRETS_KEY', 'clave-dedicada-de-pruebas-0123456789');
        $this->options[self::OPTION] = ['smtp' => ['password' => $this->legacyEncrypted('clave-smtp', 'jwt-secret-de-pruebas-0123456789abcdef')]];

        $this->assertSame('clave-smtp', SecretsStorage::get(['smtp', 'password']));
    }

    public function test_un_valor_legacy_cifrado_con_la_clave_literal_antigua_no_se_lee(): void
    {
        $this->options[self::OPTION] = ['smtp' => ['password' => $this->legacyEncrypted('clave-smtp', 'hwe-fallback-insecure-key')]];

        $this->assertSame('', SecretsStorage::get(['smtp', 'password']));
        $this->assertNotEmpty($this->logged);
    }

    // ── Valor corrupto o manipulado, con clave ─────────────────────────────

    public function test_un_valor_manipulado_se_trata_como_vacio_y_se_registra_con_su_ruta_sin_el_valor(): void
    {
        define('HWE_SECRETS_KEY', 'clave-dedicada-de-pruebas-0123456789');
        SecretsStorage::setAll($this->tree());
        $stored = $this->options[self::OPTION]['payments']['wompi']['secret_key'];
        $this->options[self::OPTION]['payments']['wompi']['secret_key'] = substr($stored, 0, -4) . 'AAAA';
        $this->logged = [];

        $value = SecretsStorage::get(['payments', 'wompi', 'secret_key']);

        $this->assertSame('', $value, 'el cifrado es autenticado: un valor alterado no se descifra');
        $this->assertStringContainsString('payments.wompi.secret_key', $this->logged());
        $this->assertStringNotContainsString(self::SECRET, $this->logged());
        $this->assertStringNotContainsString($stored, $this->logged());
    }

    public function test_el_mismo_fallo_no_se_repite_en_el_log_dentro_de_una_peticion(): void
    {
        define('HWE_SECRETS_KEY', 'clave-dedicada-de-pruebas-0123456789');
        SecretsStorage::setAll($this->tree());
        $this->options[self::OPTION]['payments']['wompi']['secret_key'] = 'v2:' . base64_encode(str_repeat('x', 60));
        $this->logged = [];

        SecretsStorage::getAll();
        SecretsStorage::getAll();
        SecretsStorage::getAll();

        $this->assertCount(1, $this->logged);
    }

    public function test_un_valor_corrupto_no_impide_leer_el_resto(): void
    {
        define('HWE_SECRETS_KEY', 'clave-dedicada-de-pruebas-0123456789');
        SecretsStorage::setAll($this->tree());
        $this->options[self::OPTION]['payments']['wompi']['secret_key'] = 'v2:' . base64_encode(str_repeat('x', 60));

        $this->assertSame('b_2', SecretsStorage::get(['payments', 'bold', 'api_key']));
        $this->assertSame('pk_1', SecretsStorage::get(['payments', 'wompi', 'public_key']));
    }
}
