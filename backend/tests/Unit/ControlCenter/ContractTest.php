<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ControlCenter\Contract;
use HWE\ControlCenter\Schema;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * El contrato legible por máquina de `/wp-json/hwe/v1/config` (`contract/config.schema.json`).
 *
 * El fichero versionado se compara con lo que genera `Contract` desde el esquema del núcleo y
 * los grupos que pueblan los módulos: si alguien añade, quita o publica un campo y no regenera
 * el contrato, este test falla. Para regenerarlo:
 *
 *     HWE_UPDATE_CONTRACT=1 composer test -- --filter ContractTest
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ContractTest extends TestCase
{
    private const SNAPSHOT = __DIR__ . '/../../../../contract/config.schema.json';

    private const MODULE_SCHEMAS = [
        'hwe-loyalty/includes/Settings.php'         => ['HWE_Loyalty_Settings', 'schema'],
        'hwe-memberships/includes/Settings.php'     => ['HWE_Memberships_Settings', 'schema'],
        'hwe-shipping/includes/SettingsSchema.php'  => ['HWE\\Shipping\\SettingsSchema', 'schema'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->alias(static fn (string $key, mixed $default = false): mixed => $default);
        Functions\when('error_log')->justReturn(true);
    }

    /** Esquema efectivo con los grupos de los módulos, como en una pila con todos encendidos. */
    private function fullSchema(): array
    {
        $plugins = dirname(__DIR__, 3) . '/wp-content/plugins/';
        // El esquema de envío usa clases de su plugin (ajustes de distancia, tipo de campo de
        // prueba): mismo mapeo PSR-4 que el autocargador de `hwe-shipping.php`.
        spl_autoload_register(static function (string $class) use ($plugins): void {
            $prefix = 'HWE\\Shipping\\';
            if (str_starts_with($class, $prefix)) {
                $file = $plugins . 'hwe-shipping/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_readable($file)) {
                    require_once $file;
                }
            }
        });
        foreach (array_keys(self::MODULE_SCHEMAS) as $file) {
            require_once $plugins . $file;
        }
        Filters\expectApplied('hwe_control_center_schema')->andReturnUsing(
            static function (array $schema): array {
                foreach (self::MODULE_SCHEMAS as $callable) {
                    $schema = $callable($schema);
                }
                return $schema;
            }
        );
        return Schema::get();
    }

    public function testTheVersionedContractMatchesTheSchema(): void
    {
        $generated = json_encode(
            Contract::publicConfigSchema($this->fullSchema()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";

        if (getenv('HWE_UPDATE_CONTRACT') === '1') {
            file_put_contents(self::SNAPSHOT, $generated);
        }

        $this->assertFileExists(self::SNAPSHOT);
        $this->assertSame(
            file_get_contents(self::SNAPSHOT),
            $generated,
            'contract/config.schema.json no coincide con el esquema: regenéralo con HWE_UPDATE_CONTRACT=1 composer test -- --filter ContractTest'
        );
    }

    public function testOnlyPublicFieldsAndNoSecrets(): void
    {
        $contract = Contract::publicConfigSchema($this->fullSchema());
        $json     = json_encode($contract, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('"secret"', $json);
        $this->assertStringNotContainsString('maps_api_key', $json);
        $this->assertStringNotContainsString('consent_retention_years', $json);
        $this->assertSame('string', $contract['properties']['brand']['properties']['name']['type']);
        $this->assertSame('boolean', $contract['properties']['ecommerce']['properties']['search_enabled']['type']);
        $this->assertArrayHasKey('modules', $contract['properties']);
        $this->assertArrayNotHasKey('instance', $contract['properties'], 'Sin campos de instancia no hay grupo instance.');
    }

    public function testInstanceTypesFromTheInstanceGroup(): void
    {
        Filters\expectApplied('hwe_control_center_schema')->andReturnUsing(
            static function (array $schema): array {
                $schema['instance']['children']['example'] = [
                    'type'     => 'group',
                    'label'    => 'Ejemplo',
                    'children' => [
                        'greeting' => ['type' => 'text', 'label' => 'Saludo', 'default' => 'Hola', 'public' => true],
                        'limit'    => ['type' => 'number', 'default' => '3', 'public' => true],
                        'shown'    => ['type' => 'boolean', 'default' => true, 'public' => true],
                        'token'    => ['type' => 'secret', 'public' => true],
                        'internal' => ['type' => 'text', 'default' => ''],
                        'mine'     => ['type' => 'example_upper', 'public' => true],
                    ],
                ];
                return $schema;
            }
        );

        $types = Contract::instanceTypes(Schema::get());

        $this->assertStringContainsString('declare module "@/lib/config/types"', $types);
        $this->assertStringContainsString("    example?: {\n", $types);
        $this->assertStringContainsString("      /** Saludo */\n      greeting?: string;\n", $types);
        $this->assertStringContainsString('limit?: string | number;', $types);
        $this->assertStringContainsString('shown?: boolean;', $types);
        $this->assertStringContainsString('mine?: unknown;', $types);
        $this->assertStringNotContainsString('token', $types);
        $this->assertStringNotContainsString('internal', $types);
    }

    public function testInstanceTypesWithoutInstanceFields(): void
    {
        $types = Contract::instanceTypes(Schema::get());

        $this->assertStringContainsString('no tiene campos públicos', $types);
        $this->assertStringContainsString('export {};', $types);
    }
}
