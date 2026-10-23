<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 2) . '/Stubs/wc-cart.php';
require_once dirname(__DIR__, 2) . '/Stubs/wc-store-api.php';
require_once dirname(__DIR__, 2) . '/Stubs/wc-order.php';

/**
 * Plugin `hwe-loyalty` (módulo `loyalty`), canje en el carrito y en el pedido (tarea 8).
 *
 * Se prueba la DECISIÓN: el navegador solo declara cuántos puntos quiere usar; WordPress
 * calcula el descuento, lo recorta a saldo, máximo por pedido y tope del carrito, lo aplica
 * como fee negativo no imponible y lo confirma contra el libro al crear el pedido. También
 * que un fallo de saldo entre el carrito y el checkout corta la compra sin dejar un descuento
 * sin respaldo (Foco 1), que cambiar los ajustes con un canje aplicado recorta o quita el fee
 * (Foco 3), y que la devolución es idempotente.
 *
 * El doble de `$wpdb` mantiene el libro, los saldos y los consumos FIFO en memoria, como el de
 * las ganancias, y añade la consulta por clave de idempotencia que usa la recuperación.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RedemptionTest extends TestCase
{
    private const USER = 7;
    private const ADMIN = 99;

    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var array<int,object> Usuarios que devuelve `get_userdata`. */
    private array $users = [];
    /** @var array<int,\WC_Order> Pedidos que devuelve `wc_get_order`. */
    private array $orders = [];
    /** @var list<string> */
    private array $logged = [];
    private int $currentUserId = self::USER;
    private int $now = 1_800_000_000;
    private int $seed = 0;
    private TestRedemptionWpdb $wpdb;
    private TestRedemptionWc $wc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'loyalty' => [
                'points_enabled'            => '1',
                'referrals_enabled'         => '0',
                'currency_name'             => 'crédito',
                'currency_name_plural'      => 'créditos',
                'point_value'               => '0.01',
                'min_redeem'                => '100',
                'max_redeem_per_order'      => '0',
                'full_payment_enabled'      => '0',
                'min_cash_due'              => '1.00',
                'expiry_days'               => '0',
                'cashback_enabled'          => '0',
                'cashback_percent'          => '0',
                'events'                    => [
                    'registration' => '0',
                    'review'       => '0',
                    'birthday'     => '0',
                ],
                'referral_signup_points_l1' => '0',
                'referral_signup_points_l2' => '0',
                'commission_first_percent'  => '0',
                'commission_next_percent'   => '0',
                'level2_enabled'            => '0',
                'commission_level2_percent' => '0',
                'commission_duration_days'  => '0',
                'transfers_enabled'         => '0',
                'transfer_min'              => '100',
                'transfer_fee_percent'      => '0',
                'transfer_daily_cap'        => '0',
                'allowed_roles'             => 'customer, subscriber',
            ],
        ];
        $this->users = [
            self::USER => (object) [
                'ID'    => self::USER,
                'roles' => ['customer'],
            ],
            self::ADMIN => (object) [
                'ID'    => self::ADMIN,
                'roles' => ['administrator'],
            ],
        ];
        $this->orders        = [];
        $this->logged        = [];
        $this->currentUserId = self::USER;
        $this->now           = 1_800_000_000;
        $this->seed          = 0;

        $this->wpdb = new TestRedemptionWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->wc = new TestRedemptionWc();

        Functions\stubs([
            'sanitize_text_field'  => static fn ($value) => is_string($value) ? trim($value) : '',
            'esc_url_raw'          => static fn ($value) => trim((string) $value),
            'wp_json_encode'       => static fn (mixed $value): string|false => json_encode($value),
            'number_format_i18n'   => static fn (float $number, int $decimals = 0): string => number_format($number, $decimals, '.', ''),
            'wc_format_decimal'    => static fn ($value, int $decimals = 2): string => number_format((float) $value, $decimals, '.', ''),
            'wc_get_price_decimals' => static fn (): int => 2,
            'get_woocommerce_currency' => static fn (): string => 'USD',
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
        Functions\when('get_current_user_id')->alias(fn (): int => $this->currentUserId);
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args): mixed => $value
        );
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\when('WC')->alias(fn (): TestRedemptionWc => $this->wc);
        Functions\when('wc_get_order')->alias(fn (int $id) => $this->orders[$id] ?? false);

        $this->loadOwnFile('plugins/hwe-loyalty/hwe-loyalty.php');
    }

    // ── Utilidades del caso ──────────────────────────────────────────────────

    /** @param array<string,mixed> $overrides */
    private function setLoyalty(array $overrides): void
    {
        $this->config['loyalty'] = array_merge($this->config['loyalty'], $overrides);
    }

    private function credit(int $points, int $userId = self::USER): void
    {
        $key = 'seed:' . $userId . ':' . (++$this->seed);
        \HWE_Loyalty_Ledger::credit($userId, $points, 'admin_credit', $key);
    }

    private function session(): TestRedemptionSession
    {
        return $this->wc->session;
    }

    private function makeCart(float $items, float $shipping = 0.0, float $otherFees = 0.0): \WC_Cart
    {
        $cart = new \WC_Cart();
        $cart->cart_contents_total = $items;
        $cart->shipping_total      = $shipping;
        if (0.0 !== $otherFees) {
            $cart->add_fee('Extra', $otherFees);
        }
        $this->wc->cart = $cart;

        return $cart;
    }

    private function requestPoints(int $points): void
    {
        \HWE_Loyalty_Redemption::updatePoints(['points' => $points]);
    }

    /** @return list<object> */
    private function loyaltyFees(\WC_Cart $cart): array
    {
        $prefix = \HWE_Loyalty_Redemption::FEE_PREFIX;

        return array_values(array_filter(
            $cart->get_fees(),
            static fn (object $fee): bool => str_starts_with((string) $fee->name, $prefix)
        ));
    }

    private function feeDiscount(\WC_Cart $cart): float
    {
        $fees = $this->loyaltyFees($cart);
        $this->assertCount(1, $fees, 'Se esperaba exactamente un fee de fidelización.');

        return -1.0 * (float) $fees[0]->amount;
    }

    private function feeName(int $points): string
    {
        $settings = \HWE_Loyalty_Settings::get();
        $unit     = 1 === $points ? $settings['currency_name'] : $settings['currency_name_plural'];

        return \HWE_Loyalty_Redemption::FEE_PREFIX . $points . ' ' . $unit;
    }

    private function feeItem(int $points, float $discount): \WC_Order_Item_Fee
    {
        $item = new \WC_Order_Item_Fee();
        $item->set_name($this->feeName($points));
        $item->set_total(-1.0 * $discount);

        return $item;
    }

    private function lineItem(float $total, float $tax = 0.0): \WC_Order_Item_Product
    {
        return new \WC_Order_Item_Product(1, $total, $tax);
    }

    /** @param array<string,mixed> $overrides */
    private function makeOrder(int $id, array $overrides = []): \WC_Order
    {
        $order = new \WC_Order();
        $order->id             = $id;
        $order->customer_id    = (int) ($overrides['customer_id'] ?? self::USER);
        $order->status         = (string) ($overrides['status'] ?? 'pending');
        $order->paid           = (bool) ($overrides['paid'] ?? false);
        $order->payment_method = (string) ($overrides['payment_method'] ?? 'card');
        $order->line_items     = $overrides['items'] ?? [$this->lineItem(100.0)];
        $order->fee_items      = $overrides['fees'] ?? [];
        $order->shipping_total = (float) ($overrides['shipping_total'] ?? 0.0);
        $order->shipping_tax   = (float) ($overrides['shipping_tax'] ?? 0.0);
        $order->total          = (float) ($overrides['total'] ?? $this->totalFor($order));
        $order->total_refunded = (float) ($overrides['total_refunded'] ?? 0.0);
        $order->meta           = $overrides['meta'] ?? [];

        $this->orders[$id] = $order;

        return $order;
    }

    private function totalFor(\WC_Order $order): float
    {
        $items = 0.0;
        foreach ($order->line_items as $item) {
            $items += (float) $item->get_total() + (float) $item->get_total_tax();
        }
        $fees = 0.0;
        foreach ($order->fee_items as $item) {
            $fees += (float) $item->get_total();
        }

        return max(0.0, round($items + $fees + $order->shipping_total + $order->shipping_tax, 2));
    }

    /** @return list<array<string,mixed>> */
    private function entriesOf(string $type): array
    {
        return array_values(array_filter(
            $this->wpdb->ledger,
            static fn (array $row): bool => (string) $row['type'] === $type
        ));
    }

    private function entryWithKey(string $key): ?array
    {
        foreach ($this->wpdb->ledger as $row) {
            if ((string) $row['idempotency_key'] === $key) {
                return $row;
            }
        }

        return null;
    }

    private function balance(): int
    {
        return (int) \HWE_Loyalty_Ledger::balance(self::USER)['balance'];
    }

    // ── Update callback y contrato de la extensión ──────────────────────────

    public function test_store_api_extension_registers_even_if_blocks_already_loaded(): void
    {
        // El módulo arranca en `plugins_loaded` (prioridad 20), DESPUÉS de que WooCommerce
        // dispare `woocommerce_blocks_loaded`: engancharse a esa acción llega tarde y la
        // extensión no se registraría nunca (visto en la pila real: «There is no such
        // namespace registered: hwe-loyalty»).
        Functions\when('did_action')->alias(
            static fn (string $hook): int => 'woocommerce_blocks_loaded' === $hook ? 1 : 0
        );
        $registered = [];
        Functions\when('woocommerce_store_api_register_endpoint_data')->alias(
            static function (array $args) use (&$registered): void {
                $registered[] = 'data:' . $args['namespace'];
            }
        );
        Functions\when('woocommerce_store_api_register_update_callback')->alias(
            static function (array $args) use (&$registered): void {
                $registered[] = 'update:' . $args['namespace'];
            }
        );

        \HWE_Loyalty_Redemption::register();

        $this->assertSame(['data:hwe-loyalty', 'update:hwe-loyalty'], $registered);
    }

    public function test_update_callback_stores_the_requested_points_in_the_woocommerce_session(): void
    {
        $this->credit(500);

        \HWE_Loyalty_Redemption::updatePoints(['points' => 250]);

        $this->assertSame(250, $this->session()->get(\HWE_Loyalty_Redemption::SESSION_KEY));

        \HWE_Loyalty_Redemption::updatePoints(['points' => 0]);

        $this->assertSame(0, $this->session()->get(\HWE_Loyalty_Redemption::SESSION_KEY));
    }

    public function test_update_callback_rejects_negative_or_non_integer_amounts(): void
    {
        foreach ([-1, '10.5', 'muchos', ['10']] as $invalid) {
            try {
                \HWE_Loyalty_Redemption::updatePoints(['points' => $invalid]);
                $this->fail('Se esperaba que rechazara ' . json_encode($invalid));
            } catch (RouteException $error) {
                $this->assertSame('hwe_loyalty_invalid_points', $error->getErrorCode());
            }
        }

        $this->assertSame(0, $this->session()->get(\HWE_Loyalty_Redemption::SESSION_KEY, 0));
    }

    public function test_cart_state_reports_the_extension_contract_in_minor_units(): void
    {
        $this->credit(500);
        $this->requestPoints(300);
        $cart = $this->makeCart(50.0, 5.0);

        \HWE_Loyalty_Redemption::applyFee($cart);
        $state = \HWE_Loyalty_Redemption::cartState();

        $this->assertTrue($state['available']);
        $this->assertNull($state['reason']);
        $this->assertSame(500, $state['balance']);
        $this->assertSame(300, $state['points_applied']);
        $this->assertSame('300', $state['discount']);
        $this->assertSame(500, $state['max_redeemable']);
        $this->assertSame(100, $state['min_redeem']);
        $this->assertFalse($state['full_payment_available']);
    }

    public function test_guest_gets_unavailable_with_guest_reason_and_no_fee(): void
    {
        $this->currentUserId = 0;
        $this->requestPoints(300);
        $cart = $this->makeCart(50.0);

        \HWE_Loyalty_Redemption::applyFee($cart);
        $state = \HWE_Loyalty_Redemption::cartState();

        $this->assertSame([], $this->loyaltyFees($cart));
        $this->assertFalse($state['available']);
        $this->assertSame('guest', $state['reason']);
        $this->assertSame(0, $state['balance']);
        $this->assertSame(0, $state['points_applied']);
        $this->assertSame('0', $state['discount']);
    }

    public function test_non_participant_gets_unavailable_with_not_participant_reason(): void
    {
        $this->currentUserId = self::ADMIN;
        $this->requestPoints(300);
        $cart = $this->makeCart(50.0);

        \HWE_Loyalty_Redemption::applyFee($cart);
        $state = \HWE_Loyalty_Redemption::cartState();

        $this->assertSame([], $this->loyaltyFees($cart));
        $this->assertFalse($state['available']);
        $this->assertSame('not_participant', $state['reason']);
        $this->assertSame(0, $state['balance']);
    }

    // ── Recortes del canje ───────────────────────────────────────────────────

    public function test_requested_points_are_clipped_to_the_live_balance(): void
    {
        $this->credit(100);
        $this->requestPoints(500);
        $cart = $this->makeCart(50.0);

        \HWE_Loyalty_Redemption::applyFee($cart);

        $this->assertSame(1.0, $this->feeDiscount($cart));
        $state = \HWE_Loyalty_Redemption::cartState();
        $this->assertSame(100, $state['points_applied']);
        $this->assertSame('100', $state['discount']);
        $this->assertSame(100, $state['max_redeemable']);
    }

    public function test_requested_points_are_clipped_to_the_max_per_order(): void
    {
        $this->credit(1000);
        $this->setLoyalty(['max_redeem_per_order' => '200']);
        $this->requestPoints(500);
        $cart = $this->makeCart(50.0);

        \HWE_Loyalty_Redemption::applyFee($cart);

        $this->assertSame(2.0, $this->feeDiscount($cart));
        $state = \HWE_Loyalty_Redemption::cartState();
        $this->assertSame(200, $state['points_applied']);
        $this->assertSame(200, $state['max_redeemable']);
    }

    public function test_with_full_payment_off_the_discount_does_not_cover_shipping(): void
    {
        $this->credit(5000);
        $this->requestPoints(2000);
        $cart = $this->makeCart(10.0, 5.0);

        \HWE_Loyalty_Redemption::applyFee($cart);

        $this->assertSame(10.0, $this->feeDiscount($cart), 'El envío (5) queda por pagar.');
        $this->assertSame(5.0, $cart->get_total('edit'));
        $state = \HWE_Loyalty_Redemption::cartState();
        $this->assertSame(1000, $state['points_applied']);
        $this->assertSame('1000', $state['discount']);
    }

    public function test_with_full_payment_off_the_discount_keeps_min_cash_due_with_zero_shipping(): void
    {
        $this->credit(5000);
        $this->requestPoints(2000);
        $cart = $this->makeCart(10.0, 0.0);

        \HWE_Loyalty_Redemption::applyFee($cart);

        $this->assertSame(9.0, $this->feeDiscount($cart), 'Debe quedar 1.00 por pagar en dinero.');
        $this->assertSame(1.0, $cart->get_total('edit'));
    }

    public function test_a_cart_below_the_cash_minimum_cannot_redeem(): void
    {
        $this->credit(5000);
        $this->requestPoints(100);
        $cart = $this->makeCart(0.5, 0.0);

        \HWE_Loyalty_Redemption::applyFee($cart);
        $state = \HWE_Loyalty_Redemption::cartState();

        $this->assertSame([], $this->loyaltyFees($cart));
        $this->assertFalse($state['available']);
        $this->assertSame('cart_below_cash_minimum', $state['reason']);
        $this->assertSame(0, $state['points_applied']);
    }

    public function test_full_payment_can_reach_zero_and_marks_the_order_as_paid_with_points(): void
    {
        $this->setLoyalty(['full_payment_enabled' => '1', 'min_cash_due' => '1.00']);
        $this->credit(5000);
        $this->requestPoints(2000);
        $cart = $this->makeCart(10.0, 5.0);

        \HWE_Loyalty_Redemption::applyFee($cart);

        $this->assertSame(15.0, $this->feeDiscount($cart));
        $this->assertSame(0.0, $cart->get_total('edit'));
        $state = \HWE_Loyalty_Redemption::cartState();
        $this->assertSame(1500, $state['points_applied']);
        $this->assertSame('1500', $state['discount']);
        $this->assertTrue($state['full_payment_available']);

        $order = $this->makeOrder(55, [
            'items'  => [$this->lineItem(10.0)],
            'fees'   => [$this->feeItem(1500, 15.0)],
            'total'  => 0.0,
            'shipping_total' => 5.0,
        ]);
        \HWE_Loyalty_Redemption::confirmOrder($order);

        $this->assertSame('yes', $order->get_meta(\HWE_Loyalty_Earnings::META_PAID_WITH_POINTS));
        $this->assertSame(0.0, $order->get_total());
        $entry = $this->entryWithKey('redeem:order:55');
        $this->assertNotNull($entry);
        $this->assertSame(-1500, (int) $entry['points']);
    }

    public function test_full_payment_covers_the_last_cent_without_charging_extra_points(): void
    {
        $this->setLoyalty([
            'full_payment_enabled' => '1',
            'point_value'          => '0.03',
        ]);
        $this->credit(1000);
        $this->requestPoints(1000);
        $cart = $this->makeCart(10.0, 0.0);

        \HWE_Loyalty_Redemption::applyFee($cart);
        $state = \HWE_Loyalty_Redemption::cartState();

        $this->assertSame(10.0, $this->feeDiscount($cart));
        $this->assertSame(0.0, $cart->get_total('edit'));
        $this->assertSame(333, $state['points_applied'], '333 × 0.03 = 9.99; el céntimo que falta es bonus.');
        $this->assertSame('1000', $state['discount']);
    }

    public function test_below_min_redeem_does_not_apply_but_the_feature_stays_available(): void
    {
        $this->credit(1000);
        $this->requestPoints(50);
        $cart = $this->makeCart(50.0);

        \HWE_Loyalty_Redemption::applyFee($cart);
        $state = \HWE_Loyalty_Redemption::cartState();

        $this->assertSame([], $this->loyaltyFees($cart));
        $this->assertTrue($state['available']);
        $this->assertSame('below_minimum', $state['reason']);
        $this->assertSame(0, $state['points_applied']);
        $this->assertSame('0', $state['discount']);
    }

    public function test_a_max_redeemable_below_min_redeem_makes_the_feature_unavailable(): void
    {
        $this->credit(50);
        $this->requestPoints(0);
        $cart = $this->makeCart(50.0);

        $state = \HWE_Loyalty_Redemption::cartState();

        $this->assertFalse($state['available']);
        $this->assertSame('below_minimum', $state['reason']);
        $this->assertSame(50, $state['max_redeemable']);
    }

    // ── Foco 3: los ajustes cambian con un canje aplicado ────────────────────

    public function test_changing_point_value_recalculates_the_discount(): void
    {
        $this->credit(5000);
        $this->requestPoints(300);

        $first = $this->makeCart(50.0);
        \HWE_Loyalty_Redemption::applyFee($first);
        $this->assertSame(3.0, $this->feeDiscount($first));

        $this->setLoyalty(['point_value' => '0.02']);
        $second = $this->makeCart(50.0);
        \HWE_Loyalty_Redemption::applyFee($second);

        $this->assertSame(6.0, $this->feeDiscount($second));
    }

    public function test_turning_points_off_removes_the_discount_on_recalculation(): void
    {
        $this->credit(5000);
        $this->requestPoints(300);

        $first = $this->makeCart(50.0);
        \HWE_Loyalty_Redemption::applyFee($first);
        $this->assertSame(3.0, $this->feeDiscount($first));

        $this->setLoyalty(['points_enabled' => '0']);
        $second = $this->makeCart(50.0);
        \HWE_Loyalty_Redemption::applyFee($second);
        $state = \HWE_Loyalty_Redemption::cartState();

        $this->assertSame([], $this->loyaltyFees($second));
        $this->assertFalse($state['available']);
        $this->assertSame('disabled', $state['reason']);
        $this->assertSame(0, $state['points_applied']);
    }

    public function test_reducing_the_max_per_order_clips_an_already_applied_redemption(): void
    {
        $this->credit(5000);
        $this->requestPoints(500);

        $first = $this->makeCart(50.0);
        \HWE_Loyalty_Redemption::applyFee($first);
        $this->assertSame(5.0, $this->feeDiscount($first));

        $this->setLoyalty(['max_redeem_per_order' => '200']);
        $second = $this->makeCart(50.0);
        \HWE_Loyalty_Redemption::applyFee($second);

        $this->assertSame(2.0, $this->feeDiscount($second));
        $this->assertSame(200, \HWE_Loyalty_Redemption::cartState()['points_applied']);
    }

    public function test_zero_or_negative_point_value_disables_redemption(): void
    {
        $this->credit(5000);

        foreach (['0', '-0.01'] as $value) {
            $this->setLoyalty(['point_value' => $value]);
            $this->requestPoints(500);
            $cart = $this->makeCart(50.0);

            \HWE_Loyalty_Redemption::applyFee($cart);
            $state = \HWE_Loyalty_Redemption::cartState();

            $this->assertSame([], $this->loyaltyFees($cart), 'point_value ' . $value);
            $this->assertFalse($state['available']);
            $this->assertSame('disabled', $state['reason']);
        }
    }

    // ── Confirmación en la creación del pedido ───────────────────────────────

    public function test_order_creation_debits_with_the_order_key_and_writes_the_metas(): void
    {
        $this->credit(500);
        $this->requestPoints(100);
        $order = $this->makeOrder(55, [
            'items' => [$this->lineItem(100.0)],
            'fees'  => [$this->feeItem(100, 1.0)],
            'total' => 99.0,
        ]);

        \HWE_Loyalty_Redemption::confirmOrder($order);

        $entry = $this->entryWithKey('redeem:order:55');
        $this->assertNotNull($entry);
        $this->assertSame(-100, (int) $entry['points']);
        $this->assertSame('redeem', (string) $entry['type']);
        $this->assertSame(400, $this->balance());

        $this->assertSame(100, (int) $order->get_meta(\HWE_Loyalty_Redemption::META_REDEEMED_POINTS));
        $this->assertSame('1.00', (string) $order->get_meta(\HWE_Loyalty_Earnings::META_DISCOUNT));
        $this->assertSame('', (string) $order->get_meta(\HWE_Loyalty_Earnings::META_PAID_WITH_POINTS));
        $this->assertSame(99.0, $order->get_total());

        $notes = implode("\n", array_map(static fn (array $note): string => $note['note'], $order->notes));
        $this->assertStringContainsString('100', $notes);
        $this->assertStringContainsString('créditos', $notes);
        $this->assertStringNotContainsString('puntos', $notes, 'La unidad sale de los ajustes, nunca escrita a mano.');
    }

    public function test_order_creation_confirms_the_amount_the_cart_already_clipped_to_balance(): void
    {
        $this->credit(100);
        $this->requestPoints(500);
        $order = $this->makeOrder(55, [
            'items' => [$this->lineItem(100.0)],
            'fees'  => [$this->feeItem(100, 1.0)],
            'total' => 99.0,
        ]);

        \HWE_Loyalty_Redemption::confirmOrder($order);

        $entry = $this->entryWithKey('redeem:order:55');
        $this->assertNotNull($entry);
        $this->assertSame(-100, (int) $entry['points']);
        $this->assertSame(0, $this->balance());
    }

    public function test_order_creation_fails_when_the_balance_no_longer_reaches_and_removes_the_discount(): void
    {
        $this->credit(100);
        $this->requestPoints(100);
        $order = $this->makeOrder(55, [
            'items' => [$this->lineItem(100.0)],
            'fees'  => [$this->feeItem(100, 1.0)],
            'total' => 99.0,
        ]);
        $order->update_meta_data('_fees_hash', 'hash-con-el-fee');

        // Otra pestaña consume 60 puntos entre el cálculo del carrito y el checkout.
        \HWE_Loyalty_Ledger::debit(self::USER, 60, 'admin_debit', 'concurrent:1');

        try {
            \HWE_Loyalty_Redemption::confirmOrder($order);
            $this->fail('El checkout debía fallar con saldo insuficiente.');
        } catch (RouteException $error) {
            $this->assertSame('hwe_loyalty_insufficient_points', $error->getErrorCode());
            $this->assertStringContainsString('créditos', $error->getMessage());
            $this->assertStringNotContainsString('puntos', $error->getMessage());
        }

        $this->assertNull($this->entryWithKey('redeem:order:55'));
        $this->assertSame('', (string) $order->get_meta(\HWE_Loyalty_Redemption::META_REDEEMED_POINTS));
        $this->assertSame([], $order->get_fees(), 'El pedido no puede quedarse con un descuento sin respaldo.');
        $this->assertSame('', (string) $order->get_meta('_fees_hash'), 'El reintento debe recrear los fees desde el carrito.');
        $this->assertSame(100.0, $order->get_total());
    }

    public function test_order_creation_fails_when_the_checkout_recalculation_already_dropped_the_fee(): void
    {
        // Lo que pasa en la Store API real: el checkout recalcula el carrito ANTES de crear el
        // pedido, `applyFee` recorta al saldo vivo (0) y el pedido nace sin fee. El cliente vio
        // un descuento: el checkout tiene que fallar con motivo claro, no cobrar el precio
        // completo en silencio (Foco 1, visto en la pila real).
        $this->credit(100);
        $cart = $this->makeCart(50.0);
        $this->requestPoints(100);
        \HWE_Loyalty_Redemption::applyFee($cart);
        $this->assertSame(100, \HWE_Loyalty_Redemption::cartState()['points_applied']);

        \HWE_Loyalty_Ledger::debit(self::USER, 100, 'admin_debit', 'concurrent:2');
        // El BFF lee el carrito justo antes del checkout (`GET /cart`): esa lectura ya ve el
        // saldo nuevo y no puede borrar lo que el cliente vio al pedir sus puntos.
        $cart->fees = [];
        \HWE_Loyalty_Redemption::applyFee($cart);
        \HWE_Loyalty_Redemption::cartState();
        $order = $this->makeOrder(56, [
            'items' => [$this->lineItem(50.0)],
            'fees'  => [],
            'total' => 50.0,
        ]);

        try {
            \HWE_Loyalty_Redemption::confirmOrder($order);
            $this->fail('El checkout debía fallar: el descuento que vio el cliente ya no tiene respaldo.');
        } catch (RouteException $error) {
            $this->assertSame('hwe_loyalty_insufficient_points', $error->getErrorCode());
        }

        $this->assertNull($this->entryWithKey('redeem:order:56'));
        $this->assertSame([], $order->get_fees());
    }

    public function test_after_an_insufficient_points_error_the_retry_charges_what_the_cart_now_shows(): void
    {
        $this->credit(300);
        $cart = $this->makeCart(50.0);
        $this->requestPoints(300);
        \HWE_Loyalty_Ledger::debit(self::USER, 100, 'admin_debit', 'concurrent:3');
        // Quedan 200: por encima del mínimo de canje (100), por debajo de lo que vio (300).

        try {
            \HWE_Loyalty_Redemption::confirmOrder($this->makeOrder(58, [
                'items' => [$this->lineItem(50.0)],
                'fees'  => [],
                'total' => 50.0,
            ]));
            $this->fail('El primer intento debía fallar.');
        } catch (RouteException $error) {
            $this->assertSame('hwe_loyalty_insufficient_points', $error->getErrorCode());
        }

        // El cliente vuelve al carrito, que ya muestra 200, y reintenta sin pedir otra cantidad:
        // no puede quedarse atascado en el mismo error.
        $cart->fees = [];
        \HWE_Loyalty_Redemption::applyFee($cart);
        $this->assertSame(200, \HWE_Loyalty_Redemption::cartState()['points_applied']);
        $retry = $this->makeOrder(59, [
            'items' => [$this->lineItem(50.0)],
            'fees'  => [$this->feeItem(200, 2.0)],
            'total' => 48.0,
        ]);

        \HWE_Loyalty_Redemption::confirmOrder($retry);

        $this->assertSame(-200, (int) $this->entryWithKey('redeem:order:59')['points']);
        $this->assertSame(0, $this->balance());
    }

    public function test_a_confirmed_redemption_clears_the_requested_points_for_the_next_cart(): void
    {
        $this->credit(500);
        $this->requestPoints(100);
        \HWE_Loyalty_Redemption::applyFee($this->makeCart(100.0));
        \HWE_Loyalty_Redemption::cartState();
        $order = $this->makeOrder(57, [
            'items' => [$this->lineItem(100.0)],
            'fees'  => [$this->feeItem(100, 1.0)],
            'total' => 99.0,
        ]);

        \HWE_Loyalty_Redemption::confirmOrder($order);

        $this->assertNotNull($this->entryWithKey('redeem:order:57'));
        // La siguiente compra no puede gastar puntos que el cliente no ha pedido para ella.
        $this->assertSame(0, \HWE_Loyalty_Redemption::requestedPoints());
        $next = $this->makeCart(100.0);
        \HWE_Loyalty_Redemption::applyFee($next);
        $this->assertSame([], $next->get_fees());
    }

    public function test_resending_the_same_draft_does_not_debit_twice(): void
    {
        $this->credit(500);
        $this->requestPoints(100);
        $order = $this->makeOrder(55, [
            'items' => [$this->lineItem(100.0)],
            'fees'  => [$this->feeItem(100, 1.0)],
            'total' => 99.0,
        ]);

        \HWE_Loyalty_Redemption::confirmOrder($order);
        $noteCount = count($order->notes);
        $order->fee_items = [];
        \HWE_Loyalty_Redemption::confirmOrder($order);

        $this->assertCount(1, $this->entriesOf('redeem'));
        $this->assertSame(400, $this->balance());
        $this->assertCount(1, $order->get_fees(), 'El fee respaldado se restaura en el reenvío.');
        $this->assertSame(-1.0, (float) $order->get_fees()[0]->get_total());
        $this->assertCount($noteCount, $order->notes, 'El reenvío no duplica la nota privada.');
    }

    public function test_a_different_request_on_an_existing_draft_keeps_the_confirmed_redemption(): void
    {
        $this->credit(500);
        $this->requestPoints(100);
        $order = $this->makeOrder(55, [
            'items' => [$this->lineItem(100.0)],
            'fees'  => [$this->feeItem(100, 1.0)],
            'total' => 99.0,
        ]);

        \HWE_Loyalty_Redemption::confirmOrder($order);
        $this->requestPoints(50);
        $order->fee_items = [$this->feeItem(50, 0.5)];
        \HWE_Loyalty_Redemption::confirmOrder($order);

        $this->assertCount(1, $this->entriesOf('redeem'));
        $this->assertSame(100, (int) $order->get_meta(\HWE_Loyalty_Redemption::META_REDEEMED_POINTS));
        $this->assertSame(-1.0, (float) $order->get_fees()[0]->get_total(), 'El canje ya confirmado no se cambia.');
        $this->assertSame(400, $this->balance());
    }

    public function test_a_crash_between_debit_and_order_meta_is_recovered_without_a_second_debit(): void
    {
        $this->credit(500);
        $this->requestPoints(100);
        $order = $this->makeOrder(55, [
            'items' => [$this->lineItem(100.0)],
            'fees'  => [$this->feeItem(100, 1.0)],
            'total' => 99.0,
        ]);

        \HWE_Loyalty_Redemption::confirmOrder($order);

        // Simula el fallo al guardar las metas después del cargo: la clave ya existe.
        $order->delete_meta_data(\HWE_Loyalty_Redemption::META_REDEEMED_POINTS);
        $order->delete_meta_data(\HWE_Loyalty_Redemption::META_REDEEM_ATTEMPT);
        $order->delete_meta_data(\HWE_Loyalty_Earnings::META_DISCOUNT);

        \HWE_Loyalty_Redemption::confirmOrder($order);

        $this->assertCount(1, $this->entriesOf('redeem'));
        $this->assertSame(400, $this->balance());
        $this->assertSame(100, (int) $order->get_meta(\HWE_Loyalty_Redemption::META_REDEEMED_POINTS));
        $this->assertSame('1.00', (string) $order->get_meta(\HWE_Loyalty_Earnings::META_DISCOUNT));
    }

    public function test_turning_points_off_before_order_creation_removes_the_cart_fee_without_debiting(): void
    {
        $this->credit(500);
        $this->requestPoints(100);
        $order = $this->makeOrder(55, [
            'items' => [$this->lineItem(100.0)],
            'fees'  => [$this->feeItem(100, 1.0)],
            'total' => 99.0,
        ]);

        $this->setLoyalty(['points_enabled' => '0']);
        \HWE_Loyalty_Redemption::confirmOrder($order);

        $this->assertNull($this->entryWithKey('redeem:order:55'));
        $this->assertSame([], $order->get_fees());
        $this->assertSame(100.0, $order->get_total());
        $this->assertSame(500, $this->balance());
    }

    // ── Devolución del canje ─────────────────────────────────────────────────

    public function test_cancelled_order_refunds_the_points_once(): void
    {
        $points = $this->confirmedOrder(55);
        $this->assertSame(400, $this->balance());

        $order = $this->orders[55];
        $order->status = 'cancelled';
        \HWE_Loyalty_Redemption::onOrderCancelled(55);

        $this->assertSame($points, $this->balance());
        $entry = $this->entryWithKey('redeem_refund:order:55');
        $this->assertNotNull($entry);
        $this->assertSame(100, (int) $entry['points']);
        $this->assertSame('redeem_refund', (string) $entry['type']);
        $this->assertSame(0, (int) $order->get_meta(\HWE_Loyalty_Redemption::META_REDEEMED_POINTS));

        \HWE_Loyalty_Redemption::onOrderCancelled(55);

        $this->assertCount(1, $this->entriesOf('redeem_refund'));
        $this->assertSame($points, $this->balance(), 'La segunda notificación no reabona.');
    }

    public function test_failed_order_refunds_and_removes_the_discount_from_an_unpaid_order(): void
    {
        $this->confirmedOrder(55);
        $order = $this->orders[55];
        $order->status = 'failed';
        $order->paid   = false;
        $order->update_meta_data('_fees_hash', 'hash-con-el-fee');

        \HWE_Loyalty_Redemption::onOrderFailed(55);

        $this->assertNotNull($this->entryWithKey('redeem_refund:order:55'));
        $this->assertSame(500, $this->balance());
        $this->assertSame(0, (int) $order->get_meta(\HWE_Loyalty_Redemption::META_REDEEMED_POINTS));
        $this->assertSame([], $order->get_fees(), 'Un pedido fallido no puede reutilizarse con descuento.');
        $this->assertSame('', (string) $order->get_meta('_fees_hash'), 'El reintento debe recrear los fees desde el carrito.');
        $this->assertSame(100.0, $order->get_total());
        $this->assertSame('', (string) $order->get_meta(\HWE_Loyalty_Earnings::META_DISCOUNT));
    }

    public function test_refunded_paid_order_refunds_once_and_keeps_the_discount_as_history(): void
    {
        $this->confirmedOrder(55);
        $order = $this->orders[55];
        $order->status         = 'refunded';
        $order->paid           = true;
        $order->total_refunded = $order->get_total();

        \HWE_Loyalty_Redemption::onOrderRefunded(55);

        $this->assertNotNull($this->entryWithKey('redeem_refund:order:55'));
        $this->assertSame(500, $this->balance());
        $this->assertSame(0, (int) $order->get_meta(\HWE_Loyalty_Redemption::META_REDEEMED_POINTS));
        $this->assertSame('1.00', (string) $order->get_meta(\HWE_Loyalty_Earnings::META_DISCOUNT));
    }

    public function test_a_partial_refund_does_not_return_points(): void
    {
        $this->confirmedOrder(55);
        $order = $this->orders[55];
        $order->status         = 'refunded';
        $order->paid           = true;
        $order->total_refunded = 5.0;

        \HWE_Loyalty_Redemption::onOrderRefunded(55);

        $this->assertNull($this->entryWithKey('redeem_refund:order:55'));
        $this->assertSame(400, $this->balance());
        $this->assertSame(100, (int) $order->get_meta(\HWE_Loyalty_Redemption::META_REDEEMED_POINTS));
    }

    public function test_a_cancelled_order_still_refunds_when_the_points_meta_was_lost(): void
    {
        $this->confirmedOrder(55);
        $order = $this->orders[55];
        $order->delete_meta_data(\HWE_Loyalty_Redemption::META_REDEEMED_POINTS);
        $order->status = 'cancelled';

        \HWE_Loyalty_Redemption::onOrderCancelled(55);

        $this->assertNotNull($this->entryWithKey('redeem_refund:order:55'));
        $this->assertSame(500, $this->balance());
        $this->assertSame(0, (int) $order->get_meta(\HWE_Loyalty_Redemption::META_REDEEMED_POINTS));
    }

    public function test_a_cancelled_order_refunds_even_if_both_redeem_metas_were_lost(): void
    {
        $this->confirmedOrder(55);
        $order = $this->orders[55];
        $order->delete_meta_data(\HWE_Loyalty_Redemption::META_REDEEMED_POINTS);
        $order->delete_meta_data(\HWE_Loyalty_Redemption::META_REDEEM_ATTEMPT);
        $order->status = 'cancelled';

        \HWE_Loyalty_Redemption::onOrderCancelled(55);

        $this->assertNotNull($this->entryWithKey('redeem_refund:order:55'));
        $this->assertSame(500, $this->balance());
    }

    public function test_a_failed_order_can_redeem_again_on_retry_with_a_new_key(): void
    {
        $this->credit(500);
        $this->requestPoints(100);
        $order = $this->makeOrder(55, [
            'items' => [$this->lineItem(100.0)],
            'fees'  => [$this->feeItem(100, 1.0)],
            'total' => 99.0,
        ]);

        \HWE_Loyalty_Redemption::confirmOrder($order);
        $order->status = 'failed';
        $order->paid   = false;
        \HWE_Loyalty_Redemption::onOrderFailed(55);
        $this->assertSame(500, $this->balance());

        // El mismo borrador se reenvía: el canje se vuelve a confirmar con una clave nueva.
        $order->fee_items = [$this->feeItem(100, 1.0)];
        \HWE_Loyalty_Redemption::confirmOrder($order);

        $this->assertNotNull($this->entryWithKey('redeem:order:55:2'));
        $this->assertSame(400, $this->balance());
        $this->assertSame(100, (int) $order->get_meta(\HWE_Loyalty_Redemption::META_REDEEMED_POINTS));
    }

    /**
     * Deja un pedido 55 con un canje de 100 puntos confirmado y devuelve el saldo inicial.
     */
    private function confirmedOrder(int $orderId): int
    {
        $this->credit(500);
        $this->requestPoints(100);
        $order = $this->makeOrder($orderId, [
            'items' => [$this->lineItem(100.0)],
            'fees'  => [$this->feeItem(100, 1.0)],
            'total' => 99.0,
        ]);
        \HWE_Loyalty_Redemption::confirmOrder($order);

        return 500;
    }
}

