<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Functions;
use HWE\ControlCenter\BackupActions;
use HWE\ControlCenter\BackupConfig;
use HWE\ControlCenter\Cli;
use HWE\ControlCenter\Revalidation;
use HWE\ControlCenter\SecretsStorage;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

use function Patchwork\redefine;

/**
 * Importación de config (Backups → Importar) y `wp hwe setup` pasan por el mismo camino
 * que un guardado del panel (`AdminSave::persist`).
 *
 * Antes, la importación hacía `Storage::setAll()` con el JSON crudo (sin el saneado del
 * schema, sin sincronizar Woo, sin revalidar) y, si los secretos no descifraban, los
 * sobrescribía y luego «revertía» a un árbol vacío: importar un export de otro servidor
 * borraba las credenciales de pago locales. Y `wp hwe setup` no disparaba
 * `hwe_control_center_saved`, así que la moneda sembrada nunca llegaba a WooCommerce.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ConfigImportTest extends TestCase
{
    private const LOCAL_KEY = 'clave-local-de-pruebas-0123456789';

    /** @var array<string,mixed> */
    private array $options = [];
    /** @var list<string> */
    private array $events = [];
    /** @var list<array{0:array,1:array}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        define('HWE_SECRETS_KEY', self::LOCAL_KEY);

        Functions\stubs([
            'wp_unslash' => static fn ($v) => $v,
            'sanitize_text_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'sanitize_textarea_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'sanitize_email' => static fn ($v) => (string) $v,
            'esc_url_raw' => static fn ($v) => (string) $v,
            '__' => static fn ($v) => $v,
        ]);
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('do_action')->alias(function (string $hook, ...$args): void {
            if ($hook === 'hwe_control_center_saved') {
                $this->events[] = 'saved';
                $this->saved[] = [$args[0], $args[1]];
            }
        });

        redefine(BackupConfig::class . '::write', function (): void {
            $this->events[] = 'backup';
        });
        redefine(Revalidation::class . '::trigger', function (): void {
            $this->events[] = 'revalidate';
        });
    }

    /** Cifra como lo haría OTRO servidor (misma derivación, otra clave). */
    private static function foreignCiphertext(string $plain): string
    {
        $key = hash('sha256', 'hwe-secrets-v2:otra-clave-de-otro-servidor-0123', true);
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return 'v2:' . base64_encode($iv . $tag . $ct);
    }

    public function testSecretsFromAnotherServerLeaveTheLocalOnesUntouched(): void
    {
        SecretsStorage::setAll(['payments' => ['wompi' => ['events_secret' => 'secreto-local']]]);
        $before = $this->options['hwe_config_secrets'];

        $status = BackupActions::importConfig(
            ['brand' => ['name' => 'Tienda importada']],
            ['payments' => ['wompi' => ['events_secret' => self::foreignCiphertext('secreto-ajeno')]]]
        );

        $this->assertSame('ok_no_secrets', $status);
        $this->assertSame($before, $this->options['hwe_config_secrets']);
        $this->assertSame('secreto-local', SecretsStorage::get(['payments', 'wompi', 'events_secret']));
        $this->assertSame('Tienda importada', $this->options['hwe_config']['brand']['name']);
    }

    public function testSecretsThatDecryptLocallyAreMergedWithoutWipingOthers(): void
    {
        SecretsStorage::setAll(['payments' => ['avalpay' => ['secret_key' => 'avalpay-local']]]);
        SecretsStorage::setAll(['payments' => [
            'avalpay' => ['secret_key' => 'avalpay-local'],
            'wompi'   => ['events_secret' => 'wompi-del-export'],
        ]]);
        $exported = $this->options['hwe_config_secrets'];
        $exported['payments']['avalpay']['secret_key'] = '';
        SecretsStorage::setAll(['payments' => ['avalpay' => ['secret_key' => 'avalpay-local']]]);

        $status = BackupActions::importConfig([], $exported);

        $this->assertSame('ok', $status);
        $this->assertSame('wompi-del-export', SecretsStorage::get(['payments', 'wompi', 'events_secret']));
        $this->assertSame('avalpay-local', SecretsStorage::get(['payments', 'avalpay', 'secret_key']));
    }

    public function testImportedConfigGoesThroughTheSchemaSanitizer(): void
    {
        BackupActions::importConfig([
            'design'      => ['colors' => ['brand' => 'red;background:url(javascript:x)']],
            'no_existe'   => ['lo_que_sea' => 'x'],
        ], null);

        $this->assertSame('', $this->options['hwe_config']['design']['colors']['brand'] ?? 'AUSENTE');
        $this->assertArrayNotHasKey('no_existe', $this->options['hwe_config']);
    }

    public function testImportFiresTheSavedActionAndRevalidatesLikeThePanel(): void
    {
        BackupActions::importConfig(['brand' => ['name' => 'X']], null);

        $this->assertSame(['backup', 'saved', 'revalidate'], $this->events);
    }

    public function testCliSetupFiresTheSavedActionOnceAndRevalidatesOnce(): void
    {
        eval('class WP_CLI { public static array $out = []; '
            . 'public static function error($m) { throw new \RuntimeException($m); } '
            . 'public static function log($m) { self::$out[] = $m; } '
            . 'public static function success($m) { self::$out[] = $m; } }');
        $file = tempnam(sys_get_temp_dir(), 'hwe-setup-');
        file_put_contents($file, json_encode(['ecommerce' => ['currency' => 'EUR']]));

        try {
            Cli::setup([$file], []);
        } finally {
            unlink($file);
        }

        $this->assertSame(['backup', 'saved', 'revalidate'], $this->events);
        $this->assertSame('EUR', $this->saved[0][0]['ecommerce']['currency'] ?? null);
    }
}
