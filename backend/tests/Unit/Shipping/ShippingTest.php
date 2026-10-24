<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Shipping\FreeShipping;
use HWE\Shipping\PostTypes;
use HWE\Shipping\PublicConfig;
use HWE\Shipping\SettingsSchema;
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
            // Cada ciudad es fija con una opción «Estándar» (id `opt<id>`): «free» decide si esa opción
            // aplica envío gratis. Una fila puede traer una segunda opción («extra») y, con `type`
            // `distance`, una referencia (`origin`) y su radio (`coverage`).
            foreach ($this->rates as $r) {
                $distance = ($r['type'] ?? 'fixed') === 'distance';
                $this->postMeta[$r['id']]['_hwe_shipping_city_type'] = $distance ? 'distance' : 'fixed';
                $options = [$this->optionRow('opt' . $r['id'], 'Estándar', '1' === $r['free'], (float) $r['amount'], $distance)];
                if (isset($r['extra'])) {
                    $options[] = $this->optionRow('exp' . $r['id'], 'Express', '1' === $r['extra']['free'], (float) $r['extra']['amount'], $distance);
                }
                $this->postMeta[$r['id']]['_hwe_shipping_city_options'] = json_encode($options);
                if ($distance) {
                    $this->postMeta[$r['id']]['_hwe_shipping_city_origin_lat'] = (string) $r['origin']['lat'];
                    $this->postMeta[$r['id']]['_hwe_shipping_city_origin_lng'] = (string) $r['origin']['lng'];
                    $this->postMeta[$r['id']]['_hwe_shipping_city_origin_label'] = $r['origin']['label'];
                    $this->postMeta[$r['id']]['_hwe_shipping_city_coverage_km'] = $r['coverage'];
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

    /** Carrito y cliente de WooCommerce para `FreeShipping::cartState()`: líneas y destino de envío. */
    private function cartWith(array $items, string $country, string $city): void
    {
        // Como en WooCommerce, el paquete de envío lleva el destino del cliente (con calle: es lo que pide
        // la decisión del envío para resolver la ciudad).
        $destination = ['country' => $country, 'city' => $city, 'address_1' => 'Calle de prueba 1'];
        Functions\when('WC')->justReturn(new ShippingWc(new ShippingWcCart($items, [['contents' => $items, 'destination' => $destination]]), new ShippingWcCustomer($country, $city)));
    }

    /** Opción tal como la guarda `DeliveryOption::toArray` (fija o por distancia). */
    private function optionRow(string $id, string $name, bool $free, float $amount, bool $distance): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'free_eligible' => $free,
            'amount' => $distance ? null : $amount,
            'price_per_km' => $distance ? 2.0 : null,
            'min_cost' => 0.0,
            'max_cost' => 0.0,
        ];
    }

    /** Paquete de WooCommerce con un artículo de `$total` a `$city` (con calle, como exige el método). */
    private function pkg(string $city = 'Madrid', float $total = 60, string $country = 'ES'): array
    {
        return [
            'contents' => [$this->item($total)],
            'applied_coupons' => [],
            'destination' => ['country' => $country, 'city' => $city, 'address_1' => 'Calle Mayor 1'],
        ];
    }

    /** Un `WC_Shipping_Rate` de la tarifa `hwe_city_rate:<optionId>` (o la de reserva) con su coste. */
    private function rate(string $optionId, float $cost): \WC_Shipping_Rate
    {
        $rate = new \WC_Shipping_Rate('hwe_city_rate', $cost);
        $rate->id = 'hwe_city_rate:' . $optionId;

        return $rate;
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

    public function testConLaReglaCumplidaSoloLasOpcionesMarcadasQuedanAZeroConSuMotivo(): void
    {
        $this->rates = [['id' => 11, 'title' => 'Madrid', 'amount' => '8', 'free' => '1', 'extra' => ['free' => '0', 'amount' => '15']]];
        $this->useRule(['enabled' => true, 'min_amount' => 50]);
        $standard = $this->rate('opt11', 8);
        $express = $this->rate('exp11', 15);

        FreeShipping::applyToRates(['a' => $standard, 'b' => $express], $this->pkg('Madrid', 60));

        $this->assertSame(0.0, $standard->cost);
        $this->assertSame([], $standard->taxes);
        $this->assertSame('Compra mínima de 50.00 € alcanzada', $standard->meta['_hwe_free_shipping_reason']);
        $this->assertSame(15.0, $express->cost, 'la opción no marcada se cobra');
        $this->assertSame([], $express->meta);
    }

    public function testSinCumplirLaReglaLasTarifasNoCambian(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 50]);
        $rate = $this->rate('opt11', 8);

        FreeShipping::applyToRates(['a' => $rate], $this->pkg('Madrid', 10));

        $this->assertSame(8.0, $rate->cost);
        $this->assertSame([], $rate->meta);
    }

    public function testUnaCiudadSinOpcionesMarcadasNoTieneEnvioGratisAunqueLleguePorElMinimo(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 50]);
        $rate = $this->rate('opt12', 12);

        FreeShipping::applyToRates(['a' => $rate], $this->pkg('Sevilla', 100));

        $this->assertSame(12.0, $rate->cost);
        $this->assertSame([], $rate->meta);
    }

    public function testLaReservaNuncaEsGratis(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 50]);
        $reserve = new \WC_Shipping_Rate('hwe_city_rate', 7);
        $reserve->id = 'hwe_city_rate:reserve';

        // Aunque el destino sea una ciudad elegible, la reserva no es una opción marcada.
        FreeShipping::applyToRates(['a' => $reserve], $this->pkg('Madrid', 100));

        $this->assertSame(7.0, $reserve->cost);
        $this->assertSame([], $reserve->meta);
    }

    public function testUnMotivoExternoDejaGratisSoloLasOpcionesElegiblesYSinLaRegla(): void
    {
        $this->rates = [['id' => 11, 'title' => 'Madrid', 'amount' => '8', 'free' => '1', 'extra' => ['free' => '0', 'amount' => '15']]];
        $this->useRule(['enabled' => false]);
        Filters\expectApplied('hwe_shipping_free_reason')
            ->once()
            ->with(null, \Mockery::type('array'), \Mockery::on(
                static fn (array $context): bool => ['hwe_city_rate:opt11'] === $context['rate_ids']
                    && 'fixed' === $context['mode']
                    && false === $context['qualifies']
            ))
            ->andReturn('Envío gratis de miembro');
        $standard = $this->rate('opt11', 8);
        $express  = $this->rate('exp11', 15);

        FreeShipping::applyToRates(['a' => $standard, 'b' => $express], $this->pkg('Madrid', 10));

        $this->assertSame(0.0, $standard->cost);
        $this->assertSame([], $standard->taxes);
        $this->assertSame('Envío gratis de miembro', $standard->meta['_hwe_free_shipping_reason']);
        $this->assertSame(15.0, $express->cost, 'La opción no marcada se sigue cobrando.');
        $this->assertSame([], $express->meta);
    }

    public function testSinMotivoExternoLasTarifasNoCambian(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 50]);
        Filters\expectApplied('hwe_shipping_free_reason')->once()->andReturn(null);
        $rate = $this->rate('opt11', 8);

        FreeShipping::applyToRates(['a' => $rate], $this->pkg('Madrid', 10));

        $this->assertSame(8.0, $rate->cost);
        $this->assertSame([], $rate->meta);
    }

    public function testLaReglaDeLaTiendaTienePrioridadYNoConsultaElMotivoExterno(): void
    {
        $this->rates = [['id' => 11, 'title' => 'Madrid', 'amount' => '8', 'free' => '1']];
        $this->useRule(['enabled' => true, 'min_amount' => 50]);
        Filters\expectApplied('hwe_shipping_free_reason')->never();
        $rate = $this->rate('opt11', 8);

        FreeShipping::applyToRates(['a' => $rate], $this->pkg('Madrid', 60));

        $this->assertSame(0.0, $rate->cost);
        $this->assertSame('Compra mínima de 50.00 € alcanzada', $rate->meta['_hwe_free_shipping_reason']);
    }

    public function testUnMotivoExternoNoHaceGratisLaReserva(): void
    {
        $this->useRule(['enabled' => false]);
        Filters\expectApplied('hwe_shipping_free_reason')->once()->andReturn('Envío gratis de miembro');
        $reserve = new \WC_Shipping_Rate('hwe_city_rate', 7);
        $reserve->id = 'hwe_city_rate:reserve';

        FreeShipping::applyToRates(['a' => $reserve], $this->pkg('Madrid', 10));

        $this->assertSame(7.0, $reserve->cost);
        $this->assertSame([], $reserve->meta);
    }

    public function testUnMotivoExternoQueNoEsTextoSeDescartaYSeRegistra(): void
    {
        $this->useRule(['enabled' => false]);
        Filters\expectApplied('hwe_shipping_free_reason')->once()->andReturn(['no', 'es', 'texto']);
        $rate = $this->rate('opt11', 8);

        FreeShipping::applyToRates(['a' => $rate], $this->pkg('Madrid', 10));

        $this->assertSame(8.0, $rate->cost);
        $this->assertSame([], $rate->meta);
        $this->assertStringContainsString('[hwe] shipping:', implode(' ', $this->logged));
        $this->assertStringContainsString('hwe_shipping_free_reason', implode(' ', $this->logged));
    }

    public function testUnCuponConEnvioGratisDejaTodoAZeroAunqueLaReglaNoSeCumpla(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 50]);
        \WC_Coupon::$free = ['GRATIS' => true];
        $standard = $this->rate('opt11', 8);
        $sevilla = $this->rate('opt12', 12);
        $rates = ['a' => $standard, 'b' => $sevilla];

        FreeShipping::applyToRates($rates, $this->pkg('Madrid', 10));
        $this->assertSame([8.0, 12.0], [$standard->cost, $sevilla->cost]);

        ShippingCost::applyCouponFreeShipping($rates, ['applied_coupons' => ['GRATIS']]);

        $this->assertSame([0.0, 0.0], [$standard->cost, $sevilla->cost]);
    }

    public function testElCarritoListaLasTarifasQueLaReglaDejaAZeroSoloDeLasOpcionesMarcadas(): void
    {
        $this->rates = [['id' => 11, 'title' => 'Madrid', 'amount' => '8', 'free' => '1', 'extra' => ['free' => '0', 'amount' => '15']]];
        $this->useRule(['enabled' => true, 'min_amount' => 50]);
        $this->cartWith([$this->item(60)], 'ES', 'Madrid');

        $state = FreeShipping::cartState();

        $this->assertTrue($state['qualifies']);
        $this->assertSame(['hwe_city_rate:opt11'], $state['rate_ids']);
    }

    public function testBajoElMinimoOSinOpcionesMarcadasElCarritoNoListaTarifas(): void
    {
        $this->rates = [['id' => 11, 'title' => 'Madrid', 'amount' => '8', 'free' => '1']];
        $this->useRule(['enabled' => true, 'min_amount' => 50]);
        $this->cartWith([$this->item(10)], 'ES', 'Madrid');

        $this->assertSame([], FreeShipping::cartState()['rate_ids']);
    }

    public function testUnaCiudadSinOpcionesMarcadasNoListaTarifasEnElCarrito(): void
    {
        $this->useRule(['enabled' => true, 'min_amount' => 50]);
        $this->cartWith([$this->item(100)], 'ES', 'Sevilla');

        $state = FreeShipping::cartState();

        $this->assertSame('not_eligible', $state['city']);
        $this->assertSame([], $state['rate_ids']);
    }

    public function testElEsquemaDelCarritoDeclaraRateIds(): void
    {
        $this->assertSame('array', FreeShipping::cartStateSchema()['rate_ids']['type']);
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

    public function testLasTarifasSoloExponenNombreYTipoDeCiudad(): void
    {
        $this->rates[] = ['id' => 14, 'title' => 'Cartagena', 'amount' => '', 'free' => '0', 'type' => 'distance',
            'origin' => ['lat' => '37.6', 'lng' => '-0.98', 'label' => 'Dirección de referencia de prueba'], 'coverage' => '12'];

        $data = RestApi::getRates(new \WP_REST_Request())->get_data();

        $this->assertSame([
            ['name' => 'Bilbao', 'type' => 'fixed'],
            ['name' => 'Cartagena', 'type' => 'distance'],
            ['name' => 'Madrid', 'type' => 'fixed'],
            ['name' => 'Sevilla', 'type' => 'fixed'],
        ], $data);
        $json = (string) json_encode($data);
        $this->assertStringNotContainsString('referencia de prueba', $json);
        $this->assertStringNotContainsString('37.6', $json);
        $this->assertStringNotContainsString('amount', $json);
    }

    public function testLaConfigPublicaIndicaSiHayCiudadesPorDistancia(): void
    {
        $this->assertSame(['shipping' => ['distance_enabled' => false]], PublicConfig::add([]));

        $this->rates[] = ['id' => 14, 'title' => 'Cartagena', 'amount' => '', 'free' => '0', 'type' => 'distance',
            'origin' => ['lat' => '37.6', 'lng' => '-0.98', 'label' => 'Referencia'], 'coverage' => '12'];

        $this->assertTrue(PublicConfig::add(['shipping' => ['reserve_name' => 'Resto']])['shipping']['distance_enabled']);
        $this->assertSame('Resto', PublicConfig::add(['shipping' => ['reserve_name' => 'Resto']])['shipping']['reserve_name']);
    }

    public function testLaConfigPublicaSeRegistraEnElFiltroDelControlCenter(): void
    {
        Filters\expectAdded('hwe_control_center_public_config')->once();

        PublicConfig::register();
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

    public function testCambiarUnaCiudadRevalidaTambienLaConfigPublica(): void
    {
        define('HWE_REVALIDATION_SECRET', 'secreto-de-prueba-0123456789');
        Functions\when('wp_is_post_revision')->justReturn(false);
        Filters\expectApplied('hwe_revalidation_tags')->andReturnUsing(static fn (array $core): array => [...$core, 'shipping']);
        $bodies = [];
        Functions\when('wp_remote_post')->alias(function (string $url, array $args) use (&$bodies): array {
            $bodies[] = $args['body'];
            return [];
        });

        Revalidation::onRateChange(11);

        $tags = array_map(static fn (string $body): string => json_decode($body, true)['tag'], $bodies);
        $this->assertSame(['shipping', 'site-config'], $tags, '`shipping.distance_enabled` vive en la config pública.');
    }

    // --- esquema de la zona de reserva (su sincronización está en ReserveZoneTest) ----------------

    public function testElEsquemaDelControlCenterGanaLosCamposDeLaZonaDeReserva(): void
    {
        $schema = SettingsSchema::schema(['shipping' => ['type' => 'group', 'children' => []], 'ecommerce' => ['type' => 'group']]);

        $this->assertSame(
            ['reserve_enabled', 'reserve_name', 'reserve_cost', 'distance_method', 'distance_travel_mode', 'distance_straight_line_increment', 'distance_cache_ttl_hours', 'distance_connection_test'],
            array_keys($schema['shipping']['children'])
        );
        $this->assertTrue($schema['shipping']['children']['reserve_enabled']['default']);
        $this->assertSame(['type' => 'group'], $schema['ecommerce'], 'no toca otros grupos');
    }

    public function testUnEsquemaSinElGrupoOMalFormadoSeDevuelveIgual(): void
    {
        $this->assertSame('roto', SettingsSchema::schema('roto'));
        $this->assertSame(['a' => 1], SettingsSchema::schema(['a' => 1]));
    }
}

/** `WC()` mínimo para el envío gratis: carrito y cliente, lo que lee `FreeShipping::cartState()`. */
final class ShippingWc
{
    public function __construct(public ShippingWcCart $cart, public ShippingWcCustomer $customer)
    {
    }
}

final class ShippingWcCart
{
    /**
     * @param array<int,array<string,mixed>> $items
     * @param array<int,array<string,mixed>> $packages
     */
    public function __construct(private array $items, private array $packages = [])
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function get_shipping_packages(): array
    {
        return $this->packages;
    }

    /** @return array<int,array<string,mixed>> */
    public function get_cart(): array
    {
        return $this->items;
    }

    public function display_prices_including_tax(): bool
    {
        return false;
    }
}

final class ShippingWcCustomer
{
    public function __construct(private string $country, private string $city)
    {
    }

    public function get_shipping_country(): string
    {
        return $this->country;
    }

    public function get_shipping_city(): string
    {
        return $this->city;
    }
}
