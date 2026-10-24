<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once HWE_BACKEND_DIR . '/tests/Stubs/wp-memberships-benefits-fakes.php';
require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-cart.php';
require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-order.php';

/**
 * Plugin `hwe-memberships`, descuento de miembro (spec §7.2).
 *
 * Se prueba el DINERO, no la UI: el descuento se aplica al precio de línea del carrito del socio
 * con el beneficio activo, sobre el precio que la tienda cobraría (oferta incluida), nunca a las
 * líneas de membresía ni al catálogo sin sesión. El cupón y los impuestos se calculan después
 * sobre el precio rebajado, el pedido guarda el original y el descuento por línea más el nivel y
 * el porcentaje, y la misma decisión alimenta `priceFor()` (BFF) y la extensión de la Store API.
 *
 * El Foco 3 se cubre perdiendo el nivel con el carrito ya lleno: el siguiente recálculo devuelve
 * el precio original y retira las metas del carrito, sin encadenar rebajas.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MemberDiscountTest extends TestCase
{
    private const USER = 7;
    private const OTHER_USER = 8;
    private const LEVEL = 101;

    private const PRODUCT = 201;          // 100.00, categoría 10 (padre 100)
    private const PRODUCT_SALE = 202;     // 80.00 de oferta, regular 100.00, categoría 20
    private const PRODUCT_OUT = 203;      // 50.00, categoría 30 (padre 300)
    private const MEMBERSHIP_PRODUCT = 301; // producto de membresía, nunca se descuenta
    private const VARIATION = 401;        // variación del producto 201

    /** @var list<string> */
    private array $activePlugins = ['hwe-memberships/hwe-memberships.php'];
    /** @var array<int,array<string,mixed>> */
    private array $postMeta = [];
    /** @var array<int,list<int>> */
    private array $productCategories = [];
    /** @var array<int,list<int>> */
    private array $ancestors = [];
    /** @var array<int,int> */
    private array $parents = [];
    /** @var list<string> */
    private array $logged = [];
    private bool $categoryReadFails = false;
    private int $currentUser = self::USER;
    private TestMemberDiscountWc $wc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activePlugins     = ['hwe-memberships/hwe-memberships.php'];
        $this->postMeta          = [];
        $this->productCategories = [
            self::PRODUCT            => [10],
            self::PRODUCT_SALE       => [20],
            self::PRODUCT_OUT        => [30],
            self::MEMBERSHIP_PRODUCT => [40],
        ];
        $this->ancestors = [
            10 => [100],
            20 => [200],
            30 => [300],
            40 => [400],
        ];
        $this->parents = [
            self::VARIATION => self::PRODUCT,
        ];
        $this->logged          = [];
        $this->categoryReadFails = false;
        $this->currentUser     = self::USER;

        $this->wc = new TestMemberDiscountWc();

        $this->loadOwnFile('plugins/hwe-memberships/includes/Settings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Benefit.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/MemberDiscount.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Registry.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Access.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Products.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/StoreApi.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Pricing.php');

        $this->postMeta[self::MEMBERSHIP_PRODUCT] = [
            \HWE_Memberships_Products::LEVEL_META => (string) self::LEVEL,
            \HWE_Memberships_Products::DAYS_META  => '30',
        ];

        \HWE_Memberships_Levels::$configs      = [];
        \HWE_Memberships_Members::$states      = [];
        $this->setLevel();
        $this->setDiscount(10);

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => 'active_plugins' === $name
                ? $this->activePlugins
                : $default
        );
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->postMeta[$id][$key] ?? ''
        );
        Functions\when('wp_get_post_terms')->alias(function (int $productId): array {
            if ($this->categoryReadFails) {
                throw new \RuntimeException('fallo simulado de categorías');
            }

            return $this->productCategories[$productId] ?? [];
        });
        Functions\when('get_ancestors')->alias(
            fn (int $termId): array => $this->ancestors[$termId] ?? []
        );
        Functions\when('wp_get_post_parent_id')->alias(
            fn (int $postId): int => $this->parents[$postId] ?? 0
        );
        Functions\when('wc_get_product')->alias(function (int $productId) {
            $definition = $this->catalog()[$productId] ?? null;
            if (null === $definition) {
                return false;
            }

            return new TestMemberDiscountProduct(
                $productId,
                (string) $definition['price'],
                (string) $definition['regular']
            );
        });
        Functions\when('wc_get_price_decimals')->alias(static fn (): int => 2);
        Functions\when('wc_format_decimal')->alias(
            static fn ($value, int $decimals = 2): string => number_format((float) $value, $decimals, '.', '')
        );
        Functions\when('wc_price')->alias(
            static fn ($amount): string => '$' . number_format((float) $amount, 2, '.', '')
        );
        Functions\when('get_current_user_id')->alias(fn (): int => $this->currentUser);
        Functions\when('WC')->alias(fn (): TestMemberDiscountWc => $this->wc);
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args): mixed => $value
        );
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => is_string($value) ? trim($value) : '',
            'wp_unslash'          => static fn ($value) => $value,
            '__'                  => static fn ($value) => (string) $value,
            'esc_html'            => static fn ($value) => (string) $value,
            'esc_html__'          => static fn ($value) => (string) $value,
        ]);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    // ── Utilidades del caso ─────────────────────────────────────────────────

    /** @return array<int,array{price:string,regular:string}> */
    private function catalog(): array
    {
        return [
            self::PRODUCT            => ['price' => '100.00', 'regular' => '120.00'],
            self::PRODUCT_SALE       => ['price' => '80.00', 'regular' => '100.00'],
            self::PRODUCT_OUT        => ['price' => '50.00', 'regular' => '50.00'],
            self::MEMBERSHIP_PRODUCT => ['price' => '30.00', 'regular' => '30.00'],
            self::VARIATION          => ['price' => '110.00', 'regular' => '110.00'],
        ];
    }

    private function setLevel(int $levelId = self::LEVEL, string $status = 'active'): void
    {
        \HWE_Memberships_Members::$states[self::USER] = [
            'level_id'  => $levelId,
            'rank'      => $levelId > 0 ? 1 : 0,
            'status'    => $status,
            'ends_at'   => null,
            'queue'     => [],
            'grant_key' => 'seed',
        ];
    }

    /** @param list<int> $categories */
    private function setDiscount(int $percent, string $scope = 'all', array $categories = []): void
    {
        \HWE_Memberships_Levels::$configs[self::LEVEL] = [
            'member_discount' => [
                'enabled'    => true,
                'percent'    => $percent,
                'scope'      => $scope,
                'categories' => $categories,
            ],
        ];
    }

    private function product(int $productId): TestMemberDiscountProduct
    {
        $definition = $this->catalog()[$productId];

        return new TestMemberDiscountProduct(
            $productId,
            (string) $definition['price'],
            (string) $definition['regular']
        );
    }

    /** @param array<string,array{product_id:int,variation_id?:int,quantity?:int}> $lines */
    private function makeCart(array $lines): TestMemberDiscountCart
    {
        $items = [];
        foreach ($lines as $key => $line) {
            $productId = (int) $line['product_id'];
            $variation = (int) ($line['variation_id'] ?? 0);
            $items[$key] = [
                'product_id'   => $productId,
                'variation_id' => $variation,
                'quantity'     => max(1, (int) ($line['quantity'] ?? 1)),
                'data'         => $this->product($variation > 0 ? $variation : $productId),
            ];
        }

        $this->wc->cart = new TestMemberDiscountCart($items);

        return $this->wc->cart;
    }

    /** @param array<string,mixed> $base */
    private function cartData(TestMemberDiscountCart $cart, array $base = []): array
    {
        return \HWE_Memberships_Pricing::cartData(
            $base + [
                'level'           => null,
                'member_discount' => ['total' => '0', 'lines' => (object) []],
                'free_delivery'   => [],
                'blocked_items'   => [],
                'purchase_notice' => null,
            ],
            ['cart' => $cart, 'user_id' => $this->currentUser, 'state' => []]
        );
    }

    // ── Precio del carrito ──────────────────────────────────────────────────

    public function testTheDiscountOnlyAppliesToTheCartOfTheMemberWithTheBenefit(): void
    {
        $this->setDiscount(10, 'categories', [100]);
        $memberCart = $this->makeCart([
            'member' => ['product_id' => self::PRODUCT],
            'public' => ['product_id' => self::PRODUCT_OUT, 'quantity' => 2],
        ]);

        \HWE_Memberships_Pricing::applyCartDiscounts($memberCart);

        $this->assertSame('90.00', $memberCart->get_cart()['member']['data']->get_price('edit'));
        $this->assertSame('50.00', $memberCart->get_cart()['public']['data']->get_price('edit'));
        $this->assertSame('10.00', $memberCart->get_cart()['member'][\HWE_Memberships_Pricing::CART_DISCOUNT]);
        $this->assertSame(10, $memberCart->get_cart()['member'][\HWE_Memberships_Pricing::CART_PERCENT]);

        $this->currentUser = self::OTHER_USER;
        $guestCart         = $this->makeCart(['line' => ['product_id' => self::PRODUCT]]);
        \HWE_Memberships_Pricing::applyCartDiscounts($guestCart);
        $this->assertSame('100.00', $guestCart->get_cart()['line']['data']->get_price('edit'));

        $this->currentUser = 0;
        $anonymousCart     = $this->makeCart(['line' => ['product_id' => self::PRODUCT]]);
        \HWE_Memberships_Pricing::applyCartDiscounts($anonymousCart);
        $this->assertSame('100.00', $anonymousCart->get_cart()['line']['data']->get_price('edit'));
        $this->assertArrayNotHasKey(\HWE_Memberships_Pricing::CART_DISCOUNT, $anonymousCart->get_cart()['line']);
    }

    public function testCategoryScopeRespectsTheInheritedCategoryChain(): void
    {
        $this->setDiscount(10, 'categories', [100]);
        $cart = $this->makeCart([
            'inherited' => ['product_id' => self::PRODUCT],
            'outside'   => ['product_id' => self::PRODUCT_OUT],
        ]);

        \HWE_Memberships_Pricing::applyCartDiscounts($cart);

        $this->assertSame('90.00', $cart->get_cart()['inherited']['data']->get_price('edit'));
        $this->assertSame('50.00', $cart->get_cart()['outside']['data']->get_price('edit'));
        $this->assertSame(10, \HWE_Memberships_Pricing::priceFor(self::USER, self::PRODUCT)['percent']);
        $this->assertSame(0, \HWE_Memberships_Pricing::priceFor(self::USER, self::PRODUCT_OUT)['percent']);
    }

    public function testTheDiscountIsCalculatedOnTheSalePrice(): void
    {
        $cart = $this->makeCart(['sale' => ['product_id' => self::PRODUCT_SALE]]);

        \HWE_Memberships_Pricing::applyCartDiscounts($cart);

        $this->assertSame('72.00', $cart->get_cart()['sale']['data']->get_price('edit'));
        $this->assertSame(
            ['regular' => '100.00', 'price' => '80.00', 'member_price' => '72.00', 'percent' => 10],
            \HWE_Memberships_Pricing::priceFor(self::USER, self::PRODUCT_SALE)
        );
    }

    public function testCouponsAndTaxesAreCalculatedAfterTheLineDiscount(): void
    {
        $cart = $this->makeCart(['line' => ['product_id' => self::PRODUCT]]);

        \HWE_Memberships_Pricing::applyCartDiscounts($cart);

        $this->assertSame(90.0, $cart->itemsTotal());
        $this->assertSame(17.1, $cart->taxFor(0.19), 'El IVA se calcula sobre el precio rebajado.');
        $this->assertSame(81.0, $cart->totalAfterCoupon(10.0), 'El cupón se aplica después del descuento.');
        $this->assertSame([], $cart->get_fees(), 'El descuento va en el precio de línea, no en un fee.');
    }

    public function testMembershipProductsAreNeverDiscounted(): void
    {
        $cart = $this->makeCart(['membership' => ['product_id' => self::MEMBERSHIP_PRODUCT]]);

        \HWE_Memberships_Pricing::applyCartDiscounts($cart);

        $this->assertSame('30.00', $cart->get_cart()['membership']['data']->get_price('edit'));
        $this->assertArrayNotHasKey(\HWE_Memberships_Pricing::CART_DISCOUNT, $cart->get_cart()['membership']);
        $this->assertSame(0, \HWE_Memberships_Pricing::priceFor(self::USER, self::MEMBERSHIP_PRODUCT)['percent']);
    }

    public function testTheCatalogWithoutUserDoesNotChange(): void
    {
        $this->currentUser = 0;

        $this->assertSame(
            ['regular' => '120.00', 'price' => '100.00', 'member_price' => '100.00', 'percent' => 0],
            \HWE_Memberships_Pricing::priceFor(0, self::PRODUCT)
        );

        $cart = $this->makeCart(['line' => ['product_id' => self::PRODUCT]]);
        \HWE_Memberships_Pricing::applyCartDiscounts($cart);

        $this->assertSame('100.00', $cart->get_cart()['line']['data']->get_price('edit'));
    }

    public function testLosingTheLevelWithAFullCartRemovesTheDiscountOnRecalculation(): void
    {
        $cart = $this->makeCart(['line' => ['product_id' => self::PRODUCT]]);
        \HWE_Memberships_Pricing::applyCartDiscounts($cart);
        $this->assertSame('90.00', $cart->get_cart()['line']['data']->get_price('edit'));

        \HWE_Memberships_Members::$states[self::USER]['status'] = 'none';
        \HWE_Memberships_Members::$states[self::USER]['level_id'] = 0;

        \HWE_Memberships_Pricing::applyCartDiscounts($cart);

        $this->assertSame('100.00', $cart->get_cart()['line']['data']->get_price('edit'));
        $this->assertArrayNotHasKey(\HWE_Memberships_Pricing::CART_DISCOUNT, $cart->get_cart()['line']);
        $this->assertArrayNotHasKey(\HWE_Memberships_Pricing::CART_ORIGINAL, $cart->get_cart()['line']);

        $this->setLevel();
        \HWE_Memberships_Pricing::applyCartDiscounts($cart);
        $this->assertSame('90.00', $cart->get_cart()['line']['data']->get_price('edit'), 'Vuelve a aplicar sin encadenar.');
    }

    public function testCategoryReadFailureLeavesTheLineAtItsNormalPrice(): void
    {
        $this->setDiscount(10, 'categories', [100]);
        $this->categoryReadFails = true;
        $cart = $this->makeCart(['line' => ['product_id' => self::PRODUCT]]);

        \HWE_Memberships_Pricing::applyCartDiscounts($cart);

        $this->assertSame('100.00', $cart->get_cart()['line']['data']->get_price('edit'));
        $this->assertSame(0, \HWE_Memberships_Pricing::priceFor(self::USER, self::PRODUCT)['percent']);
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
    }

    public function testAnOutOfRangePercentIsClampedWhenApplied(): void
    {
        $this->setDiscount(150);
        $this->assertSame(100, \HWE_Memberships_Pricing::priceFor(self::USER, self::PRODUCT)['percent']);
        $this->assertSame('0.00', \HWE_Memberships_Pricing::priceFor(self::USER, self::PRODUCT)['member_price']);

        $this->setDiscount(-5);
        $this->assertSame(0, \HWE_Memberships_Pricing::priceFor(self::USER, self::PRODUCT)['percent']);
        $this->assertSame('100.00', \HWE_Memberships_Pricing::priceFor(self::USER, self::PRODUCT)['member_price']);
    }

    public function testTheBenefitFieldsSanitizeThePercentScopeAndCategories(): void
    {
        $clean = \HWE_Memberships_Benefits::sanitizeLevelConfig([
            'member_discount' => [
                'enabled'    => true,
                'percent'    => 150,
                'scope'      => 'otro',
                'categories' => [3, '7', 'x', 0, 3],
            ],
        ]);

        $this->assertSame(100, $clean['member_discount']['percent']);
        $this->assertSame('all', $clean['member_discount']['scope']);
        $this->assertSame([3, 7], $clean['member_discount']['categories']);
    }

    public function testPriceForMatchesTheCartForEveryEligibleLine(): void
    {
        $cart = $this->makeCart([
            'a' => ['product_id' => self::PRODUCT],
            'b' => ['product_id' => self::PRODUCT_SALE, 'quantity' => 2],
        ]);

        \HWE_Memberships_Pricing::applyCartDiscounts($cart);

        foreach (['a' => self::PRODUCT, 'b' => self::PRODUCT_SALE] as $key => $productId) {
            $this->assertSame(
                \HWE_Memberships_Pricing::priceFor(self::USER, $productId)['member_price'],
                $cart->get_cart()[$key]['data']->get_price('edit')
            );
        }
    }

    public function testAVariationUsesThePriceOfTheVariationAndTheScopeOfItsParent(): void
    {
        $this->setDiscount(10, 'categories', [100]);

        $quote = \HWE_Memberships_Pricing::priceFor(self::USER, self::PRODUCT, self::VARIATION);

        $this->assertSame(10, $quote['percent']);
        $this->assertSame('110.00', $quote['price']);
        $this->assertSame('99.00', $quote['member_price']);
    }

    public function testTheStoreApiExtensionReceivesTheTotalAndPerLineDiscount(): void
    {
        $cart = $this->makeCart([
            'a' => ['product_id' => self::PRODUCT],
            'b' => ['product_id' => self::PRODUCT_SALE, 'quantity' => 2],
        ]);

        \HWE_Memberships_Pricing::applyCartDiscounts($cart);
        $data = $this->cartData($cart);

        $this->assertSame('2600', $data['member_discount']['total'], '10.00 + 2 × 8.00 en unidades menores.');
        $this->assertSame(['a' => '1000', 'b' => '1600'], (array) $data['member_discount']['lines']);
    }

    public function testTheExtensionIsEmptyWithoutDiscount(): void
    {
        $this->setDiscount(10, 'categories', [100]);
        $cart = $this->makeCart(['line' => ['product_id' => self::PRODUCT_OUT]]);
        \HWE_Memberships_Pricing::applyCartDiscounts($cart);

        $data = $this->cartData($cart);

        $this->assertSame('0', $data['member_discount']['total']);
        $this->assertSame([], (array) $data['member_discount']['lines']);
    }

    // ── Pedido ──────────────────────────────────────────────────────────────

    public function testTheOrderLineKeepsTheOriginalPriceAndTheDiscount(): void
    {
        $cart = $this->makeCart(['line' => ['product_id' => self::PRODUCT]]);
        \HWE_Memberships_Pricing::applyCartDiscounts($cart);

        $order = new \WC_Order();
        $order->id = 55;
        $item      = new \WC_Order_Item_Product(9, 0.0, 0.0, self::PRODUCT, 1);

        \HWE_Memberships_Pricing::snapshotLineItem($item, 'line', $cart->get_cart()['line'], $order);

        $this->assertSame('100.00', $item->get_meta(\HWE_Memberships_Pricing::META_LINE_ORIGINAL));
        $this->assertSame('10.00', $item->get_meta(\HWE_Memberships_Pricing::META_LINE_DISCOUNT));
        $this->assertSame(self::LEVEL, (int) $order->get_meta(\HWE_Memberships_Pricing::META_ORDER_LEVEL));
        $this->assertSame(10, (int) $order->get_meta(\HWE_Memberships_Pricing::META_ORDER_PERCENT));
    }

    public function testAMembershipLineDoesNotWriteDiscountMetas(): void
    {
        $cart = $this->makeCart(['membership' => ['product_id' => self::MEMBERSHIP_PRODUCT]]);
        \HWE_Memberships_Pricing::applyCartDiscounts($cart);

        $order = new \WC_Order();
        $item  = new \WC_Order_Item_Product(9, 0.0, 0.0, self::MEMBERSHIP_PRODUCT, 1);

        \HWE_Memberships_Pricing::snapshotLineItem($item, 'membership', $cart->get_cart()['membership'], $order);

        $this->assertSame('', $item->get_meta(\HWE_Memberships_Pricing::META_LINE_ORIGINAL));
        $this->assertSame('', $item->get_meta(\HWE_Memberships_Pricing::META_LINE_DISCOUNT));
        $this->assertSame('', $order->get_meta(\HWE_Memberships_Pricing::META_ORDER_LEVEL));
    }

    public function testTheOrderBoxGetsASingleRowWithTheLevelAndThePercent(): void
    {
        $order = new \WC_Order();
        $order->update_meta_data(\HWE_Memberships_Pricing::META_ORDER_LEVEL, self::LEVEL);
        $order->update_meta_data(\HWE_Memberships_Pricing::META_ORDER_PERCENT, 10);

        $rows = [
            ['label' => 'Discount level', 'value' => (string) self::LEVEL, 'state' => 'info'],
            ['label' => 'Discount percent', 'value' => '10', 'state' => 'info'],
            ['label' => 'Nivel', 'value' => 'Nivel 101', 'state' => 'granted'],
        ];

        $filtered = \HWE_Memberships_Pricing::orderBoxRows($rows, $order);

        $this->assertCount(2, $filtered);
        $this->assertSame('Nivel', $filtered[0]['label']);
        $this->assertSame('Descuento de miembro', $filtered[1]['label']);
        $this->assertStringContainsString('#101', (string) $filtered[1]['value']);
        $this->assertStringContainsString('10%', (string) $filtered[1]['value']);
    }

    // ── Registro y módulo apagado ───────────────────────────────────────────

    public function testRegisterHooksTheCartOrderAndExtensionPoints(): void
    {
        Actions\expectAdded('woocommerce_before_calculate_totals')
            ->once()
            ->with([\HWE_Memberships_Pricing::class, 'applyCartDiscounts'], 999, 1);
        Actions\expectAdded('woocommerce_checkout_create_order_line_item')
            ->once()
            ->with([\HWE_Memberships_Pricing::class, 'snapshotLineItem'], 10, 4);
        Filters\expectAdded(\HWE_Memberships_StoreApi::CART_DATA_FILTER)
            ->once()
            ->with([\HWE_Memberships_Pricing::class, 'cartData'], 10, 2);
        Filters\expectAdded('hwe_memberships_order_box_rows')
            ->once()
            ->with([\HWE_Memberships_Pricing::class, 'orderBoxRows'], 10, 2);

        \HWE_Memberships_Pricing::register();
    }

    public function testWithTheModuleOffNothingIsHookedOrDiscounted(): void
    {
        $this->activePlugins = [];

        Actions\expectAdded('woocommerce_before_calculate_totals')->never();
        Actions\expectAdded('woocommerce_checkout_create_order_line_item')->never();
        Filters\expectAdded(\HWE_Memberships_StoreApi::CART_DATA_FILTER)->never();
        Filters\expectAdded('hwe_memberships_order_box_rows')->never();

        \HWE_Memberships_Pricing::register();

        $this->assertSame(0, \HWE_Memberships_Pricing::priceFor(self::USER, self::PRODUCT)['percent']);
        $this->assertSame('100.00', \HWE_Memberships_Pricing::priceFor(self::USER, self::PRODUCT)['member_price']);
    }
}

