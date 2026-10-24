<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TestMembershipsServiceWpdb;

require_once HWE_BACKEND_DIR . '/tests/Stubs/wp-memberships-service-wpdb.php';
require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-order.php';
require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-cart.php';

/**
 * Plugin `hwe-memberships`, ciclo de vida al desactivar y activar (A-3).
 *
 * Con el plugin apagado no existe ninguna compuerta de compra, así que un producto de membresía
 * publicado se vendería y se cobraría sin conceder nada. Se prueba la DECISIÓN del ciclo:
 *  · desactivar retira de la venta los productos vinculados publicados y recuerda cuáles eran;
 *  · activar restaura solo esos y solo si siguen en el estado que puso el plugin (un cambio
 *    manual del administrador no se pisa);
 *  · la conciliación concede la membresía de un pedido cobrado durante el apagón una sola vez y
 *    repetirla no crea otra concesión;
 *  · la lista de productos retirados y el instante de la desactivación quedan en opciones para
 *    sobrevivir a la petición que apaga el plugin.
 *
 * El doble de `$wpdb` es el de `PurchaseTest`: la conciliación usa el `Purchase::processOrder`
 * real, con sus claves `order:<pedido>:item:<línea>` como única prueba de idempotencia.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LifecycleTest extends TestCase
{
    private const BUYER   = 7;
    private const LEVEL   = 101;
    private const PRODUCT = 501;
    private const OTHER   = 502;
    private const FREE    = 503;

    private int $now = 1_800_000_000;

    /** @var array<string,mixed> */
    private array $options = [];
    /** @var array<int,TestLifecycleProduct> */
    private array $products = [];
    /** @var array<int,\WP_Post> */
    private array $levelPosts = [];
    /** @var array<int,array<string,mixed>> */
    private array $postMeta = [];
    /** @var array<int,\WC_Order> */
    private array $orders = [];
    /** @var list<int> */
    private array $paidOrderIds = [];
    /** @var list<int> */
    private array $createdOrderIds = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var array<string,list<array{type:string,callback:callable,priority:int,accepted:int}>> */
    private array $hooks = [];
    /** @var list<array{0:string,1:mixed}> */
    private array $activationHooks = [];
    /** @var list<array{0:string,1:mixed}> */
    private array $deactivationHooks = [];
    private TestMembershipsServiceWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now             = 1_800_000_000;
        $this->options         = [];
        $this->products        = [];
        $this->levelPosts      = [];
        $this->postMeta        = [];
        $this->orders          = [];
        $this->paidOrderIds    = [];
        $this->createdOrderIds = [];
        $this->logged          = [];
        $this->hooks           = [];
        $this->activationHooks = [];
        $this->deactivationHooks = [];

        $this->wpdb      = new TestMembershipsServiceWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->loadOwnFile('plugins/hwe-memberships/includes/Schema.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Levels.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Timeline.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Events.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Members.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Grants.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Products.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Purchase.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Settings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Benefit.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/LoyaltyBridge.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/InfoPerk.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/EarlyAccess.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ActivationPoints.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ReferralBonus.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ReferralGift.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/MemberDiscount.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/FreeDeliveries.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Registry.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Lifecycle.php');
        \HWE_Memberships_Events::clear();

        $this->makeLevel(self::LEVEL, 5, true);
        $this->seedProduct(self::PRODUCT, self::LEVEL, 30);
        $this->seedProduct(self::OTHER, self::LEVEL, 0);

        $this->products[self::PRODUCT] = new TestLifecycleProduct(self::PRODUCT, 'publish');
        $this->products[self::OTHER]   = new TestLifecycleProduct(self::OTHER, 'publish');
        $this->products[self::FREE]    = new TestLifecycleProduct(self::FREE, 'publish');

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            return match ($name) {
                'hwe_config'     => [],
                'active_plugins' => ['hwe-memberships/hwe-memberships.php', 'hwe-loyalty/hwe-loyalty.php'],
                default          => $this->options[$name] ?? $default,
            };
        });
        Functions\when('update_option')->alias(function (string $name, mixed $value, mixed $autoload = null): bool {
            if (array_key_exists($name, $this->options) && $this->options[$name] === $value) {
                return false;
            }
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);
            return true;
        });
        Functions\when('get_post')->alias(fn (int $id) => $this->levelPosts[$id] ?? null);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->postMeta[$id][$key] ?? ''
        );
        Functions\when('get_userdata')->alias(
            fn (int $id) => self::BUYER === $id ? (object) ['ID' => $id] : false
        );
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        $this->hooks = [];
        Functions\when('add_action')->alias(function (string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool {
            $this->hooks[$hook][] = ['type' => 'action', 'callback' => $callback, 'priority' => $priority, 'accepted' => max(1, $accepted)];
            return true;
        });
        Functions\when('add_filter')->alias(function (string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool {
            $this->hooks[$hook][] = ['type' => 'filter', 'callback' => $callback, 'priority' => $priority, 'accepted' => max(1, $accepted)];
            return true;
        });
        Functions\when('do_action')->alias(function (string $hook, mixed ...$args): void {
            foreach ($this->callbacks($hook, 'action') as $entry) {
                ($entry['callback'])(...array_slice($args, 0, $entry['accepted']));
            }
        });
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed ...$args): mixed {
            foreach ($this->callbacks($hook, 'filter') as $entry) {
                $value = ($entry['callback'])($value, ...array_slice($args, 0, $entry['accepted'] - 1));
            }
            return $value;
        });
        Functions\when('get_posts')->alias(function (array $args): array {
            if ('product' !== ($args['post_type'] ?? '')) {
                return [];
            }

            $ids = [];
            foreach ($this->postMeta as $productId => $meta) {
                if (isset($meta[\HWE_Memberships_Products::LEVEL_META])
                    && 'publish' === $this->products[$productId]->get_status()) {
                    $ids[] = $productId;
                }
            }

            return $ids;
        });
        Functions\when('wc_get_product')->alias(fn (int $id) => $this->products[$id] ?? false);
        Functions\when('wc_get_orders')->alias(function (array $args): array {
            $ids = isset($args['date_paid']) ? $this->paidOrderIds : [];
            if (isset($args['date_created'])) {
                $ids = array_merge($ids, $this->createdOrderIds);
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $ids = array_values(array_diff($ids, array_map('intval', (array) ($args['exclude'] ?? []))));

            return array_slice($ids, 0, max(1, (int) ($args['limit'] ?? 0)));
        });
        Functions\when('wc_get_order')->alias(fn (int $id) => $this->orders[$id] ?? false);
        Functions\when('wc_get_is_paid_statuses')->alias(static fn (): array => ['processing', 'completed']);
        Functions\stubs([
            'register_activation_hook'  => function (string $file, mixed $callback): void {
                $this->activationHooks[] = [$file, $callback];
            },
            'register_deactivation_hook' => function (string $file, mixed $callback): void {
                $this->deactivationHooks[] = [$file, $callback];
            },
        ]);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
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

    private function makeLevel(int $id, int $rank, bool $purchasable): void
    {        $post                  = new \WP_Post();
        $post->ID              = $id;
        $post->post_type       = \HWE_Memberships_Levels::POST_TYPE;
        $post->post_status     = 'publish';
        $post->post_title      = 'Nivel ' . $id;
        $post->post_name       = 'nivel-' . $id;
        $post->post_content    = '';
        $this->levelPosts[$id] = $post;
        $this->postMeta[$id]   = [
            \HWE_Memberships_Levels::RANK_META        => (string) $rank,
            \HWE_Memberships_Levels::PURCHASABLE_META => $purchasable ? '1' : '0',
        ];
    }

    private function seedProduct(int $productId, int $levelId, int $days): void
    {
        $this->postMeta[$productId] = [
            \HWE_Memberships_Products::LEVEL_META => (string) $levelId,
            \HWE_Memberships_Products::DAYS_META  => (string) $days,
        ];
    }

    private function makeOrder(int $id, int $customerId, int $itemId, int $productId): \WC_Order
    {
        $order              = new \WC_Order();
        $order->id          = $id;
        $order->customer_id = $customerId;
        $order->status      = 'processing';
        $order->paid        = true;
        $order->total       = 100.0;
        $order->line_items  = [new \WC_Order_Item_Product($itemId, 100.0, 0.0, $productId, 1)];

        $this->orders[$id] = $order;

        return $order;
    }

    // ── Desactivación ───────────────────────────────────────────────────────

    public function testDeactivatingHidesPublishedMembershipProductsAndRemembersThem(): void
    {
        \HWE_Memberships_Lifecycle::onDeactivate();

        $this->assertSame('draft', $this->products[self::PRODUCT]->get_status(), 'El producto de membresía se retira de la venta.');
        $this->assertSame('draft', $this->products[self::OTHER]->get_status());
        $this->assertSame('publish', $this->products[self::FREE]->get_status(), 'Un producto normal no se toca.');

        $this->assertSame(
            [self::PRODUCT => 'publish', self::OTHER => 'publish'],
            $this->options[\HWE_Memberships_Lifecycle::OPTION_HIDDEN] ?? null
        );
        $this->assertSame($this->now, $this->options[\HWE_Memberships_Lifecycle::OPTION_DEACTIVATED_AT] ?? null);
        $this->assertSame([], array_filter($this->logged, static fn (string $line): bool => str_contains($line, '[hwe] memberships:') && str_contains($line, 'falló')));
    }

    public function testDeactivatingTwiceKeepsTheOriginalStatusesAndTheOldestInstant(): void
    {
        $this->options[\HWE_Memberships_Lifecycle::OPTION_HIDDEN] = [self::PRODUCT => 'publish'];
        $this->options[\HWE_Memberships_Lifecycle::OPTION_DEACTIVATED_AT] = $this->now - 500;
        $this->products[self::PRODUCT]->set_status('draft');

        \HWE_Memberships_Lifecycle::onDeactivate();

        $this->assertSame(
            [self::PRODUCT => 'publish', self::OTHER => 'publish'],
            $this->options[\HWE_Memberships_Lifecycle::OPTION_HIDDEN]
        );
        $this->assertSame($this->now - 500, $this->options[\HWE_Memberships_Lifecycle::OPTION_DEACTIVATED_AT]);
    }

    // ── Activación: restauración de productos ──────────────────────────────

    public function testActivatingRestoresOnlyTheProductsStillInTheStatusThePluginSet(): void
    {
        $this->options[\HWE_Memberships_Lifecycle::OPTION_HIDDEN] = [
            self::PRODUCT => 'publish',
            self::OTHER   => 'publish',
            self::FREE    => 'publish',
        ];
        $this->options[\HWE_Memberships_Lifecycle::OPTION_DEACTIVATED_AT] = $this->now - 60;

        $this->products[self::PRODUCT]->set_status('draft');
        $this->products[self::OTHER]->set_status('private');
        $this->products[self::FREE]->set_status('draft');

        $notTouched = new TestLifecycleProduct(999, 'draft');
        $this->products[999] = $notTouched;

        \HWE_Memberships_Lifecycle::onActivate();

        $this->assertSame('publish', $this->products[self::PRODUCT]->get_status(), 'El que sigue en borrador vuelve a publicarse.');
        $this->assertSame('private', $this->products[self::OTHER]->get_status(), 'El que el administrador cambió a mano no se pisa.');
        $this->assertSame('publish', $this->products[self::FREE]->get_status());
        $this->assertSame('draft', $notTouched->get_status(), 'Un producto que no está en la lista no se toca.');
        $this->assertArrayNotHasKey(\HWE_Memberships_Lifecycle::OPTION_HIDDEN, $this->options);
    }

    public function testActivatingSkipsADeletedProduct(): void
    {
        $this->options[\HWE_Memberships_Lifecycle::OPTION_HIDDEN] = [777 => 'publish'];

        \HWE_Memberships_Lifecycle::onActivate();

        $this->assertArrayNotHasKey(\HWE_Memberships_Lifecycle::OPTION_HIDDEN, $this->options);
        $this->assertSame([], $this->logged);
    }

    // ── Activación: conciliación de pedidos ────────────────────────────────

    public function testReconciliationGrantsAPaidMembershipOrderOnce(): void
    {
        $this->options[\HWE_Memberships_Lifecycle::OPTION_DEACTIVATED_AT] = $this->now - 3600;
        $order = $this->makeOrder(900, self::BUYER, 9, self::PRODUCT);
        $this->paidOrderIds = [900];

        \HWE_Memberships_Lifecycle::onActivate();

        $grant = $this->wpdb->grantByKey('order:900:item:9');
        $this->assertNotNull($grant, 'El pedido cobrado durante el apagón concede su membresía.');
        $this->assertSame(self::LEVEL, (int) $grant['level_id']);
        $this->assertSame('order', $grant['origin']);
        $this->assertSame('processed', $order->get_meta(\HWE_Memberships_Purchase::META_ORDER_STATE)['status'] ?? null);
        $this->assertCount(1, $this->wpdb->grants);

        // Una segunda activación (o un reintento manual) no vuelve a conceder.
        $this->options[\HWE_Memberships_Lifecycle::OPTION_DEACTIVATED_AT] = $this->now - 3600;
        \HWE_Memberships_Lifecycle::onActivate();

        $this->assertCount(1, $this->wpdb->grants, 'La clave por línea y el estado del pedido impiden la doble concesión.');
        $this->assertSame('processed', $order->get_meta(\HWE_Memberships_Purchase::META_ORDER_STATE)['status'] ?? null);
    }

    public function testReconciliationAlsoFindsAnOlderOrderPaidDuringTheWindow(): void
    {
        $this->options[\HWE_Memberships_Lifecycle::OPTION_DEACTIVATED_AT] = $this->now - 3600;
        $order = $this->makeOrder(901, self::BUYER, 10, self::OTHER);
        $this->paidOrderIds    = [];
        $this->createdOrderIds = [901];

        \HWE_Memberships_Lifecycle::onActivate();

        $this->assertNotNull($this->wpdb->grantByKey('order:901:item:10'));
        $this->assertSame('processed', $order->get_meta(\HWE_Memberships_Purchase::META_ORDER_STATE)['status'] ?? null);
    }

    public function testReconciliationRegistersTheBenefitsAndCreditsActivationPoints(): void
    {
        $this->postMeta[self::LEVEL][\HWE_Memberships_Levels::BENEFITS_META] = [
            'activation_points' => ['enabled' => true, 'points' => 50],
        ];
        $this->options[\HWE_Memberships_Lifecycle::OPTION_DEACTIVATED_AT] = $this->now - 3600;
        $this->makeOrder(902, self::BUYER, 11, self::PRODUCT);
        $this->paidOrderIds = [902];

        $credits = [];
        Functions\when('hwe_loyalty_available')->alias(static fn (string $feature): bool => 'points' === $feature);
        Functions\when('hwe_loyalty_credit')->alias(function (int $userId, int $points, string $type, string $key) use (&$credits): int {
            $credits[$key] = $points;
            return 1;
        });
        Functions\when('hwe_loyalty_reverse_credit')->alias(static fn (string $key, string $reversalKey): int => 1);

        \HWE_Memberships_Lifecycle::onActivate();

        $this->assertSame(
            50,
            $credits['membership:order:902:item:11'] ?? null,
            'La conciliación registra los beneficios y abona los puntos como el cobro normal.'
        );
    }

    public function testAFailingReconciliationDoesNotBlockActivation(): void
    {
        $this->options[\HWE_Memberships_Lifecycle::OPTION_HIDDEN] = [self::PRODUCT => 'publish'];
        $this->options[\HWE_Memberships_Lifecycle::OPTION_DEACTIVATED_AT] = $this->now - 3600;
        $this->products[self::PRODUCT]->set_status('draft');
        $this->paidOrderIds = [900];

        Functions\when('wc_get_orders')->alias(static function (array $args): array {
            throw new \RuntimeException('sin base de datos');
        });

        \HWE_Memberships_Lifecycle::onActivate();

        $this->assertSame('publish', $this->products[self::PRODUCT]->get_status(), 'La restauración se completa igual.');
        $this->assertArrayHasKey(\HWE_Memberships_Lifecycle::OPTION_DEACTIVATED_AT, $this->options, 'El fallo deja la ventana para reintentar.');
        $this->assertNotEmpty(array_filter($this->logged, static fn (string $line): bool => str_contains($line, 'la consulta de pedidos cobrados')));
    }

    // ── Hooks del archivo principal ────────────────────────────────────────

    public function testTheMainFileRegistersTheLifecycleHooks(): void
    {
        $this->loadOwnFile('plugins/hwe-memberships/hwe-memberships.php');

        $activation = array_values(array_filter(
            $this->activationHooks,
            static fn (array $hook): bool => [\HWE_Memberships_Lifecycle::class, 'onActivate'] === $hook[1]
        ));
        $deactivation = array_values(array_filter(
            $this->deactivationHooks,
            static fn (array $hook): bool => [\HWE_Memberships_Lifecycle::class, 'onDeactivate'] === $hook[1]
        ));

        $this->assertCount(1, $activation);
        $this->assertCount(1, $deactivation);
        $this->assertSame(
            realpath(HWE_WP_CONTENT_DIR . '/plugins/hwe-memberships/hwe-memberships.php'),
            realpath($activation[0][0])
        );
        $this->assertSame(
            realpath(HWE_WP_CONTENT_DIR . '/plugins/hwe-memberships/hwe-memberships.php'),
            realpath($deactivation[0][0])
        );
    }
}

/** Producto mínimo del ciclo de vida: id, estado y guardado. */
final class TestLifecycleProduct
{
    public int $saves = 0;

    public function __construct(
        private int $id,
        private string $status = 'publish'
    ) {
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_status(): string
    {
        return $this->status;
    }

    public function set_status(string $status): void
    {
        $this->status = $status;
    }

    public function save(): int
    {
        ++$this->saves;

        return $this->id;
    }
}
