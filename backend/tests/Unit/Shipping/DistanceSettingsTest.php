<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\ControlCenter\SecretsStorage;
use HWE\Shipping\Distance\Settings;
use HWE\Shipping\SettingsSchema;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Ajustes de distancia (`Settings`): leídos del Control Center con saneado y por defecto, y el
 * esquema que los declara (`SettingsSchema`). Cada test corre en su proceso porque la clave de
 * cifrado de los secretos es una constante global.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DistanceSettingsTest extends TestCase
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
        Functions\when('error_log')->justReturn(true);
        Functions\stubs([
            'plugin_dir_url'           => static fn () => '',
            'register_activation_hook' => static fn () => null,
            'is_admin'                 => static fn () => false,
        ]);
        $this->loadOwnFile('plugins/hwe-shipping/hwe-shipping.php');
    }

    /** Guarda ajustes del Control Center (sección `shipping` e `integrations`) sin cifrar. */
    private function config(array $config): void
    {
        $this->options['hwe_config'] = $config;
    }

    public function testSinAjustesGuardadosDevuelveLosValoresPorDefecto(): void
    {
        $this->assertSame('straight_line', Settings::method());
        $this->assertSame('none', Settings::mapsProvider());
        $this->assertSame('', Settings::mapsKey());
        $this->assertFalse(Settings::mapsConfigured());
        $this->assertSame('driving', Settings::travelMode());
        $this->assertSame(30, Settings::incrementPct());
        $this->assertSame(24, Settings::cacheTtlHours());
    }

    public function testElMetodoSoloAceptaMapasOLineaRecta(): void
    {
        $this->config(['shipping' => ['distance_method' => 'maps']]);
        $this->assertSame('maps', Settings::method());

        $this->config(['shipping' => ['distance_method' => 'a_ver']]);
        $this->assertSame('straight_line', Settings::method(), 'un valor desconocido cae a línea recta');
    }

    public function testElProveedorDeMapasSoloAceptaLosTresConocidos(): void
    {
        $this->config(['integrations' => ['maps_provider' => 'google_maps']]);
        $this->assertSame('google_maps', Settings::mapsProvider());

        $this->config(['integrations' => ['maps_provider' => 'openrouteservice']]);
        $this->assertSame('openrouteservice', Settings::mapsProvider());

        $this->config(['integrations' => ['maps_provider' => 'proveedor_inventado']]);
        $this->assertSame('none', Settings::mapsProvider());
    }

    public function testElModoDeViajeSoloPuedeSerConduciroCaminar(): void
    {
        $this->config(['shipping' => ['distance_travel_mode' => 'walking']]);
        $this->assertSame('walking', Settings::travelMode());

        $this->config(['shipping' => ['distance_travel_mode' => 'volando']]);
        $this->assertSame('driving', Settings::travelMode());
    }

    public function testElIncrementoSeSaneaAlRangoCeroCienDoscientos(): void
    {
        $cases = [
            '45'   => 45,
            150    => 150,
            250    => 200,
            -5     => 0,
            'abc'  => 30,
        ];
        foreach ($cases as $stored => $expected) {
            $this->config(['shipping' => ['distance_straight_line_increment' => $stored]]);
            $this->assertSame($expected, Settings::incrementPct(), 'valor guardado: ' . var_export($stored, true));
        }
    }

    public function testElTiempoDeCacheSeSaneaAMinimoUnaHora(): void
    {
        $cases = [
            '12'  => 12,
            48    => 48,
            0     => 1,
            -3    => 1,
            'abc' => 24,
        ];
        foreach ($cases as $stored => $expected) {
            $this->config(['shipping' => ['distance_cache_ttl_hours' => $stored]]);
            $this->assertSame($expected, Settings::cacheTtlHours(), 'valor guardado: ' . var_export($stored, true));
        }
    }

    public function testLosMapasSoloQuedanConfiguradosConProveedorYClave(): void
    {
        define('HWE_SECRETS_KEY', 'clave-de-pruebas-para-los-ajustes-de-distancia');
        SecretsStorage::setAll(['integrations' => ['maps_api_key' => 'clave-de-pruebas']]);

        $this->config(['integrations' => ['maps_provider' => 'google_maps']]);
        $this->assertTrue(Settings::mapsConfigured());
        $this->assertSame('clave-de-pruebas', Settings::mapsKey());

        $this->config(['integrations' => ['maps_provider' => 'none']]);
        $this->assertFalse(Settings::mapsConfigured(), 'sin proveedor no hay mapas aunque haya clave');
    }

    public function testSinClaveNoHayMapasConfigurados(): void
    {
        $this->config(['integrations' => ['maps_provider' => 'openrouteservice']]);

        $this->assertFalse(Settings::mapsConfigured());
        $this->assertSame('', Settings::mapsKey());
    }

    public function testElEsquemaDeDistanciaDeclaraLosCamposConSusDefectos(): void
    {
        $schema   = SettingsSchema::schema(['shipping' => ['type' => 'group', 'children' => []]]);
        $children = $schema['shipping']['children'];

        $this->assertSame(
            ['reserve_enabled', 'reserve_name', 'reserve_cost', 'distance_method', 'distance_travel_mode', 'distance_straight_line_increment', 'distance_cache_ttl_hours', 'distance_connection_test'],
            array_keys($children)
        );
        $this->assertSame('straight_line', $children['distance_method']['default']);
        $this->assertSame('driving', $children['distance_travel_mode']['default']);
        $this->assertSame('30', (string) $children['distance_straight_line_increment']['default']);
        $this->assertSame('24', (string) $children['distance_cache_ttl_hours']['default']);
    }
}
