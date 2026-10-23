<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 2) . '/Stubs/wc-order.php';

/**
 * Plugin `hwe-loyalty` (módulo `loyalty`), parte de ganancias por pedido (tarea 6).
 *
 * Se prueba la DECISIÓN: cuándo un pedido «cuenta» (spec §6.1), qué base se usa (líneas de
 * producto después de cupones e impuestos, sin envío, sin fees de extras de envío y sin la
 * parte pagada con puntos), a quién se abona cada concepto (§6.2), la idempotencia por pedido
 * y beneficiario (Foco de revisión 2) y la reversión limitada al saldo en cancelación o
 * reembolso total (§6.4).
 *
 * El doble de `$wpdb` mantiene el libro y los saldos en memoria para poder comprobar el efecto
 * real de `HWE_Loyalty_Ledger::credit()/debit()` sin WordPress ni MySQL.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EarningsTest extends TestCase
{
    private const BUYER = 7;
    private const REFERRER = 8;
    private const GRANDPARENT = 9;
    private const OTHER = 10;
    private const NON_PARTICIPANT = 99;

    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var array<int,object> Usuarios que devuelve `get_userdata`. */
    private array $users = [];
    /** @var array<int,array<string,mixed>> Meta de producto. */
    private array $postMeta = [];
    /** @var array<int,\WC_Order> Pedidos que devuelve `wc_get_order`. */
    private array $orders = [];
    /** @var list<\WC_Order> Pedidos que devuelve `wc_get_orders` como anteriores del comprador. */
    private array $priorOrders = [];
    /** @var array<string,mixed> Argumentos de la última llamada a `wc_get_orders`. */
    private array $lastOrderQuery = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var list<string> Medios de pago que esperan a `completed`. */
    private array $deferredMethods = ['cod'];
    /** @var callable|null Ajuste de las ganancias calculadas. */
    private $earningsFilter = null;
    private int $now = 1_800_000_000;
    private TestEarningsWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'loyalty' => [
                'points_enabled'            => '1',
                'referrals_enabled'         => '1',
                'currency_name'             => 'punto',
                'currency_name_plural'      => 'puntos',
                'point_value'               => '0.01',
                'expiry_days'               => '0',
                'cashback_enabled'          => '0',
                'cashback_percent'          => '0',
                'commission_first_percent'  => '10',
                'commission_next_percent'   => '5',
                'level2_enabled'            => '0',
                'commission_level2_percent' => '3',
                'commission_duration_days'  => '0',
                'allowed_roles'             => 'customer, subscriber',
            ],
        ];
        $this->users = [
            self::BUYER => (object) [
                'ID'           => self::BUYER,
                'roles'        => ['customer'],
                'display_name' => 'Ana Visible',
            ],
            self::REFERRER => (object) [
                'ID'           => self::REFERRER,
                'roles'        => ['customer'],
                'display_name' => 'Beto Visible',
            ],
            self::GRANDPARENT => (object) [
                'ID'           => self::GRANDPARENT,
                'roles'        => ['subscriber'],
                'display_name' => 'Carla Visible',
            ],
            self::OTHER => (object) [
                'ID'           => self::OTHER,
                'roles'        => ['customer'],
                'display_name' => 'Dani Visible',
            ],
            self::NON_PARTICIPANT => (object) [
                'ID'           => self::NON_PARTICIPANT,
                'roles'        => ['administrator'],
                'display_name' => 'Operador',
            ],
        ];
        $this->postMeta    = [];
        $this->orders      = [];
        $this->priorOrders = [];
        $this->lastOrderQuery = [];
        $this->logged      = [];
        $this->deferredMethods = ['cod'];
        $this->earningsFilter  = null;
        $this->now             = 1_800_000_000;

        $this->wpdb = new TestEarningsWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->referTo(self::BUYER, self::REFERRER, 'confirmed', 5);
        $this->referTo(self::REFERRER, self::GRANDPARENT, 'confirmed', 5);

        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => is_string($value) ? trim($value) : '',
            'esc_url_raw'         => static fn ($value) => trim((string) $value),
        ]);
        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            if ('hwe_config' === $name) {
                return $this->config;
            }
            if ('active_plugins' === $name) {
                return ['hwe-loyalty/hwe-loyalty.php'];
            }
            return $default;
        });
        Functions\when('get_userdata')->alias(fn (int $id) => $this->users[$id] ?? false);
        Functions\when('get_post_meta')->alias(
            fn (int $postId, string $key, bool $single = false) => $this->postMeta[$postId][$key] ?? ''
        );
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('wc_get_order')->alias(fn (int $id) => $this->orders[$id] ?? false);
        Functions\when('wc_get_orders')->alias(function (array $args = []): array {
            $this->lastOrderQuery = $args;
            return $this->priorOrders;
        });
        Functions\when('wc_get_is_paid_statuses')->justReturn(['processing', 'completed']);
        Functions\when('wc_get_price_decimals')->justReturn(2);
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed ...$args): mixed {
            if ('hwe_loyalty_collected_on_completion' === $hook) {
                return $this->deferredMethods;
            }
            if ('hwe_loyalty_order_earnings' === $hook && null !== $this->earningsFilter) {
                return ($this->earningsFilter)($value, ...$args);
            }
            return $value;
        });
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });

        $this->loadOwnFile('plugins/hwe-loyalty/hwe-loyalty.php');
    }

    // ── Utilidades del caso ──────────────────────────────────────────────────

    /** @param array<string,mixed> $overrides */
    private function setLoyalty(array $overrides): void
    {
        $this->config['loyalty'] = array_merge($this->config['loyalty'], $overrides);
    }

    private function referTo(int $userId, ?int $referrerId, string $status = 'confirmed', int $daysAgo = 5): void
    {
        $date = gmdate('Y-m-d H:i:s', $this->now - $daysAgo * DAY_IN_SECONDS);
        $this->wpdb->referrals[$userId] = [
            'user_id'       => $userId,
            'code'          => sprintf('CODE%04d', $userId),
            'referrer_id'   => $referrerId,
            'status'        => $status,
            'attributed_at' => $date,
            'confirmed_at'  => 'confirmed' === $status ? $date : null,
        ];
    }

    private function clearReferrer(int $userId): void
    {
        $this->wpdb->referrals[$userId] = [
            'user_id'       => $userId,
            'code'          => sprintf('CODE%04d', $userId),
            'referrer_id'   => null,
            'status'        => 'pending',
            'attributed_at' => gmdate('Y-m-d H:i:s', $this->now - 5 * DAY_IN_SECONDS),
            'confirmed_at'  => null,
        ];
    }

    private function item(int $productId, int $quantity, float $total, float $tax = 0.0): TestEarningsItem
    {
        return new TestEarningsItem($productId, $quantity, $total, $tax);
    }

    /** @param array<string,mixed> $overrides */
    private function makeOrder(int $id, int $customerId, array $overrides = []): \WC_Order
    {
        $order = new \WC_Order();
        $order->id             = $id;
        $order->customer_id    = $customerId;
        $order->status         = (string) ($overrides['status'] ?? 'processing');
        $order->paid           = (bool) ($overrides['paid'] ?? in_array($order->status, ['processing', 'completed'], true));
        $order->payment_method = (string) ($overrides['payment_method'] ?? 'card');
        $order->line_items     = $overrides['items'] ?? [$this->item(101, 1, 100.0)];
        $order->fee_items      = $overrides['fees'] ?? [];
        $order->total          = (float) ($overrides['total'] ?? 100.0);
        $order->total_refunded = (float) ($overrides['total_refunded'] ?? 0.0);
        $order->meta           = $overrides['meta'] ?? [];

        $this->orders[$id] = $order;

        return $order;
    }

    /** @return array<string,mixed> */
    private function stateOf(\WC_Order $order): array
    {
        $raw = $order->get_meta('_hwe_loyalty_earnings');

        return is_array($raw) ? $raw : [];
    }

    /** @return list<array<string,mixed>> */
    private function entriesOf(int $userId, ?string $type = null): array
    {
        return array_values(array_filter(
            $this->wpdb->ledger,
            static fn (array $row): bool => (int) $row['user_id'] === $userId
                && (null === $type || (string) $row['type'] === $type)
        ));
    }

    private function pointsOf(int $userId, string $type): int
    {
        return array_sum(array_map(
            static fn (array $row): int => (int) $row['points'],
            $this->entriesOf($userId, $type)
        ));
    }

    private function entryCount(int $userId, string $type): int
    {
        return count($this->entriesOf($userId, $type));
    }

    private function balanceOf(int $userId): int
    {
        return (int) \HWE_Loyalty_Ledger::balance($userId)['balance'];
    }

    /** @return list<string> */
    private function noteTexts(\WC_Order $order): array
    {
        return array_map(static fn (array $note): string => $note['note'], $order->notes);
    }

    // ── Base de cálculo ──────────────────────────────────────────────────────

    public function test_order_base_uses_product_lines_after_coupons_and_taxes_and_ignores_shipping_fees_and_points(): void
    {
        $order = $this->makeOrder(self::OTHER, self::BUYER, [
            'items' => [
                $this->item(101, 2, 60.0, 11.4),
                $this->item(102, 1, 40.0),
            ],
            // Extra de envío del módulo `shipping`: llega como fee line y no entra en la base.
            'fees'  => [$this->item(999, 1, 15.0)],
            'meta'  => ['_hwe_loyalty_discount' => '20.00'],
        ]);

        $this->assertEqualsWithDelta(
            91.4,
            \HWE_Loyalty_Earnings::orderBase($order),
            0.0001,
            'Líneas después de cupones (60 + 40), con sus impuestos (11.4), menos 20 pagados con puntos.'
        );
    }

    public function test_order_base_never_goes_below_zero(): void
    {
        $order = $this->makeOrder(55, self::BUYER, [
            'items' => [$this->item(101, 1, 10.0)],
            'meta'  => ['_hwe_loyalty_discount' => '25.00'],
        ]);

        $this->assertSame(0.0, \HWE_Loyalty_Earnings::orderBase($order));
    }

    // ── Cashback ─────────────────────────────────────────────────────────────

    public function test_cashback_is_only_paid_when_enabled_and_the_buyer_has_a_participating_account(): void
    {
        $this->clearReferrer(self::BUYER);
        $this->setLoyalty(['cashback_enabled' => '1', 'cashback_percent' => '10']);

        $paid = $this->makeOrder(55, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(55);

        $this->assertSame(10, $this->pointsOf(self::BUYER, 'cashback'));
        $this->assertSame(1, $this->entryCount(self::BUYER, 'cashback'));

        // Con el cashback apagado, el mismo comprador no recibe nada por su compra.
        $this->setLoyalty(['cashback_enabled' => '0']);
        $this->makeOrder(56, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(56);
        $this->assertSame(1, $this->entryCount(self::BUYER, 'cashback'));

        // Invitado: no hay cuenta, así que no hay cashback.
        $this->setLoyalty(['cashback_enabled' => '1']);
        $this->makeOrder(57, 0);
        \HWE_Loyalty_Earnings::processOrder(57);

        // Comprador con un rol que no participa: tampoco.
        $this->makeOrder(58, self::NON_PARTICIPANT);
        \HWE_Loyalty_Earnings::processOrder(58);

        $this->assertSame(1, $this->entryCount(self::BUYER, 'cashback'));
        $this->assertSame([], $this->entriesOf(0));
        $this->assertSame([], $this->entriesOf(self::NON_PARTICIPANT));
        $this->assertNotSame('', $paid->get_id());
    }

    public function test_points_are_always_floored(): void
    {
        $this->clearReferrer(self::BUYER);
        $this->setLoyalty(['cashback_enabled' => '1', 'cashback_percent' => '10']);
        $this->makeOrder(55, self::BUYER, ['items' => [$this->item(101, 1, 99.0)]]);

        \HWE_Loyalty_Earnings::processOrder(55);

        $this->assertSame(9, $this->pointsOf(self::BUYER, 'cashback'), '9.9 se redondea hacia abajo.');
    }

    // ── Comisiones N1 y N2 ───────────────────────────────────────────────────

    public function test_first_purchase_uses_the_first_percent_and_later_ones_use_the_next(): void
    {
        $this->makeOrder(55, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(55);

        $this->assertSame(10, $this->pointsOf(self::REFERRER, 'referral_commission_l1'));
        $this->assertSame(
            'earn:order:55:referral_commission_l1:' . self::REFERRER,
            (string) $this->entriesOf(self::REFERRER, 'referral_commission_l1')[0]['idempotency_key']
        );
        $this->assertSame([], $this->priorOrders);

        // Un pedido cobrado anterior convierte la compra en «siguiente».
        $this->priorOrders = [$this->orders[55]];
        $this->makeOrder(56, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(56);

        $commissions = $this->entriesOf(self::REFERRER, 'referral_commission_l1');
        $this->assertCount(2, $commissions);
        $this->assertSame(5, (int) $commissions[1]['points'], 'La segunda compra usa el porcentaje de siguientes.');
        $this->assertSame(15, $this->pointsOf(self::REFERRER, 'referral_commission_l1'));
        $this->assertSame(self::BUYER, $this->lastOrderQuery['customer_id'] ?? null);
        $this->assertSame([56], $this->lastOrderQuery['exclude'] ?? null, 'El pedido actual no cuenta como anterior.');
    }

    public function test_a_previous_cash_on_delivery_order_still_in_processing_does_not_count_as_collected(): void
    {
        $previous = $this->makeOrder(54, self::BUYER, ['status' => 'processing', 'payment_method' => 'cod']);
        $this->priorOrders = [$previous];

        $this->makeOrder(55, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(55);

        $this->assertSame(
            10,
            $this->pointsOf(self::REFERRER, 'referral_commission_l1'),
            'Un pedido contra reembolso en «processing» todavía no está cobrado.'
        );
    }

    public function test_commission_stops_after_the_configured_duration_from_the_attribution(): void
    {
        $this->referTo(self::BUYER, self::REFERRER, 'confirmed', 40);
        $this->setLoyalty(['commission_duration_days' => '30']);

        $this->makeOrder(55, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(55);
        $this->assertSame([], $this->entriesOf(self::REFERRER), 'Fuera de la ventana no hay comisión.');

        $this->setLoyalty(['commission_duration_days' => '0']);
        $this->makeOrder(56, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(56);
        $this->assertSame(10, $this->pointsOf(self::REFERRER, 'referral_commission_l1'), '0 = sin límite.');
    }

    public function test_a_pending_attribution_counts_the_duration_from_its_attribution_date(): void
    {
        $this->referTo(self::BUYER, self::REFERRER, 'pending', 10);
        $this->setLoyalty(['commission_duration_days' => '30']);

        $this->makeOrder(55, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(55);
        $this->assertSame(10, $this->pointsOf(self::REFERRER, 'referral_commission_l1'));

        $this->referTo(self::BUYER, self::REFERRER, 'pending', 40);
        $this->makeOrder(56, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(56);
        $this->assertSame(10, $this->pointsOf(self::REFERRER, 'referral_commission_l1'), 'Ya venció.');
    }

    public function test_level_two_is_paid_only_when_enabled_and_the_grandparent_still_exists(): void
    {
        $this->setLoyalty(['level2_enabled' => '1']);

        $this->makeOrder(55, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(55);
        $this->assertSame(3, $this->pointsOf(self::GRANDPARENT, 'referral_commission_l2'));

        $this->setLoyalty(['level2_enabled' => '0']);
        $this->makeOrder(56, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(56);
        $this->assertSame(3, $this->pointsOf(self::GRANDPARENT, 'referral_commission_l2'), 'Apagado no abona.');

        $this->setLoyalty(['level2_enabled' => '1']);
        unset($this->users[self::GRANDPARENT]);
        $this->makeOrder(57, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(57);
        $this->assertSame(3, $this->pointsOf(self::GRANDPARENT, 'referral_commission_l2'), 'No se abona a un id inexistente.');
    }

    // ── Bono por producto ────────────────────────────────────────────────────

    public function test_product_bonus_is_paid_to_the_first_level_referrer_per_unit(): void
    {
        $this->setLoyalty(['commission_first_percent' => '0', 'commission_next_percent' => '0']);
        $this->postMeta[101]['_hwe_loyalty_referrer_bonus'] = '5';

        $this->makeOrder(55, self::BUYER, [
            'items' => [
                $this->item(101, 2, 70.0),
                $this->item(102, 1, 30.0),
            ],
        ]);
        \HWE_Loyalty_Earnings::processOrder(55);

        $this->assertSame(10, $this->pointsOf(self::REFERRER, 'referral_product_bonus'));
        $this->assertSame([], $this->entriesOf(self::BUYER), 'El bono es para el referidor, no para el comprador.');
        $this->assertSame(
            'earn:order:55:referral_product_bonus:' . self::REFERRER,
            (string) $this->entriesOf(self::REFERRER, 'referral_product_bonus')[0]['idempotency_key']
        );
    }

    public function test_a_product_bonus_without_a_referrer_is_not_paid(): void
    {
        $this->clearReferrer(self::BUYER);
        $this->postMeta[101]['_hwe_loyalty_referrer_bonus'] = '5';

        $this->makeOrder(55, self::BUYER);

        \HWE_Loyalty_Earnings::processOrder(55);

        $this->assertSame([], $this->wpdb->ledger);
    }

    // ── Cuándo cuenta un pedido ──────────────────────────────────────────────

    public function test_cash_on_delivery_earns_nothing_in_processing_and_everything_in_completed(): void
    {
        $order = $this->makeOrder(55, self::BUYER, ['status' => 'processing', 'payment_method' => 'cod']);

        \HWE_Loyalty_Earnings::processOrder(55);
        $this->assertSame([], $this->wpdb->ledger, 'Contra reembolso no se abona hasta cobrarlo.');
        $this->assertSame([], $this->stateOf($order), 'Tampoco se marca el pedido como procesado.');

        $order->status = 'completed';
        \HWE_Loyalty_Earnings::processOrder(55);

        $this->assertSame(10, $this->pointsOf(self::REFERRER, 'referral_commission_l1'));
        $this->assertSame('processed', $this->stateOf($order)['status'] ?? null);
    }

    public function test_the_collected_on_completion_filter_can_add_another_offline_method(): void
    {
        $this->deferredMethods = ['cod', 'bacs'];
        $order = $this->makeOrder(55, self::BUYER, ['status' => 'processing', 'payment_method' => 'bacs']);

        \HWE_Loyalty_Earnings::processOrder(55);
        $this->assertSame([], $this->wpdb->ledger);

        $order->status = 'completed';
        \HWE_Loyalty_Earnings::processOrder(55);
        $this->assertSame(10, $this->pointsOf(self::REFERRER, 'referral_commission_l1'));
    }

    public function test_an_order_paid_with_points_does_not_generate_any_earning(): void
    {
        $this->setLoyalty(['cashback_enabled' => '1', 'cashback_percent' => '10']);
        $this->postMeta[101]['_hwe_loyalty_referrer_bonus'] = '5';
        $order = $this->makeOrder(55, self::BUYER, ['meta' => ['_hwe_loyalty_paid_with_points' => 'yes']]);

        \HWE_Loyalty_Earnings::processOrder(55);

        $this->assertSame([], $this->wpdb->ledger);
        $state = $this->stateOf($order);
        $this->assertSame('skipped', $state['status'] ?? null);
        $this->assertSame('paid_with_points', $state['reason'] ?? null);
    }

    public function test_processing_completed_and_payment_complete_on_the_same_order_credit_each_beneficiary_once(): void
    {
        $this->setLoyalty(['cashback_enabled' => '1', 'cashback_percent' => '10']);
        $this->postMeta[101]['_hwe_loyalty_referrer_bonus'] = '5';
        $order = $this->makeOrder(55, self::BUYER);

        \HWE_Loyalty_Earnings::onOrderProcessing(55);

        $order->status = 'completed';
        \HWE_Loyalty_Earnings::onOrderCompleted(55);
        \HWE_Loyalty_Earnings::onPaymentComplete(55);

        $this->assertSame(1, $this->entryCount(self::BUYER, 'cashback'));
        $this->assertSame(10, $this->pointsOf(self::BUYER, 'cashback'));
        $this->assertSame(1, $this->entryCount(self::REFERRER, 'referral_commission_l1'));
        $this->assertSame(10, $this->pointsOf(self::REFERRER, 'referral_commission_l1'));
        $this->assertSame(1, $this->entryCount(self::REFERRER, 'referral_product_bonus'));
        $this->assertSame(5, $this->pointsOf(self::REFERRER, 'referral_product_bonus'));
        $this->assertCount(3, $this->wpdb->ledger, 'Un abono por beneficiario y concepto.');
        $this->assertCount(3, $order->notes, 'Una nota privada por abono, no una por aviso repetido.');
        $this->assertSame('processed', $this->stateOf($order)['status'] ?? null);
    }

    public function test_is_collected_requires_a_paid_order_and_waits_for_the_configured_methods(): void
    {
        $card = $this->makeOrder(55, self::BUYER, ['status' => 'processing', 'payment_method' => 'card']);
        $this->assertTrue(\HWE_Loyalty_Earnings::isCollected($card));

        $cod = $this->makeOrder(56, self::BUYER, ['status' => 'processing', 'payment_method' => 'cod']);
        $this->assertFalse(\HWE_Loyalty_Earnings::isCollected($cod));

        $cod->status = 'completed';
        $this->assertTrue(\HWE_Loyalty_Earnings::isCollected($cod));

        $unpaid = $this->makeOrder(57, self::BUYER, ['status' => 'processing', 'paid' => false]);
        $this->assertFalse(\HWE_Loyalty_Earnings::isCollected($unpaid));

        $hold = $this->makeOrder(58, self::BUYER, ['status' => 'on-hold']);
        $this->assertFalse(\HWE_Loyalty_Earnings::isCollected($hold));

        $this->deferredMethods = ['cod', 'bacs'];
        $bacs = $this->makeOrder(59, self::BUYER, ['status' => 'processing', 'payment_method' => 'bacs']);
        $this->assertFalse(\HWE_Loyalty_Earnings::isCollected($bacs));
        $bacs->status = 'completed';
        $this->assertTrue(\HWE_Loyalty_Earnings::isCollected($bacs));
    }

    // ── Reversión ────────────────────────────────────────────────────────────

    public function test_cancelled_order_reverses_each_earning_limited_to_the_beneficiary_balance(): void
    {
        $this->setLoyalty(['cashback_enabled' => '1', 'cashback_percent' => '10']);
        $order = $this->makeOrder(55, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(55);

        // El comprador gastó 4 de los 10 que ganó: solo se pueden recuperar 6.
        \HWE_Loyalty_Ledger::debit(self::BUYER, 4, 'redeem', 'test:spend:' . self::BUYER);

        \HWE_Loyalty_Earnings::onOrderCancelled(55);

        $this->assertSame(-6, $this->pointsOf(self::BUYER, 'earn_reversal'));
        $this->assertSame(-10, $this->pointsOf(self::REFERRER, 'earn_reversal'));
        $this->assertSame(0, $this->balanceOf(self::BUYER));
        $this->assertSame(0, $this->balanceOf(self::REFERRER));
        $this->assertSame(
            'reverse:order:55:cashback:' . self::BUYER,
            (string) $this->entriesOf(self::BUYER, 'earn_reversal')[0]['idempotency_key']
        );

        $state = $this->stateOf($order);
        $this->assertSame('reversed', $state['status'] ?? null);
        $this->assertSame(4, $state['unrecovered'] ?? null, 'Lo no recuperado queda anotado en el pedido.');

        $notes = $this->noteTexts($order);
        $this->assertCount(4, $notes, 'Dos abonos y dos anulaciones.');
        $this->assertStringContainsString('no recuperado: 4', implode("\n", $notes));
    }

    public function test_reversing_twice_does_not_duplicate_the_charge(): void
    {
        $this->setLoyalty(['cashback_enabled' => '1', 'cashback_percent' => '10']);
        $order = $this->makeOrder(55, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(55);
        $notesAfterProcessing = count($order->notes);

        \HWE_Loyalty_Earnings::onOrderCancelled(55);
        \HWE_Loyalty_Earnings::onOrderCancelled(55);

        $this->assertSame(1, $this->entryCount(self::BUYER, 'earn_reversal'));
        $this->assertSame(1, $this->entryCount(self::REFERRER, 'earn_reversal'));
        $this->assertSame($notesAfterProcessing + 2, count($order->notes), 'No se repiten las notas.');
    }

    public function test_a_total_refund_reverses_but_a_partial_refund_does_not(): void
    {
        $this->setLoyalty(['cashback_enabled' => '1', 'cashback_percent' => '10']);
        $order = $this->makeOrder(55, self::BUYER, ['total' => 100.0]);
        \HWE_Loyalty_Earnings::processOrder(55);
        \HWE_Loyalty_Ledger::debit(self::BUYER, 4, 'redeem', 'test:spend:' . self::BUYER);

        $order->total_refunded = 30.0;
        \HWE_Loyalty_Earnings::onOrderRefunded(55);
        $this->assertSame([], $this->entriesOf(self::BUYER, 'earn_reversal'));
        $this->assertSame('processed', $this->stateOf($order)['status'] ?? null);

        $order->total_refunded = 100.0;
        \HWE_Loyalty_Earnings::onOrderRefunded(55);
        $this->assertSame(1, $this->entryCount(self::BUYER, 'earn_reversal'));
        $this->assertSame(-6, $this->pointsOf(self::BUYER, 'earn_reversal'), 'Limitado al saldo del comprador.');
        $this->assertSame(1, $this->entryCount(self::REFERRER, 'earn_reversal'));
        $this->assertSame(4, $this->stateOf($order)['unrecovered'] ?? null);
        $this->assertSame('reversed', $this->stateOf($order)['status'] ?? null);
    }

    public function test_a_deleted_referrer_is_never_credited(): void
    {
        $this->assertSame(self::REFERRER, \HWE_Loyalty_Referrals::attributionOf(self::BUYER)['referrer_id'] ?? null);

        unset($this->users[self::REFERRER]);
        $order = $this->makeOrder(55, self::BUYER);

        \HWE_Loyalty_Earnings::processOrder(55);

        $this->assertNull(\HWE_Loyalty_Referrals::referrerOf(self::BUYER));
        $this->assertSame([], $this->entriesOf(self::REFERRER));
        $this->assertSame([], $this->wpdb->ledger);
        $this->assertSame('processed', $this->stateOf($order)['status'] ?? null);
        $this->assertNotEmpty($this->logged);
    }

    // ── Referencia tipada y notas ────────────────────────────────────────────

    public function test_commissions_reference_the_buyer_and_cashback_references_the_order(): void
    {
        $this->setLoyalty(['cashback_enabled' => '1', 'cashback_percent' => '10']);
        $this->postMeta[101]['_hwe_loyalty_referrer_bonus'] = '5';
        $this->makeOrder(55, self::BUYER);

        \HWE_Loyalty_Earnings::processOrder(55);

        $cashback = $this->entriesOf(self::BUYER, 'cashback')[0];
        $this->assertSame('order', $cashback['ref_type']);
        $this->assertSame('55', $cashback['ref_id']);

        foreach (['referral_commission_l1', 'referral_product_bonus'] as $type) {
            $row = $this->entriesOf(self::REFERRER, $type)[0];
            $this->assertSame('user', $row['ref_type'], 'Convención de la red de referidos (tarea 5).');
            $this->assertSame((string) self::BUYER, $row['ref_id']);
            $this->assertStringContainsString('"order":55', (string) $row['data']);
        }
    }

    public function test_the_private_note_uses_the_configured_currency_name_and_is_not_for_the_customer(): void
    {
        $this->setLoyalty([
            'currency_name_plural' => 'créditos',
            'cashback_enabled'     => '1',
            'cashback_percent'     => '10',
        ]);
        $order = $this->makeOrder(55, self::BUYER);

        \HWE_Loyalty_Earnings::processOrder(55);

        $notes = $this->noteTexts($order);
        $this->assertCount(2, $notes, 'Cashback y comisión.');
        $this->assertStringContainsString('créditos', implode("\n", $notes));
        $this->assertStringNotContainsString('puntos', implode("\n", $notes), 'El nombre de la unidad no se escribe a mano.');
        foreach ($order->notes as $note) {
            $this->assertFalse($note['is_customer_note'], 'La trazabilidad es una nota privada.');
        }
    }

    // ── Filtro de extensibilidad ─────────────────────────────────────────────

    public function test_the_order_earnings_filter_can_adjust_the_points_before_crediting(): void
    {
        $this->clearReferrer(self::BUYER);
        $this->setLoyalty(['cashback_enabled' => '1', 'cashback_percent' => '10']);
        $seen = [];
        $this->earningsFilter = function (array $earnings, $order, float $base, array $settings) use (&$seen): array {
            $seen = ['order' => $order->get_id(), 'base' => $base, 'percent' => $settings['cashback_percent']];
            foreach ($earnings as &$earning) {
                if ('cashback' === $earning['type']) {
                    $earning['points'] += 5;
                }
            }
            return $earnings;
        };

        $this->makeOrder(55, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(55);

        $this->assertSame(15, $this->pointsOf(self::BUYER, 'cashback'));
        $this->assertSame(['order' => 55, 'base' => 100.0, 'percent' => 10], $seen);
    }

    public function test_a_malformed_order_earnings_filter_result_is_discarded_and_logged(): void
    {
        $this->setLoyalty(['cashback_enabled' => '1', 'cashback_percent' => '10']);
        $this->earningsFilter = static fn (): string => 'no es una lista';

        $order = $this->makeOrder(55, self::BUYER);
        \HWE_Loyalty_Earnings::processOrder(55);

        $this->assertSame([], $this->wpdb->ledger);
        $this->assertSame('processed', $this->stateOf($order)['status'] ?? null);
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] loyalty:', $this->logged[0]);
    }

    // ── Hooks y getter de atribución ─────────────────────────────────────────

    public function test_register_hooks_the_order_status_events_and_payment_complete(): void
    {
        Actions\expectAdded('woocommerce_order_status_processing')
            ->once()
            ->with([\HWE_Loyalty_Earnings::class, 'onOrderProcessing']);
        Actions\expectAdded('woocommerce_order_status_completed')
            ->once()
            ->with([\HWE_Loyalty_Earnings::class, 'onOrderCompleted']);
        Actions\expectAdded('woocommerce_payment_complete')
            ->once()
            ->with([\HWE_Loyalty_Earnings::class, 'onPaymentComplete']);
        // La reversión corre DESPUÉS de la devolución del canje (Redemption, prioridad 10): si
        // no, ve el saldo antes de que vuelvan los puntos canjeados, no recupera nada y deja
        // puntos sin respaldo cuando las ganancias del pedido ya se gastaron.
        Actions\expectAdded('woocommerce_order_status_cancelled')
            ->once()
            ->with([\HWE_Loyalty_Earnings::class, 'onOrderCancelled'], 20);
        Actions\expectAdded('woocommerce_order_status_refunded')
            ->once()
            ->with([\HWE_Loyalty_Earnings::class, 'onOrderRefunded'], 20);

        \HWE_Loyalty_Earnings::register();
    }

    public function test_attribution_of_exposes_only_the_fields_needed_for_the_commission_window(): void
    {
        $attribution = \HWE_Loyalty_Referrals::attributionOf(self::BUYER);

        $this->assertSame(self::REFERRER, $attribution['referrer_id']);
        $this->assertSame('confirmed', $attribution['status']);
        $this->assertNotEmpty($attribution['attributed_at']);
        $this->assertNotEmpty($attribution['confirmed_at']);
        $this->assertNull(\HWE_Loyalty_Referrals::attributionOf(404));
    }
}

/**
 * Línea de pedido mínima: producto, cantidad y totales (después de cupones, con su impuesto).
 */
final class TestEarningsItem
{
    public function __construct(
        private int $productId,
        private int $quantity,
        private float $total,
        private float $tax = 0.0
    ) {
    }

    public function get_product_id(): int
    {
        return $this->productId;
    }

    public function get_quantity(): int
    {
        return $this->quantity;
    }

    public function get_total(): float
    {
        return $this->total;
    }

    public function get_total_tax(): float
    {
        return $this->tax;
    }
}

/**
 * Doble de `$wpdb` para las ganancias: mantiene las filas de referidos, el libro, los saldos y los
 * consumos FIFO en memoria. Entiende solo las sentencias que emiten `HWE_Loyalty_Referrals` y
 * `HWE_Loyalty_Ledger::credit()/debit()/balance()`; cualquier otra devuelve vacío.
 */
final class TestEarningsWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var array<int,array<string,mixed>> */
    public array $referrals = [];
    /** @var array<int,array<string,mixed>> */
    public array $ledger = [];
    /** @var array<int,array{balance:int,earned:int,used:int}> */
    public array $balances = [];
    /** @var list<array<string,mixed>> */
    public array $consumptions = [];
    /** @var list<string> */
    public array $queries = [];
    private int $nextLedgerId = 1;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }
        return $sql . ' /* ' . json_encode($args) . ' */';
    }

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        if (str_ends_with($table, 'hwe_loyalty_ledger')) {
            foreach ($this->ledger as $row) {
                if ((string) $row['idempotency_key'] === (string) $data['idempotency_key']) {
                    return false;
                }
            }
            $id = $this->nextLedgerId++;
            $this->insert_id = $id;
            $this->ledger[$id] = $data + ['id' => $id];
            return 1;
        }

        if (str_ends_with($table, 'hwe_loyalty_ledger_consumptions')) {
            $this->consumptions[] = $data;
            return 1;
        }

        $this->insert_id = $this->nextLedgerId++;
        return 1;
    }

    public function query(string $sql): int|false
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_starts_with($sql, 'INSERT IGNORE INTO ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[0] ?? 0);
            $this->balances[$userId] ??= ['balance' => 0, 'earned' => 0, 'used' => 0];
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[count($args) - 1] ?? 0);
            $row    = $this->balances[$userId] ?? ['balance' => 0, 'earned' => 0, 'used' => 0];
            if (str_contains($sql, 'balance = balance +')) {
                $row['balance'] += (int) ($args[0] ?? 0);
                $row['earned']  += (int) ($args[1] ?? 0);
            } elseif (str_contains($sql, 'balance = balance -')) {
                $row['balance'] -= (int) ($args[0] ?? 0);
                $row['used']    += (int) ($args[1] ?? 0);
            }
            $this->balances[$userId] = $row;
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->prefix . 'hwe_loyalty_ledger')) {
            [$points, $id, $guard] = $args;
            $id     = (int) $id;
            $points = (int) $points;
            if (!isset($this->ledger[$id]) || (int) $this->ledger[$id]['remaining_points'] < $points) {
                return 0;
            }
            $this->ledger[$id]['remaining_points'] = (int) $this->ledger[$id]['remaining_points'] - $points;
            return 1;
        }

        // START TRANSACTION, COMMIT y ROLLBACK no necesitan estado en el doble.
        return 1;
    }

    public function get_row(string $sql): ?object
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[0] ?? 0);
            return isset($this->balances[$userId])
                ? (object) ($this->balances[$userId] + ['user_id' => $userId])
                : null;
        }

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_referrals')) {
            $row = $this->referrals[(int) ($args[0] ?? 0)] ?? null;
            return null === $row ? null : (object) $row;
        }

        return null;
    }

    public function get_var(string $sql): string|int|null
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);
        foreach ($this->ledger as $row) {
            if ((string) $row['idempotency_key'] === (string) ($args[0] ?? '')) {
                return (int) $row['id'];
            }
        }
        return null;
    }

    /** @return list<object> */
    public function get_results(string $sql): array
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_ledger')) {
            $userId = (int) ($args[0] ?? 0);
            $limit  = (int) ($args[1] ?? 100);
            $rows   = [];
            foreach ($this->ledger as $row) {
                if (
                    (int) $row['user_id'] === $userId
                    && (int) $row['points'] > 0
                    && (int) $row['remaining_points'] > 0
                ) {
                    $rows[] = $row;
                }
            }

            usort($rows, static function (array $left, array $right): int {
                $leftExpiry  = $left['expires_at'] ?? null;
                $rightExpiry = $right['expires_at'] ?? null;
                if (null === $leftExpiry && null === $rightExpiry) {
                    return (int) $left['id'] <=> (int) $right['id'];
                }
                if (null === $leftExpiry) {
                    return 1;
                }
                if (null === $rightExpiry) {
                    return -1;
                }
                return strcmp((string) $leftExpiry, (string) $rightExpiry)
                    ?: ((int) $left['id'] <=> (int) $right['id']);
            });

            return array_map(
                static fn (array $row): object => (object) $row,
                array_slice($rows, 0, $limit)
            );
        }

        return [];
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        return false;
    }

    /** @return array<int,string> */
    private function args(string $sql): array
    {
        if (preg_match('~/\\* (\\[.*\\]) \\*/$~s', $sql, $matches)) {
            $decoded = json_decode($matches[1], true);
            return is_array($decoded) ? array_values($decoded) : [];
        }
        return [];
    }
}
