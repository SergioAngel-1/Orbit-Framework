<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Instance;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-order.php';
require_once HWE_BACKEND_DIR . '/tests/Stubs/wp-memberships-benefits-fakes.php';

/**
 * Beneficio de membresía de instancia del ejemplo (spec §7.7).
 *
 * El tipo se registra por `hwe_memberships_benefit_types`, se configura por nivel (cada cuántos
 * pedidos cobrados y con qué nota privada) y solo actúa con el beneficio activo y el módulo
 * encendido: deja una nota PRIVADA en el pedido que completa cada tramo y una marca en su meta
 * para no repetirla cuando el mismo pedido se notifica otra vez (`processing` → `completed`).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ExampleMembershipBenefitTest extends TestCase
{
    private const BUYER = 41;
    private const LEVEL = 301;
    private const KEY   = 'example_order_gift';
    private const META  = '_example_membership_gift_note';

    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var list<string> Plugins activos según `active_plugins`. */
    private array $activePlugins = ['hwe-memberships/hwe-memberships.php'];
    /** @var array<int,\WC_Order> Pedidos que devuelve `wc_get_order`. */
    private array $orders = [];
    /** @var array<string,list<array{type:string,callback:callable,priority:int,accepted:int}>> */
    private array $hooks = [];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->config        = ['brand' => ['locale' => 'es'], 'i18n' => ['locales' => '']];
        $this->activePlugins = ['hwe-memberships/hwe-memberships.php'];
        $this->orders        = [];
        $this->hooks         = [];
        $this->logged        = [];
        \HWE_Memberships_Levels::$configs = [];
        \HWE_Memberships_Members::$states = [];

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            return match ($name) {
                'hwe_config'     => $this->config,
                'active_plugins' => $this->activePlugins,
                default          => $default,
            };
        });
        Functions\when('wc_get_order')->alias(fn (int $id) => $this->orders[$id] ?? false);
        Functions\when('wc_get_orders')->alias(function (array $args = []): array|object {
            $statuses = array_map('strval', (array) ($args['status'] ?? []));
            $customer = (int) ($args['customer_id'] ?? 0);
            $ids      = [];

            foreach ($this->orders as $id => $order) {
                if ((int) $order->get_customer_id() !== $customer) {
                    continue;
                }
                if ([] !== $statuses && !in_array((string) $order->get_status(), $statuses, true)) {
                    continue;
                }
                $ids[] = $id;
            }

            if (empty($args['paginate'])) {
                return $ids;
            }

            return (object) [
                'orders'        => array_slice($ids, 0, max(1, (int) ($args['limit'] ?? 10))),
                'total'         => count($ids),
                'max_num_pages' => 1,
            ];
        });
        Functions\when('add_action')->alias(function (string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool {
            $this->hooks[$hook][] = [
                'type'     => 'action',
                'callback' => $callback,
                'priority' => $priority,
                'accepted' => max(1, $accepted),
            ];

            return true;
        });
        Functions\when('add_filter')->alias(function (string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool {
            $this->hooks[$hook][] = [
                'type'     => 'filter',
                'callback' => $callback,
                'priority' => $priority,
                'accepted' => max(1, $accepted),
            ];

            return true;
        });
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed ...$args): mixed {
            foreach ($this->callbacks($hook, 'filter') as $entry) {
                $value = ($entry['callback'])($value, ...array_slice($args, 0, $entry['accepted'] - 1));
            }

            return $value;
        });
        Functions\when('do_action')->alias(function (string $hook, mixed ...$args): void {
            foreach ($this->callbacks($hook, 'action') as $entry) {
                ($entry['callback'])(...array_slice($args, 0, $entry['accepted']));
            }
        });
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;

            return true;
        });
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => trim((string) $value),
            '__'                  => static fn ($value) => (string) $value,
        ]);

        // El mu-plugin de instancia se carga ANTES que el plugin del módulo, como en WordPress:
        // su clase se declara de forma perezosa dentro del filtro.
        $this->loadOwnFile('mu-plugins/instance/70-example-membership-benefit.php');
        $this->loadMemberships();
    }

    // ── Registro y configuración por nivel ──────────────────────────────────

    public function testElTipoDelEjemploSeRegistraYSeConfiguraPorNivel(): void
    {
        $this->assertFalse(
            class_exists(\HWE_Example_Membership_Order_Gift::class, false),
            'La clase se declara al aplicar el filtro, no al cargar el mu-plugin.'
        );

        $all = \HWE_Memberships_Benefits::all();

        $this->assertArrayHasKey(self::KEY, $all);
        $benefit = $all[self::KEY];
        $this->assertInstanceOf(\HWE_Example_Membership_Order_Gift::class, $benefit);
        $this->assertSame(self::KEY, $benefit->key());
        $this->assertSame(['orders', 'note'], array_keys($benefit->fields()));

        $clean = \HWE_Memberships_Benefits::sanitizeLevelConfig([
            self::KEY => [
                'enabled' => '1',
                'orders'  => '0',
                'note'    => '  Pedido de regalo  ',
                'sobra'   => 'se descarta',
            ],
        ]);

        $this->assertTrue($clean[self::KEY]['enabled']);
        $this->assertSame(1, $clean[self::KEY]['orders'], 'Un número por debajo del mínimo se recorta.');
        $this->assertSame('Pedido de regalo', $clean[self::KEY]['note']);
        $this->assertArrayNotHasKey('sobra', $clean[self::KEY], 'Un campo no declarado no se guarda.');

        \HWE_Memberships_Levels::$configs[self::LEVEL] = [
            self::KEY => ['enabled' => true, 'orders' => 4, 'note' => 'Pedido de regalo'],
        ];

        $this->assertSame(
            ['key' => self::KEY, 'orders' => 4],
            $benefit->publicData(self::LEVEL),
            'El dato público son los pedidos que hacen falta, sin frases.'
        );
    }

    // ── Ciclo del pedido ────────────────────────────────────────────────────

    public function testDejaUnaNotaPrivadaCadaDosPedidosCobradosYNoLaRepite(): void
    {
        $this->activateBenefit(['enabled' => true, 'orders' => 2, 'note' => 'Pedido de regalo']);
        \HWE_Memberships_Benefits::register();
        $this->assertArrayHasKey('woocommerce_order_status_processing', $this->hooks);

        $first = $this->makeOrder(55);
        \do_action('woocommerce_order_status_processing', 55);
        $this->assertSame([], $first->notes, 'Con un solo pedido cobrado todavía no toca.');

        $second = $this->makeOrder(56);
        \do_action('woocommerce_order_status_processing', 56);

        $this->assertCount(1, $second->notes);
        $this->assertSame('Pedido de regalo', $second->notes[0]['note']);
        $this->assertFalse($second->notes[0]['is_customer_note'], 'La nota es privada, no del cliente.');

        $mark = $second->get_meta(self::META);
        $this->assertIsArray($mark);
        $this->assertSame(self::LEVEL, $mark['level_id']);
        $this->assertSame(2, $mark['orders']);
        $this->assertSame(2, $mark['every']);

        // El mismo pedido se notifica otra vez al pasar a completed: no repite la nota.
        \do_action('woocommerce_order_status_completed', 56);
        $this->assertCount(1, $second->notes);
        $this->assertSame([], $first->notes, 'El pedido anterior no se toca.');
    }

    public function testUnPedidoNoCobradoNoDejaNota(): void
    {
        $this->activateBenefit(['enabled' => true, 'orders' => 1, 'note' => 'Pedido de regalo']);
        \HWE_Memberships_Benefits::register();

        $order = $this->makeOrder(57, 'processing', false);
        \do_action('woocommerce_order_status_processing', 57);

        $this->assertSame([], $order->notes);
        $this->assertSame('', $order->get_meta(self::META));
    }

    public function testConElBeneficioApagadoEnElNivelNoDejaNota(): void
    {
        $this->activateBenefit(['enabled' => false, 'orders' => 1, 'note' => 'Pedido de regalo']);
        \HWE_Memberships_Benefits::register();

        $benefit = \HWE_Memberships_Benefits::get(self::KEY);
        $this->assertNotNull($benefit);
        $this->assertFalse($benefit->isActiveFor(self::BUYER));

        $order = $this->makeOrder(58);
        \do_action('woocommerce_order_status_processing', 58);

        $this->assertSame([], $order->notes);
    }

    public function testConElModuloApagadoNoSeEnganchaNiActua(): void
    {
        $this->activateBenefit(['enabled' => true, 'orders' => 1, 'note' => 'Pedido de regalo']);
        $this->activePlugins = [];
        \HWE_Memberships_Benefits::register();

        $this->assertArrayNotHasKey('woocommerce_order_status_processing', $this->hooks);
        $this->assertArrayNotHasKey('woocommerce_order_status_completed', $this->hooks);

        $benefit = \HWE_Memberships_Benefits::get(self::KEY);
        $this->assertNotNull($benefit);
        $this->assertFalse($benefit->isActiveFor(self::BUYER));

        // Aunque otra vía llame al manejador, el tipo no actúa sin el módulo.
        $order = $this->makeOrder(59);
        $benefit->onOrderCollected(59);

        $this->assertSame([], $order->notes);
    }

    // ── Utilidades del caso ─────────────────────────────────────────────────

    /** @param array<string,mixed> $config */
    private function activateBenefit(array $config): void
    {
        \HWE_Memberships_Levels::$configs[self::LEVEL] = [self::KEY => $config];
        \HWE_Memberships_Members::$states[self::BUYER] = [
            'level_id'  => self::LEVEL,
            'rank'      => 5,
            'status'    => \HWE_Memberships_Members::STATUS_ACTIVE,
            'ends_at'   => null,
            'queue'     => [],
            'grant_key' => 'grant:example',
        ];
    }

    private function makeOrder(int $id, string $status = 'processing', bool $paid = true): \WC_Order
    {
        $order              = new \WC_Order();
        $order->id          = $id;
        $order->customer_id = self::BUYER;
        $order->status      = $status;
        $order->paid        = $paid;
        $this->orders[$id]  = $order;

        return $order;
    }

    /** @return list<array{type:string,callback:callable,priority:int,accepted:int}> */
    private function callbacks(string $hook, string $type): array
    {
        $list = array_values(array_filter(
            $this->hooks[$hook] ?? [],
            static fn (array $entry): bool => $type === $entry['type']
        ));

        usort($list, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $list;
    }

    private function loadMemberships(): void
    {
        $this->loadOwnFile('plugins/hwe-memberships/includes/Settings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Benefit.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/InfoPerk.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/EarlyAccess.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ActivationPoints.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ReferralBonus.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ReferralGift.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Registry.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Purchase.php');
    }
}
