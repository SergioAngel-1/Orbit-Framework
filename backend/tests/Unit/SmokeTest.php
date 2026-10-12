<?php

declare(strict_types=1);

namespace HWE\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\ControlCenter\SecretsStorage;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Prueba de humo de la tubería PHPUnit + Brain Monkey: si esto pasa, un test
 * puede cargar código propio sin WordPress y simular sus funciones.
 *
 * El cifrado de SecretsStorage sirve de ejemplo porque es lógica pura salvo por
 * `get_option`/`update_option`, que aquí se simulan con un almacén en memoria.
 */
final class SmokeTest extends TestCase
{
    // La clave se define como constante: proceso aparte para no contaminar a
    // los demás tests (las constantes no se pueden deshacer; ver bootstrap.php).
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_secrets_storage_cifra_y_descifra_ida_y_vuelta(): void
    {
        define('HWE_SECRETS_KEY', 'clave-solo-para-tests-0123456789');

        $store = [];
        Functions\when('update_option')->alias(
            static function (string $key, mixed $value) use (&$store): bool {
                $store[$key] = $value;
                return true;
            }
        );
        // `use (&$store)`, no `fn`: las arrow functions capturan por valor y verían un almacén vacío.
        Functions\when('get_option')->alias(
            static function (string $key, mixed $default = false) use (&$store): mixed {
                return $store[$key] ?? $default;
            }
        );

        SecretsStorage::setAll(['payments' => ['wompi' => ['secret_key' => 'sk_test_123']]]);

        // Lo persistido va cifrado (formato v2, AES-GCM) y nunca en claro.
        $persisted = $store['hwe_config_secrets']['payments']['wompi']['secret_key'];
        $this->assertStringStartsWith('v2:', $persisted);
        $this->assertStringNotContainsString('sk_test_123', $persisted);

        // Y se recupera tal cual; una ruta inexistente devuelve el valor por defecto.
        $this->assertSame('sk_test_123', SecretsStorage::get(['payments', 'wompi', 'secret_key']));
        $this->assertSame('por-defecto', SecretsStorage::get(['no', 'existe'], 'por-defecto'));
    }

    // El archivo se carga con `require_once`: proceso aparte para que otros tests
    // puedan volver a cargarlo y ver sus hooks de nivel superior.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_carga_un_mu_plugin_con_codigo_de_nivel_superior(): void
    {
        // Patrón de bootstrap.php: primero la expectativa del hook, después la carga.
        Actions\expectAdded('init')->once();

        $this->loadOwnFile('mu-plugins/hwe-control-center.php');
    }

    public function test_brain_monkey_intercepta_error_log(): void
    {
        // Las tareas de seguridad comprueban que los fallos se registran con
        // `error_log('[hwe] …')`: eso exige que `error_log` sea redefinible
        // (backend/patchwork.json). Este test guarda esa configuración.
        Functions\expect('error_log')->once()->with('[hwe] prueba');

        error_log('[hwe] prueba');
    }
}
