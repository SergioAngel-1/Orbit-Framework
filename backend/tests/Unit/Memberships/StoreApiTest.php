<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TestMembershipsServiceWpdb;

require_once HWE_BACKEND_DIR . '/tests/Stubs/wp-memberships-service-wpdb.php';
require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-cart.php';
require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-store-api.php';
require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-order.php';

/**
 * Plugin `hwe-memberships`, extensión de la Store API y bloqueo de compra (spec §6.3 y §10.4).
 *
 * Se prueba la DECISIÓN: una línea cuyo requisito de acceso no cumple el usuario se rechaza al
 * añadirla y otra vez al crear el pedido, con un motivo estable y sus datos estructurados; una
 * línea que ya estaba en el carrito y pasa a bloqueada (caducidad, congelación o retirada) se
 * lista en `blocked_items` y no permite crear el pedido (Foco de revisión 3). También el contrato
 * de `extensions.hwe-memberships` (nivel efectivo, descuento de miembro, envío gratis, avisos de
 * compra), el callback que solo acepta `{ free_delivery: bool }`, el punto de extensión interna
 * para las tareas 12 y 13, y que con el módulo apagado no se registra nada.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StoreApiTest extends TestCase
{
    private const USER = 7;

    private const LEVEL_LOW  = 101; // rango 1
    private const LEVEL_HIGH = 102; // rango 5
    private const LEVEL_MID  = 103; // rango 3 (nivel del requisito)

    private const PRODUCT_RESTRICTED = 201; // exige rango 3
    private const PRODUCT_EARLY      = 202; // exige rango 3 y abre en el futuro
    private const PRODUCT_PUBLIC     = 203;
    private const PRODUCT_VARIATION  = 401; // variación del producto 201
    private const MEMBERSHIP_LOW     = 301; // nivel 101, 30 días
    private const MEMBERSHIP_HIGH    = 302; // nivel 102, 30 días

    private int $now = 1_800_000_000;
    /** @var list<string> */
    private array $activePlugins = ['hwe-memberships/hwe-memberships.php'];
    /** @var array<int,\WP_Post> */
    private array $levelPosts = [];
    /** @var array<int,array<string,mixed>> */
    private array $postMeta = [];
    /** @var array<int,array<string,mixed>> */
    private array $termMeta = [];
    /** @var array<int,int> */
    private array $parents = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var array<string,mixed> Argumentos con los que se registró cada endpoint. */
    private array $registered = [];
    /** @var callable|null */
    private $cartFilter = null;
    private int $currentUser = self::USER;
    private TestMembershipsServiceWpdb $wpdb;
    private TestStoreApiWc $wc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now           = 1_800_000_000;
        $this->activePlugins = ['hwe-memberships/hwe-memberships.php'];
        $this->levelPosts    = [];
        $this->postMeta      = [];
        $this->termMeta      = [];
        $this->parents       = [];
        $this->logged        = [];
        $this->registered    = [];
        $this->cartFilter    = null;
        $this->currentUser   = self::USER;

        $this->wpdb = new TestMembershipsServiceWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->loadOwnFile('plugins/hwe-memberships/includes/Schema.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Settings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Levels.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Timeline.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Events.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Members.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Grants.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Products.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Access.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/StoreApi.php');
        \HWE_Memberships_Events::clear();
        \HWE_Memberships_Access::flush();

        $this->makeLevel(self::LEVEL_LOW, 1);
        $this->makeLevel(self::LEVEL_HIGH, 5);
        $this->makeLevel(self::LEVEL_MID, 3);

        $this->seedMembershipProduct(self::MEMBERSHIP_LOW, self::LEVEL_LOW, 30);
        $this->seedMembershipProduct(self::MEMBERSHIP_HIGH, self::LEVEL_HIGH, 30);
        $this->restrictProduct(self::PRODUCT_RESTRICTED, 3);
        $this->restrictProduct(
            self::PRODUCT_EARLY,
            3,
            gmdate('Y-m-d H:i:s', $this->now + 7 * DAY_IN_SECONDS)
        );
        $this->parents[self::PRODUCT_VARIATION] = self::PRODUCT_RESTRICTED;

        $this->wc = new TestStoreApiWc();

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => 'active_plugins' === $name
                ? $this->activePlugins
                : $default
        );
        Functions\when('get_post')->alias(fn (int $id) => $this->levelPosts[$id] ?? null);
        Functions\when('get_posts')->alias(function (array $args): array {
            if ((\HWE_Memberships_Levels::POST_TYPE) !== ($args['post_type'] ?? '')) {
                return [];
            }

            $statuses = (array) ($args['post_status'] ?? ['publish']);
            return array_values(array_filter(
                $this->levelPosts,
                static fn (\WP_Post $post): bool => in_array((string) $post->post_status, $statuses, true)
            ));
        });
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->postMeta[$id][$key] ?? ''
        );
        Functions\when('get_term_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->termMeta[$id][$key] ?? ''
        );
        Functions\when('wp_get_post_terms')->alias(static fn (): array => []);
        Functions\when('get_ancestors')->alias(static fn (): array => []);
        Functions\when('wp_get_post_parent_id')->alias(
            fn (int $postId): int => $this->parents[$postId] ?? 0
        );
        Functions\when('get_current_user_id')->alias(fn (): int => $this->currentUser);
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('did_action')->alias(
            static fn (string $hook): int => 'woocommerce_blocks_loaded' === $hook ? 1 : 0
        );
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed ...$args): mixed {
            if (\HWE_Memberships_StoreApi::CART_DATA_FILTER === $hook && null !== $this->cartFilter) {
                return ($this->cartFilter)($value, $args[0] ?? []);
            }

            return $value;
        });
        Functions\when('WC')->alias(fn (): TestStoreApiWc => $this->wc);
        Functions\when('woocommerce_store_api_register_endpoint_data')->alias(
            function (array $args): void {
                $this->registered[] = 'data:' . (string) ($args['namespace'] ?? '');
            }
        );
        Functions\when('woocommerce_store_api_register_update_callback')->alias(
            function (array $args): void {
                $this->registered[] = 'update:' . (string) ($args['namespace'] ?? '');
            }
        );
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => is_string($value) ? trim($value) : '',
            'wp_unslash'          => static fn ($value) => $value,
            '__'                  => static fn ($value) => (string) $value,
            'esc_html__'          => static fn ($value) => (string) $value,
            'esc_html'            => static fn ($value) => (string) $value,
        ]);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    // ── Utilidades del caso ─────────────────────────────────────────────────

    private function makeLevel(int $id, int $rank, string $status = 'publish'): void
    {
        $post               = new \WP_Post();
        $post->ID           = $id;
        $post->post_type    = \HWE_Memberships_Levels::POST_TYPE;
        $post->post_status  = $status;
        $post->post_title   = 'Nivel ' . $id;
        $post->post_name    = 'nivel-' . $id;
        $post->post_content = '';
        $this->levelPosts[$id] = $post;
        $this->postMeta[$id]   = [
            \HWE_Memberships_Levels::RANK_META        => (string) $rank,
            \HWE_Memberships_Levels::PURCHASABLE_META => '1',
            \HWE_Memberships_Levels::MIN_AGE_META     => '0',
        ];
    }

    private function seedMembershipProduct(int $productId, int $levelId, int $days): void
    {
        $this->postMeta[$productId] = [
            \HWE_Memberships_Products::LEVEL_META => (string) $levelId,
            \HWE_Memberships_Products::DAYS_META  => (string) $days,
        ];
    }

    private function restrictProduct(int $productId, int $minRank, ?string $opensAt = null): void
    {
        $this->postMeta[$productId][\HWE_Memberships_Access::MIN_RANK_META] = (string) $minRank;
        if (null !== $opensAt) {
            $this->postMeta[$productId][\HWE_Memberships_Access::OPENS_AT_META] = $opensAt;
        }
    }

    /** Concesión con fechas coherentes: activa desde ayer o programada desde mañana. */
    private function seedGrant(array $overrides = []): int
    {
        $status = (string) ($overrides['status'] ?? 'active');
        $start  = 'scheduled' === $status
            ? $this->now + DAY_IN_SECONDS
            : $this->now - DAY_IN_SECONDS;

        return $this->wpdb->seedGrant(array_merge(
            [
                'user_id'   => self::USER,
                'level_id'  => self::LEVEL_LOW,
                'status'    => $status,
                'starts_at' => gmdate('Y-m-d H:i:s', $start),
            ],
            $overrides
        ));
    }

    /** @param array<string,array<string,mixed>> $lines */
    private function makeCart(array $lines): \WC_Cart
    {
        $this->wc->cart = new \WC_Cart($lines);

        return $this->wc->cart;
    }

    private function restrictedLine(string $key = 'line-a'): array
    {
        return [$key => ['product_id' => self::PRODUCT_RESTRICTED, 'variation_id' => 0, 'quantity' => 1]];
    }

    private function makeOrder(int $customerId, int ...$productIds): \WC_Order
    {
        $order              = new \WC_Order();
        $order->id          = 900;
        $order->customer_id = $customerId;
        $order->line_items  = [];

        foreach ($productIds as $index => $productId) {
            $order->line_items[] = new \WC_Order_Item_Product(10 + $index, 0.0, 0.0, $productId, 1);
        }

        return $order;
    }

    private function assertRouteError(string $code, int $status, callable $call): RouteException
    {
        try {
            $call();
        } catch (RouteException $exception) {
            $this->assertSame($code, $exception->getErrorCode());
            $this->assertSame($status, $exception->getHTTPStatusCode());

            return $exception;
        }

        $this->fail('Se esperaba un RouteException con el código «' . $code . '».');
    }

    private function session(): TestStoreApiSession
    {
        return $this->wc->session;
    }

    // ── Registro y contrato de la extensión ─────────────────────────────────

    public function testRegisterHooksTheValidatorsAndTheCartExtension(): void
    {
        Actions\expectAdded('woocommerce_store_api_validate_add_to_cart')
            ->once()
            ->with([\HWE_Memberships_StoreApi::class, 'validateAddToCart'], 10, 2);
        Actions\expectAdded('woocommerce_store_api_cart_errors')
            ->once()
            ->with([\HWE_Memberships_StoreApi::class, 'validateCart'], 10, 2);
        Actions\expectAdded('woocommerce_store_api_checkout_update_order_from_request')
            ->once()
            ->with([\HWE_Memberships_StoreApi::class, 'validateOrderRequest'], 10, 2);

        \HWE_Memberships_StoreApi::register();

        $this->assertSame(
            ['data:hwe-memberships', 'update:hwe-memberships'],
            $this->registered
        );
    }

    public function testRegistrationWaitsForBlocksLoadedWhenItHasNotFiredYet(): void
    {
        Functions\when('did_action')->alias(static fn (string $hook): int => 0);
        Actions\expectAdded('woocommerce_blocks_loaded')
            ->once()
            ->with([\HWE_Memberships_StoreApi::class, 'registerStoreApi']);

        \HWE_Memberships_StoreApi::register();

        $this->assertSame([], $this->registered);
    }

    public function testWithTheModuleOffNothingIsRegisteredAndNothingIsBlocked(): void
    {
        $this->activePlugins = [];

        Actions\expectAdded('woocommerce_store_api_validate_add_to_cart')->never();
        Actions\expectAdded('woocommerce_store_api_cart_errors')->never();
        Actions\expectAdded('woocommerce_store_api_checkout_update_order_from_request')->never();

        \HWE_Memberships_StoreApi::register();

        $this->assertSame([], $this->registered);

        \HWE_Memberships_StoreApi::validateAddToCart(
            new \WC_Cart([]),
            new \WP_REST_Request(['id' => self::PRODUCT_RESTRICTED, 'quantity' => 1])
        );
        \HWE_Memberships_StoreApi::validateOrderRequest(
            $this->makeOrder(self::USER, self::PRODUCT_RESTRICTED),
            new \WP_REST_Request([])
        );
        $errors = new \WP_Error();
        \HWE_Memberships_StoreApi::validateCart($errors, $this->makeCart($this->restrictedLine()));
        $this->assertSame([], $errors->get_error_codes());
        $this->assertSame([], $this->wpdb->writeQueries());
    }

    public function testExtensionSchemaDescribesTheStableContract(): void
    {
        $schema = \HWE_Memberships_StoreApi::cartStateSchema();

        foreach (['level', 'member_discount', 'free_delivery', 'blocked_items', 'purchase_notice'] as $key) {
            $this->assertArrayHasKey($key, $schema);
            $this->assertTrue($schema[$key]['readonly']);
            $this->assertSame(['view', 'edit'], $schema[$key]['context']);
        }

        $this->assertSame(
            ['membership_required', 'membership_early_access'],
            $schema['blocked_items']['items']['properties']['reason']['enum']
        );
        $this->assertSame(
            ['renewal', 'upgrade', 'scheduled', null],
            $schema['purchase_notice']['enum']
        );
    }

    // ── Bloqueo al añadir ───────────────────────────────────────────────────

    public function testAddingALineThatNeedsAMembershipFailsWithTheStableReason(): void
    {
        $this->seedGrant([
            'user_id'  => self::USER,
            'level_id' => self::LEVEL_LOW,
            'status'   => 'active',
            'ends_at'  => null,
        ]);

        $exception = $this->assertRouteError('hwe_membership_required', 409, function (): void {
            \HWE_Memberships_StoreApi::validateAddToCart(
                new \WC_Cart([]),
                new \WP_REST_Request(['id' => self::PRODUCT_RESTRICTED, 'quantity' => 1])
            );
        });

        $data = $exception->getAdditionalData();
        $this->assertSame('membership_required', $data['reason']);
        $this->assertSame(3, $data['min_rank']);
        $this->assertSame(self::LEVEL_MID, $data['level_id']);
        $this->assertSame('nivel-103', $data['level_slug']);
        $this->assertSame('Nivel 103', $data['level_name']);
        $this->assertNull($data['opens_at']);
    }

    public function testAddingALineThatIsNotPublicYetFailsWithTheEarlyAccessReason(): void
    {
        $exception = $this->assertRouteError('hwe_membership_early_access', 409, function (): void {
            \HWE_Memberships_StoreApi::validateAddToCart(
                new \WC_Cart([]),
                new \WP_REST_Request(['id' => self::PRODUCT_EARLY, 'quantity' => 1])
            );
        });

        $this->assertSame('membership_early_access', $exception->getAdditionalData()['reason']);
        $this->assertNotNull($exception->getAdditionalData()['opens_at']);
    }

    public function testAVariationUsesTheRequirementOfItsParentProduct(): void
    {
        $exception = $this->assertRouteError('hwe_membership_required', 409, function (): void {
            \HWE_Memberships_StoreApi::validateAddToCart(
                new \WC_Cart([]),
                new \WP_REST_Request(['id' => self::PRODUCT_VARIATION, 'variation_id' => self::PRODUCT_VARIATION, 'quantity' => 1])
            );
        });

        $this->assertSame(self::PRODUCT_VARIATION, $exception->getAdditionalData()['product_id']);
        $this->assertSame(3, $exception->getAdditionalData()['min_rank']);
    }

    public function testAMemberWithEnoughRankCanAddTheProduct(): void
    {
        $this->seedGrant([
            'user_id'  => self::USER,
            'level_id' => self::LEVEL_MID,
            'status'   => 'active',
            'ends_at'  => null,
        ]);

        \HWE_Memberships_StoreApi::validateAddToCart(
            new \WC_Cart([]),
            new \WP_REST_Request(['id' => self::PRODUCT_RESTRICTED, 'quantity' => 1])
        );

        $this->assertSame([], $this->logged);
    }

    // ── Línea ya en el carrito (Foco 3) ─────────────────────────────────────

    public function testALineBlockedByAWithdrawnLevelAppearsInBlockedItemsAndStopsTheOrder(): void
    {
        $this->seedGrant([
            'status'   => 'revoked',
            'ends_at'  => gmdate('Y-m-d H:i:s', $this->now - DAY_IN_SECONDS),
            'revoked_at' => gmdate('Y-m-d H:i:s', $this->now - DAY_IN_SECONDS),
        ]);
        $cart = $this->makeCart($this->restrictedLine());
        \HWE_Memberships_Access::flush();

        $state = \HWE_Memberships_StoreApi::cartState();

        $this->assertCount(1, $state['blocked_items']);
        $item = $state['blocked_items'][0];
        $this->assertSame('line-a', $item['key']);
        $this->assertSame(self::PRODUCT_RESTRICTED, $item['product_id']);
        $this->assertSame('membership_required', $item['reason']);
        $this->assertSame(3, $item['min_rank']);
        $this->assertSame('nivel-103', $item['level_slug']);
        $this->assertNull($item['opens_at']);

        $order = $this->makeOrder(self::USER, self::PRODUCT_RESTRICTED);
        $this->assertRouteError('hwe_membership_required', 409, function () use ($order): void {
            \HWE_Memberships_StoreApi::validateOrderRequest($order, new \WP_REST_Request([]));
        });
    }

    public function testTheCartStateReadsTheMemberOnceForAllLines(): void
    {
        $this->seedGrant();

        $lines = [];
        for ($i = 1; $i <= 5; $i++) {
            $lines['line-' . $i] = [
                'product_id'   => self::PRODUCT_RESTRICTED,
                'variation_id' => 0,
                'quantity'     => 1,
            ];
        }
        $this->makeCart($lines);
        \HWE_Memberships_Access::flush();

        $this->wpdb->queries = [];
        $state = \HWE_Memberships_StoreApi::cartState();

        $reads = array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_contains($sql, 'hwe_memberships_members')
                || str_contains($sql, 'hwe_memberships_grants')
        ));

        $this->assertCount(5, $state['blocked_items']);
        $this->assertLessThanOrEqual(
            2,
            count($reads),
            'El estado del socio se lee una vez por petición, no una por línea: ' . count($reads) . ' consultas.'
        );
    }

    public function testALineBlockedByAnExpiredLevelAppearsInBlockedItemsAndStopsTheOrder(): void
    {
        $this->seedGrant([
            'user_id'  => self::USER,
            'level_id' => self::LEVEL_HIGH,
            'status'   => 'active',
            'ends_at'  => gmdate('Y-m-d H:i:s', $this->now - DAY_IN_SECONDS),
        ]);
        $this->makeCart($this->restrictedLine());
        \HWE_Memberships_Access::flush();

        $state = \HWE_Memberships_StoreApi::cartState();

        $this->assertSame('membership_required', $state['blocked_items'][0]['reason'] ?? null);
        $this->assertSame([], $this->wpdb->writeQueries(), 'Una lectura nunca escribe en la base de datos.');

        $order = $this->makeOrder(self::USER, self::PRODUCT_RESTRICTED);
        $this->assertRouteError('hwe_membership_required', 409, function () use ($order): void {
            \HWE_Memberships_StoreApi::validateOrderRequest($order, new \WP_REST_Request([]));
        });
    }

    public function testALineBlockedByAFrozenAccountAppearsInBlockedItemsAndHasNoLevel(): void
    {
        $this->wpdb->seedMember(self::USER, [
            'frozen_at' => gmdate('Y-m-d H:i:s', $this->now - 5 * DAY_IN_SECONDS),
        ]);
        $this->seedGrant([
            'user_id'  => self::USER,
            'level_id' => self::LEVEL_HIGH,
            'status'   => 'active',
            'ends_at'  => null,
        ]);
        $this->makeCart($this->restrictedLine());
        \HWE_Memberships_Access::flush();

        $state = \HWE_Memberships_StoreApi::cartState();

        $this->assertNull($state['level']);
        $this->assertSame('membership_required', $state['blocked_items'][0]['reason'] ?? null);

        $order = $this->makeOrder(self::USER, self::PRODUCT_RESTRICTED);
        $this->assertRouteError('hwe_membership_required', 409, function () use ($order): void {
            \HWE_Memberships_StoreApi::validateOrderRequest($order, new \WP_REST_Request([]));
        });
    }

    public function testCartErrorsReportTheBlockedLineWithItsStructuredData(): void
    {
        $cart   = $this->makeCart($this->restrictedLine());
        $errors = new \WP_Error();

        \HWE_Memberships_StoreApi::validateCart($errors, $cart);

        $this->assertContains('hwe_membership_required', $errors->get_error_codes());
        $data = $errors->get_error_data('hwe_membership_required');
        $this->assertSame(409, $data['status']);
        $this->assertSame('membership_required', $data['reason']);
        $this->assertSame(3, $data['min_rank']);
    }

    public function testACartWithAnEarlyAccessLineBlocksTheOrderWithTheEarlyAccessCode(): void
    {
        $cart = $this->makeCart([
            'line-a' => ['product_id' => self::PRODUCT_EARLY, 'variation_id' => 0, 'quantity' => 1],
        ]);

        $state = \HWE_Memberships_StoreApi::cartState();
        $this->assertSame('membership_early_access', $state['blocked_items'][0]['reason'] ?? null);

        $order = $this->makeOrder(self::USER, self::PRODUCT_EARLY);
        $exception = $this->assertRouteError('hwe_membership_early_access', 409, function () use ($order): void {
            \HWE_Memberships_StoreApi::validateOrderRequest($order, new \WP_REST_Request([]));
        });
        $this->assertNotNull($exception->getAdditionalData()['opens_at']);
    }

    public function testAnOrderWithOnlyAllowedLinesIsNotBlocked(): void
    {
        $this->seedGrant([
            'user_id'  => self::USER,
            'level_id' => self::LEVEL_MID,
            'status'   => 'active',
            'ends_at'  => null,
        ]);
        $this->makeCart([
            'line-a' => ['product_id' => self::PRODUCT_RESTRICTED, 'variation_id' => 0, 'quantity' => 1],
        ]);
        \HWE_Memberships_Access::flush();

        $state = \HWE_Memberships_StoreApi::cartState();

        $this->assertSame([], $state['blocked_items']);
        \HWE_Memberships_StoreApi::validateOrderRequest(
            $this->makeOrder(self::USER, self::PRODUCT_RESTRICTED),
            new \WP_REST_Request([])
        );
    }

    // ── Contrato de la extensión ────────────────────────────────────────────

    public function testTheExtensionExposesTheEffectiveLevelAndTheEmptyDiscountStructure(): void
    {
        $this->seedGrant([
            'user_id'  => self::USER,
            'level_id' => self::LEVEL_LOW,
            'status'   => 'active',
            'ends_at'  => null,
        ]);
        $this->makeCart([
            'line-a' => ['product_id' => self::PRODUCT_PUBLIC, 'variation_id' => 0, 'quantity' => 1],
        ]);

        $state = \HWE_Memberships_StoreApi::cartState();

        $this->assertSame(
            ['id' => self::LEVEL_LOW, 'slug' => 'nivel-101', 'rank' => 1, 'name' => 'Nivel 101'],
            $state['level']
        );
        $this->assertSame('0', $state['member_discount']['total']);
        $this->assertSame([], (array) $state['member_discount']['lines']);
        $this->assertSame(
            [
                'available' => false,
                'remaining' => 0,
                'requested' => false,
                'applied'   => false,
                'reason'    => null,
            ],
            $state['free_delivery']
        );
        $this->assertSame([], $state['blocked_items']);
        $this->assertNull($state['purchase_notice']);
    }

    public function testAGuestHasNoLevelAndAPublicLineIsStillBuyable(): void
    {
        $this->currentUser = 0;
        $this->makeCart([
            'line-a' => ['product_id' => self::PRODUCT_PUBLIC, 'variation_id' => 0, 'quantity' => 1],
        ]);

        $state = \HWE_Memberships_StoreApi::cartState();

        $this->assertNull($state['level']);
        $this->assertSame([], $state['blocked_items']);
    }

    public function testTheInternalFilterCanFillTheDiscountAndFreeDeliveryButNotTheSecurityFields(): void
    {
        $this->makeCart($this->restrictedLine());
        $this->cartFilter = static function (array $data): array {
            $data['member_discount'] = [
                'total' => '1250',
                'lines' => ['line-a' => '1250'],
            ];
            $data['free_delivery'] = [
                'available' => true,
                'remaining' => 2,
                'requested' => true,
                'applied'   => true,
                'reason'    => null,
            ];
            $data['blocked_items']  = [];
            $data['purchase_notice'] = 'upgrade';

            return $data;
        };

        $state = \HWE_Memberships_StoreApi::cartState();

        $this->assertSame('1250', $state['member_discount']['total']);
        $this->assertSame(['line-a' => '1250'], (array) $state['member_discount']['lines']);
        $this->assertTrue($state['free_delivery']['available']);
        $this->assertSame(2, $state['free_delivery']['remaining']);
        $this->assertTrue($state['free_delivery']['applied']);
        $this->assertFalse(
            $state['free_delivery']['requested'],
            'El filtro no puede declarar una petición que el cliente no hizo.'
        );
        $this->assertCount(1, $state['blocked_items'], 'El filtro no puede retirar las líneas bloqueadas.');
        $this->assertNull($state['purchase_notice'], 'El filtro no puede inventar un aviso de compra.');
    }

    public function testTheUpdateCallbackOnlyStoresTheFreeDeliveryRequest(): void
    {
        \HWE_Memberships_StoreApi::updateState([
            'free_delivery' => true,
            'level'         => ['id' => self::LEVEL_HIGH],
        ]);

        $this->assertTrue($this->session()->get(\HWE_Memberships_StoreApi::SESSION_FREE_DELIVERY));
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('solo acepta free_delivery', $this->logged[0]);

        \HWE_Memberships_StoreApi::updateState(['free_delivery' => 'sí']);

        $this->assertTrue(
            $this->session()->get(\HWE_Memberships_StoreApi::SESSION_FREE_DELIVERY),
            'Un valor que no es booleano se ignora y conserva el anterior.'
        );

        \HWE_Memberships_StoreApi::updateState(['free_delivery' => false]);
        $this->assertFalse($this->session()->get(\HWE_Memberships_StoreApi::SESSION_FREE_DELIVERY));
    }

    public function testTheExtensionReportsTheRequestedFreeDelivery(): void
    {
        $this->session()->set(\HWE_Memberships_StoreApi::SESSION_FREE_DELIVERY, true);
        $this->makeCart([
            'line-a' => ['product_id' => self::PRODUCT_PUBLIC, 'variation_id' => 0, 'quantity' => 1],
        ]);

        $state = \HWE_Memberships_StoreApi::cartState();

        $this->assertTrue($state['free_delivery']['requested']);
    }

    // ── Avisos de compra ────────────────────────────────────────────────────

    public function testBuyingTheSameLevelIsAnnouncedAsARenewal(): void
    {
        $this->seedGrant([
            'user_id'  => self::USER,
            'level_id' => self::LEVEL_LOW,
            'status'   => 'active',
            'ends_at'  => gmdate('Y-m-d H:i:s', $this->now + 30 * DAY_IN_SECONDS),
        ]);
        $this->makeCart([
            'line-a' => ['product_id' => self::MEMBERSHIP_LOW, 'variation_id' => 0, 'quantity' => 1],
        ]);

        $this->assertSame('renewal', \HWE_Memberships_StoreApi::cartState()['purchase_notice']);
    }

    public function testBuyingAHigherLevelIsAnnouncedAsAnUpgrade(): void
    {
        $this->seedGrant([
            'user_id'  => self::USER,
            'level_id' => self::LEVEL_LOW,
            'status'   => 'active',
            'ends_at'  => gmdate('Y-m-d H:i:s', $this->now + 30 * DAY_IN_SECONDS),
        ]);
        $this->makeCart([
            'line-a' => ['product_id' => self::MEMBERSHIP_HIGH, 'variation_id' => 0, 'quantity' => 1],
        ]);

        $this->assertSame('upgrade', \HWE_Memberships_StoreApi::cartState()['purchase_notice']);
    }

    public function testBuyingALowerLevelIsAnnouncedAsScheduled(): void
    {
        $this->seedGrant([
            'user_id'  => self::USER,
            'level_id' => self::LEVEL_HIGH,
            'status'   => 'active',
            'ends_at'  => gmdate('Y-m-d H:i:s', $this->now + 30 * DAY_IN_SECONDS),
        ]);
        $this->makeCart([
            'line-a' => ['product_id' => self::MEMBERSHIP_LOW, 'variation_id' => 0, 'quantity' => 1],
        ]);

        $this->assertSame('scheduled', \HWE_Memberships_StoreApi::cartState()['purchase_notice']);
    }

    public function testBuyingALevelThatTheQueueAlreadyCoversIsAnnouncedAsScheduled(): void
    {
        $this->seedGrant([
            'user_id'  => self::USER,
            'level_id' => self::LEVEL_LOW,
            'status'   => 'active',
            'ends_at'  => gmdate('Y-m-d H:i:s', $this->now + 10 * DAY_IN_SECONDS),
        ]);
        $this->seedGrant([
            'user_id'  => self::USER,
            'level_id' => self::LEVEL_HIGH,
            'status'   => 'scheduled',
            'starts_at' => gmdate('Y-m-d H:i:s', $this->now + 20 * DAY_IN_SECONDS),
            'ends_at'  => gmdate('Y-m-d H:i:s', $this->now + 50 * DAY_IN_SECONDS),
        ]);
        $this->makeCart([
            'line-a' => ['product_id' => self::MEMBERSHIP_HIGH, 'variation_id' => 0, 'quantity' => 1],
        ]);

        $this->assertSame('scheduled', \HWE_Memberships_StoreApi::cartState()['purchase_notice']);
    }

    public function testAMemberWithoutCoverageHasNoPurchaseNotice(): void
    {
        $this->makeCart([
            'line-a' => ['product_id' => self::MEMBERSHIP_HIGH, 'variation_id' => 0, 'quantity' => 1],
        ]);

        $this->assertNull(\HWE_Memberships_StoreApi::cartState()['purchase_notice']);
    }
}

/** Doble mínimo de `WC()`: carrito y sesión en memoria. */
final class TestStoreApiWc
{
    public ?\WC_Cart $cart = null;
    public TestStoreApiSession $session;

    public function __construct()
    {
        $this->session = new TestStoreApiSession();
    }
}

/** Sesión de WooCommerce en memoria. */
final class TestStoreApiSession
{
    /** @var array<string,mixed> */
    public array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
}
