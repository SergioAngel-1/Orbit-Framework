<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\Shipping\ReserveZone;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 2) . '/Stubs/wc-shipping.php';

/**
 * Zona de reserva del módulo (tarea 7): con el módulo activo la zona existe siempre, cubre el país
 * base y lleva el método `hwe_city_rate` en lugar del `flat_rate` que creaba antes. Solo toca la
 * zona que el propio plugin guardó en su opción; las demás zonas de la tienda no se modifican.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReserveZoneTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $config = [];
    /** @var array<string,mixed> */
    private array $options = [];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = ['ecommerce' => ['country' => 'ES']];
        $this->options = [];
        $this->logged = [];
        \WC_Shipping_Zone::$store = [];
        \WC_Shipping_Zone::$nextId = 1;
        \WC_Shipping_Zone::$nextInstance = 100;
        \WC_Shipping_Zone::$saves = 0;

        Functions\stubs([
            '__'                       => static fn (string $text): string => $text,
            'wc_get_price_decimals'    => static fn (): int => 2,
            'is_admin'                 => static fn (): bool => false,
            'plugin_dir_url'           => static fn (): string => '',
            'register_activation_hook' => static fn (): null => null,
            'did_action'               => static fn (): int => 0,
            'remove_accents'           => static fn (string $text): string => $text,
            'sanitize_text_field'      => static fn (mixed $value): string => trim((string) $value),
            'wp_cache_get'             => static fn (): bool => false,
            'wp_cache_set'             => static fn (): bool => true,
            'wp_cache_delete'          => static fn (): bool => true,
        ]);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;

            return true;
        });
        Functions\when('get_option')->alias(function (string $key, mixed $default = false): mixed {
            if ('hwe_config' === $key) {
                return $this->config;
            }

            return $this->options[$key] ?? $default;
        });
        Functions\when('update_option')->alias(function (string $key, mixed $value): bool {
            $this->options[$key] = $value;

            return true;
        });

        $this->loadOwnFile('mu-plugins/hwe-geo/packs.php');
        $this->loadOwnFile('plugins/hwe-shipping/hwe-shipping.php');
    }

    /** Zona que ya existe en la tienda, con sus métodos (`[id, instance]`). @param list<array{0:string,1:int}> $methods */
    private function seedZone(int $id, string $name, string $country, array $methods): void
    {
        \WC_Shipping_Zone::$store[$id] = [
            'name'      => $name,
            'locations' => [[$country, 'country']],
            'methods'   => array_map(
                static fn (array $method): \WC_Shipping_Method_Fake => new \WC_Shipping_Method_Fake($method[0], $method[1]),
                $methods
            ),
        ];
        \WC_Shipping_Zone::$nextId = max(\WC_Shipping_Zone::$nextId, $id + 1);
    }

    /** @return list<string> */
    private function methodIds(int $zoneId): array
    {
        return array_map(static fn (\WC_Shipping_Method_Fake $m): string => $m->id, \WC_Shipping_Zone::$store[$zoneId]['methods']);
    }

    public function testLaZonaExisteConElMetodoPropioAunqueLaReservaEsteApagada(): void
    {
        $this->config = ['ecommerce' => ['country' => 'ES'], 'shipping' => ['reserve_enabled' => false]];

        ReserveZone::sync();

        $this->assertSame([['ES', 'country']], \WC_Shipping_Zone::$store[1]['locations']);
        $this->assertSame(['hwe_city_rate'], $this->methodIds(1));
        $this->assertSame(1, $this->options['hwe_shipping_reserve_zone_id']);
    }

    public function testLaZonaLlevaElNombreDeLaReservaYElPaisDeLaTienda(): void
    {
        $this->config = ['ecommerce' => ['country' => 'ES'], 'shipping' => ['reserve_name' => 'Envío península']];

        ReserveZone::sync();

        $this->assertSame('Envío península', \WC_Shipping_Zone::$store[1]['name']);
        $this->assertSame([['ES', 'country']], \WC_Shipping_Zone::$store[1]['locations']);
    }

    public function testSustituyeElFlatRateQueElPluginHabiaCreado(): void
    {
        $this->seedZone(1, 'Envío nacional', 'ES', [['flat_rate', 100]]);
        $this->options['hwe_shipping_reserve_zone_id'] = 1;

        ReserveZone::sync();

        $this->assertSame(['hwe_city_rate'], $this->methodIds(1));
    }

    public function testSincronizarDosVecesEsIdempotente(): void
    {
        ReserveZone::sync();
        ReserveZone::sync();

        $this->assertCount(1, \WC_Shipping_Zone::$store);
        $this->assertSame(['hwe_city_rate'], $this->methodIds(1));
        $this->assertSame(1, $this->options['hwe_shipping_reserve_zone_id']);
    }

    public function testNoTocaOtrasZonasDeLaTienda(): void
    {
        $this->seedZone(2, 'Otra zona', 'FR', [['flat_rate', 200]]);
        $this->options['woocommerce_flat_rate_200_settings'] = ['cost' => '3'];

        ReserveZone::sync();

        $this->assertSame('Otra zona', \WC_Shipping_Zone::$store[2]['name']);
        $this->assertSame([['FR', 'country']], \WC_Shipping_Zone::$store[2]['locations']);
        $this->assertSame(['flat_rate'], $this->methodIds(2));
        $this->assertSame(['cost' => '3'], $this->options['woocommerce_flat_rate_200_settings']);
    }

    public function testYaNoEscribeLosAjustesDelFlatRate(): void
    {
        ReserveZone::sync();

        $this->assertArrayNotHasKey('woocommerce_flat_rate_100_settings', $this->options);
    }

    public function testSiLaZonaSeBorraSeCreaOtra(): void
    {
        $this->options['hwe_shipping_reserve_zone_id'] = 55;

        ReserveZone::sync();

        $this->assertSame(1, $this->options['hwe_shipping_reserve_zone_id']);
        $this->assertSame(['hwe_city_rate'], $this->methodIds(1));
    }

    public function testSinPaisNoCreaLaZonaYLoRegistra(): void
    {
        $this->config = ['ecommerce' => ['country' => '']];
        Functions\when('hwe_geo_store_country')->justReturn('');
        Functions\when('wc_get_base_location')->justReturn(['country' => '']);

        ReserveZone::sync();

        $this->assertSame([], \WC_Shipping_Zone::$store);
        $this->assertStringContainsString('[hwe] shipping:', $this->logged[0]);
    }
}
