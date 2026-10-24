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
require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-order.php';
require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-cart.php';

/**
 * Plugin `hwe-memberships`, parte de compra y activación al cobrar (spec §5.3).
 *
 * Se prueba la DECISIÓN, no el SQL: cada regla de compra con su motivo estable y sus datos
 * (`days_left`), la validación de la Store API al añadir y al crear el pedido, y el ciclo de
 * activación/reversión con la idempotencia por línea como punto crítico (Foco de revisión 1):
 * `processing` → `completed` → `payment_complete`, un webhook repetido o una ida y vuelta de
 * estado dejan **una sola** concesión. El doble de `$wpdb` es el mismo de las tareas 5.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PurchaseTest extends TestCase
{
    private const BUYER = 7;
    private const NEW_ACCOUNT = 8;

    private const LEVEL = 101;        // rango 5, comprable, sin antigüedad mínima
    private const LEVEL_TOP = 102;    // rango 10, comprable
    private const LEVEL_LOW = 103;    // rango 3, NO comprable
    private const LEVEL_AGE = 104;    // rango 20, comprable, 30 días de antigüedad
    private const LEVEL_BOUNDARY = 105; // rango 7, comprable, 10 días de antigüedad
    private const LEVEL_LOWER = 106;  // rango 3, comprable (para el nivel ya cubierto)

    private const PRODUCT = 201;       // nivel 101, 30 días
    private const PRODUCT_FOREVER = 202; // nivel 102, indefinida
    private const PRODUCT_NORMAL = 203;
    private const PRODUCT_OFF = 204;   // nivel 103, no comprable
    private const PRODUCT_BOUNDARY = 205; // nivel 105, 10 días
    private const PRODUCT_AGE = 206;   // nivel 104, 30 días de antigüedad mínima

    private int $now = 1_800_000_000;
    /** @var array<int,\WP_Post> */
    private array $levelPosts = [];
    /** @var array<int,array<string,mixed>> */
    private array $postMeta = [];
    /** @var array<int,object> */
    private array $users = [];
    /** @var array<int,\WC_Order> */
    private array $orders = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var list<array{0:string,1:string}> */
    private array $notices = [];
    private bool $pending = false;
    /** @var list<string> */
    private array $deferred = ['cod'];
    private int $currentUser = self::BUYER;
    private TestMembershipsServiceWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now         = 1_800_000_000;
        $this->levelPosts  = [];
        $this->postMeta    = [];
        $this->users       = [];
        $this->orders      = [];
        $this->logged      = [];
        $this->notices     = [];
        $this->pending     = false;
        $this->deferred    = ['cod'];
        $this->currentUser = self::BUYER;

        $this->wpdb = new TestMembershipsServiceWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->loadOwnFile('plugins/hwe-memberships/includes/Schema.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Levels.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Timeline.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Events.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Members.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Grants.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Products.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Purchase.php');
        \HWE_Memberships_Events::clear();

        $this->makeLevel(self::LEVEL, 5, true);
        $this->makeLevel(self::LEVEL_TOP, 10, true);
        $this->makeLevel(self::LEVEL_LOW, 3, false);
        $this->makeLevel(self::LEVEL_AGE, 20, true, 30);
        $this->makeLevel(self::LEVEL_BOUNDARY, 7, true, 10);
        $this->makeLevel(self::LEVEL_LOWER, 3, true);

        $this->seedProduct(self::PRODUCT, self::LEVEL, 30);
        $this->seedProduct(self::PRODUCT_FOREVER, self::LEVEL_TOP, 0);
        $this->seedProduct(self::PRODUCT_OFF, self::LEVEL_LOW, 30);
        $this->seedProduct(self::PRODUCT_BOUNDARY, self::LEVEL_BOUNDARY, 10);
        $this->seedProduct(self::PRODUCT_AGE, self::LEVEL_AGE, 30);

        $this->users[self::BUYER] = (object) [
            'ID'              => self::BUYER,
            'user_registered' => gmdate('Y-m-d H:i:s', $this->now - 100 * DAY_IN_SECONDS),
        ];
        $this->users[self::NEW_ACCOUNT] = (object) [
            'ID'              => self::NEW_ACCOUNT,
            'user_registered' => gmdate('Y-m-d H:i:s', $this->now - 10 * DAY_IN_SECONDS),
        ];

        Functions\when('get_post')->alias(fn (int $id) => $this->levelPosts[$id] ?? null);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->postMeta[$id][$key] ?? ''
        );
        Functions\when('get_userdata')->alias(fn (int $id) => $this->users[$id] ?? false);
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('wc_get_order')->alias(fn (int $id) => $this->orders[$id] ?? false);
        Functions\when('get_current_user_id')->alias(fn (): int => $this->currentUser);
        Functions\when('wc_add_notice')->alias(function (string $message, string $type = 'success'): void {
            $this->notices[] = [$message, $type];
        });
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed ...$args): mixed {
            if ('hwe_account_is_pending' === $hook) {
                return $this->pending;
            }
            if ('hwe_memberships_collected_on_completion' === $hook) {
                return $this->deferred;
            }
            return $value;
        });
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => trim((string) $value),
            'wp_unslash'          => static fn ($value) => $value,
            '__'                  => static fn ($value) => $value,
            'esc_html'            => static fn ($value) => (string) $value,
            'esc_html__'          => static fn ($value) => $value,
        ]);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    // ── Utilidades del caso ─────────────────────────────────────────────────

    private function makeLevel(int $id, int $rank, bool $purchasable, int $minAge = 0, string $status = 'publish'): void
    {
        $post               = new \WP_Post();
        $post->ID           = $id;
        $post->post_type    = \HWE_Memberships_Levels::POST_TYPE;
        $post->post_status  = $status;
        $post->post_title   = 'Nivel ' . $id;
        $post->post_name    = 'nivel-' . $id;
        $post->post_content = 'Descripción ' . $id;
        $this->levelPosts[$id] = $post;
        $this->postMeta[$id]   = [
            \HWE_Memberships_Levels::RANK_META              => (string) $rank,
            \HWE_Memberships_Levels::PURCHASABLE_META       => $purchasable ? '1' : '0',
            \HWE_Memberships_Levels::MIN_AGE_META           => (string) $minAge,
        ];
    }

    private function seedProduct(int $productId, int $levelId, int $days): void
    {
        $this->postMeta[$productId] = [
            \HWE_Memberships_Products::LEVEL_META => (string) $levelId,
            \HWE_Memberships_Products::DAYS_META  => (string) $days,
        ];
    }

    private function makeItem(int $itemId, int $productId, int $quantity = 1): \WC_Order_Item_Product
    {
        return new \WC_Order_Item_Product($itemId, 0.0, 0.0, $productId, $quantity);
    }

    /** @param array<string,mixed> $overrides */
    private function makeOrder(int $id, int $customerId, array $overrides = []): \WC_Order
    {
        $order = new \WC_Order();
        $order->id             = $id;
        $order->customer_id    = $customerId;
        $order->status         = (string) ($overrides['status'] ?? 'processing');
        $order->paid           = (bool) ($overrides['paid'] ?? true);
        $order->payment_method = (string) ($overrides['payment_method'] ?? 'card');
        $order->line_items     = $overrides['items'] ?? [$this->makeItem(9, self::PRODUCT)];
        $order->total          = (float) ($overrides['total'] ?? 100.0);
        $order->total_refunded = (float) ($overrides['total_refunded'] ?? 0.0);
        $order->meta           = $overrides['meta'] ?? [];

        $this->orders[$id] = $order;

        return $order;
    }

    private function assertRouteError(string $code, callable $call): RouteException
    {
        try {
            $call();
        } catch (RouteException $exception) {
            $this->assertSame($code, $exception->getErrorCode());

            return $exception;
        }

        $this->fail('Se esperaba un RouteException con el código «' . $code . '».');
    }

    /** @return list<array<string,mixed>> */
    private function grantsOf(int $userId): array
    {
        return $this->wpdb->grantsOf($userId);
    }

    private function grantByKey(string $key): ?array
    {
        return $this->wpdb->grantByKey($key);
    }

    /** @return array<string,mixed> */
    private function orderState(\WC_Order $order): array
    {
        $state = $order->get_meta(\HWE_Memberships_Purchase::META_ORDER_STATE);

        return is_array($state) ? $state : [];
    }

    // ── Reglas de compra ────────────────────────────────────────────────────

    public function testCanPurchaseRejectsAGuest(): void
    {
        $result = \HWE_Memberships_Purchase::canPurchase(0, self::LEVEL);

        $this->assertFalse($result['ok']);
        $this->assertSame('membership_login_required', $result['reason']);
        $this->assertSame(self::LEVEL, $result['level_id']);
    }

    public function testCanPurchaseRejectsALevelThatIsNotForSale(): void
    {
        $off   = \HWE_Memberships_Purchase::canPurchase(self::BUYER, self::LEVEL_LOW);
        $ghost = \HWE_Memberships_Purchase::canPurchase(self::BUYER, 999);

        $this->assertFalse($off['ok']);
        $this->assertSame('membership_not_purchasable', $off['reason']);
        $this->assertFalse($ghost['ok']);
        $this->assertSame('membership_not_purchasable', $ghost['reason']);
    }

    public function testCanPurchaseRejectsAPendingAccount(): void
    {
        $this->pending = true;

        $result = \HWE_Memberships_Purchase::canPurchase(self::BUYER, self::LEVEL);

        $this->assertFalse($result['ok']);
        $this->assertSame('membership_account_pending', $result['reason']);
    }

    public function testCanPurchaseReportsTheDaysLeftForATooNewAccount(): void
    {
        $result = \HWE_Memberships_Purchase::canPurchase(self::NEW_ACCOUNT, self::LEVEL_AGE);

        $this->assertFalse($result['ok']);
        $this->assertSame('membership_account_too_new', $result['reason']);
        $this->assertSame(20, $result['days_left']);
        $this->assertSame(30, $result['min_account_age_days']);
        $this->assertSame(10, $result['account_age_days']);
    }

    public function testCanPurchaseAcceptsAnAccountThatMeetsTheMinimumAgeExactly(): void
    {
        $result = \HWE_Memberships_Purchase::canPurchase(self::NEW_ACCOUNT, self::LEVEL_BOUNDARY);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['reason']);
        $this->assertSame(0, $result['days_left']);
    }

    public function testCanPurchaseRejectsAnIndefiniteLevelAlreadyCovered(): void
    {
        $this->wpdb->seedGrant([
            'user_id'  => self::BUYER,
            'level_id' => self::LEVEL,
            'status'   => 'active',
            'ends_at'  => null,
        ]);

        $same = \HWE_Memberships_Purchase::canPurchase(self::BUYER, self::LEVEL);
        $lower = \HWE_Memberships_Purchase::canPurchase(self::BUYER, self::LEVEL_LOWER);
        $higher = \HWE_Memberships_Purchase::canPurchase(self::BUYER, self::LEVEL_TOP);

        $this->assertFalse($same['ok']);
        $this->assertSame('membership_already_indefinite', $same['reason']);
        $this->assertSame(self::LEVEL, $same['current_level_id']);
        $this->assertFalse($lower['ok']);
        $this->assertSame('membership_already_indefinite', $lower['reason'], 'Un nivel inferior también está cubierto.');
        $this->assertTrue($higher['ok'], 'Un nivel superior todavía se puede comprar.');
    }

    public function testAScheduledIndefiniteGrantAlsoBlocksButAFiniteOneDoesNot(): void
    {
        $this->wpdb->seedGrant([
            'user_id'  => self::BUYER,
            'level_id' => self::LEVEL,
            'status'   => 'active',
            'ends_at'  => gmdate('Y-m-d H:i:s', $this->now + 10 * DAY_IN_SECONDS),
        ]);

        $this->assertTrue(
            \HWE_Memberships_Purchase::canPurchase(self::BUYER, self::LEVEL_LOWER)['ok'],
            'Una concesión con fin no cubre el nivel para siempre.'
        );

        $this->wpdb->seedGrant([
            'user_id'  => self::BUYER,
            'level_id' => self::LEVEL,
            'status'   => 'scheduled',
            'starts_at' => gmdate('Y-m-d H:i:s', $this->now + 30 * DAY_IN_SECONDS),
            'ends_at'  => null,
        ]);

        $this->assertFalse(\HWE_Memberships_Purchase::canPurchase(self::BUYER, self::LEVEL_LOWER)['ok']);
    }

    public function testBuyingWhileFrozenIsAllowed(): void
    {
        $this->wpdb->seedMember(self::BUYER, ['frozen_at' => gmdate('Y-m-d H:i:s', $this->now - 5 * DAY_IN_SECONDS)]);
        $this->wpdb->seedGrant([
            'user_id'  => self::BUYER,
            'level_id' => self::LEVEL,
            'status'   => 'active',
            'ends_at'  => gmdate('Y-m-d H:i:s', $this->now + 50 * DAY_IN_SECONDS),
        ]);

        $result = \HWE_Memberships_Purchase::canPurchase(self::BUYER, self::LEVEL_TOP);

        $this->assertTrue($result['ok'], 'La congelación no impide comprar; la concesión se desplazará al descongelar.');
    }

    // ── Activación al cobrar ────────────────────────────────────────────────

    public function testProcessingCompletedAndPaymentCompleteGrantOncePerLine(): void
    {
        $order = $this->makeOrder(55, self::BUYER);
        $item  = $order->line_items[0];

        \HWE_Memberships_Purchase::onOrderProcessing(55);
        $order->status = 'completed';
        \HWE_Memberships_Purchase::onOrderCompleted(55);
        \HWE_Memberships_Purchase::onPaymentComplete(55);

        $grants = $this->grantsOf(self::BUYER);
        $this->assertCount(1, $grants, 'Un pedido notificado tres veces concede una sola vez.');
        $grant = $this->grantByKey('order:55:item:9');
        $this->assertNotNull($grant);
        $this->assertSame(self::LEVEL, (int) $grant['level_id']);
        $this->assertSame(30, (int) $grant['duration_days']);
        $this->assertSame('order', $grant['origin']);
        $this->assertSame('order', $grant['ref_type']);
        $this->assertSame('55', (string) $grant['ref_id']);
        $this->assertSame('active', $grant['status']);

        $this->assertSame((int) $grant['id'], (int) $item->get_meta(\HWE_Memberships_Purchase::META_LINE_GRANT));
        $this->assertSame(self::LEVEL, (int) $item->get_meta(\HWE_Memberships_Purchase::META_LINE_LEVEL));
        $this->assertSame(30, (int) $item->get_meta(\HWE_Memberships_Purchase::META_LINE_DAYS));

        $state = $this->orderState($order);
        $this->assertSame('processed', $state['status'] ?? null);
        $this->assertSame(self::LEVEL, (int) $this->wpdb->members[self::BUYER]['level_id']);
    }

    public function testCashOnDeliveryWaitsForCompleted(): void
    {
        $order = $this->makeOrder(56, self::BUYER, ['paid' => false, 'payment_method' => 'cod']);

        \HWE_Memberships_Purchase::processOrder(56);
        $this->assertSame([], $this->grantsOf(self::BUYER));
        $this->assertSame([], $this->orderState($order));

        $order->status = 'completed';
        $order->paid   = true;
        \HWE_Memberships_Purchase::processOrder(56);

        $this->assertCount(1, $this->grantsOf(self::BUYER));
        $this->assertSame('processed', $this->orderState($order)['status'] ?? null);
    }

    public function testAnUnpaidOrderIsNeverGranted(): void
    {
        $this->makeOrder(57, self::BUYER, ['paid' => false]);

        \HWE_Memberships_Purchase::processOrder(57);

        $this->assertSame([], $this->grantsOf(self::BUYER));
    }

    public function testAGuestOrderIsLoggedAndIgnored(): void
    {
        $order = $this->makeOrder(58, 0);

        \HWE_Memberships_Purchase::processOrder(58);

        $this->assertSame([], $this->wpdb->grants);
        $this->assertSame([], $this->orderState($order));
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
        $this->assertStringContainsString('invitado', $this->logged[0]);
    }

    public function testThePurchasedSnapshotWinsOverTheCurrentProductConfiguration(): void
    {
        $order = $this->makeOrder(59, self::BUYER);
        $item  = $order->line_items[0];
        $item->add_meta_data(\HWE_Memberships_Purchase::META_LINE_LEVEL, self::LEVEL, true);
        $item->add_meta_data(\HWE_Memberships_Purchase::META_LINE_DAYS, 15, true);

        \HWE_Memberships_Purchase::processOrder(59);

        $grant = $this->grantByKey('order:59:item:9');
        $this->assertNotNull($grant);
        $this->assertSame(15, (int) $grant['duration_days'], 'La línea guarda la duración comprada, no la actual del producto.');
        $this->assertSame(
            gmdate('Y-m-d H:i:s', $this->now + 15 * DAY_IN_SECONDS),
            substr((string) $grant['ends_at'], 0, 19)
        );
    }

    public function testAnOrderWithoutMembershipLinesIsLeftUntouched(): void
    {
        $order = $this->makeOrder(60, self::BUYER, ['items' => [$this->makeItem(9, self::PRODUCT_NORMAL)]]);

        \HWE_Memberships_Purchase::processOrder(60);

        $this->assertSame([], $this->wpdb->grants);
        $this->assertSame([], $this->orderState($order));
    }

    // ── Reversión ───────────────────────────────────────────────────────────

    public function testCancellingAnOrderRevokesItsGrantOnlyOnce(): void
    {
        $this->makeOrder(61, self::BUYER);
        \HWE_Memberships_Purchase::processOrder(61);
        $events = count($this->wpdb->event_rows);

        \HWE_Memberships_Purchase::onOrderCancelled(61);
        \HWE_Memberships_Purchase::onOrderCancelled(61);

        $grant = $this->grantByKey('order:61:item:9');
        $this->assertNotNull($grant);
        $this->assertSame('revoked', $grant['status']);
        $this->assertNull($this->wpdb->members[self::BUYER]['level_id']);
        $this->assertSame($events + 1, count($this->wpdb->event_rows), 'La revocación no se repite.');
        $this->assertSame('reversed', $this->orderState($this->orders[61])['status'] ?? null);
    }

    public function testATotalRefundReversesButAPartialRefundDoesNot(): void
    {
        $order = $this->makeOrder(62, self::BUYER, ['total' => 100.0]);
        \HWE_Memberships_Purchase::processOrder(62);

        $order->total_refunded = 30.0;
        \HWE_Memberships_Purchase::onOrderRefunded(62);
        $this->assertSame('active', $this->grantByKey('order:62:item:9')['status'], 'Un reembolso parcial no revierte.');

        $order->total_refunded = 100.0;
        \HWE_Memberships_Purchase::onOrderRefunded(62);
        $this->assertSame('revoked', $this->grantByKey('order:62:item:9')['status']);

        // Cambio manual a `refunded` sin filas de reembolso: se trata como total.
        $manual = $this->makeOrder(63, self::BUYER);
        \HWE_Memberships_Purchase::processOrder(63);
        $manual->status = 'refunded';
        \HWE_Memberships_Purchase::onOrderRefunded(63);
        $this->assertSame('revoked', $this->grantByKey('order:63:item:9')['status']);
    }

    public function testReturningToProcessingAfterACancellationDoesNotGrantAgain(): void
    {
        $order = $this->makeOrder(64, self::BUYER);
        \HWE_Memberships_Purchase::processOrder(64);
        \HWE_Memberships_Purchase::onOrderCancelled(64);

        $order->status = 'processing';
        $order->paid   = true;
        \HWE_Memberships_Purchase::onOrderProcessing(64);

        $this->assertCount(1, $this->grantsOf(self::BUYER), 'La ida y vuelta de estado no crea otra concesión.');
        $this->assertSame('revoked', $this->grantByKey('order:64:item:9')['status']);
        $this->assertNull($this->wpdb->members[self::BUYER]['level_id']);
    }

    public function testIsCollectedRequiresAPaidOrderAndWaitsForTheConfiguredMethods(): void
    {
        $card = $this->makeOrder(70, self::BUYER, ['payment_method' => 'card']);
        $this->assertTrue(\HWE_Memberships_Purchase::isCollected($card));

        $cod = $this->makeOrder(71, self::BUYER, ['payment_method' => 'cod']);
        $this->assertFalse(\HWE_Memberships_Purchase::isCollected($cod));
        $cod->status = 'completed';
        $this->assertTrue(\HWE_Memberships_Purchase::isCollected($cod));

        $unpaid = $this->makeOrder(72, self::BUYER, ['paid' => false]);
        $this->assertFalse(\HWE_Memberships_Purchase::isCollected($unpaid));

        $hold = $this->makeOrder(73, self::BUYER, ['status' => 'on-hold']);
        $this->assertFalse(\HWE_Memberships_Purchase::isCollected($hold));

        $this->deferred = ['cod', 'bacs'];
        $bacs = $this->makeOrder(74, self::BUYER, ['payment_method' => 'bacs']);
        $this->assertFalse(\HWE_Memberships_Purchase::isCollected($bacs));
        $bacs->status = 'completed';
        $this->assertTrue(\HWE_Memberships_Purchase::isCollected($bacs));
    }

    public function testRegisterHooksTheOrderStateEvents(): void
    {
        Actions\expectAdded('woocommerce_order_status_processing')
            ->once()
            ->with([\HWE_Memberships_Purchase::class, 'onOrderProcessing']);
        Actions\expectAdded('woocommerce_order_status_completed')
            ->once()
            ->with([\HWE_Memberships_Purchase::class, 'onOrderCompleted']);
        Actions\expectAdded('woocommerce_payment_complete')
            ->once()
            ->with([\HWE_Memberships_Purchase::class, 'onPaymentComplete']);
        Actions\expectAdded('woocommerce_order_status_cancelled')
            ->once()
            ->with([\HWE_Memberships_Purchase::class, 'onOrderCancelled'], 20);
        Actions\expectAdded('woocommerce_order_status_refunded')
            ->once()
            ->with([\HWE_Memberships_Purchase::class, 'onOrderRefunded'], 20);
        Actions\expectAdded('woocommerce_checkout_create_order_line_item')
            ->once()
            ->with([\HWE_Memberships_Purchase::class, 'snapshotLineItem'], 10, 4);
        Actions\expectAdded('woocommerce_store_api_validate_add_to_cart')
            ->once()
            ->with([\HWE_Memberships_Purchase::class, 'validateAddToCart'], 10, 2);
        Actions\expectAdded('woocommerce_store_api_cart_errors')
            ->once()
            ->with([\HWE_Memberships_Purchase::class, 'validateCart'], 10, 2);
        Actions\expectAdded('woocommerce_store_api_checkout_update_order_from_request')
            ->once()
            ->with([\HWE_Memberships_Purchase::class, 'validateOrderRequest'], 10, 2);

        \HWE_Memberships_Purchase::register();
    }

    // ── Validación de la Store API ──────────────────────────────────────────

    public function testAddingAMembershipProductRequiresALoggedInAccount(): void
    {
        $this->currentUser = 0;

        $this->assertRouteError('hwe_membership_login_required', function (): void {
            \HWE_Memberships_Purchase::validateAddToCart(
                new \WC_Cart([]),
                new \WP_REST_Request(['id' => self::PRODUCT, 'quantity' => 1])
            );
        });
    }

    public function testAddingASecondMembershipLineIsRejected(): void
    {
        $cart = new \WC_Cart(['a' => ['product_id' => self::PRODUCT, 'variation_id' => 0, 'quantity' => 1]]);

        $this->assertRouteError('hwe_membership_one_per_cart', function () use ($cart): void {
            \HWE_Memberships_Purchase::validateAddToCart(
                $cart,
                new \WP_REST_Request(['id' => self::PRODUCT_FOREVER, 'quantity' => 1])
            );
        });
    }

    public function testAMembershipLineWithQuantityTwoIsRejected(): void
    {
        $this->assertRouteError('hwe_membership_one_per_cart', function (): void {
            \HWE_Memberships_Purchase::validateAddToCart(
                new \WC_Cart([]),
                new \WP_REST_Request(['id' => self::PRODUCT, 'quantity' => 2])
            );
        });
    }

    public function testANonSaleableLevelCannotBeAdded(): void
    {
        $this->assertRouteError('hwe_membership_not_purchasable', function (): void {
            \HWE_Memberships_Purchase::validateAddToCart(
                new \WC_Cart([]),
                new \WP_REST_Request(['id' => self::PRODUCT_OFF, 'quantity' => 1])
            );
        });
    }

    public function testAValidMembershipCanBeAdded(): void
    {
        \HWE_Memberships_Purchase::validateAddToCart(
            new \WC_Cart([]),
            new \WP_REST_Request(['id' => self::PRODUCT, 'quantity' => 1])
        );

        $this->assertSame([], $this->notices);
    }

    public function testCheckoutCartErrorsReportTheBlockedLine(): void
    {
        $this->wpdb->seedGrant([
            'user_id'  => self::BUYER,
            'level_id' => self::LEVEL,
            'status'   => 'active',
            'ends_at'  => null,
        ]);
        $cart   = new \WC_Cart(['a' => ['product_id' => self::PRODUCT, 'variation_id' => 0, 'quantity' => 1]]);
        $errors = new \WP_Error();

        \HWE_Memberships_Purchase::validateCart($errors, $cart);

        $this->assertContains('hwe_membership_already_indefinite', $errors->get_error_codes());
        $this->assertNotSame('', (string) $errors->get_error_message());
    }

    public function testOrderCreationRevalidatesTheMembershipLine(): void
    {
        $order = $this->makeOrder(80, self::BUYER);
        $this->pending = true;

        $this->assertRouteError('hwe_membership_account_pending', function () use ($order): void {
            \HWE_Memberships_Purchase::validateOrderRequest($order, new \WP_REST_Request([]));
        });

        $this->pending = false;

        $twoLines = $this->makeOrder(81, self::BUYER, [
            'items' => [$this->makeItem(9, self::PRODUCT), $this->makeItem(10, self::PRODUCT_FOREVER)],
        ]);
        $this->assertRouteError('hwe_membership_one_per_cart', function () use ($twoLines): void {
            \HWE_Memberships_Purchase::validateOrderRequest($twoLines, new \WP_REST_Request([]));
        });

        $tooNew = $this->makeOrder(82, self::NEW_ACCOUNT, [
            'items' => [$this->makeItem(9, self::PRODUCT_AGE)],
        ]);
        $tooNewError = $this->assertRouteError('hwe_membership_account_too_new', function () use ($tooNew): void {
            \HWE_Memberships_Purchase::validateOrderRequest($tooNew, new \WP_REST_Request([]));
        });
        $this->assertSame(20, $tooNewError->getAdditionalData()['days_left'] ?? null);

        $this->wpdb->seedGrant([
            'user_id'  => self::BUYER,
            'level_id' => self::LEVEL,
            'status'   => 'active',
            'ends_at'  => null,
        ]);
        $covered = $this->makeOrder(83, self::BUYER);
        $this->assertRouteError('hwe_membership_already_indefinite', function () use ($covered): void {
            \HWE_Memberships_Purchase::validateOrderRequest($covered, new \WP_REST_Request([]));
        });

        $guest = $this->makeOrder(84, 0);
        $this->assertRouteError('hwe_membership_login_required', function () use ($guest): void {
            \HWE_Memberships_Purchase::validateOrderRequest($guest, new \WP_REST_Request([]));
        });
    }

    public function testSnapshotWritesTheLevelAndTheDurationOnTheOrderLine(): void
    {
        $order = $this->makeOrder(82, self::BUYER);

        $monthly = $this->makeItem(9, self::PRODUCT);
        \HWE_Memberships_Purchase::snapshotLineItem($monthly, 'cart-key', ['product_id' => self::PRODUCT], $order);
        $this->assertSame(self::LEVEL, (int) $monthly->get_meta(\HWE_Memberships_Purchase::META_LINE_LEVEL));
        $this->assertSame(30, (int) $monthly->get_meta(\HWE_Memberships_Purchase::META_LINE_DAYS));

        $forever = $this->makeItem(10, self::PRODUCT_FOREVER);
        \HWE_Memberships_Purchase::snapshotLineItem($forever, 'cart-key', ['product_id' => self::PRODUCT_FOREVER], $order);
        $this->assertSame(0, (int) $forever->get_meta(\HWE_Memberships_Purchase::META_LINE_DAYS));

        $normal = $this->makeItem(11, self::PRODUCT_NORMAL);
        \HWE_Memberships_Purchase::snapshotLineItem($normal, 'cart-key', ['product_id' => self::PRODUCT_NORMAL], $order);
        $this->assertSame('', $normal->get_meta(\HWE_Memberships_Purchase::META_LINE_LEVEL));
    }

    public function testTheClassicAddToCartValidationIsTheFallback(): void
    {
        $this->currentUser = 0;

        $passed = \HWE_Memberships_Purchase::validateClassicAddToCart(true, self::PRODUCT, 1);

        $this->assertFalse($passed);
        $this->assertNotEmpty($this->notices);
        $this->assertSame('error', $this->notices[0][1]);
    }
}
