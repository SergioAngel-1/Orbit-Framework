<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\Shipping\CityRateMethod;
use HWE\Shipping\DeliveryPoint;
use HWE\Shipping\Distance\Haversine;
use HWE\Shipping\Rates\GeoPoint;
use HWE\Shipping\ShippingStatus;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 2) . '/Stubs/wc-shipping.php';

/**
 * Método de envío `hwe_city_rate` (tarea 7): una tarifa por opción de la ciudad de destino, la
 * tarifa de reserva para las ciudades sin registro y la decisión compartida con `ShippingStatus`.
 *
 * Se prueba el comportamiento de cara a WooCommerce: las tarifas que quedan en el método, su id,
 * su coste y sus metas. La distancia sale de la línea recta real (sin red), y el respaldo se
 * prueba con el método «maps» sin clave, que falla cerrado a línea recta marcada.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CityRateMethodTest extends TestCase
{
    private const ORIGIN_LAT = 40.4168;
    private const ORIGIN_LNG = -3.7038;
    /** Un punto a unos 1,1 km del origen, en línea recta. */
    private const NEAR_LAT = 40.4268;
    /** Incremento por defecto de la línea recta (`Settings::DEFAULT_INCREMENT_PCT`). */
    private const INCREMENT = 1.3;

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
    private int $transientReads = 0;
    /** @var list<string> */
    private array $logged = [];
    private CityRateWc $wc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = ['ecommerce' => ['country' => 'ES']];
        $this->options = [];
        $this->posts = [];
        $this->meta = [];
        $this->transients = [];
        $this->transientReads = 0;
        $this->logged = [];
        $this->wc = new CityRateWc(new CityRateSession());

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
            'remove_accents'           => static fn (string $text): string => strtr($text, [
                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
                'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
                'ñ' => 'n', 'Ñ' => 'N',
            ]),
            'sanitize_text_field'      => static function (mixed $value): string {
                $text = is_scalar($value) ? (string) $value : '';

                return trim((string) preg_replace('/\s+/', ' ', $text));
            },
        ]);
        Functions\when('WC')->justReturn($this->wc);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;

            return true;
        });
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value): mixed => $value
        );
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
        Functions\when('get_transient')->alias(function (string $key): mixed {
            $this->transientReads++;

            return $this->transients[$key] ?? false;
        });
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

    /**
     * Registra una ciudad (post del CPT) con su tipo, sus opciones y, si es por distancia, origen
     * y radio. `$type` vacío simula una ciudad del modelo anterior.
     *
     * @param list<array<string,mixed>> $options
     */
    private function city(int $id, string $name, string $type, array $options, ?array $origin = null, string $coverage = '10'): void
    {
        // Guardar otra vez el mismo id reemplaza el post (como hace el guardado de WordPress).
        $this->posts = array_values(array_filter($this->posts, static fn (\WP_Post $p): bool => $p->ID !== $id));
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
            $meta['_hwe_shipping_city_origin_label'] = 'Origen';
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

    /** @return array<string,mixed> */
    private function package(string $country = 'ES', string $city = 'Madrid', string $address = 'Calle Mayor 1'): array
    {
        return [
            'contents'        => [],
            'applied_coupons' => [],
            'destination'     => [
                'address_1' => $address,
                'city'      => $city,
                'country'   => $country,
            ],
        ];
    }

    /** Declara el pin del cliente para la dirección del paquete por defecto. */
    private function setPoint(float $lat = self::NEAR_LAT, float $lng = self::ORIGIN_LNG): void
    {
        DeliveryPoint::updatePoint([
            'action'    => 'set_point',
            'lat'       => $lat,
            'lng'       => $lng,
            'address_1' => 'Calle Mayor 1',
            'city'      => 'Madrid',
            'country'   => 'ES',
        ]);
    }

    /** @param array<string,mixed> $package @return array<string,\WC_Shipping_Rate> */
    private function rates(array $package): array
    {
        $method = new CityRateMethod(7);
        $method->calculate_shipping($package);

        return $method->rates;
    }

    private function originOfMadrid(): array
    {
        return ['lat' => self::ORIGIN_LAT, 'lng' => self::ORIGIN_LNG];
    }

    /* ------------------------------------------------------------------ destino y reserva */

    public function testUnDestinoDeOtroPaisNoRecibeTarifas(): void
    {
        $this->city(1, 'Madrid', 'fixed', [$this->option('std01', 'Estándar', ['amount' => 5])]);

        $this->assertSame([], $this->rates($this->package('PT', 'Madrid')));
        $this->assertSame(['mode' => 'none', 'status' => 'ok'], ShippingStatus::forPackage($this->package('PT', 'Madrid')));
    }

    public function testUnaCiudadSinRegistroRecibeLaTarifaDeReservaConSuNombreYSuCoste(): void
    {
        $this->config['shipping'] = ['reserve_name' => 'Resto del país', 'reserve_cost' => '7.5'];

        $rates = $this->rates($this->package('ES', 'Toledo'));

        $this->assertSame(['hwe_city_rate:reserve'], array_keys($rates));
        $rate = $rates['hwe_city_rate:reserve'];
        $this->assertSame('Resto del país', $rate->label);
        $this->assertEqualsWithDelta(7.5, $rate->cost, 0.001);
        $this->assertSame('reserve', $rate->meta['hwe_city_type']);
        $this->assertSame('reserve', $rate->meta['hwe_option_id']);
        $this->assertArrayNotHasKey('hwe_distance_km', $rate->meta);
        $this->assertSame(['mode' => 'reserve', 'status' => 'ok'], ShippingStatus::forPackage($this->package('ES', 'Toledo')));
    }

    public function testSinReservaActivaUnaCiudadSinRegistroNoRecibeNinguna(): void
    {
        $this->config['shipping'] = ['reserve_enabled' => false];

        $this->assertSame([], $this->rates($this->package('ES', 'Toledo')));
        $this->assertSame(['mode' => 'none', 'status' => 'ok'], ShippingStatus::forPackage($this->package('ES', 'Toledo')));
    }

    public function testUnaCiudadDelModeloAnteriorSeTrataComoSinRegistro(): void
    {
        $this->config['shipping'] = ['reserve_name' => 'Resto del país', 'reserve_cost' => '4'];
        $this->city(2, 'Toledo', '', [$this->option('vieja1', 'Antigua', ['amount' => 9])]);

        $rates = $this->rates($this->package('ES', 'Toledo'));

        $this->assertSame(['hwe_city_rate:reserve'], array_keys($rates));
        $this->assertEqualsWithDelta(4.0, $rates['hwe_city_rate:reserve']->cost, 0.001);
    }

    public function testSinCalleOCiudadNoHayTarifaYLoExplica(): void
    {
        $this->city(1, 'Madrid', 'fixed', [$this->option('std01', 'Estándar', ['amount' => 5])]);

        $this->assertSame([], $this->rates($this->package('ES', 'Madrid', '')));
        $this->assertSame(['mode' => 'none', 'status' => 'needs_address'], ShippingStatus::forPackage($this->package('ES', 'Madrid', '')));
        $this->assertSame([], $this->rates($this->package('ES', '')));
        $this->assertSame(['mode' => 'none', 'status' => 'needs_address'], ShippingStatus::forPackage($this->package('ES', '')));
    }

    /* ------------------------------------------------------------------ ciudad fija */

    public function testUnaCiudadFijaTieneUnaTarifaPorOpcionConSuImporte(): void
    {
        $this->city(1, 'Madrid', 'fixed', [
            $this->option('std01', 'Estándar', ['amount' => 5]),
            $this->option('exp01', 'Express', ['amount' => 12.5]),
        ]);

        $rates = $this->rates($this->package());

        $this->assertSame(['hwe_city_rate:std01', 'hwe_city_rate:exp01'], array_keys($rates));
        $this->assertSame('Estándar', $rates['hwe_city_rate:std01']->label);
        $this->assertEqualsWithDelta(5.0, $rates['hwe_city_rate:std01']->cost, 0.001);
        $this->assertEqualsWithDelta(12.5, $rates['hwe_city_rate:exp01']->cost, 0.001);
        $this->assertSame('std01', $rates['hwe_city_rate:std01']->meta['hwe_option_id']);
        $this->assertSame('fixed', $rates['hwe_city_rate:std01']->meta['hwe_city_type']);
        $this->assertArrayNotHasKey('hwe_distance_km', $rates['hwe_city_rate:std01']->meta);
        $this->assertSame(['mode' => 'fixed', 'status' => 'ok'], ShippingStatus::forPackage($this->package()));
    }

    /* ------------------------------------------------------------------ ciudad por distancia */

    public function testUnPuntoDentroDelRadioDaUnaTarifaPorOpcionConSuCosteYSusMetas(): void
    {
        $this->city(1, 'Madrid', 'distance', [
            $this->option('rapid1', 'Rápido', ['price_per_km' => 2.0, 'min_cost' => 3]),
            $this->option('lento1', 'Lento', ['price_per_km' => 2.0]),
        ], $this->originOfMadrid(), '10');
        $this->setPoint();

        $km = Haversine::km(new GeoPoint(self::ORIGIN_LAT, self::ORIGIN_LNG), new GeoPoint(self::NEAR_LAT, self::ORIGIN_LNG)) * self::INCREMENT;
        $rates = $this->rates($this->package());

        $this->assertSame(['hwe_city_rate:rapid1', 'hwe_city_rate:lento1'], array_keys($rates));
        // 2 €/km sobre ~1,44 km son ~2,9 €: el mínimo de 3 € lo eleva.
        $this->assertEqualsWithDelta(3.0, $rates['hwe_city_rate:rapid1']->cost, 0.001);
        $this->assertEqualsWithDelta(round($km * 2.0, 2), $rates['hwe_city_rate:lento1']->cost, 0.001);

        $meta = $rates['hwe_city_rate:lento1']->meta;
        $this->assertSame('distance', $meta['hwe_city_type']);
        $this->assertSame('lento1', $meta['hwe_option_id']);
        $this->assertSame((string) round($km, 2), $meta['hwe_distance_km']);
        $this->assertSame('no', $meta['hwe_fallback']);
        $this->assertSame('straight_line', $meta['Proveedor de distancia']);
        $this->assertArrayNotHasKey('Motivo del respaldo', $meta);
        $this->assertStringContainsString('mlat=' . number_format(self::NEAR_LAT, 6, '.', ''), $meta['Ubicación de entrega']);

        $status = ShippingStatus::forPackage($this->package());
        $this->assertSame('distance', $status['mode']);
        $this->assertSame('ok', $status['status']);
        $this->assertEqualsWithDelta(round($km, 2), $status['distance_km'], 0.001);
        $this->assertFalse($status['fallback']);
    }

    public function testSinPinNoHayTarifaYLoDiceNeedsLocation(): void
    {
        $this->city(1, 'Madrid', 'distance', [$this->option('lento1', 'Lento', ['price_per_km' => 2.0])], $this->originOfMadrid());

        $this->assertSame([], $this->rates($this->package()));
        $this->assertSame(['mode' => 'distance', 'status' => 'needs_location'], ShippingStatus::forPackage($this->package()));
    }

    public function testUnaCiudadPorDistanciaSinReferenciaSeTrataComoSinRegistroYNoRompe(): void
    {
        $this->city(1, 'Madrid', 'distance', [$this->option('lento1', 'Lento', ['price_per_km' => 2.0])]);
        $this->setPoint();

        $this->assertSame(['hwe_city_rate:reserve'], array_keys($this->rates($this->package())));
        $this->assertSame(['mode' => 'reserve', 'status' => 'ok'], ShippingStatus::forPackage($this->package()));
    }

    public function testFueraDelRadioNoHayTarifaYLoDiceOutOfCoverage(): void
    {
        $this->city(1, 'Madrid', 'distance', [$this->option('lento1', 'Lento', ['price_per_km' => 2.0])], $this->originOfMadrid(), '0.5');
        $this->setPoint();

        $this->assertSame([], $this->rates($this->package()));
        $this->assertSame(['mode' => 'distance', 'status' => 'out_of_coverage'], ShippingStatus::forPackage($this->package()));
    }

    public function testUnPinMuyLejosDelRadioSeDescartaSinPedirLaDistanciaAlProveedor(): void
    {
        // Barcelona está a ~500 km en línea recta de la referencia (radio 10 km): ninguna ruta puede
        // ser más corta que la línea recta, así que no hace falta ni caché ni petición de pago.
        $this->city(1, 'Madrid', 'distance', [$this->option('lento1', 'Lento', ['price_per_km' => 2.0])], $this->originOfMadrid());
        $this->setPoint(41.3874, 2.1686);
        $this->transientReads = 0;

        $this->assertSame([], $this->rates($this->package()));
        $this->assertSame(['mode' => 'distance', 'status' => 'out_of_coverage'], ShippingStatus::forPackage($this->package()));
        $this->assertSame(0, $this->transientReads, 'Un pin fuera de cobertura por línea recta no consulta la distancia.');
    }

    public function testElRespaldoAStraightLineSeMarcaEnLasMetasYNuncaCuestaCero(): void
    {
        // Método «maps» sin proveedor ni clave: la distancia cae a línea recta marcada como respaldo.
        $this->config['shipping'] = ['distance_method' => 'maps'];
        $this->city(1, 'Madrid', 'distance', [$this->option('lento1', 'Lento', ['price_per_km' => 2.0])], $this->originOfMadrid());
        $this->setPoint();

        $rates = $this->rates($this->package());

        $meta = $rates['hwe_city_rate:lento1']->meta;
        $this->assertSame('yes', $meta['hwe_fallback']);
        $this->assertSame('not_configured', $meta['Motivo del respaldo']);
        $this->assertGreaterThan(0.0, $rates['hwe_city_rate:lento1']->cost);
        $this->assertTrue($this->hasLogLike('[hwe] shipping:'));
        $status = ShippingStatus::forPackage($this->package());
        $this->assertTrue($status['fallback']);
    }

    /* ------------------------------------------------------------------ opciones y filtro */

    public function testRenombrarUnaOpcionNoCambiaElIdDeLaTarifa(): void
    {
        $this->city(1, 'Madrid', 'fixed', [$this->option('std01', 'Estándar', ['amount' => 5])]);
        $this->assertSame(['hwe_city_rate:std01'], array_keys($this->rates($this->package())));

        // Guardar la opción es otra petición: la decisión memoizada de la anterior no debe sobrevivir.
        $this->city(1, 'Madrid', 'fixed', [$this->option('std01', 'Ahorro', ['amount' => 5])]);
        ShippingStatus::forget();
        $rates = $this->rates($this->package());

        $this->assertSame(['hwe_city_rate:std01'], array_keys($rates));
        $this->assertSame('Ahorro', $rates['hwe_city_rate:std01']->label);
    }

    public function testBorrarUnaOpcionQuitaSuTarifaSinErrorYLasDemasSiguenIgual(): void
    {
        $this->city(1, 'Madrid', 'fixed', [
            $this->option('std01', 'Estándar', ['amount' => 5]),
            $this->option('exp01', 'Express', ['amount' => 12]),
        ]);
        $this->city(1, 'Madrid', 'fixed', [$this->option('std01', 'Estándar', ['amount' => 5])]);

        $rates = $this->rates($this->package());

        $this->assertSame(['hwe_city_rate:std01'], array_keys($rates));
        $this->assertEqualsWithDelta(5.0, $rates['hwe_city_rate:std01']->cost, 0.001);
    }

    public function testElFiltroDeCosteSeAplicaConElContextoDeLaTarifa(): void
    {
        $seen = [];
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed ...$args) use (&$seen): mixed {
            if ('hwe_shipping_rate_cost' !== $hook) {
                return $value;
            }
            $seen[] = $args[0];

            return $value * 2;
        });
        $this->city(1, 'Madrid', 'fixed', [$this->option('std01', 'Estándar', ['amount' => 5])]);

        $rates = $this->rates($this->package());

        $this->assertEqualsWithDelta(10.0, $rates['hwe_city_rate:std01']->cost, 0.001);
        $this->assertSame([['city' => 'Madrid', 'option_id' => 'std01', 'type' => 'fixed', 'km' => null]], $seen);
    }

    public function testUnFiltroQueDevuelveUnCosteInvalidoOmiteLaTarifaYLoRegistra(): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value): mixed => 'hwe_shipping_rate_cost' === $hook ? -1.0 : $value
        );
        $this->city(1, 'Madrid', 'fixed', [$this->option('std01', 'Estándar', ['amount' => 5])]);

        $this->assertSame([], $this->rates($this->package()));
        $this->assertTrue($this->hasLogLike('[hwe] shipping:'));
    }

    /* ------------------------------------------------------------------ decisión compartida */

    public function testLaDistanciaSeCalculaUnaSolaVezPorPaqueteAunqueSeConsulteVarias(): void
    {
        $this->city(1, 'Madrid', 'distance', [$this->option('lento1', 'Lento', ['price_per_km' => 2.0])], $this->originOfMadrid());
        $this->setPoint();
        $package = $this->package();

        ShippingStatus::forPackage($package);
        ShippingStatus::forPackage($package);
        $this->rates($package);

        $this->assertSame(1, $this->transientReads, 'la distancia se pide una vez por petición');
    }

    private function hasLogLike(string $fragment): bool
    {
        foreach ($this->logged as $message) {
            if (str_contains($message, $fragment)) {
                return true;
            }
        }

        return false;
    }
}

/** Sesión de WooCommerce mínima: `get`/`set` sobre un array, lo único que usa `DeliveryPoint`. */
final class CityRateSession
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

/** Doble de `WC()` con la sesión de arriba. */
final class CityRateWc
{
    public function __construct(public CityRateSession $session)
    {
    }
}
