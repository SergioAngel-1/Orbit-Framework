<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Shipping\FreeShipping;
use HWE\Shipping\PostTypes;
use HWE\Shipping\ReserveZone;
use HWE\Shipping\RestApi;
use HWE\Shipping\Revalidation;
use HWE\Shipping\Save;
use HWE\Shipping\ShippingCost;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 2) . '/Stubs/wc-shipping.php';

/**
 * `hwe-shipping` (tarea 54): tarifa por ciudad, envío gratis por regla, métodos extra, alcance con
 * traducciones a N idiomas, guardado de las cajas, revalidación y zona de reserva de WooCommerce.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ShippingTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $config = ['ecommerce' => ['country' => 'ES']];
    /** @var array<string,mixed> */
    private array $options = [];
    /** @var list<array{id:int,title:string,amount:string,free:string}> */
    private array $rates = [];
    /** @var list<array{id:int,title:string,enabled:string,added:string}> */
    private array $methods = [];
    /** @var array<int,array<string,string>> */
    private array $postMeta = [];
    /** @var array<int,array<string,string>> */
    private array $termMeta = [];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->rates = [
            ['id' => 11, 'title' => 'Madrid', 'amount' => '8', 'free' => '1'],
            ['id' => 12, 'title' => 'Sevilla', 'amount' => '12', 'free' => '0'],
            ['id' => 13, 'title' => 'Bilbao', 'amount' => '', 'free' => '1'],
        ];
        $this->methods = [
            ['id' => 21, 'title' => 'Seguro', 'enabled' => '1', 'added' => '5'],
            ['id' => 22, 'title' => 'Caja de regalo', 'enabled' => '', 'added' => ''],
            ['id' => 23, 'title' => 'Inactivo', 'enabled' => '0', 'added' => '9'],
        ];

        Functions\stubs([
            'wp_json_encode' => static fn ($v) => json_encode($v),
            '__' => static fn ($v) => $v,
            'esc_html__' => static fn ($v) => $v,
            'wp_strip_all_tags' => static fn ($v) => strip_tags((string) $v),
            'wp_unslash' => static fn ($v) => $v,
            'sanitize_text_field' => static fn ($v) => trim((string) $v),
            'wc_price' => static fn ($v) => '<span>' . number_format((float) $v, 2) . ' €</span>',
            'wc_get_price_decimals' => static fn () => 2,
            'remove_accents' => static fn (string $s) => strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'ñ' => 'n', 'Ñ' => 'N']),
            'wp_cache_get' => static fn () => false,
            'wp_cache_set' => static fn () => true,
            'wp_cache_delete' => static fn () => true,
            'plugin_dir_url' => static fn () => '',
            'register_activation_hook' => static fn () => null,
            'is_admin' => static fn () => false,
            'wp_verify_nonce' => static fn () => 1,
            'current_user_can' => static fn () => true,
        ]);
        Functions\when('error_log')->alias(function (string $m): bool {
            $this->logged[] = $m;
            return true;
        });
        Functions\when('get_option')->alias(function (string $key, $default = false) {
            if ('hwe_config' === $key) {
                return $this->config;
            }
            return $this->options[$key] ?? $default;
        });
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('get_the_title')->alias(function ($post): string {
            if (is_object($post)) {
                return $post->post_title ?? '';
            }
            foreach ([...$this->rates, ...$this->methods] as $row) {
                if ($row['id'] === (int) $post) {
                    return $row['title'];
                }
            }
            return '';
        });
        Functions\when('get_post_meta')->alias(fn (int $id, string $key) => $this->postMeta[$id][$key] ?? '');
        Functions\when('get_term_meta')->alias(fn (int $id, string $key) => $this->termMeta[$id][$key] ?? '');
        Functions\when('get_posts')->alias(fn (array $args) => $this->posts($args));

        $this->loadOwnFile('mu-plugins/hwe-geo/packs.php');
        $this->loadOwnFile('plugins/hwe-shipping/hwe-shipping.php');
    }

    /** `get_posts` simulado: tarifas, métodos, productos del alcance y ciudades con envío gratis. */
    private function posts(array $args): array
    {
        $type = $args['post_type'] ?? '';
        $make = function (int $id, string $title, string $name = ''): \WP_Post {
            $p = new \WP_Post();
            $p->ID = $id;
            $p->post_title = $title;
            $p->post_name = $name ?: strtolower($title);
            return $p;
        };
        $out = [];
        if (PostTypes::RATE_SLUG === $type) {
            foreach ($this->rates as $r) {
                $this->postMeta[$r['id']]['_hwe_shipping_rate_amount'] = $r['amount'];
                $this->postMeta[$r['id']]['_hwe_shipping_rate_free_eligible'] = $r['free'];
                if (isset($args['meta_key']) && ($this->postMeta[$r['id']][$args['meta_key']] ?? '') !== $args['meta_value']) {
                    continue;
                }
                $out[] = $make($r['id'], $r['title']);
            }
            if (($args['orderby'] ?? '') === 'title') {
                usort($out, static fn ($a, $b) => strcmp($a->post_title, $b->post_title));
            }
            return ($args['fields'] ?? '') === 'ids' ? array_map(static fn ($p) => $p->ID, $out) : $out;
        }
        if (PostTypes::METHOD_SLUG === $type) {
            foreach ($this->methods as $m) {
                if ('0' === $m['enabled']) {
                    continue;
                }
                $this->postMeta[$m['id']]['_hwe_shipping_method_added_value'] = $m['added'];
                $out[] = $make($m['id'], $m['title']);
            }
            return $out;
        }
        if ('product' === $type) {
            return array_map(fn (string $slug) => $make(900, 'Producto ' . $slug, $slug), (array) ($args['post_name__in'] ?? []));
        }
        return [];
    }

    private function useRule(array $rule): void
    {
        Filters\expectApplied('hwe_shipping_free_rule')->andReturn($rule);
    }

    private function item(float $total, float $tax = 0.0, int $productId = 5, bool $ships = true): array
    {
        return ['data' => new \WC_Product($productId, $ships), 'product_id' => $productId, 'line_total' => $total, 'line_tax' => $tax];
    }

    // --- tarifa por ciudad -----------------------------------------------------------------------

    public function testLaCiudadSeNormalizaSinTildesNiMayusculas(): void
    {
        $this->assertSame('bogota d.c.', ShippingCost::normalizeCity('  Bogotá   D.C. '));
        $this->assertSame('a coruna', ShippingCost::normalizeCity('A  Coruña'));
    }

    public function testElPaisBaseSaleDelControlCenter(): void
    {
        $this->assertSame('ES', ShippingCost::baseCountry());

        $this->config = ['ecommerce' => ['country' => 'co']];
        $this->assertSame('CO', ShippingCost::baseCountry());
    }

    public function testLaTarifaDeLaCiudadSustituyeElCosteDeLaTarifaPlana(): void
    {
        $flat = new \WC_Shipping_Rate('flat_rate', 3);
        $pickup = new \WC_Shipping_Rate('local_pickup', 0);

        $rates = ShippingCost::applyCityRate(['a' => $flat, 'b' => $pickup], ['destination' => ['city' => ' MADRID ', 'country' => 'ES']]);

        $this->assertSame(8.0, $rates['a']->cost);
        $this->assertSame(0.0, $rates['b']->cost, 'solo la tarifa plana');
    }

    public function testUnaCiudadSinTarifaConservaElCosteDeLaZona(): void
    {
        $flat = new \WC_Shipping_Rate('flat_rate', 3);

        ShippingCost::applyCityRate(['a' => $flat], ['destination' => ['city' => 'Pamplona', 'country' => 'ES']]);

        $this->assertSame(3.0, $flat->cost);
    }

    public function testUnaTarifaSinImporteNoCuentaComoGratis(): void
    {
        $flat = new \WC_Shipping_Rate('flat_rate', 3);

        ShippingCost::applyCityRate(['a' => $flat], ['destination' => ['city' => 'Bilbao', 'country' => 'ES']]);

        $this->assertSame(3.0, $flat->cost, 'Bilbao no tiene importe: cae al de la zona');
    }

    public function testUnImporteCeroExplicitoSiEsGratis(): void
    {
        $this->rates[] = ['id' => 14, 'title' => 'Gijón', 'amount' => '0', 'free' => '0'];
        $flat = new \WC_Shipping_Rate('flat_rate', 3);

        ShippingCost::applyCityRate(['a' => $flat], ['destination' => ['city' => 'Gijon', 'country' => 'ES']]);

        $this->assertSame(0.0, $flat->cost);
    }

    public function testUnaCiudadHomonimaDeOtroPaisNoHeredaLaTarifa(): void
    {
        $flat = new \WC_Shipping_Rate('flat_rate', 3);

        ShippingCost::applyCityRate(['a' => $flat], ['destination' => ['city' => 'Madrid', 'country' => 'CO']]);

        $this->assertSame(3.0, $flat->cost);
    }

    public function testSinCiudadNoHaceNada(): void
    {
        $flat = new \WC_Shipping_Rate('flat_rate', 3);

        ShippingCost::applyCityRate(['a' => $flat], ['destination' => ['country' => 'ES']]);

        $this->assertSame(3.0, $flat->cost);
    }

    public function testUnCuponConEnvioGratisDejaTodasLasTarifasAcero(): void
    {
        \WC_Coupon::$free = ['GRATIS' => true, 'NORMAL' => false];
        $a = new \WC_Shipping_Rate('flat_rate', 8);
        $b = new \WC_Shipping_Rate('other', 4);

        ShippingCost::applyCouponFreeShipping(['a' => $a, 'b' => $b], ['applied_coupons' => ['NORMAL', 'GRATIS']]);

        $this->assertSame([0.0, 0.0], [$a->cost, $b->cost]);
        $this->assertSame([], $a->taxes);
    }

    public function testSinCuponDeEnvioGratisLasTarifasQuedanComoEstan(): void
    {
        \WC_Coupon::$free = ['NORMAL' => false];
        $a = new \WC_Shipping_Rate('flat_rate', 8);

        ShippingCost::applyCouponFreeShipping(['a' => $a], ['applied_coupons' => ['NORMAL', 'NOEXISTE']]);

        $this->assertSame(8.0, $a->cost);
    }

    public function testLasCiudadesConEnvioGratisYSusNombres(): void
    {
        $this->assertSame(['madrid' => true, 'sevilla' => false, 'bilbao' => true], ShippingCost::freeShippingCities());
        $this->assertSame(['Bilbao', 'Madrid'], ShippingCost::freeShippingCityNames());
    }

    // --- envío gratis por regla ------------------------------------------------------------------

    public function testSinReglaOSinCiudadesElEnvioGratisEstaApagado(): void
    {
        $this->useRule(['enabled' => false]);
        $this->assertFalse(FreeShipping::evaluate([$this->item(100)], 'ES', 'Madrid')['enabled']);
    }

    public function testNingunaCiudadLoAdmiteLaReglaNoPuedeCumplirse(): void
    {
        $this->rates = [['id' => 11, 'title' => 'Madrid', 'amount' => '8', 'free' => '0']];
        $this->useRule(['enabled' => true, 'min_amount' => 50]);

        $this->assertFalse(FreeShipping::evaluate([$this->item(100)], 'ES', 'Madrid')['enabled']);
    }

    public function testAlcanzarElMinimoEnUnaCiudadElegibleConcedeElEnvioGratis(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 50]);

        $state = FreeShipping::evaluate([$this->item(30), $this->item(25)], 'ES', 'Madrid');

        $this->assertTrue($state['qualifies']);
        $this->assertSame(55.0, $state['eligible_subtotal']);
        $this->assertSame(0.0, $state['remaining']);
        $this->assertSame('eligible', $state['city']);
    }

    public function testSiFaltaParaElMinimoDiceCuantoFalta(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 50]);

        $state = FreeShipping::evaluate([$this->item(30)], 'ES', 'Madrid');

        $this->assertFalse($state['qualifies']);
        $this->assertSame(20.0, $state['remaining']);
    }

    public function testUnaCiudadSinLaCasillaNoEsGratisAunqueLleguePorElMinimo(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 10]);

        $state = FreeShipping::evaluate([$this->item(100)], 'ES', 'Sevilla');

        $this->assertFalse($state['qualifies']);
        $this->assertSame('not_eligible', $state['city']);
    }

    public function testUnaCiudadSinTarifaNuncaEsGratis(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 10]);

        $this->assertSame('not_eligible', FreeShipping::evaluate([$this->item(100)], 'ES', 'Pamplona')['city']);
    }

    public function testSinCiudadAunNoSeSabe(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 10]);

        $state = FreeShipping::evaluate([$this->item(100)], 'ES', '');

        $this->assertSame('unknown', $state['city']);
        $this->assertFalse($state['qualifies']);
        $this->assertFalse($state['all_cities'], 'no todas las ciudades lo admiten');
    }

    public function testUnDestinoFueraDelPaisBaseNoEsElegible(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 10]);

        $this->assertSame('not_eligible', FreeShipping::evaluate([$this->item(100)], 'CO', 'Madrid')['city']);
    }

    public function testSoloSumanLosArticulosQueNecesitanEnvio(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 50]);

        $state = FreeShipping::evaluate([$this->item(30), $this->item(500, 0, 6, false)], 'ES', 'Madrid');

        $this->assertSame(30.0, $state['eligible_subtotal']);
        $this->assertFalse($state['qualifies']);
    }

    public function testSiNingunArticuloEnvioFisicoLaReglaNoAplica(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 10]);

        $this->assertFalse(FreeShipping::evaluate([$this->item(100, 0, 6, false)], 'ES', 'Madrid')['enabled']);
    }

    public function testConAlcanceSoloSumanLosProductosYCategoriasDeLaRegla(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 50, 'categories' => ['velas'], 'products' => ['vela-roja']]);
        Functions\when('get_terms')->justReturn([7]);
        Functions\when('get_post_field')->alias(fn (string $f, int $id) => [5 => 'vela-roja', 6 => 'otra', 8 => 'jabon'][$id] ?? '');
        Functions\when('wc_get_product_cat_ids')->alias(fn (int $id) => 6 === $id ? [7, 1] : [2]);

        $state = FreeShipping::evaluate([$this->item(30, 0, 5), $this->item(25, 0, 6), $this->item(900, 0, 8)], 'ES', 'Madrid');

        $this->assertSame(55.0, $state['eligible_subtotal'], 'el producto 8 no está en el alcance');
        $this->assertTrue($state['scoped']);
        $this->assertTrue($state['qualifies']);
    }

    public function testConLaReglaCumplidaTodasLasTarifasQuedanAceroConSuMotivo(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 50]);
        $rate = new \WC_Shipping_Rate('flat_rate', 8);

        FreeShipping::applyToRates(['a' => $rate], ['contents' => [$this->item(60)], 'destination' => ['country' => 'ES', 'city' => 'Madrid']]);

        $this->assertSame(0.0, $rate->cost);
        $this->assertSame([], $rate->taxes);
        $this->assertSame('Compra mínima de 50.00 € alcanzada', $rate->meta['Envío gratis']);
    }

    public function testSinCumplirLaReglaLasTarifasNoCambian(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 50]);
        $rate = new \WC_Shipping_Rate('flat_rate', 8);

        FreeShipping::applyToRates(['a' => $rate], ['contents' => [$this->item(10)], 'destination' => ['country' => 'ES', 'city' => 'Madrid']]);

        $this->assertSame(8.0, $rate->cost);
        $this->assertSame([], $rate->meta);
    }

    public function testLaReglaSeNormalizaAntesDeUsarse(): void
    {
        $this->useRule(['enabled' => 1, 'min_amount' => '-5', 'categories' => ['a', '', 3]]);

        $this->assertSame(['enabled' => true, 'min_amount' => 0.0, 'categories' => ['a', '3'], 'products' => []], FreeShipping::rule());
    }

    public function testElCarritoSinReglaDevuelveElEstadoApagadoEnUnidadesMenores(): void
    {
        $this->useRule(['enabled' => false]);

        $state = FreeShipping::cartState();

        $this->assertFalse($state['enabled']);
        $this->assertSame('0', $state['threshold']);
        $this->assertSame('0', $state['remaining']);
    }

    // --- alcance del envío gratis con traducciones -----------------------------------------------

    public function testElAlcanceEstaVacioConLaReglaApagada(): void
    {
        $this->useRule(['enabled' => false]);

        $this->assertSame(['categories' => [], 'products' => [], 'cities' => []], FreeShipping::scope());
    }

    public function testElAlcanceListaCiudadesYProductosConSusTraduccionesPorIdioma(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 10, 'products' => ['vela-roja']]);
        Functions\when('hwe_i18n_locales')->justReturn(['en', 'pt_br']);
        Functions\when('hwe_i18n_meta_key')->alias(fn (string $f, string $s) => "_hwe_{$f}_{$s}");
        $this->postMeta[900] = ['_hwe_name_en' => 'Red candle', '_hwe_slug_en' => 'red-candle', '_hwe_name_pt_br' => ''];

        $scope = FreeShipping::scope();

        $this->assertSame(['Bilbao', 'Madrid'], $scope['cities']);
        $this->assertSame('vela-roja', $scope['products'][0]['slug']);
        $this->assertSame(
            ['en' => ['name' => 'Red candle', 'slug' => 'red-candle']],
            $scope['products'][0]['translations'],
            'solo los idiomas con algo traducido'
        );
    }

    public function testSinHweContentI18nLasTraduccionesVanVacias(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 10, 'products' => ['vela-roja']]);

        $this->assertSame([], FreeShipping::scope()['products'][0]['translations']);
    }

    // --- REST ------------------------------------------------------------------------------------

    public function testLosMetodosActivosSeExponenSinLosInactivos(): void
    {
        $data = RestApi::getMethods(new \WP_REST_Request())->get_data();

        $this->assertSame(
            [
                ['id' => 21, 'name' => 'Seguro', 'added_value' => 5],
                ['id' => 22, 'name' => 'Caja de regalo', 'added_value' => 0],
            ],
            $data
        );
    }

    public function testLasTarifasSoloExponenNombresDeCiudad(): void
    {
        $data = RestApi::getRates(new \WP_REST_Request())->get_data();

        $this->assertSame(['Bilbao', 'Madrid', 'Sevilla'], $data);
        $this->assertStringNotContainsString('amount', json_encode($data));
    }

    public function testLasTresRutasSonPublicasEnElNamespaceDelPlugin(): void
    {
        $routes = [];
        Functions\when('register_rest_route')->alias(function (string $ns, string $route, array $args) use (&$routes): bool {
            $routes[$ns . $route] = [$args['methods'], $args['permission_callback']];
            return true;
        });

        RestApi::routes();

        $this->assertSame(
            ['hwe-shipping/v1/methods', 'hwe-shipping/v1/free-shipping/scope', 'hwe-shipping/v1/rates'],
            array_keys($routes)
        );
        foreach ($routes as [$method, $permission]) {
            $this->assertSame('GET', $method);
            $this->assertSame('__return_true', $permission);
        }
    }

    // --- guardado --------------------------------------------------------------------------------

    public function testGuardaLaTarifaComoEnteroNoNegativoYLaCasillaDeEnvioGratis(): void
    {
        $written = [];
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value) use (&$written): bool {
            $written[$key] = $value;
            return true;
        });
        $_POST = ['hwe_shipping_rate_nonce' => 'x', 'hwe_shipping_rate_amount' => '12.6', 'hwe_shipping_rate_free_eligible' => '1'];

        Save::saveRate(11);

        $this->assertSame('13', $written['_hwe_shipping_rate_amount']);
        $this->assertSame('1', $written['_hwe_shipping_rate_free_eligible']);
    }

    public function testUnaTarifaNegativaOSinNumeroQuedaEnCero(): void
    {
        $written = [];
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value) use (&$written): bool {
            $written[$key] = $value;
            return true;
        });

        $_POST = ['hwe_shipping_rate_nonce' => 'x', 'hwe_shipping_rate_amount' => '-4'];
        Save::saveRate(11);
        $this->assertSame('0', $written['_hwe_shipping_rate_amount']);

        $_POST = ['hwe_shipping_rate_nonce' => 'x', 'hwe_shipping_rate_amount' => 'abc'];
        Save::saveRate(11);
        $this->assertSame('0', $written['_hwe_shipping_rate_amount']);
        $this->assertSame('0', $written['_hwe_shipping_rate_free_eligible']);
    }

    public function testGuardaElMetodoYBorraElValorAgregadoVacio(): void
    {
        $written = [];
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value) use (&$written): bool {
            $written[$key] = $value;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function (int $id, string $key) use (&$written): bool {
            $written[$key] = 'borrado';
            return true;
        });
        $_POST = ['hwe_shipping_method_nonce' => 'x', 'hwe_shipping_method_enabled' => '1', 'hwe_shipping_method_added_value' => ''];

        Save::saveMethod(21);

        $this->assertSame('1', $written['_hwe_shipping_method_enabled']);
        $this->assertSame('borrado', $written['_hwe_shipping_method_added_value']);
    }

    public function testSinNonceValidoNoSeGuardaNada(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(false);
        Functions\expect('update_post_meta')->never();
        $_POST = ['hwe_shipping_rate_nonce' => 'x', 'hwe_shipping_rate_amount' => '5'];

        Save::saveRate(11);
    }

    // --- revalidación ----------------------------------------------------------------------------

    public function testDeclaraElTagShippingSinDuplicarlo(): void
    {
        $this->assertSame(['site-config', 'shipping'], Revalidation::tags(['site-config']));
        $this->assertSame(['shipping'], Revalidation::tags(['shipping']));
        $this->assertSame(['shipping'], Revalidation::tags('roto'));
    }

    public function testCambiarUnaTarifaRevalidaElTag(): void
    {
        define('HWE_REVALIDATION_SECRET', 'secreto-de-prueba-0123456789');
        Functions\when('wp_is_post_revision')->justReturn(false);
        Filters\expectApplied('hwe_revalidation_tags')->andReturnUsing(static fn (array $core): array => [...$core, 'shipping']);
        $bodies = [];
        Functions\when('wp_remote_post')->alias(function (string $url, array $args) use (&$bodies): array {
            $bodies[] = $args['body'];
            return [];
        });

        Revalidation::onChange(11);

        $this->assertCount(1, $bodies);
        $this->assertSame('shipping', json_decode($bodies[0], true)['tag']);
    }

    // --- zona de reserva -------------------------------------------------------------------------

    private function resetZones(): void
    {
        \WC_Shipping_Zone::$store = [];
        \WC_Shipping_Zone::$nextId = 1;
        \WC_Shipping_Zone::$nextInstance = 100;
        \WC_Shipping_Zone::$saves = 0;
    }

    public function testElEsquemaDelControlCenterGanaLosCamposDeLaZonaDeReserva(): void
    {
        $schema = ReserveZone::schema(['shipping' => ['type' => 'group', 'children' => []], 'ecommerce' => ['type' => 'group']]);

        $this->assertSame(['reserve_enabled', 'reserve_name', 'reserve_cost'], array_keys($schema['shipping']['children']));
        $this->assertTrue($schema['shipping']['children']['reserve_enabled']['default']);
        $this->assertSame(['type' => 'group'], $schema['ecommerce'], 'no toca otros grupos');
    }

    public function testUnEsquemaSinElGrupoOMalFormadoSeDevuelveIgual(): void
    {
        $this->assertSame('roto', ReserveZone::schema('roto'));
        $this->assertSame(['a' => 1], ReserveZone::schema(['a' => 1]));
    }

    public function testCreaLaZonaDeReservaConElPaisDeLaTiendaYElCosteConfigurado(): void
    {
        $this->resetZones();
        $this->config = ['ecommerce' => ['country' => 'ES'], 'shipping' => ['reserve_name' => 'Envío península', 'reserve_cost' => '7']];

        ReserveZone::sync();

        $zone = \WC_Shipping_Zone::$store[1];
        $this->assertSame('Envío península', $zone['name']);
        $this->assertSame([['ES', 'country']], $zone['locations']);
        $this->assertCount(1, $zone['methods']);
        $this->assertSame('flat_rate', $zone['methods'][0]->id);
        $settings = $this->options['woocommerce_flat_rate_100_settings'];
        $this->assertSame(['title' => 'Envío península', 'tax_status' => 'none', 'cost' => '7'], $settings);
        $this->assertSame(1, $this->options['hwe_shipping_reserve_zone_id']);
    }

    public function testSincronizarDosVecesNoDuplicaLaZonaNiElMetodo(): void
    {
        $this->resetZones();
        $this->config = ['ecommerce' => ['country' => 'ES']];
        ReserveZone::sync();

        $this->config = ['ecommerce' => ['country' => 'PT'], 'shipping' => ['reserve_cost' => '9']];
        ReserveZone::sync();

        $this->assertCount(1, \WC_Shipping_Zone::$store);
        $this->assertCount(1, \WC_Shipping_Zone::$store[1]['methods']);
        $this->assertSame([['PT', 'country']], \WC_Shipping_Zone::$store[1]['locations'], 'sigue al país de la tienda');
        $this->assertSame('9', $this->options['woocommerce_flat_rate_100_settings']['cost']);
    }

    public function testRespetaLosAjustesAjenosDelMetodoYSoloPisaLosSuyos(): void
    {
        $this->resetZones();
        $this->options['woocommerce_flat_rate_100_settings'] = ['requires' => 'min_amount', 'cost' => '1'];
        $this->config = ['ecommerce' => ['country' => 'ES']];
        ReserveZone::sync();
        // El método ya existía con id 100? No: la zona es nueva y crea el 100.
        $settings = $this->options['woocommerce_flat_rate_100_settings'];

        $this->assertSame('min_amount', $settings['requires']);
        $this->assertSame('0', $settings['cost']);
    }

    public function testApagadaNoCreaNingunaZona(): void
    {
        $this->resetZones();
        $this->config = ['ecommerce' => ['country' => 'ES'], 'shipping' => ['reserve_enabled' => false]];

        ReserveZone::sync();

        $this->assertSame([], \WC_Shipping_Zone::$store);
    }

    public function testSiLaZonaSeBorroSeCreaOtra(): void
    {
        $this->resetZones();
        $this->options['hwe_shipping_reserve_zone_id'] = 55;
        $this->config = ['ecommerce' => ['country' => 'ES']];

        ReserveZone::sync();

        $this->assertSame(1, $this->options['hwe_shipping_reserve_zone_id']);
    }

    public function testSinPaisNoCreaLaZonaYLoRegistra(): void
    {
        $this->resetZones();
        $this->config = ['ecommerce' => ['country' => '']];
        Functions\when('hwe_geo_store_country')->justReturn('');
        Functions\when('wc_get_base_location')->justReturn(['country' => '']);

        ReserveZone::sync();

        $this->assertSame([], \WC_Shipping_Zone::$store);
        $this->assertStringContainsString('[hwe] shipping:', $this->logged[0]);
    }
}
