<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TestMembershipsServiceWpdb;

require_once HWE_BACKEND_DIR . '/tests/Stubs/wp-memberships-service-wpdb.php';
require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-cart.php';
require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-shipping.php';
require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-order.php';

/**
 * Plugin `hwe-memberships`, envíos gratis por periodo (spec §7.3).
 *
 * Se prueba el DINERO y el CUPO, no la UI:
 *  · cada concesión activa aporta el cupo de su nivel y una renovación empieza con el suyo; el
 *    cupo no usado muere con la concesión que lo dio;
 *  · la reserva comprueba el disponible menos las reservas con la fila del socio bloqueada: dos
 *    pedidos simultáneos no gastan la misma unidad (Foco 2);
 *  · al crear el pedido se reserva, al cobrarse se consume y al cancelarse, fallar o reembolsarse
 *    del todo se devuelve; repetir los avisos de estado no consume dos veces (Foco 1);
 *  · si el envío ya salía a 0 por otra regla no se reserva cupo;
 *  · con el módulo `shipping` activo solo se usa el filtro estable (`hwe_shipping_free_reason`);
 *    sin él, se ponen a 0 las tarifas que no son de recogida por el id del método, nunca por la
 *    etiqueta. Sin `shipping`, o sin su filtro, nada se rompe (Foco 5).
 *
 * El doble de `$wpdb` mantiene las concesiones y los socios en memoria y simula las
 * transacciones con una foto del estado; el registro de hooks en memoria permite comprobar qué
 * queda enganchado en cada escenario sin cargar `shipping`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FreeDeliveriesTest extends TestCase
{
    private const USER = 7;
    private const OTHER_USER = 8;
    private const LEVEL = 101;

    private int $now = 1_800_000_000;

    /** @var list<string> */
    private array $activePlugins = ['hwe-memberships/hwe-memberships.php'];
    /** @var array<string,mixed> */
    private array $config = ['brand' => ['locale' => 'es']];
    /** @var array<int,object> */
    private array $users = [];
    /** @var array<int,\WP_Post> */
    private array $levelPosts = [];
    /** @var array<int,array<string,mixed>> */
    private array $levelMeta = [];
    /** @var array<int,\WC_Order> */
    private array $orders = [];
    /** @var array<string,list<array{type:string,callback:callable,priority:int,accepted:int}>> */
    private array $hooks = [];
    /** @var list<string> */
    private array $logged = [];
    private TestMembershipsServiceWpdb $wpdb;
    private TestFreeDeliveriesWc $wc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now           = 1_800_000_000;
        $this->activePlugins = ['hwe-memberships/hwe-memberships.php'];
        $this->config        = ['brand' => ['locale' => 'es']];
        $this->users         = [];
        $this->levelPosts    = [];
        $this->levelMeta     = [];
        $this->orders        = [];
        $this->hooks         = [];
        $this->logged        = [];

        $this->wpdb      = new TestMembershipsServiceWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        foreach ([self::USER, self::OTHER_USER] as $id) {
            $this->users[$id] = (object) [
                'ID'              => $id,
                'roles'           => ['customer'],
                'user_registered' => gmdate('Y-m-d H:i:s', $this->now - 90 * DAY_IN_SECONDS),
            ];
        }

        $this->wc = new TestFreeDeliveriesWc();

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            return match ($name) {
                'hwe_config'     => $this->config,
                'active_plugins' => $this->activePlugins,
                default          => $default,
            };
        });
        Functions\when('get_userdata')->alias(fn (int $id) => $this->users[$id] ?? false);
        Functions\when('get_post')->alias(fn (int $id) => $this->levelPosts[$id] ?? null);
        Functions\when('get_posts')->alias(function (array $args): array {
            if (\HWE_Memberships_Levels::POST_TYPE !== ($args['post_type'] ?? '')) {
                return [];
            }

            return array_values($this->levelPosts);
        });
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->levelMeta[$id][$key] ?? ''
        );
        Functions\when('get_current_user_id')->alias(fn (): int => self::USER);
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('WC')->alias(fn (): TestFreeDeliveriesWc => $this->wc);
        Functions\when('wc_get_order')->alias(fn (int $id) => $this->orders[$id] ?? false);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => trim((string) $value),
            'esc_html'            => static fn ($value) => (string) $value,
            'esc_html__'          => static fn ($value) => (string) $value,
            '__'                  => static fn ($value) => (string) $value,
        ]);

        $this->fakeHookRegistry();
        $this->loadMemberships();
        \HWE_Memberships_Events::clear();
        \HWE_Memberships_Benefit_Free_Deliveries::flushRateState();
    }

    // ------------------------------------------------------------------------
    //  Registro del tipo de beneficio
    // ------------------------------------------------------------------------

    public function testTheBenefitRegistersTheQuotaFieldAndHasNoDependency(): void
    {
        $benefit = \HWE_Memberships_Benefits::get('free_deliveries');

        $this->assertInstanceOf(\HWE_Memberships_Benefit_Free_Deliveries::class, $benefit);
        $this->assertSame('free_deliveries', $benefit->key());
        $this->assertNull($benefit->dependsOn(), 'Funciona sin el módulo shipping.');

        $fields = $benefit->fields();
        $this->assertArrayHasKey('count', $fields);
        $this->assertSame(\HWE_Memberships_Benefit::FIELD_NUMBER, $fields['count']['type']);
        $this->assertSame(0, $fields['count']['min']);

        $clean = \HWE_Memberships_Benefits::sanitizeLevelConfig([
            'free_deliveries' => ['enabled' => true, 'count' => -3],
        ]);
        $this->assertSame(0, $clean['free_deliveries']['count'], 'Una cantidad negativa se recorta a 0.');
        $this->assertTrue($clean['free_deliveries']['enabled']);

        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 3]]);
        $this->assertSame(
            ['key' => 'free_deliveries', 'count' => 3],
            $benefit->publicData(self::LEVEL)
        );
    }

    // ------------------------------------------------------------------------
    //  Cupo por concesión (§7.3)
    // ------------------------------------------------------------------------

    public function testTheQuotaComesFromTheActiveGrantAndRenewalStartsAFreshOne(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 2]]);
        $old = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:old');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();

        $quota = $benefit->quotaFor(self::USER);
        $this->assertSame(2, $quota['count']);
        $this->assertSame(2, $quota['remaining']);
        $this->assertTrue($quota['available']);
        $this->assertSame($old, $quota['grant_id']);

        $this->requestFreeDelivery(true);
        $this->assertTrue($benefit->reserveForOrder($this->appliedOrder(101)));

        $quota = $benefit->quotaFor(self::USER);
        $this->assertSame(1, $quota['remaining']);
        $this->assertSame(1, $this->wpdb->grants[$old]['shipping_quota_reserved']);

        // Renovación: la concesión anterior termina y la nueva empieza con su propio cupo.
        $this->wpdb->grants[$old]['status']  = 'ended';
        $this->wpdb->grants[$old]['ends_at'] = gmdate('Y-m-d H:i:s', $this->now - 60);
        $renewal = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:renewal');

        $quota = $benefit->quotaFor(self::USER);
        $this->assertSame($renewal, $quota['grant_id']);
        $this->assertSame(2, $quota['remaining'], 'El cupo no usado de la concesión vieja no se hereda.');
        $this->assertSame(1, (int) $this->wpdb->grants[$old]['shipping_quota_reserved'], 'La reserva vieja sigue en su concesión.');

        $this->assertTrue($benefit->reserveForOrder($this->appliedOrder(102)));
        $this->assertSame(1, (int) $this->wpdb->grants[$renewal]['shipping_quota_reserved']);
        $this->assertSame(1, $benefit->quotaFor(self::USER)['remaining']);
    }

    public function testTheLastUnitCannotBeSpentByTwoOrders(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 1]]);
        $grant  = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:one');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $this->requestFreeDelivery(true);

        $first = $this->appliedOrder(201);
        $this->assertTrue($benefit->reserveForOrder($first));

        $second = $this->appliedOrder(202);
        $error  = null;
        try {
            $benefit->reserveForOrder($second);
        } catch (RouteException $exception) {
            $error = $exception;
        }

        $this->assertInstanceOf(RouteException::class, $error, 'El segundo pedido no puede gastar la misma unidad.');
        $this->assertSame('hwe_membership_free_delivery_unavailable', $error->getErrorCode());
        $this->assertSame(409, $error->getHTTPStatusCode());
        $this->assertSame('', (string) $second->get_meta(\HWE_Memberships_Grants::META_FREE_DELIVERY_GRANT));
        $this->assertSame(1, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved']);

        // La comprobación y la escritura van en la misma transacción, con la fila del socio bloqueada.
        $queries = implode("\n", $this->wpdb->queries);
        $this->assertStringContainsString('START TRANSACTION', $queries);
        $this->assertStringContainsString('FOR UPDATE', $queries);
        $this->assertStringContainsString('COMMIT', $queries);
    }

    // ------------------------------------------------------------------------
    //  Reserva → consumo → devolución
    // ------------------------------------------------------------------------

    public function testReserveConsumeAndReleaseAreIdempotentPerOrder(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 2]]);
        $grant   = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:lifecycle');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $order   = $this->appliedOrder(301);
        $this->requestFreeDelivery(true);

        $this->assertTrue($benefit->reserveForOrder($order));
        $this->assertTrue($benefit->reserveForOrder($order), 'Reservar dos veces el mismo pedido no gasta otra unidad.');
        $this->assertSame(1, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved']);
        $this->assertSame(\HWE_Memberships_Grants::FREE_DELIVERY_RESERVED, (string) $order->get_meta(\HWE_Memberships_Grants::META_FREE_DELIVERY_STATE));
        $this->assertSame($grant, (int) $order->get_meta(\HWE_Memberships_Grants::META_FREE_DELIVERY_GRANT));

        $order->status = 'processing';
        $order->paid   = true;

        $benefit->onOrderCollected(301);
        $benefit->onOrderCollected(301);

        $this->assertSame(1, (int) $this->wpdb->grants[$grant]['shipping_quota_used']);
        $this->assertSame(0, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved']);
        $this->assertSame(\HWE_Memberships_Grants::FREE_DELIVERY_CONSUMED, (string) $order->get_meta(\HWE_Memberships_Grants::META_FREE_DELIVERY_STATE));

        $benefit->onOrderCancelled(301);
        $benefit->onOrderCancelled(301);

        $this->assertSame(0, (int) $this->wpdb->grants[$grant]['shipping_quota_used']);
        $this->assertSame(\HWE_Memberships_Grants::FREE_DELIVERY_RELEASED, (string) $order->get_meta(\HWE_Memberships_Grants::META_FREE_DELIVERY_STATE));
    }

    public function testRepeatedStatusNotificationsConsumeOnlyOnce(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 1]]);
        $grant   = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:repeat');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $order   = $this->appliedOrder(302);
        $this->requestFreeDelivery(true);

        $benefit->reserveForOrder($order);
        $order->status = 'processing';
        $order->paid   = true;

        // El mismo pedido notificado como processing, completed y payment_complete.
        $benefit->onOrderCollected(302);
        $order->status = 'completed';
        $benefit->onOrderCollected(302);
        $benefit->onOrderCollected(302);

        $this->assertSame(1, (int) $this->wpdb->grants[$grant]['shipping_quota_used']);
        $this->assertSame(0, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved']);

        // Una ida y vuelta de estado no devuelve lo consumido ni lo vuelve a consumir.
        $benefit->onOrderCancelled(302);
        $benefit->onOrderCollected(302);
        $this->assertSame(0, (int) $this->wpdb->grants[$grant]['shipping_quota_used']);
        $this->assertSame(\HWE_Memberships_Grants::FREE_DELIVERY_RELEASED, (string) $order->get_meta(\HWE_Memberships_Grants::META_FREE_DELIVERY_STATE));
    }

    /**
     * Dos avisos simultáneos del mismo pedido (redirect + webhook): el segundo cargó el pedido
     * ANTES de que el primero lo consumiera y espera el bloqueo del socio. Al obtenerlo debe
     * releer el pedido; si usara su copia vieja (`reserved`) consumiría la unidad dos veces.
     */
    public function testAConcurrentNotificationDoesNotConsumeTheSameOrderTwice(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 3]]);
        $grant = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:race-consume');
        $this->wpdb->grants[$grant]['shipping_quota_used'] = 1; // lo que dejó el primer aviso

        $fresh = $this->appliedOrder(320);
        $this->tagFreeDelivery($fresh, $grant, \HWE_Memberships_Grants::FREE_DELIVERY_CONSUMED);
        $stale = clone $fresh;
        $stale->update_meta_data(\HWE_Memberships_Grants::META_FREE_DELIVERY_STATE, \HWE_Memberships_Grants::FREE_DELIVERY_RESERVED);
        $this->serveStaleOrderFirst(320, $stale, $fresh);

        $this->assertTrue(\HWE_Memberships_Grants::consumeFreeDelivery(320));

        $this->assertSame(1, (int) $this->wpdb->grants[$grant]['shipping_quota_used'], 'No se consume dos veces.');
    }

    public function testAConcurrentCancellationDoesNotReleaseTheSameOrderTwice(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 3]]);
        $grant = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:race-release');
        $this->wpdb->grants[$grant]['shipping_quota_reserved'] = 1; // otro pedido distinto sigue reservado

        $fresh = $this->appliedOrder(321);
        $this->tagFreeDelivery($fresh, $grant, \HWE_Memberships_Grants::FREE_DELIVERY_RELEASED);
        $stale = clone $fresh;
        $stale->update_meta_data(\HWE_Memberships_Grants::META_FREE_DELIVERY_STATE, \HWE_Memberships_Grants::FREE_DELIVERY_RESERVED);
        $this->serveStaleOrderFirst(321, $stale, $fresh);

        $this->assertTrue(\HWE_Memberships_Grants::releaseFreeDelivery(321));

        $this->assertSame(1, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved'], 'La reserva de otro pedido no se toca.');
    }

    /**
     * La reserva se hace al preparar el pedido de la Store API, antes del cobro. Si el cliente
     * abandona tras un error del checkout, WooCommerce borra el borrador a las 24 h sin pasar por
     * `cancelled` ni `failed`: la unidad reservada debe volver al cupo al borrarse el pedido.
     */
    public function testDeletingAnUnpaidDraftOrderReturnsItsReservation(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 1]]);
        $grant   = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:draft');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $order   = $this->appliedOrder(330);
        $this->requestFreeDelivery(true);

        $benefit->reserveForOrder($order);
        $this->assertSame(1, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved']);

        $benefit->onOrderDeleted(330);

        $this->assertSame(0, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved']);
        $this->assertSame(1, $benefit->quotaFor(self::USER)['remaining']);
        $this->assertSame(\HWE_Memberships_Grants::FREE_DELIVERY_RELEASED, (string) $order->get_meta(\HWE_Memberships_Grants::META_FREE_DELIVERY_STATE));
    }

    public function testDeletingAPaidOrderKeepsItsConsumedUnit(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 1]]);
        $grant   = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:paid-delete');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $order   = $this->appliedOrder(331);
        $this->requestFreeDelivery(true);

        $benefit->reserveForOrder($order);
        $order->status = 'processing';
        $order->paid   = true;
        $benefit->onOrderCollected(331);

        $benefit->onOrderDeleted(331);

        $this->assertSame(1, (int) $this->wpdb->grants[$grant]['shipping_quota_used'], 'Un pedido cobrado ya gastó su envío.');
        $this->assertSame(\HWE_Memberships_Grants::FREE_DELIVERY_CONSUMED, (string) $order->get_meta(\HWE_Memberships_Grants::META_FREE_DELIVERY_STATE));
    }

    public function testCancelledOrFailedOrdersReturnTheReservation(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 2]]);
        $grant   = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:cancel');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $this->requestFreeDelivery(true);

        $cancelled = $this->appliedOrder(401);
        $benefit->reserveForOrder($cancelled);
        $benefit->onOrderCancelled(401);

        $this->assertSame(0, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved']);
        $this->assertSame(0, (int) $this->wpdb->grants[$grant]['shipping_quota_used']);
        $this->assertSame(\HWE_Memberships_Grants::FREE_DELIVERY_RELEASED, (string) $cancelled->get_meta(\HWE_Memberships_Grants::META_FREE_DELIVERY_STATE));

        $failed = $this->appliedOrder(402);
        $benefit->reserveForOrder($failed);
        $benefit->onOrderFailed(402);

        $this->assertSame(0, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved']);
        $this->assertSame(\HWE_Memberships_Grants::FREE_DELIVERY_RELEASED, (string) $failed->get_meta(\HWE_Memberships_Grants::META_FREE_DELIVERY_STATE));
    }

    public function testAFullRefundReturnsTheConsumedUnitAndAPartialOneDoesNot(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 1]]);
        $grant   = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:refund');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $order   = $this->appliedOrder(501);
        $order->total = 100.0;
        $this->requestFreeDelivery(true);

        $benefit->reserveForOrder($order);
        $order->status = 'processing';
        $order->paid   = true;
        $benefit->onOrderCollected(501);
        $this->assertSame(1, (int) $this->wpdb->grants[$grant]['shipping_quota_used']);

        $order->total_refunded = 30.0;
        $benefit->onOrderRefunded(501);
        $this->assertSame(1, (int) $this->wpdb->grants[$grant]['shipping_quota_used'], 'Un reembolso parcial no devuelve el envío.');

        $order->total_refunded = 100.0;
        $benefit->onOrderRefunded(501);
        $this->assertSame(0, (int) $this->wpdb->grants[$grant]['shipping_quota_used']);
        $this->assertSame(0, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved']);
        $this->assertSame(\HWE_Memberships_Grants::FREE_DELIVERY_RELEASED, (string) $order->get_meta(\HWE_Memberships_Grants::META_FREE_DELIVERY_STATE));
    }

    // ------------------------------------------------------------------------
    //  Cuándo NO se reserva (§7.3)
    // ------------------------------------------------------------------------

    public function testAnOrderAlreadyFreeByAnotherRuleDoesNotReserve(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 2]]);
        $grant   = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:already-free');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $this->requestFreeDelivery(true);

        // La regla de compra mínima de shipping ya lo dejó a 0: no se toca el cupo.
        $ruleFree = $this->appliedOrder(
            601,
            0.0,
            [\HWE_Memberships_Benefit_Free_Deliveries::SHIPPING_REASON_META => 'Compra mínima alcanzada']
        );
        $this->assertFalse($benefit->reserveForOrder($ruleFree));
        $this->assertSame(0, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved']);
        $this->assertSame('', (string) $ruleFree->get_meta(\HWE_Memberships_Grants::META_FREE_DELIVERY_GRANT));

        // El destino no admite envío gratis: el pedido se crea con su coste y tampoco reserva.
        $notEligible = $this->appliedOrder(602, 8.0, ['_hwe_free_shipping_reason' => 'Otra regla']);
        $this->assertFalse($benefit->reserveForOrder($notEligible));
        $this->assertSame(0, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved']);
        $this->assertSame('', (string) $notEligible->get_meta(\HWE_Memberships_Grants::META_FREE_DELIVERY_GRANT));
    }

    /**
     * WooCommerce guarda las tarifas YA filtradas (`woocommerce_package_rates`) en la sesión,
     * identificadas por un hash del paquete (contenido, cupones, destino, usuario). El envío gratis
     * depende de algo que el hash no ve —la petición del socio y el cupo—, así que sin marcarlo en
     * el paquete, pedir o quitar el envío gratis reutilizaba las tarifas viejas: pedirlo no lo
     * aplicaba, y quitarlo lo dejaba aplicado (un pedido con envío gratis sin reservar cupo).
     */
    public function testThePackageCarriesTheRequestSoWooCommerceDoesNotReuseStaleRates(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 1]]);
        $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:package');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $benefit->register();

        $callbacks = $this->callbacks('woocommerce_cart_shipping_packages', 'filter');
        $this->assertCount(1, $callbacks, 'El beneficio marca los paquetes de envío del carrito.');

        $packages = [['contents' => [], 'destination' => ['country' => 'CO']]];
        $mark     = static fn (array $in): array => ($callbacks[0]['callback'])($in);

        $this->requestFreeDelivery(false);
        $without = $mark($packages);

        $this->requestFreeDelivery(true);
        $with = $mark($packages);

        $this->assertNotSame($without, $with, 'Pedir el envío gratis cambia el paquete, y con él el hash de WooCommerce.');
        $this->assertSame($packages[0]['destination'], $with[0]['destination'], 'No se toca nada más del paquete.');

        // Quitar la petición devuelve el paquete original: las tarifas vuelven a calcularse.
        $this->requestFreeDelivery(false);
        $this->assertSame($without, $mark($packages));
    }

    public function testAnExhaustedQuotaChangesThePackageToo(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 1]]);
        $grant   = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:package-quota');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $benefit->register();
        $mark    = $this->callbacks('woocommerce_cart_shipping_packages', 'filter')[0]['callback'];
        $this->requestFreeDelivery(true);

        $packages  = [['contents' => []]];
        $available = $mark($packages);

        $this->wpdb->grants[$grant]['shipping_quota_used'] = 1;

        $this->assertNotSame($available, $mark($packages), 'Sin cupo, las tarifas ya no pueden salir gratis: otro hash.');
    }

    // ------------------------------------------------------------------------
    //  Integración con shipping (filtro estable)
    // ------------------------------------------------------------------------

    public function testWithShippingActiveOnlyTheStableFilterIsUsed(): void
    {
        if (!defined('HWE_SHIPPING_FREE_REASON_FILTER')) {
            define('HWE_SHIPPING_FREE_REASON_FILTER', \HWE_Memberships_Benefit_Free_Deliveries::SHIPPING_FILTER);
        }
        $this->activePlugins = ['hwe-memberships/hwe-memberships.php', 'hwe-shipping/hwe-shipping.php'];
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 2]]);
        $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:shipping');

        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $benefit->register();
        $this->requestFreeDelivery(true);

        $this->assertCount(1, $this->callbacks(\HWE_Memberships_Benefit_Free_Deliveries::SHIPPING_FILTER, 'filter'));
        $this->assertSame(
            [],
            $this->callbacks('woocommerce_package_rates', 'filter'),
            'Con shipping activo los precios de las tarifas los toca shipping, no membresías.'
        );

        $this->assertSame(
            \HWE_Memberships_Benefit_Free_Deliveries::FREE_REASON,
            \apply_filters(
                \HWE_Memberships_Benefit_Free_Deliveries::SHIPPING_FILTER,
                null,
                ['contents' => [], 'destination' => ['city' => 'Madrid']],
                ['rate_ids' => ['hwe_city_rate:opt11'], 'city' => 'eligible', 'qualifies' => false]
            ),
            'Solo las opciones elegibles del destino permiten que el motivo se devuelva.'
        );

        \HWE_Memberships_Benefit_Free_Deliveries::flushRateState();
        $this->assertNull(
            \apply_filters(
                \HWE_Memberships_Benefit_Free_Deliveries::SHIPPING_FILTER,
                null,
                ['destination' => ['city' => 'Pamplona']],
                ['rate_ids' => [], 'city' => 'not_eligible', 'qualifies' => false]
            ),
            'La zona de reserva no tiene opciones elegibles: no se aplica.'
        );

        // El motivo de otro módulo no se pisa.
        $this->assertSame(
            'otro-motivo',
            \apply_filters(
                \HWE_Memberships_Benefit_Free_Deliveries::SHIPPING_FILTER,
                'otro-motivo',
                [],
                ['rate_ids' => ['hwe_city_rate:opt11']]
            )
        );
    }

    public function testWithShippingActiveAnAppliedOrderReservesDuringCheckout(): void
    {
        if (!defined('HWE_SHIPPING_FREE_REASON_FILTER')) {
            define('HWE_SHIPPING_FREE_REASON_FILTER', \HWE_Memberships_Benefit_Free_Deliveries::SHIPPING_FILTER);
        }
        $this->activePlugins = ['hwe-memberships/hwe-memberships.php', 'hwe-shipping/hwe-shipping.php'];
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 1]]);
        $grant = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:shipping-order');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();

        $this->requestFreeDelivery(true);
        $order = $this->appliedOrder(
            611,
            0.0,
            [\HWE_Memberships_Benefit_Free_Deliveries::SHIPPING_REASON_META => \HWE_Memberships_Benefit_Free_Deliveries::FREE_REASON]
        );

        $this->assertTrue($benefit->reserveForOrder($order));
        $this->assertSame(1, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved']);
    }

    public function testWithoutShippingNonPickupRatesAreZeroedByMethodId(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 1]]);
        $grant   = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:noship');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $this->requestFreeDelivery(true);

        $pickup = new \WC_Shipping_Rate('local_pickup', 5.0);
        $flat   = new \WC_Shipping_Rate('flat_rate', 8.0);
        $rates  = $benefit->applyToRates(['pickup' => $pickup, 'flat' => $flat], ['destination' => ['city' => 'Madrid']]);

        $this->assertSame(5.0, $pickup->cost, 'La recogida se identifica por el id del método, no por el texto.');
        $this->assertSame(0.0, $flat->cost);
        $this->assertSame([], $flat->taxes);
        $this->assertSame(
            \HWE_Memberships_Benefit_Free_Deliveries::FREE_REASON,
            $flat->meta[\HWE_Memberships_Benefit_Free_Deliveries::RATE_META]
        );

        // La marca de la tarifa llega a la línea de envío del pedido y autoriza la reserva.
        $order = $this->appliedOrder(
            701,
            0.0,
            [\HWE_Memberships_Benefit_Free_Deliveries::RATE_META => \HWE_Memberships_Benefit_Free_Deliveries::FREE_REASON]
        );
        $this->assertTrue($benefit->reserveForOrder($order));
        $this->assertSame($grant, (int) $order->get_meta(\HWE_Memberships_Grants::META_FREE_DELIVERY_GRANT));
        $this->assertSame(1, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved']);
    }

    public function testWithShippingActiveButWithoutTheFilterNothingBreaks(): void
    {
        $this->activePlugins = ['hwe-memberships/hwe-memberships.php', 'hwe-shipping/hwe-shipping.php'];
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 1]]);
        $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:legacy-shipping');

        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $benefit->register();
        $this->requestFreeDelivery(true);

        $this->assertSame([], $this->callbacks(\HWE_Memberships_Benefit_Free_Deliveries::SHIPPING_FILTER, 'filter'));
        $this->assertSame([], $this->callbacks('woocommerce_package_rates', 'filter'), 'No se tocan las tarifas de shipping.');
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('no publica', implode(' ', $this->logged));
    }

    public function testShippingOffDoesNotBreakAndAppliesToRateCosts(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 1]]);
        $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:off');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $benefit->register();
        $this->requestFreeDelivery(true);

        $this->assertCount(1, $this->callbacks('woocommerce_package_rates', 'filter'));

        $flat = new \WC_Shipping_Rate('flat_rate', 8.0);
        $benefit->applyToRates(['flat' => $flat], []);
        $this->assertSame(0.0, $flat->cost);

        // El nivel se pierde con el carrito lleno: el siguiente recálculo vuelve a cobrar el envío.
        $grant = array_key_first($this->wpdb->grants);
        $this->wpdb->grants[$grant]['status']  = 'ended';
        $this->wpdb->grants[$grant]['ends_at'] = gmdate('Y-m-d H:i:s', $this->now - 60);
        \HWE_Memberships_Benefit_Free_Deliveries::flushRateState();

        $recalculated = new \WC_Shipping_Rate('flat_rate', 8.0);
        $benefit->applyToRates(['flat' => $recalculated], []);
        $this->assertSame(8.0, $recalculated->cost);
        $this->assertArrayNotHasKey(\HWE_Memberships_Benefit_Free_Deliveries::RATE_META, $recalculated->meta);
    }

    // ------------------------------------------------------------------------
    //  Extensión del carrito
    // ------------------------------------------------------------------------

    public function testTheExtensionPublishesTheQuotaAndTheApplicationState(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 2]]);
        $grant   = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:cart');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $this->requestFreeDelivery(true);

        $data = $benefit->cartData($this->cartPayload(true), ['user_id' => self::USER, 'cart' => $this->wc->cart, 'state' => []]);
        $this->assertSame(
            [
                'available' => true,
                'remaining' => 2,
                'requested' => true,
                'applied'   => true,
                'reason'    => \HWE_Memberships_Benefit_Free_Deliveries::FREE_REASON,
            ],
            $data['free_delivery']
        );

        // Si ya salía 0 por otra regla, se dice y no se aplica el beneficio.
        \HWE_Memberships_Benefit_Free_Deliveries::flushRateState();
        $zero = new \WC_Shipping_Rate('flat_rate', 0.0);
        $benefit->applyToRates(['zero' => $zero], []);
        $data = $benefit->cartData($this->cartPayload(true), ['user_id' => self::USER]);
        $this->assertFalse($data['free_delivery']['applied']);
        $this->assertSame('already_free', $data['free_delivery']['reason']);
        $this->assertSame(2, $data['free_delivery']['remaining']);

        // El nivel se pierde con el carrito lleno: el coste vuelve y el cupo deja de estar disponible.
        $this->wpdb->grants[$grant]['status']  = 'ended';
        $this->wpdb->grants[$grant]['ends_at'] = gmdate('Y-m-d H:i:s', $this->now - 60);
        $data = $benefit->cartData($this->cartPayload(true), ['user_id' => self::USER]);
        $this->assertFalse($data['free_delivery']['available']);
        $this->assertSame(0, $data['free_delivery']['remaining']);
        $this->assertFalse($data['free_delivery']['applied']);
        $this->assertSame('not_available', $data['free_delivery']['reason']);
    }

    public function testTheExtensionReadsTheCalculatedRatesToTellAnAlreadyFreeShipping(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 1]]);
        $grant   = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:rates');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $this->requestFreeDelivery(true);

        // La regla de compra mínima de shipping ya dejó la tarifa a 0: no se atribuye al cupo.
        $this->wc->shipping = new TestFreeDeliveriesShipping([
            ['rates' => ['hwe_city_rate:opt11' => new \WC_Shipping_Rate('hwe_city_rate', 0.0)]],
        ]);
        $data = $benefit->cartData($this->cartPayload(true), ['user_id' => self::USER]);
        $this->assertFalse($data['free_delivery']['applied']);
        $this->assertSame('already_free', $data['free_delivery']['reason']);
        $this->assertSame(1, $data['free_delivery']['remaining']);

        // La marca del propio beneficio sí se reconoce como aplicada.
        $applied = new \WC_Shipping_Rate('hwe_city_rate', 0.0);
        $applied->add_meta_data(\HWE_Memberships_Benefit_Free_Deliveries::SHIPPING_REASON_META, \HWE_Memberships_Benefit_Free_Deliveries::FREE_REASON);
        $this->wc->shipping = new TestFreeDeliveriesShipping([
            ['rates' => ['hwe_city_rate:opt11' => $applied]],
        ]);
        $data = $benefit->cartData($this->cartPayload(true), ['user_id' => self::USER]);
        $this->assertTrue($data['free_delivery']['applied']);
        $this->assertSame(\HWE_Memberships_Benefit_Free_Deliveries::FREE_REASON, $data['free_delivery']['reason']);
        $this->assertSame(1, $data['free_delivery']['remaining']);
        $this->assertSame(0, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved'], 'Una lectura nunca reserva.');
    }

    public function testAFreeShippingCouponDoesNotSpendTheQuota(): void
    {
        if (!defined('HWE_SHIPPING_FREE_REASON_FILTER')) {
            define('HWE_SHIPPING_FREE_REASON_FILTER', \HWE_Memberships_Benefit_Free_Deliveries::SHIPPING_FILTER);
        }
        $this->activePlugins = ['hwe-memberships/hwe-memberships.php', 'hwe-shipping/hwe-shipping.php'];
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 1]]);
        $grant   = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:coupon');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $this->requestFreeDelivery(true);
        \WC_Coupon::$free = ['GRATIS' => true];

        $this->wc->cart->applied_coupons = ['GRATIS'];
        $data = $benefit->cartData($this->cartPayload(true), ['user_id' => self::USER, 'cart' => $this->wc->cart]);
        $this->assertFalse($data['free_delivery']['applied'], 'El cupón ya pagaba el envío.');
        $this->assertSame('already_free', $data['free_delivery']['reason']);
        $this->assertSame(1, $data['free_delivery']['remaining']);

        $order = $this->appliedOrder(851);
        $order->coupon_codes = ['GRATIS'];
        $this->assertFalse($benefit->reserveForOrder($order));
        $this->assertSame(0, (int) $this->wpdb->grants[$grant]['shipping_quota_reserved']);

        \WC_Coupon::$free = [];
    }

    public function testAVirtualCartDoesNotApplyFreeDelivery(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 1]]);
        $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:virtual');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $this->requestFreeDelivery(true);

        $virtual = new class extends \WC_Cart {
            public function needs_shipping(): bool
            {
                return false;
            }
        };

        $data = $benefit->cartData($this->cartPayload(true), ['user_id' => self::USER, 'cart' => $virtual]);
        $this->assertTrue($data['free_delivery']['available'], 'El cupo existe aunque no haya nada que enviar.');
        $this->assertFalse($data['free_delivery']['applied']);
        $this->assertSame('not_eligible', $data['free_delivery']['reason']);
    }

    public function testAnExhaustedQuotaIsNotAvailableButTheCountIsKept(): void
    {
        $this->makeLevel(self::LEVEL, 5, ['free_deliveries' => ['enabled' => true, 'count' => 1]]);
        $grant   = $this->seedActiveGrant(self::USER, self::LEVEL, 'grant:exhausted');
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $this->requestFreeDelivery(true);

        $this->wpdb->grants[$grant]['shipping_quota_used'] = 1;

        $data = $benefit->cartData($this->cartPayload(true), ['user_id' => self::USER]);
        $this->assertFalse($data['free_delivery']['available']);
        $this->assertSame(0, $data['free_delivery']['remaining']);
        $this->assertSame('quota_exhausted', $data['free_delivery']['reason']);

        $order = $this->appliedOrder(801);
        try {
            $benefit->reserveForOrder($order);
            $this->fail('Sin cupo el checkout no debe reservar.');
        } catch (RouteException $exception) {
            $this->assertSame('hwe_membership_free_delivery_unavailable', $exception->getErrorCode());
        }
    }

    // ------------------------------------------------------------------------
    //  Caja del pedido
    // ------------------------------------------------------------------------

    public function testTheOrderBoxAddsOneFreeDeliveryRow(): void
    {
        $benefit = new \HWE_Memberships_Benefit_Free_Deliveries();
        $order   = new \WC_Order();
        $order->id = 900;
        $order->update_meta_data(\HWE_Memberships_Grants::META_FREE_DELIVERY_GRANT, 55);
        $order->update_meta_data(\HWE_Memberships_Grants::META_FREE_DELIVERY_STATE, \HWE_Memberships_Grants::FREE_DELIVERY_CONSUMED);
        $order->update_meta_data(\HWE_Memberships_Grants::META_FREE_DELIVERY_USER, self::USER);

        $rows = [
            ['label' => 'Free delivery grant', 'value' => '55', 'state' => 'info'],
            ['label' => 'Free delivery state', 'value' => 'consumed', 'state' => 'info'],
            ['label' => 'Free delivery user', 'value' => (string) self::USER, 'state' => 'info'],
            ['label' => 'Nivel', 'value' => 'Nivel 101', 'state' => 'granted'],
        ];

        $filtered = $benefit->orderBoxRows($rows, $order);

        $this->assertCount(2, $filtered);
        $this->assertSame('Nivel', $filtered[0]['label']);
        $this->assertSame('Envío gratis', $filtered[1]['label']);
        $this->assertStringContainsString('#55', (string) $filtered[1]['value']);
        $this->assertStringContainsString('Usado', (string) $filtered[1]['value']);
        $this->assertSame('consumed', $filtered[1]['state']);
    }

    // ------------------------------------------------------------------------
    //  Utilidades del caso
    // ------------------------------------------------------------------------

    /** Marca en el pedido el estado del envío gratis de miembro. */
    private function tagFreeDelivery(\WC_Order $order, int $grantId, string $state): void
    {
        $order->update_meta_data(\HWE_Memberships_Grants::META_FREE_DELIVERY_GRANT, $grantId);
        $order->update_meta_data(\HWE_Memberships_Grants::META_FREE_DELIVERY_STATE, $state);
        $order->update_meta_data(\HWE_Memberships_Grants::META_FREE_DELIVERY_USER, self::USER);
    }

    /** `wc_get_order()` devuelve primero la copia vieja de un pedido y después la actual. */
    private function serveStaleOrderFirst(int $orderId, \WC_Order $stale, \WC_Order $fresh): void
    {
        $calls = 0;
        Functions\when('wc_get_order')->alias(function (int $id) use (&$calls, $orderId, $stale, $fresh) {
            if ($id !== $orderId) {
                return $this->orders[$id] ?? false;
            }

            return 1 === ++$calls ? $stale : $fresh;
        });
    }

    private function makeLevel(int $id, int $rank, array $benefits = []): void
    {
        $post               = new \WP_Post();
        $post->ID           = $id;
        $post->post_type    = \HWE_Memberships_Levels::POST_TYPE;
        $post->post_status  = 'publish';
        $post->post_title   = 'Nivel ' . $id;
        $post->post_name    = 'nivel-' . $id;
        $post->post_content = 'Descripción ' . $id;
        $this->levelPosts[$id] = $post;
        $this->levelMeta[$id]  = [\HWE_Memberships_Levels::RANK_META => (string) $rank];
        if ([] !== $benefits) {
            $this->levelMeta[$id][\HWE_Memberships_Levels::BENEFITS_META] = $benefits;
        }
    }

    private function seedActiveGrant(int $userId, int $levelId, string $key): int
    {
        return $this->wpdb->seedGrant([
            'user_id'         => $userId,
            'level_id'        => $levelId,
            'idempotency_key' => $key,
            'status'          => 'active',
            'starts_at'       => gmdate('Y-m-d H:i:s', $this->now - 3600),
            'ends_at'         => gmdate('Y-m-d H:i:s', $this->now + 30 * DAY_IN_SECONDS),
        ]);
    }

    /** Pedido recién creado por la Store API, con la línea de envío que dejó el beneficio. */
    private function appliedOrder(int $id, float $shippingTotal = 0.0, array $shippingMeta = []): \WC_Order
    {
        $order                = new \WC_Order();
        $order->id            = $id;
        $order->customer_id   = self::USER;
        $order->status        = 'pending';
        $order->shipping_total = $shippingTotal;
        if ([] === $shippingMeta) {
            $shippingMeta = [\HWE_Memberships_Benefit_Free_Deliveries::RATE_META => \HWE_Memberships_Benefit_Free_Deliveries::FREE_REASON];
        }
        $order->shipping_items = [new \WC_Order_Item_Shipping($shippingMeta)];
        $this->orders[$id]     = $order;

        return $order;
    }

    /** @return array<string,mixed> Estado base que entrega `HWE_Memberships_StoreApi::cartState()`. */
    private function cartPayload(bool $requested): array
    {
        return [
            'level'           => null,
            'member_discount' => ['total' => '0', 'lines' => (object) []],
            'free_delivery'   => [
                'available' => false,
                'remaining' => 0,
                'requested' => $requested,
                'applied'   => false,
                'reason'    => null,
            ],
            'blocked_items'   => [],
            'purchase_notice' => null,
        ];
    }

    private function requestFreeDelivery(bool $requested): void
    {
        $this->wc->session->set(\HWE_Memberships_StoreApi::SESSION_FREE_DELIVERY, $requested);
    }

    /** Registro de hooks en memoria, como en el resto de tests del módulo. */
    private function fakeHookRegistry(): void
    {
        Functions\when('add_action')->alias(function (string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool {
            $this->hooks[$hook][] = ['type' => 'action', 'callback' => $callback, 'priority' => $priority, 'accepted' => max(1, $accepted)];
            return true;
        });
        Functions\when('add_filter')->alias(function (string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool {
            $this->hooks[$hook][] = ['type' => 'filter', 'callback' => $callback, 'priority' => $priority, 'accepted' => max(1, $accepted)];
            return true;
        });
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed ...$args): mixed {
            foreach ($this->callbacks($hook, 'filter') as $entry) {
                $value = ($entry['callback'])($value, ...array_slice($args, 0, $entry['accepted'] - 1));
            }
            return $value;
        });
    }

    /** @return list<array{type:string,callback:callable,priority:int,accepted:int}> */
    private function callbacks(string $hook, string $type): array
    {
        return array_values(array_filter(
            $this->hooks[$hook] ?? [],
            static fn (array $entry): bool => $type === $entry['type']
        ));
    }

    private function loadMemberships(): void
    {
        $this->loadOwnFile('plugins/hwe-memberships/includes/Settings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Schema.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Levels.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Timeline.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Events.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Members.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Grants.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Benefit.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/InfoPerk.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/EarlyAccess.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ActivationPoints.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ReferralBonus.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ReferralGift.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/MemberDiscount.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/FreeDeliveries.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Registry.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Purchase.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/StoreApi.php');
    }
}

/** `WC()` mínimo: sesión de WooCommerce (petición de envío gratis) y carrito. */
final class TestFreeDeliveriesWc
{
    public TestFreeDeliveriesSession $session;
    public \WC_Cart $cart;
    public ?TestFreeDeliveriesShipping $shipping = null;

    public function __construct()
    {
        $this->session = new TestFreeDeliveriesSession();
        $this->cart    = new \WC_Cart([]);
    }
}

/** `WC()->shipping` mínimo: paquetes ya calculados con sus tarifas. */
final class TestFreeDeliveriesShipping
{
    /** @param list<array<string,mixed>> $packages */
    public function __construct(private array $packages)
    {
    }

    /** @return list<array<string,mixed>> */
    public function get_packages(): array
    {
        return $this->packages;
    }
}

final class TestFreeDeliveriesSession
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
