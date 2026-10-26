<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-order.php';

/**
 * Plugin `hwe-memberships`, integración con la base de ganancias de `loyalty`: un plan no
 * puntúa.
 *
 * Se prueba la DECISIÓN, no la UI: `hwe-loyalty` pregunta línea a línea con
 * `hwe_loyalty_line_earns` y `hwe-memberships` excluye los productos de membresía (también sus
 * variaciones) mientras la instancia no active «Los planes generan puntos de fidelización»
 * (`plans_earn_points`). Se comprueba la base real de `HWE_Loyalty_Earnings` con el filtro
 * enganchado por el puente, no una copia de la regla.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LoyaltyPlanEarningsTest extends TestCase
{
    private const NORMAL = 101;
    private const PLAN = 200;
    private const PLAN_VARIATION = 201;

    /** @var array<string,list<array{callback:callable,priority:int,accepted:int}>> */
    private array $hooks = [];
    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var list<string> */
    private array $logged = [];
    private bool $registered = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hooks = [];
        $this->config = [
            'memberships' => ['plans_earn_points' => false],
        ];
        $this->logged = [];
        $this->registered = false;

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            if ('hwe_config' === $name) {
                return $this->config;
            }
            if ('active_plugins' === $name) {
                return ['hwe-loyalty/hwe-loyalty.php', 'hwe-memberships/hwe-memberships.php'];
            }
            return $default;
        });
        // Vínculo de membresía: el plan lo tiene; la variación lo hereda de su padre.
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key, bool $single = true): mixed =>
                \HWE_Memberships_Products::LEVEL_META === $key && self::PLAN === $postId ? 10 : ''
        );
        Functions\when('wp_get_post_parent_id')->alias(
            static fn (int $postId): int => self::PLAN_VARIATION === $postId ? self::PLAN : 0
        );
        Functions\when('wc_get_price_decimals')->justReturn(2);
        Functions\when('hwe_loyalty_available')->justReturn(true);
        Functions\when('hwe_loyalty_credit')->justReturn(1);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        $this->fakeHookRegistry();

        $this->loadOwnFile('plugins/hwe-loyalty/includes/Earnings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Products.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Settings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/LoyaltyBridge.php');
    }

    // ── Base de ganancias ────────────────────────────────────────────────────

    public function test_a_plan_line_does_not_count_but_the_normal_product_does(): void
    {
        $this->bootBridge();

        $order = $this->order([
            new TestPlanLineItem(self::NORMAL, 1, 100.0, 0.0),
            new TestPlanLineItem(self::PLAN, 1, 50.0, 0.0),
        ]);

        $this->assertSame(
            100.0,
            \HWE_Loyalty_Earnings::orderBase($order),
            'La base solo cuenta el producto normal; el plan de membresía queda fuera.'
        );
    }

    public function test_an_order_with_only_a_plan_has_no_base_and_no_cashback(): void
    {
        $this->bootBridge();

        $order = $this->order([new TestPlanLineItem(self::PLAN, 1, 50.0, 0.0)]);

        $this->assertSame(0.0, \HWE_Loyalty_Earnings::orderBase($order));
    }

    public function test_a_variation_of_a_membership_product_does_not_count(): void
    {
        $this->bootBridge();

        // WooCommerce guarda el padre en `product_id` y la variación en `variation_id`. Aquí la
        // línea trae solo el id de la variación: el vínculo se resuelve por su padre.
        $order = $this->order([
            new TestPlanLineItem(self::NORMAL, 1, 100.0, 0.0),
            new TestPlanLineItem(self::PLAN_VARIATION, 1, 30.0, 0.0, self::PLAN_VARIATION),
        ]);

        $this->assertSame(
            100.0,
            \HWE_Loyalty_Earnings::orderBase($order),
            'La variación hereda el vínculo del producto padre y tampoco puntúa.'
        );
    }

    public function test_the_setting_makes_the_plan_count(): void
    {
        $this->config['memberships']['plans_earn_points'] = true;
        $this->bootBridge();

        $order = $this->order([
            new TestPlanLineItem(self::NORMAL, 1, 100.0, 0.0),
            new TestPlanLineItem(self::PLAN, 1, 50.0, 0.0),
        ]);

        $this->assertSame(
            150.0,
            \HWE_Loyalty_Earnings::orderBase($order),
            'Con «Los planes generan puntos» encendido, el plan vuelve a la base.'
        );
    }

    public function test_the_bridge_respects_an_earlier_filter_decision(): void
    {
        $this->bootBridge();

        // Otro módulo (o la instancia) ya excluyó el producto normal: el puente no lo reabre.
        add_filter(
            'hwe_loyalty_line_earns',
            static fn (bool $earns, object $item): bool => self::NORMAL !== $item->get_product_id(),
            5,
            3
        );

        $order = $this->order([new TestPlanLineItem(self::NORMAL, 1, 100.0, 0.0)]);

        $this->assertSame(0.0, \HWE_Loyalty_Earnings::orderBase($order));
    }

    // ── Utilidades ───────────────────────────────────────────────────────────

    private function bootBridge(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        \HWE_Memberships_LoyaltyBridge::register();
    }

    /** @param list<TestPlanLineItem> $items */
    private function order(array $items): \WC_Order
    {
        $order = new \WC_Order();
        $order->id = 55;
        $order->line_items = $items;

        return $order;
    }

    /**
     * Registro de hooks en memoria: `add_filter` guarda y `apply_filters` aplica de verdad la
     * cadena, que es lo que se quiere probar.
     */
    private function fakeHookRegistry(): void
    {
        Functions\when('add_filter')->alias(function (string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool {
            $this->hooks[$hook][] = ['callback' => $callback, 'priority' => $priority, 'accepted' => max(1, $accepted)];
            return true;
        });
        Functions\when('add_action')->justReturn(true);
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed ...$args): mixed {
            $callbacks = $this->hooks[$hook] ?? [];
            usort($callbacks, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);
            foreach ($callbacks as $entry) {
                $value = ($entry['callback'])($value, ...array_slice($args, 0, $entry['accepted'] - 1));
            }

            return $value;
        });
    }
}

/** Línea de pedido mínima: producto, variación, cantidad y totales. */
final class TestPlanLineItem
{
    public function __construct(
        private int $productId,
        private int $quantity,
        private float $total,
        private float $tax = 0.0,
        private int $variationId = 0
    ) {
    }

    public function get_product_id(): int
    {
        return $this->productId;
    }

    public function get_variation_id(): int
    {
        return $this->variationId;
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