/** Producto mínimo con precio de venta y precio regular. */
final class TestMemberDiscountProduct
{
    public function __construct(
        private int $id,
        private string $price,
        private string $regular = ''
    ) {
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_price(string $context = 'view'): string
    {
        return $this->price;
    }

    public function get_regular_price(string $context = 'view'): string
    {
        return '' !== $this->regular ? $this->regular : $this->price;
    }

    public function set_price($price): void
    {
        $this->price = is_numeric($price) ? number_format((float) $price, 2, '.', '') : (string) $price;
    }
}

/** Carrito con sus líneas mutables, totales de línea y una simulación de cupón/IVA posterior. */
final class TestMemberDiscountCart extends \WC_Cart
{
    /** @var array<string,array<string,mixed>> */
    public array $cart_contents = [];

    /** @param array<string,array<string,mixed>> $items */
    public function __construct(array $items = [])
    {
        parent::__construct($items);
        $this->cart_contents = $items;
    }

    /** @return array<string,array<string,mixed>> */
    public function get_cart(): array
    {
        return $this->cart_contents;
    }

    public function itemsTotal(): float
    {
        $total = 0.0;
        foreach ($this->cart_contents as $item) {
            $total += (float) $item['data']->get_price('edit') * (int) ($item['quantity'] ?? 1);
        }

        return round($total, 2);
    }

    public function taxFor(float $rate): float
    {
        return round($this->itemsTotal() * $rate, 2);
    }

    public function totalAfterCoupon(float $percent): float
    {
        return round($this->itemsTotal() * (1 - $percent / 100), 2);
    }
}

/** Doble mínimo de `WC()` para el carrito actual. */
final class TestMemberDiscountWc
{
    public ?\WC_Cart $cart = null;
}
