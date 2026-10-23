<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Functions;
use HWE\ControlCenter\AdminSave;
use HWE\ControlCenter\RestApi;
use HWE\ControlCenter\Schema;
use HWE\ControlCenter\SecretsStorage;
use HWE\ControlCenter\Storage;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Integraciones → Mapas: la integración genérica de mapas del núcleo.
 *
 * El proveedor y la clave los usa solo el backend (el módulo `shipping` es quien llama a
 * los proveedores). El navegador no los necesita, así que ninguno de los dos sale por la
 * config pública; la clave, además, se guarda cifrada con `SecretsStorage`.
 */
final class MapsIntegrationTest extends TestCase
{
    /** @var array<string,mixed> Opciones de WordPress simuladas (`hwe_config`, `hwe_config_secrets`…). */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->alias(fn (string $key, mixed $default = false): mixed => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, mixed $value): bool {
            $this->options[$key] = $value;
            return true;
        });
        // Saneado del camino real de guardado (`AdminSave::sanitizeAndSplit`).
        Functions\stubs([
            'sanitize_text_field'     => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'sanitize_textarea_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'sanitize_email'          => static fn ($v) => (string) $v,
            'esc_url_raw'             => static fn ($v) => (string) $v,
        ]);
        Functions\when('error_log')->justReturn(true);
        // El config público incluye el icono del sitio: sin WordPress real, vacío.
        Functions\when('get_site_icon_url')->justReturn('');
    }

    /** @return array<string,mixed> */
    private function publicConfig(): array
    {
        return RestApi::handleGet(new \WP_REST_Request())->get_data();
    }

    public function testTheMapsProviderIsASelectWithTheSupportedProviders(): void
    {
        $field = Schema::get()['integrations']['children']['maps_provider'];

        $this->assertSame('select', $field['type']);
        $this->assertSame('none', $field['default'], 'Sin proveedor se calcula en línea recta.');
        $this->assertSame(['none', 'google_maps', 'openrouteservice'], array_keys($field['options']));
        $this->assertArrayHasKey('public', $field);
        $this->assertFalse($field['public'], 'El proveedor se resuelve en el servidor, no en el navegador.');
    }

    public function testTheMapsKeyIsASecretAndExplainsItsProtections(): void
    {
        $field       = Schema::get()['integrations']['children']['maps_api_key'];
        $description = (string) ($field['description'] ?? '');

        $this->assertSame('secret', $field['type']);
        $this->assertArrayHasKey('public', $field);
        $this->assertFalse($field['public'], 'La clave no puede salir por la config pública.');
        $this->assertArrayNotHasKey('default', $field, 'Un secreto no lleva valor por defecto.');

        // Lo que el editor necesita saber para no exponerla ni romperla.
        $this->assertStringContainsStringIgnoringCase('cifrada', $description);
        $this->assertStringContainsStringIgnoringCase('servidor', $description);
        $this->assertStringContainsStringIgnoringCase('Routes', $description);
        $this->assertStringContainsStringIgnoringCase('Geocoding', $description);
        $this->assertStringContainsStringIgnoringCase('IP', $description);
        $this->assertStringContainsStringIgnoringCase('referer', $description);
    }

    public function testNeitherFieldReachesThePublicConfigEvenWithAStoredValue(): void
    {
        // Un valor almacenado no cambia la respuesta: el proveedor es de servidor y la clave
        // es un secreto, aunque una importación la hubiera dejado en el árbol equivocado.
        $this->options['hwe_config'] = [
            'integrations' => [
                'maps_provider' => 'google_maps',
                'maps_api_key'  => 'clave-que-no-debe-salir',
            ],
        ];

        $public = $this->publicConfig();

        $this->assertArrayHasKey('integrations', $public, 'El grupo sigue publicado con sus campos públicos.');
        $this->assertArrayNotHasKey('maps_provider', $public['integrations']);
        $this->assertArrayNotHasKey('maps_api_key', $public['integrations']);

        $json = json_encode($public, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('maps_provider', $json);
        $this->assertStringNotContainsString('maps_api_key', $json);
        $this->assertStringNotContainsString('clave-que-no-debe-salir', $json);
    }

    /**
     * El contrato que consumen los módulos: el proveedor se lee de la config y la clave del
     * almacén cifrado. Además, la clave no puede acabar en `hwe_config` (la opción pública).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheMapsSettingsAreReadableAtThePathsTheModulesUse(): void
    {
        define('HWE_SECRETS_KEY', 'clave-de-pruebas-para-la-integracion-de-mapas');

        [$config, $secrets] = AdminSave::sanitizeAndSplit(Schema::get(), [
            'integrations' => [
                'maps_provider' => 'openrouteservice',
                'maps_api_key'  => '  clave-de-pruebas  ',
            ],
        ]);
        Storage::merge($config);
        SecretsStorage::mergeNonEmpty($secrets);

        $this->assertSame('openrouteservice', Storage::get(['integrations', 'maps_provider'], 'none'));
        $this->assertSame('clave-de-pruebas', SecretsStorage::get(['integrations', 'maps_api_key']));

        $stored = (string) ($this->options['hwe_config_secrets']['integrations']['maps_api_key'] ?? '');
        $this->assertStringStartsWith('v2:', $stored, 'La clave se guarda cifrada (AES-256-GCM).');
        $this->assertStringNotContainsString('clave-de-pruebas', $stored);
        $this->assertArrayNotHasKey('maps_api_key', $this->options['hwe_config']['integrations'] ?? []);
    }
}
