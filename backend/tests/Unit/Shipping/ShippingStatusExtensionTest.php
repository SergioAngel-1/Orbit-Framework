<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\Shipping\DeliveryPoint;
use HWE\Shipping\ShippingStatus;
use HWE\Shipping\StoreApiExtension;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 2) . '/Stubs/wc-shipping.php';
require_once dirname(__DIR__, 2) . '/Stubs/wc-store-api.php';

// Constante de `wpdb` que WooCommerce usa como `schema_type`; en los tests basta su valor.
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/**
 * Extensión `cart.extensions.hwe_shipping` de la Store API (tarea 8, spec §8.3): el estado del envío
 * del primer paquete del carrito. Es la misma decisión que usa el método de envío (`ShippingStatus`),
 * sin importes ni referencias, y sin romper cuando no hay carrito.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ShippingStatusExtensionTest extends TestCase
{
    private const ORIGIN_LAT = 40.4168;
    private const ORIGIN_LNG = -3.7038;
    /** Un punto a unos 1,1 km del origen, en línea recta. */
    private const NEAR_LAT = 40.4268;

    /** @var array<string,mixed> */
    private array $config = [];
    /** @var array<string,mixed> */
    private array $options = [];
    /** @var list<\WP_Post> */
    private array $posts = [];
    /** @var array<int,array<string,string>> */
    private array $meta = [];
    /** @var array<string,mixed> */
    private array $transients = [];
    private ExtensionWc $wc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = ['ecommerce' => ['country' => 'ES']];
        $this->options = [];
        $this->posts = [];
        $this->meta = [];
        $this->transients = [];
        $this->wc = new ExtensionWc();

        Functions\stubs([
            '__'                       => static fn (string $text): string => $text,
            'is_admin'                 => static fn (): bool => false,
            'plugin_dir_url'           => static fn (): string => '',
            'register_activation_hook' => static fn (): null => null,
            'did_action'               => static fn (): int => 0,
            'wc_get_price_decimals'    => static fn (): int => 2,
            'wp_cache_get'             => static fn (): bool => false,
            'wp_cache_set'             => static fn (): bool => true,
            'wp_cache_delete'          => static fn (): bool => true,
            'remove_accents'           => static fn (string $text): string => $text,
            'sanitize_text_field'      => static fn (mixed $value): string => trim((string) (is_scalar($value) ? $value : '')),
            'apply_filters'            => static fn (string $hook, mixed $value): mixed => $value,
        ]);
        Functions\when('WC')->justReturn($this->wc);
        Functions\when('error_log')->justReturn(true);
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
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
        Functions\when('get_posts')->alias(fn (): array => $this->posts);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key = '', bool $single = false): string => $this->meta[$id][$key] ?? ''
        );
        Functions\when('get_the_title')->alias(
            static fn (mixed $post): string => is_object($post) ? (string) ($post->post_title ?? '') : ''
        );

        $this->loadOwnFile('mu-plugins/hwe-geo/packs.php');
        $this->loadOwnFile('plugins/hwe-shipping/hwe-shipping.php');
        ShippingStatus::forget();
    }

    /* ------------------------------------------------------------------ utilidades */

    /** Registra una ciudad (post del CPT) con su tipo, sus opciones y, si es por distancia, origen y radio. */
    private function city(int $id, string $name, string $type, array $options, ?array $origin = null, string $coverage = '10'): void
    {
        $post = new \WP_Post();
        $post->ID = $id;
        $post->post_title = $name;
        $post->post_status = 'publish';
        $this->posts[] = $post;

        $meta = [
            '_hwe_shipping_city_type'    => $type,
            '_hwe_shipping_city_options' => (string) json_encode($options),
        ];
        if (null !== $origin) {
            $meta['_hwe_shipping_city_origin_lat'] = (string) $origin['lat'];
            $meta['_hwe_shipping_city_origin_lng'] = (string) $origin['lng'];
            $meta['_hwe_shipping_city_origin_label'] = 'Referencia';
            $meta['_hwe_shipping_city_coverage_km'] = $coverage;
        }
        $this->meta[$id] = $meta;
    }

    /** @return array<string,mixed> */
    private function option(string $id, string $name, array $extra = []): array
    {
        return array_merge([
            'id'            => $id,
            'name'          => $name,
            'free_eligible' => false,
            'amount'        => null,
            'price_per_km'  => null,
            'min_cost'      => 0,
            'max_cost'      => 0,
        ], $extra);
    }

    /** Paquete con calle, como lo entrega WooCommerce al cálculo. */
    private function package(string $city = 'Madrid', string $country = 'ES', string $address = 'Calle Mayor 1'): array
    {
        return [
            'contents'        => [],
            'applied_coupons' => [],
            'destination'     => ['address_1' => $address, 'city' => $city, 'country' => $country],
        ];
    }

    /** Declara el pin del cliente para la dirección del paquete por defecto (sesión de WooCommerce). */
    private function setPoint(float $lat = self::NEAR_LAT): void
    {
        DeliveryPoint::updatePoint([
            'action'    => 'set_point',
            'lat'       => $lat,
            'lng'       => self::ORIGIN_LNG,
            'address_1' => 'Calle Mayor 1',
            'city'      => 'Madrid',
            'country'   => 'ES',
        ]);
    }

    /** @return array<string,mixed> */
    private function state(array $package): array
    {
        $this->wc->cart = new ExtensionCart([$package]);

        return StoreApiExtension::cartState();
    }

    /* ------------------------------------------------------------------ registro y esquema */

    public function testSeRegistraLaExtensionHweShippingEnElCarrito(): void
    {
        $registered = [];
        Functions\when('woocommerce_store_api_register_endpoint_data')->alias(
            function (array $args) use (&$registered): void {
                $registered = $args;
            }
        );

        StoreApiExtension::registerStoreApi();

        $this->assertSame('hwe_shipping', $registered['namespace']);
        $this->assertSame('cart', $registered['endpoint']);
        $this->assertSame([StoreApiExtension::class, 'cartState'], $registered['data_callback']);
        $this->assertSame([StoreApiExtension::class, 'cartStateSchema'], $registered['schema_callback']);
    }

    public function testElEsquemaDeclaraModoEstadoYLosDatosDeDistancia(): void
    {
        $schema = StoreApiExtension::cartStateSchema();

        $this->assertSame(['fixed', 'distance', 'reserve', 'none'], $schema['mode']['enum']);
        $this->assertSame(['ok', 'needs_address', 'needs_location', 'out_of_coverage'], $schema['status']['enum']);
        $this->assertSame('number', $schema['distance_km']['type']);
        $this->assertSame('integer', $schema['duration_min']['type']);
        $this->assertSame('boolean', $schema['fallback']['type']);
    }

    /* ------------------------------------------------------------------ estado neutro */

    public function testSinCarritoElEstadoEsNeutroYNoRompe(): void
    {
        $this->wc->cart = null;

        $this->assertSame(['mode' => 'none', 'status' => 'needs_address'], StoreApiExtension::cartState());
    }

    public function testSinPaquetesElEstadoEsNeutro(): void
    {
        $this->wc->cart = new ExtensionCart([]);

        $this->assertSame(['mode' => 'none', 'status' => 'needs_address'], StoreApiExtension::cartState());
    }

    /* ------------------------------------------------------------------ estado por ciudad */

    public function testUnaCiudadFijaDaModoFijoConEstadoOkSinDatosDeDistancia(): void
    {
        $this->city(1, 'Madrid', 'fixed', [$this->option('std01', 'Estándar', ['amount' => 5])]);

        $this->assertSame(['mode' => 'fixed', 'status' => 'ok'], $this->state($this->package()));
    }

    public function testUnaCiudadSinRegistroSinReservaDaModoNoneConEstadoOk(): void
    {
        $this->config['shipping'] = ['reserve_enabled' => false];

        $this->assertSame(['mode' => 'none', 'status' => 'ok'], $this->state($this->package('Toledo')));
    }

    public function testUnaCiudadSinCalleEsNeedsAddress(): void
    {
        $this->city(1, 'Madrid', 'fixed', [$this->option('std01', 'Estándar', ['amount' => 5])]);

        $this->assertSame(['mode' => 'none', 'status' => 'needs_address'], $this->state($this->package('Madrid', 'ES', '')));
    }

    public function testUnaCiudadPorDistanciaSinPinEsNeedsLocation(): void
    {
        $this->city(1, 'Madrid', 'distance', [$this->option('lento1', 'Lento', ['price_per_km' => 2.0])], ['lat' => self::ORIGIN_LAT, 'lng' => self::ORIGIN_LNG]);

        $this->assertSame(['mode' => 'distance', 'status' => 'needs_location'], $this->state($this->package()));
    }

    public function testConElPinDentroDelRadioDaLaDistanciaYLaDuracionYMarcaElRespaldo(): void
    {
        // Proveedor «maps» sin clave: falla cerrado a línea recta marcada, sin ninguna petición de red.
        $this->config['shipping'] = ['distance_method' => 'maps'];
        $this->city(1, 'Madrid', 'distance', [$this->option('lento1', 'Lento', ['price_per_km' => 2.0])], ['lat' => self::ORIGIN_LAT, 'lng' => self::ORIGIN_LNG], '10');
        $this->setPoint();

        $state = $this->state($this->package());

        $this->assertSame(['mode', 'status', 'distance_km', 'duration_min', 'fallback'], array_keys($state));
        $this->assertSame('distance', $state['mode']);
        $this->assertSame('ok', $state['status']);
        $this->assertGreaterThan(0.0, $state['distance_km']);
        $this->assertLessThan(2.0, $state['distance_km'], 'un punto a ~1,1 km del origen');
        $this->assertIsInt($state['duration_min']);
        $this->assertTrue($state['fallback']);
    }

    public function testUnPinFueraDelRadioEsOutOfCoverageSinDistancia(): void
    {
        // Radio de 100 m: el punto a ~1,1 km queda fuera.
        $this->city(1, 'Madrid', 'distance', [$this->option('lento1', 'Lento', ['price_per_km' => 2.0])], ['lat' => self::ORIGIN_LAT, 'lng' => self::ORIGIN_LNG], '0.1');
        $this->setPoint();

        $this->assertSame(['mode' => 'distance', 'status' => 'out_of_coverage'], $this->state($this->package()));
    }
}

/** Sesión de WooCommerce mínima: `get`/`set` sobre un array, lo único que usa `DeliveryPoint`. */
final class ExtensionSession
{
    /** @var array<string,mixed> */
    private array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
}

/** Carrito con los paquetes que devuelve `get_shipping_packages()`. */
final class ExtensionCart
{
    /** @param list<array<string,mixed>> $packages */
    public function __construct(private array $packages)
    {
    }

    /** @return list<array<string,mixed>> */
    public function get_shipping_packages(): array
    {
        return $this->packages;
    }
}

/** Doble de `WC()`: carrito (o null, sin carrito) y sesión. */
final class ExtensionWc
{
    public ?ExtensionCart $cart = null;
    public ExtensionSession $session;

    public function __construct()
    {
        $this->session = new ExtensionSession();
    }
}