/**
 * Contenedor mínimo de `WC()`: el carrito y la sesión que lee el canje.
 */
final class TestRedemptionWc
{
    public ?\WC_Cart $cart = null;
    public TestRedemptionSession $session;

    public function __construct()
    {
        $this->session = new TestRedemptionSession();
    }
}

/** Sesión de WooCommerce en memoria. */
final class TestRedemptionSession
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

/**
 * Doble de `$wpdb` para el canje: libro, saldos, consumos FIFO y búsqueda por clave de
 * idempotencia (la que usa la recuperación tras un fallo al guardar las metas del pedido).
 */
final class TestRedemptionWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
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
            } elseif (str_contains($sql, 'SET balance = %d')) {
                $row['balance'] = (int) ($args[0] ?? 0);
                $row['used']    = (int) ($args[1] ?? 0);
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

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_ledger')) {
            $key = (string) ($args[0] ?? '');
            foreach ($this->ledger as $row) {
                if ((string) $row['idempotency_key'] === $key) {
                    return (object) $row;
                }
            }
            return null;
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

        if (str_contains($sql, 'hwe_loyalty_ledger_consumptions')) {
            $debitId = (int) ($args[0] ?? 0);
            $rows    = [];
            foreach ($this->consumptions as $consumption) {
                if ((int) $consumption['debit_id'] !== $debitId) {
                    continue;
                }
                $credit = $this->ledger[(int) $consumption['credit_id']] ?? [];
                $rows[] = (object) [
                    'credit_id'  => (int) $consumption['credit_id'],
                    'points'     => (int) $consumption['points'],
                    'expires_at' => $credit['expires_at'] ?? null,
                ];
            }
            return $rows;
        }

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
