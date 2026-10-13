<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\CouponRules;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin `hwe-coupon-rules` (módulo `coupon-rules`): ventana de validez inclusiva, primera
 * compra, sesión, alcance y cupones públicos para el BFF.
 *
 * Cada test en su proceso: el archivo define funciones y constantes globales, y `WC_Coupon`
 * se simula con `eval` (WooCommerce no está disponible).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CouponRulesTest extends TestCase
{
    /** @var array<int,array<string,mixed>> metas por id de cupón */
    private array $meta = [];

    /** @var array<string,callable> filtros registrados por el archivo */
    private array $filters = [];

    /** @var list<array<string,mixed>> consultas a wc_get_orders */
    private array $orderQueries = [];

    /** @var array<string,list<int>> resultado de wc_get_orders por tipo de consulta */
    private array $ordersFound = ['customer' => [], 'email' => []];

    protected function setUp(): void
    {
        parent::setUp();

        eval('class WC_Coupon {
            public const E_WC_COUPON_INVALID_FILTERED = 100;
            public const E_WC_COUPON_USAGE_LIMIT_REACHED = 106;
            public const E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK = 107;
            public const E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK_GUEST = 108;
            public static array $data = [];
            public function __construct(private int $id = 0) {}
            private function d(string $k, $default = null) { return self::$data[$this->id][$k] ?? $default; }
            public function get_id() { return $this->id; }
            public function get_code() { return $this->d("code", "cupon"); }
            public function get_discount_type() { return $this->d("type", "percent"); }
            public function get_amount() { return $this->d("amount", "10"); }
            public function get_free_shipping() { return $this->d("free_shipping", false); }
            public function get_product_ids($ctx = "view") { return $this->d("products", []); }
            public function get_product_categories($ctx = "view") { return $this->d("categories", []); }
            public function get_excluded_product_ids() { return $this->d("ex_products", []); }
            public function get_excluded_product_categories() { return $this->d("ex_categories", []); }
            public function get_exclude_sale_items() { return $this->d("ex_sale", false); }
            public function get_minimum_amount() { return $this->d("min", ""); }
            public function get_maximum_amount() { return $this->d("max", ""); }
            public function get_email_restrictions() { return $this->d("emails", []); }
            public function get_date_expires() {
                $t = $this->d("expires");
                return $t === null ? null : new class($t) { public function __construct(private int $t) {} public function getTimestamp() { return $this->t; } };
            }
            public function get_usage_limit() { return $this->d("usage_limit", 0); }
            public function get_usage_count() { return $this->d("usage_count", 0); }
            public function get_usage_limit_per_user() { return $this->d("per_user", 0); }
            public function get_data_store() { return null; }
        }');
        \WC_Coupon::$data = [];

        $this->meta = [];
        $this->filters = [];
        $this->orderQueries = [];
        $this->ordersFound = ['customer' => [], 'email' => []];

        Functions\when('get_post_meta')->alias(
            fn ($id, $key) => $this->meta[(int) $id][$key] ?? ''
        );
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('UTC'));
        Functions\when('date_i18n')->alias(static fn (string $f, int $t) => gmdate($f, $t));
        Functions\when('__')->returnArg(1);
        Functions\when('is_admin')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('wc_get_is_paid_statuses')->justReturn(['processing', 'completed']);
        Functions\when('get_userdata')->justReturn((object) ['user_email' => 'ana@example.com']);
        Functions\when('wc_get_orders')->alias(function (array $q): array {
            $this->orderQueries[] = $q;
            return isset($q['billing_email']) ? $this->ordersFound['email'] : $this->ordersFound['customer'];
        });
        Functions\when('add_filter')->alias(function (string $name, $cb) {
            $this->filters[$name] = $cb;
            return true;
        });
        Functions\when('add_action')->justReturn(true);
    }

    private function load(): void
    {
        $this->loadOwnFile('plugins/hwe-coupon-rules/hwe-coupon-rules.php');
    }

    /** Registra un cupón de prueba y devuelve el objeto. */
    private function coupon(int $id, array $data = [], array $meta = []): \WC_Coupon
    {
        \WC_Coupon::$data[$id] = $data + ['code' => 'cupon' . $id];
        $this->meta[$id] = $meta;
        return new \WC_Coupon($id);
    }

    private function validate(\WC_Coupon $coupon): bool
    {
        return ($this->filters['woocommerce_coupon_is_valid'])(true, $coupon);
    }

    private function errorMessage(int $code, ?\WC_Coupon $coupon = null): string
    {
        return ($this->filters['woocommerce_coupon_error'])('genérico', $code, $coupon);
    }

    // ── alcance ─────────────────────────────────────────────────────────────

    public function testScopeIsDerivedFromTheNativeListsAndProductsWinOverCategories(): void
    {
        $this->load();

        $this->assertSame('all', hwe_coupon_scope($this->coupon(1)));
        $this->assertSame('categories', hwe_coupon_scope($this->coupon(2, ['categories' => [5]])));
        $this->assertSame('products', hwe_coupon_scope($this->coupon(3, ['products' => [9], 'categories' => [5]])));
    }

    public function testFirstPurchaseImpliesRequiresLogin(): void
    {
        $this->load();
        $this->meta[1] = [HWE_COUPON_META_FIRST_ONLY => 'yes'];
        $this->meta[2] = [HWE_COUPON_META_LOGIN => 'yes'];
        $this->meta[3] = [];

        $this->assertTrue(hwe_coupon_requires_login(1));
        $this->assertTrue(hwe_coupon_requires_login(2));
        $this->assertFalse(hwe_coupon_requires_login(3));
    }

    // ── ventana de validez ──────────────────────────────────────────────────

    public function testExpiryIsInclusiveOfTheLastDay(): void
    {
        $this->load();
        $midnight = strtotime('2026-10-27 00:00:00 UTC');
        $coupon = $this->coupon(1, ['expires' => $midnight]);
        $expired = $this->filters['woocommerce_coupon_validate_expiry_date'];

        Functions\when('time')->justReturn($midnight + 3600);
        $this->assertFalse($expired(true, $coupon), 'El propio día de caducidad todavía vale.');

        Functions\when('time')->justReturn($midnight + 86400);
        $this->assertTrue($expired(false, $coupon), 'Pasado ese día, caduca.');
    }

    public function testACouponThatHasNotStartedIsRejectedWithItsDate(): void
    {
        $this->load();
        $future = strtotime('2026-11-05 00:00:00 UTC');
        $coupon = $this->coupon(1, [], [HWE_COUPON_META_STARTS => '2026-11-05']);
        Functions\when('time')->justReturn($future - 3600);

        $this->assertFalse($this->validate($coupon));
        $this->assertStringContainsString(
            '05/11/2026',
            $this->errorMessage(\WC_Coupon::E_WC_COUPON_INVALID_FILTERED, $coupon)
        );

        Functions\when('time')->justReturn($future);
        $this->assertTrue($this->validate($coupon));
    }

    // ── sesión y primera compra ─────────────────────────────────────────────

    public function testAGuestCannotUseALoginOnlyCoupon(): void
    {
        $this->load();
        $coupon = $this->coupon(1, [], [HWE_COUPON_META_LOGIN => 'yes']);

        $this->assertFalse($this->validate($coupon));
        $this->assertStringContainsString(
            'cuenta',
            $this->errorMessage(\WC_Coupon::E_WC_COUPON_INVALID_FILTERED, $coupon)
        );
    }

    public function testFirstPurchaseIsLostWithAPaidOrderOfTheAccount(): void
    {
        $this->load();
        Functions\when('get_current_user_id')->justReturn(7);
        $coupon = $this->coupon(1, [], [HWE_COUPON_META_FIRST_ONLY => 'yes']);

        $this->assertTrue($this->validate($coupon), 'Sin pedidos, es su primera compra.');

        $this->ordersFound['customer'] = [501];
        // Otro proceso de memoización por petición: los resultados se cachean con `static`.
        $this->assertTrue(hwe_coupon_has_previous_order(8, ''), 'Otra cuenta con pedido pagado.');
    }

    public function testFirstPurchaseAlsoLooksAtTheBillingEmailOfGuestOrders(): void
    {
        $this->load();
        $this->ordersFound['email'] = [900];

        $this->assertTrue(hwe_coupon_has_previous_order(7, ''));
        $this->assertSame('ana@example.com', end($this->orderQueries)['billing_email']);
    }

    public function testFirstPurchaseCutoffDateIsPassedToTheOrderQuery(): void
    {
        $this->load();

        hwe_coupon_has_previous_order(7, '2026-01-01');

        $this->assertSame('>=2026-01-01', $this->orderQueries[0]['date_created']);
        $this->assertSame(['processing', 'completed'], $this->orderQueries[0]['status']);
    }

    // ── cupones públicos ────────────────────────────────────────────────────

    private function publish(array $coupons): void
    {
        $ids = array_keys($coupons);
        Functions\when('get_posts')->justReturn($ids);
        foreach ($coupons as $id => $spec) {
            $this->coupon($id, $spec['data'] ?? [], ($spec['meta'] ?? []) + [HWE_COUPON_META_PUBLIC => 'yes']);
        }
    }

    public function testPublicListAdvertisesAnActiveCouponWithItsScopeBySlug(): void
    {
        $this->load();
        Functions\when('time')->justReturn(1_800_000_000);
        Functions\when('get_post_field')->alias(static fn ($f, $id) => 'producto-' . $id);
        Functions\when('get_term')->alias(static function ($id) {
            $t = new \WP_Term();
            $t->slug = 'cat-' . $id;
            return $t;
        });
        $this->publish([
            10 => ['data' => ['code' => 'HOLA10', 'amount' => '15', 'type' => 'percent', 'categories' => [3]]],
        ]);

        $list = hwe_coupon_public_list();

        $this->assertCount(1, $list);
        $this->assertSame('HOLA10', $list[0]['code']);
        $this->assertSame('categories', $list[0]['scope']);
        $this->assertSame(['cat-3'], $list[0]['categorySlugs']);
        $this->assertSame([], $list[0]['productSlugs']);
    }

    public function testPublicListSkipsExpiredExhaustedAndUnadvertisableCoupons(): void
    {
        $this->load();
        $now = 1_800_000_000;
        Functions\when('time')->justReturn($now);
        $this->publish([
            1 => ['data' => ['expires' => $now - 3 * 86400]],                    // caducado
            2 => ['data' => ['usage_limit' => 5, 'usage_count' => 5]],            // agotado
            3 => ['data' => ['emails' => ['ana@example.com']]],                   // depende de quién compra
            4 => ['meta' => ['product_brands' => [12]]],                          // marcas: no evaluable
            5 => ['data' => ['code' => 'VALE']],                                  // el único válido
        ]);

        $codes = array_column(hwe_coupon_public_list(), 'code');

        $this->assertSame(['VALE'], $codes);
    }

    public function testACouponStartingWithinTheLookaheadIsListedButNotOneFurtherAway(): void
    {
        $this->load();
        // El margen es de 3 h (HWE_COUPON_PUBLIC_LOOKAHEAD): ISR de fichas y categorías.
        $now = strtotime('2026-11-01 22:00:00 UTC');
        Functions\when('time')->justReturn($now);
        $this->publish([
            1 => ['data' => ['code' => 'PRONTO'], 'meta' => [HWE_COUPON_META_STARTS => '2026-11-02']],  // empieza en 2 h
            2 => ['data' => ['code' => 'LEJOS'], 'meta' => [HWE_COUPON_META_STARTS => '2026-11-10']],
        ]);

        $codes = array_column(hwe_coupon_public_list(), 'code');

        $this->assertSame(['PRONTO'], $codes);
    }

    public function testPublicListFlagsWhatDependsOnTheCustomer(): void
    {
        $this->load();
        Functions\when('time')->justReturn(1_800_000_000);
        $this->publish([
            1 => ['data' => ['code' => 'UNAVEZ', 'per_user' => 1]],
            2 => ['data' => ['code' => 'PRIMERA'], 'meta' => [HWE_COUPON_META_FIRST_ONLY => 'yes']],
            3 => ['data' => ['code' => 'LIBRE']],
        ]);

        $byCode = array_column(hwe_coupon_public_list(), null, 'code');

        $this->assertTrue($byCode['UNAVEZ']['perUserLimited']);
        $this->assertTrue($byCode['PRIMERA']['perUserLimited']);
        $this->assertTrue($byCode['PRIMERA']['requiresLogin']);
        $this->assertFalse($byCode['LIBRE']['perUserLimited']);
    }

    public function testTheCouponsRegisterTheirRevalidationTag(): void
    {
        $this->load();

        $tags = ($this->filters['hwe_revalidation_tags'])(['posts']);

        $this->assertSame(['posts', 'coupons'], $tags);
    }
}
