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
require_once HWE_WP_CONTENT_DIR . '/plugins/hwe-loyalty/includes/EntryTypes.php';
require_once HWE_WP_CONTENT_DIR . '/plugins/hwe-loyalty/includes/Earnings.php';

/**
 * Plugin `hwe-memberships` (módulo `memberships`), beneficios que dependen de `loyalty`
 * (spec §7.4, §7.5 y §8).
 *
 * Se prueba la DECISIÓN, no la UI:
 *  · los puntos por activación se abonan una sola vez por línea de pedido cobrada, nunca por
 *    un regalo ni por una asignación de administrador, se revierten con la concesión y, si
 *    «Puntos» está apagado en el cobro, dejan constancia en el historial y no se reintentan;
 *  · la comisión extra del referidor entra por `hwe_loyalty_order_earnings` con el tipo propio
 *    y el nivel que el referidor tiene EN ESE MOMENTO, y `loyalty` la acepta y la revierte como
 *    cualquier otra ganancia del pedido;
 *  · el regalo de membresía lo recibe el referido, una sola vez, con el tope del periodo de la
 *    concesión activa del referidor y en la misma transacción (dos verificaciones seguidas no
 *    pasan del tope), solo si el referidor sigue activo y con el beneficio;
 *  · con `loyalty` apagado no queda ningún hook de membresías en sus hooks y los tres
 *    beneficios no existen para el cliente; con membresías apagado no se registra nada.
 *
 * El doble de `$wpdb` mantiene concesiones, socios e historial en memoria; las funciones del
 * contrato de `loyalty` se simulan con `Functions\when()` (existen solo con el plugin cargado)
 * y guardan sus abonos para comprobar la idempotencia real por clave. `HWE_Loyalty_EntryTypes`
 * y `HWE_Loyalty_Earnings::normalizeEarnings()` son los reales: así se comprueba que el tipo
 * propio se declara como abono y que la ganancia sobrevive al saneado de `loyalty`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LoyaltyBenefitsTest extends TestCase
{
    private const REFERRER = 20;
    private const REFERRER2 = 26;
    private const REFEREE  = 21;
    private const REFEREE2 = 22;
    private const BUYER    = 23;
    private const VISITOR  = 24;
    private const ADMIN    = 25;

    private const LEVEL_BASIC = 101; // rango 5
    private const LEVEL_HIGH  = 102; // rango 10
    private const LEVEL_GIFT  = 103; // rango 7

    private int $now = 1_800_000_000;

    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var list<string> Plugins activos según `active_plugins`. */
    private array $activePlugins = [];
    /** @var array<int,object> Usuarios que devuelve `get_userdata`. */
    private array $users = [];
    /** @var array<int,array<string,mixed>> Meta de usuario. */
    private array $userMeta = [];
    /** @var array<int,\WP_Post> */
    private array $levelPosts = [];
    /** @var array<int,array<string,mixed>> */
    private array $levelMeta = [];
    /** @var array<string,list<array{type:string,callback:callable,priority:int,accepted:int}>> */
    private array $hooks = [];
    /** @var list<string> */
    private array $logged = [];
    private bool $booted = false;
    private TestMembershipsServiceWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now           = 1_800_000_000;
        $this->config        = [
            'brand'       => ['locale' => 'es'],
            'memberships' => ['referral_gift_requires_email_verification' => true],
        ];
        $this->activePlugins = [
            'hwe-memberships/hwe-memberships.php',
            'hwe-loyalty/hwe-loyalty.php',
        ];
        $this->userMeta   = [];
        $this->levelPosts = [];
        $this->levelMeta  = [];
        $this->hooks      = [];
        $this->logged     = [];
        $this->booted     = false;

        $GLOBALS['hwe_test_loyalty'] = [
            'loaded'       => true,
            'points'       => true,
            'referrals'    => true,
            'referrers'    => [],
            'credit_calls' => [],
            'credits'      => [],
            'reverse_calls' => [],
            'reversals'    => [],
        ];

        $this->wpdb      = new TestMembershipsServiceWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->users = [];
        foreach ([self::REFERRER, self::REFERRER2, self::REFEREE, self::REFEREE2, self::BUYER, self::VISITOR, self::ADMIN] as $id) {
            $this->users[$id] = (object) [
                'ID'              => $id,
                'roles'           => ['customer'],
                'user_registered' => gmdate('Y-m-d H:i:s', $this->now - 90 * DAY_IN_SECONDS),
            ];
        }
        $this->users[self::ADMIN]->roles = ['administrator'];

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            return match ($name) {
                'hwe_config'     => $this->config,
                'active_plugins' => $this->activePlugins,
                default          => $default,
            };
        });
        Functions\when('get_userdata')->alias(
            fn (int $id) => $this->users[$id] ?? false
        );
        Functions\when('get_user_meta')->alias(function (int $userId, string $key = '', bool $single = false): mixed {
            if ('' === $key) {
                return $this->userMeta[$userId] ?? [];
            }

            return $single ? ($this->userMeta[$userId][$key] ?? '') : [$this->userMeta[$userId][$key] ?? ''];
        });
        Functions\when('update_user_meta')->alias(function (int $userId, string $key, mixed $value): bool {
            $this->userMeta[$userId][$key] = $value;
            return true;
        });
        Functions\when('delete_user_meta')->alias(function (int $userId, string $key): bool {
            unset($this->userMeta[$userId][$key]);
            return true;
        });
        Functions\when('get_post')->alias(fn (int $id) => $this->levelPosts[$id] ?? null);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->levelMeta[$id][$key] ?? ''
        );
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => trim((string) $value),
            'esc_html'            => static fn ($value) => (string) $value,
            'esc_html__'          => static fn ($value) => $value,
            '__'                  => static fn ($value) => $value,
        ]);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });

        $this->fakeLoyaltyContract();
        $this->fakeHookRegistry();
        $this->loadMemberships();
        \HWE_Memberships_Events::clear();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['hwe_test_loyalty']);
        parent::tearDown();
    }

    // ------------------------------------------------------------------------
    //  Puntos por activación (§7.4)
    // ------------------------------------------------------------------------

    public function testAnOrderGrantCreditsActivationPointsOncePerLine(): void
    {
        $this->makeLevel(
            self::LEVEL_BASIC,
            5,
            ['activation_points' => ['enabled' => true, 'points' => 50]]
        );
        $this->bootBenefits();

        $grantId = \HWE_Memberships_Grants::grant(
            self::BUYER,
            self::LEVEL_BASIC,
            30,
            'order',
            'order:55:item:9',
            ['type' => 'order', 'id' => 55]
        );

        $this->assertIsInt($grantId);
        $credits = $GLOBALS['hwe_test_loyalty']['credits'];
        $this->assertArrayHasKey('membership:order:55:item:9', $credits);
        $this->assertSame(50, $credits['membership:order:55:item:9']['points']);
        $this->assertSame('membership_activation', $credits['membership:order:55:item:9']['type']);
        $this->assertSame(['type' => 'order', 'id' => 55], $credits['membership:order:55:item:9']['ref']);
        $this->assertSame($grantId, $credits['membership:order:55:item:9']['data']['grant']);
        $this->assertSame(self::LEVEL_BASIC, $credits['membership:order:55:item:9']['data']['level_id']);

        // El pedido se notifica otra vez (misma línea): la clave repetida no abona dos veces.
        $again = \HWE_Memberships_Grants::grant(
            self::BUYER,
            self::LEVEL_BASIC,
            30,
            'order',
            'order:55:item:9',
            ['type' => 'order', 'id' => 55]
        );
        $this->assertNull($again, 'La concesión ya existía.');
        $this->assertCount(1, $GLOBALS['hwe_test_loyalty']['credits']);

        // Un aviso repetido de la acción tampoco encuentra saldo nuevo que abonar.
        \do_action('hwe_membership_activated', self::BUYER, $grantId, self::LEVEL_BASIC);
        $this->assertCount(1, $GLOBALS['hwe_test_loyalty']['credits']);
        $this->assertCount(2, $GLOBALS['hwe_test_loyalty']['credit_calls']);
    }

    public function testADeferredOrderGrantStillCreditsItsPointsAtCharge(): void
    {
        $this->makeLevel(
            self::LEVEL_BASIC,
            5,
            ['activation_points' => ['enabled' => true, 'points' => 50]]
        );
        $this->makeLevel(
            self::LEVEL_HIGH,
            10,
            ['activation_points' => ['enabled' => true, 'points' => 90]]
        );
        $this->bootBenefits();

        // Una cobertura superior ya activa (asignada por el equipo) deja la compra en cola.
        \HWE_Memberships_Grants::grant(self::BUYER, self::LEVEL_HIGH, 30, 'admin', 'admin:high', [], self::ADMIN);
        $this->assertSame([], $GLOBALS['hwe_test_loyalty']['credits'], 'La asignación de administrador no abona.');

        $grantId = \HWE_Memberships_Grants::grant(
            self::BUYER,
            self::LEVEL_BASIC,
            15,
            'order',
            'order:56:item:1',
            ['type' => 'order', 'id' => 56]
        );

        $this->assertIsInt($grantId);
        $this->assertContains('deferred', $this->wpdb->eventTypes());
        $this->assertArrayHasKey('membership:order:56:item:1', $GLOBALS['hwe_test_loyalty']['credits']);
        $this->assertSame(
            50,
            $GLOBALS['hwe_test_loyalty']['credits']['membership:order:56:item:1']['points'],
            'Se abona el nivel comprado, no el nivel efectivo que ya tenía.'
        );
    }

    public function testTheDailyActivationOfADeferredPurchaseDoesNotRetryTheCredit(): void
    {
        $this->makeLevel(
            self::LEVEL_BASIC,
            5,
            ['activation_points' => ['enabled' => true, 'points' => 50]]
        );
        $this->makeLevel(
            self::LEVEL_HIGH,
            10,
            ['activation_points' => ['enabled' => true, 'points' => 90]]
        );
        $this->bootBenefits();

        // Una cobertura superior activa deja la compra en cola; el cobro ya abonó sus puntos.
        \HWE_Memberships_Grants::grant(self::BUYER, self::LEVEL_HIGH, 30, 'admin', 'admin:high', [], self::ADMIN);
        \HWE_Memberships_Grants::grant(
            self::BUYER,
            self::LEVEL_BASIC,
            15,
            'order',
            'order:56:item:1',
            ['type' => 'order', 'id' => 56]
        );
        $this->assertCount(1, $GLOBALS['hwe_test_loyalty']['credit_calls'], 'El cobro abona una vez.');

        // La tarea diaria activa la compra aplazada cuando termina la cobertura superior. Es una
        // activación del ciclo, no un cobro: no se vuelve a intentar el abono ni se registra un
        // rechazo falso de `loyalty`.
        $later = new \DateTimeImmutable(gmdate('Y-m-d H:i:s', $this->now + 31 * DAY_IN_SECONDS), new \DateTimeZone('UTC'));
        $this->assertGreaterThan(0, \HWE_Memberships_Grants::materialize(self::BUYER, $later));

        $this->assertCount(1, $GLOBALS['hwe_test_loyalty']['credit_calls'], 'La tarea diaria no reintenta el abono.');
        $this->assertCount(1, $GLOBALS['hwe_test_loyalty']['credits']);
        $this->assertStringNotContainsString('loyalty rechazó el abono', implode(' ', $this->logged));
    }

    public function testADeferredPurchaseChargedWithoutPointsNeverCreditsThemLater(): void
    {
        $this->makeLevel(
            self::LEVEL_BASIC,
            5,
            ['activation_points' => ['enabled' => true, 'points' => 50]]
        );
        $this->makeLevel(
            self::LEVEL_HIGH,
            10,
            ['activation_points' => ['enabled' => true, 'points' => 90]]
        );
        $this->bootBenefits();
        $GLOBALS['hwe_test_loyalty']['points'] = false;

        \HWE_Memberships_Grants::grant(self::BUYER, self::LEVEL_HIGH, 30, 'admin', 'admin:high', [], self::ADMIN);
        \HWE_Memberships_Grants::grant(
            self::BUYER,
            self::LEVEL_BASIC,
            15,
            'order',
            'order:57:item:1',
            ['type' => 'order', 'id' => 57]
        );

        $skipped = fn (): array => array_values(array_filter(
            $this->wpdb->event_rows,
            static fn (array $row): bool => \HWE_Memberships_Events::POINTS_SKIPPED === (string) ($row['type'] ?? '')
        ));
        $this->assertCount(1, $skipped(), 'El cobro sin puntos deja constancia una vez.');
        $this->assertSame([], $GLOBALS['hwe_test_loyalty']['credit_calls']);

        // «Puntos» se enciende después, pero la compra ya se cobró sin ellos: no se abona tarde.
        $GLOBALS['hwe_test_loyalty']['points'] = true;
        $later = new \DateTimeImmutable(gmdate('Y-m-d H:i:s', $this->now + 31 * DAY_IN_SECONDS), new \DateTimeZone('UTC'));
        $this->assertGreaterThan(0, \HWE_Memberships_Grants::materialize(self::BUYER, $later));

        $this->assertSame([], $GLOBALS['hwe_test_loyalty']['credit_calls'], 'No se abona al activar lo ya cobrado.');
        $this->assertSame([], $GLOBALS['hwe_test_loyalty']['credits']);
        $this->assertCount(1, $skipped(), 'La tarea diaria no duplica `points_skipped`.');
        $this->assertStringNotContainsString('loyalty rechazó el abono', implode(' ', $this->logged));
    }

    public function testGiftAndAdminGrantsDoNotCreditActivationPoints(): void    {
        $this->makeLevel(
            self::LEVEL_BASIC,
            5,
            ['activation_points' => ['enabled' => true, 'points' => 50]]
        );
        $this->makeLevel(self::LEVEL_GIFT, 7);
        $this->seedActiveGrant(self::REFERRER, self::LEVEL_BASIC, 'referrer:active');
        $this->bootBenefits();

        \HWE_Memberships_Grants::grant(self::BUYER, self::LEVEL_BASIC, 30, 'admin', 'admin:1', [], self::ADMIN);

        $result = \HWE_Memberships_Grants::grantReferralGift(
            self::REFERRER,
            self::REFEREE,
            self::LEVEL_GIFT,
            30,
            2
        );

        $this->assertSame('granted', $result['reason']);
        $this->assertSame([], $GLOBALS['hwe_test_loyalty']['credit_calls'], 'Ni el regalo ni el administrador abonan puntos.');
    }

    public function testRevokingTheOrderGrantReversesTheCreditedPoints(): void
    {
        $this->makeLevel(
            self::LEVEL_BASIC,
            5,
            ['activation_points' => ['enabled' => true, 'points' => 50]]
        );
        $this->bootBenefits();

        \HWE_Memberships_Grants::grant(
            self::BUYER,
            self::LEVEL_BASIC,
            30,
            'order',
            'order:55:item:9',
            ['type' => 'order', 'id' => 55]
        );
        $this->assertCount(1, $GLOBALS['hwe_test_loyalty']['credits']);

        $this->assertTrue(\HWE_Memberships_Grants::revokeByKey('order:55:item:9', 0, 'order_cancelled_or_refunded'));

        $this->assertSame(
            [['membership:order:55:item:9', 'membership:reversal:order:55:item:9']],
            $GLOBALS['hwe_test_loyalty']['reverse_calls']
        );
        $this->assertArrayHasKey('membership:reversal:order:55:item:9', $GLOBALS['hwe_test_loyalty']['reversals']);
        $this->assertSame(50, $GLOBALS['hwe_test_loyalty']['reversals']['membership:reversal:order:55:item:9']['points']);

        // Una segunda revocación no vuelve a mover puntos.
        $this->assertFalse(\HWE_Memberships_Grants::revokeByKey('order:55:item:9', 0, 'order_cancelled_or_refunded'));
        $this->assertCount(1, $GLOBALS['hwe_test_loyalty']['reverse_calls']);
    }

    public function testWithoutPointsAtChargeTheSkipIsRecordedAndNeverRetried(): void
    {
        $this->makeLevel(
            self::LEVEL_BASIC,
            5,
            ['activation_points' => ['enabled' => true, 'points' => 50]]
        );
        // El beneficio se registró con «Puntos» encendido; el interruptor se apaga antes del
        // cobro (p. ej. el editor cambia la configuración entre dos peticiones).
        $this->bootBenefits();
        $GLOBALS['hwe_test_loyalty']['points'] = false;

        $grantId = \HWE_Memberships_Grants::grant(
            self::BUYER,
            self::LEVEL_BASIC,
            30,
            'order',
            'order:70:item:1',
            ['type' => 'order', 'id' => 70]
        );

        $this->assertIsInt($grantId);
        $this->assertSame([], $GLOBALS['hwe_test_loyalty']['credit_calls'], 'No se intenta el abono.');
        $this->assertSame([], $GLOBALS['hwe_test_loyalty']['credits']);

        $skipped = array_values(array_filter(
            $this->wpdb->event_rows,
            static fn (array $row): bool => \HWE_Memberships_Events::POINTS_SKIPPED === (string) ($row['type'] ?? '')
        ));
        $this->assertCount(1, $skipped, 'El cobro sin puntos queda en el historial.');
        $this->assertSame(self::BUYER, (int) $skipped[0]['user_id']);
        $this->assertSame($grantId, (int) $skipped[0]['grant_id']);
        $this->assertSame(50, (int) (json_decode((string) $skipped[0]['data'], true)['points'] ?? 0));
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', implode(' ', $this->logged));
        $this->assertStringContainsString('no abonó puntos', implode(' ', $this->logged));

        // «Nunca se reintenta después»: volver a encender «Puntos» no genera un abono tardío.
        $GLOBALS['hwe_test_loyalty']['points'] = true;
        \HWE_Memberships_Events::flush();
        $this->assertSame([], $GLOBALS['hwe_test_loyalty']['credits']);
    }

    // ------------------------------------------------------------------------
    //  Comisión extra en referidos (§7.5)
    // ------------------------------------------------------------------------

    public function testTheExtraCommissionUsesTheReferrersLevelAtCharge(): void
    {
        $this->makeLevel(
            self::LEVEL_BASIC,
            5,
            ['referral_bonus' => ['enabled' => true, 'percent' => 2]]
        );
        $this->makeLevel(
            self::LEVEL_HIGH,
            10,
            ['referral_bonus' => ['enabled' => true, 'percent' => 5]]
        );
        $this->seedActiveGrant(self::REFERRER, self::LEVEL_BASIC, 'referrer:active');
        $this->bootBenefits();
        $GLOBALS['hwe_test_loyalty']['referrers'][self::BUYER] = self::REFERRER;

        $first = \apply_filters(
            'hwe_loyalty_order_earnings',
            [],
            $this->order(55, self::BUYER),
            100.0,
            []
        );

        $this->assertCount(1, $first);
        $this->assertSame(self::REFERRER, $first[0]['user_id']);
        $this->assertSame('membership_referral_bonus', $first[0]['type']);
        $this->assertSame(2, $first[0]['points'], '2 % de 100, redondeado hacia abajo como loyalty.');
        $this->assertSame(['type' => 'user', 'id' => self::BUYER], $first[0]['ref']);
        $this->assertSame(55, $first[0]['data']['order']);
        $this->assertSame(self::LEVEL_BASIC, $first[0]['data']['level_id'], 'Cuenta el nivel del referidor al cobrar.');

        // El referidor sube de nivel: el siguiente pedido usa ya el porcentaje del nivel nuevo.
        \HWE_Memberships_Grants::grant(
            self::REFERRER,
            self::LEVEL_HIGH,
            30,
            'admin',
            'admin:upgrade',
            [],
            self::ADMIN
        );

        $second = \apply_filters(
            'hwe_loyalty_order_earnings',
            [],
            $this->order(56, self::BUYER),
            100.0,
            []
        );

        $this->assertCount(1, $second);
        $this->assertSame(5, $second[0]['points']);
        $this->assertSame(self::LEVEL_HIGH, $second[0]['data']['level_id']);
    }

    public function testTheExtraCommissionIsADeclaredCreditTypeThatLoyaltyAccepts(): void
    {
        $this->makeLevel(
            self::LEVEL_BASIC,
            5,
            ['referral_bonus' => ['enabled' => true, 'percent' => 2]]
        );
        $this->seedActiveGrant(self::REFERRER, self::LEVEL_BASIC, 'referrer:active');
        $this->bootBenefits();
        $GLOBALS['hwe_test_loyalty']['referrers'][self::BUYER] = self::REFERRER;

        $types = \HWE_Loyalty_EntryTypes::all();
        $this->assertSame('credit', $types['membership_activation'] ?? null);
        $this->assertSame('credit', $types['membership_referral_bonus'] ?? null);

        $earnings = \apply_filters(
            'hwe_loyalty_order_earnings',
            [],
            $this->order(55, self::BUYER),
            100.0,
            []
        );

        $normalize = new \ReflectionMethod(\HWE_Loyalty_Earnings::class, 'normalizeEarnings');
        $normalized = $normalize->invoke(null, $earnings);

        $this->assertCount(1, $normalized, 'loyalty descarta las entradas incompletas o de un tipo que no es abono.');
        $this->assertSame('membership_referral_bonus', $normalized[0]['type']);
        $this->assertSame(self::REFERRER, $normalized[0]['user_id']);
        $this->assertSame(2, $normalized[0]['points']);
        $this->assertSame(
            'earn:order:55:membership_referral_bonus:' . self::REFERRER,
            'earn:order:55:' . $normalized[0]['type'] . ':' . $normalized[0]['user_id'],
            'La clave de loyalty permite registrar y revertir la ganancia con el pedido.'
        );
    }

    public function testWithoutTheBenefitOrWithAFrozenReferrerTheEarningsAreUntouched(): void
    {
        $this->makeLevel(
            self::LEVEL_BASIC,
            5,
            ['referral_bonus' => ['enabled' => false, 'percent' => 2]]
        );
        $this->seedActiveGrant(self::REFERRER, self::LEVEL_BASIC, 'referrer:active');
        $this->bootBenefits();
        $GLOBALS['hwe_test_loyalty']['referrers'][self::BUYER] = self::REFERRER;

        $original = [[
            'user_id' => self::BUYER,
            'type'    => 'referral_commission_l1',
            'points'  => 7,
            'ref'     => [],
            'data'    => [],
        ]];

        $this->assertSame(
            $original,
            \apply_filters('hwe_loyalty_order_earnings', $original, $this->order(55, self::BUYER), 100.0, []),
            'Con el beneficio apagado el array sale intacto.'
        );

        // El referidor está congelado: el beneficio no está activo y no hay comisión extra.
        $this->levelMeta[self::LEVEL_BASIC][\HWE_Memberships_Levels::BENEFITS_META]['referral_bonus']['enabled'] = true;
        $this->wpdb->seedMember(self::REFERRER, ['frozen_at' => gmdate('Y-m-d H:i:s', $this->now - 3600)]);

        $this->assertSame(
            $original,
            \apply_filters('hwe_loyalty_order_earnings', $original, $this->order(56, self::BUYER), 100.0, []),
            'Un referidor congelado no genera comisión extra.'
        );
    }

    public function testTheExtraCommissionFollowsTheReferrerReassignedAfterConfirmation(): void
    {
        $this->makeLevel(
            self::LEVEL_BASIC,
            5,
            ['referral_bonus' => ['enabled' => true, 'percent' => 2]]
        );
        $this->makeLevel(
            self::LEVEL_HIGH,
            10,
            ['referral_bonus' => ['enabled' => true, 'percent' => 5]]
        );
        $this->seedActiveGrant(self::REFERRER, self::LEVEL_BASIC, 'referrer:active');
        $this->seedActiveGrant(self::REFERRER2, self::LEVEL_HIGH, 'referrer:other');
        $this->bootBenefits();
        \do_action('hwe_loyalty_attribution_confirmed', self::BUYER, self::REFERRER);

        $first = \apply_filters(
            'hwe_loyalty_order_earnings',
            [],
            $this->order(55, self::BUYER),
            100.0,
            []
        );

        $this->assertCount(1, $first);
        $this->assertSame(self::REFERRER, $first[0]['user_id']);
        $this->assertSame(2, $first[0]['points']);

        // `loyalty` reasigna la atribución ya confirmada: el siguiente cálculo usa al nuevo y
        // su nivel, sin que quede una copia vieja en la meta.
        $GLOBALS['hwe_test_loyalty']['referrers'][self::BUYER] = self::REFERRER2;

        $second = \apply_filters(
            'hwe_loyalty_order_earnings',
            [],
            $this->order(56, self::BUYER),
            100.0,
            []
        );

        $this->assertCount(1, $second);
        $this->assertSame(self::REFERRER2, $second[0]['user_id'], 'La comisión extra va al referidor vigente.');
        $this->assertSame(5, $second[0]['points'], 'Cuenta el nivel del nuevo referidor.');
        $this->assertSame(self::LEVEL_HIGH, $second[0]['data']['level_id']);
        $this->assertArrayNotHasKey(
            \HWE_Memberships_LoyaltyBridge::REFERRER_META,
            $this->userMeta[self::BUYER] ?? []
        );
    }

    public function testAnAnonymizedOrUnlinkedAttributionPaysNoExtraCommission(): void
    {
        $this->makeLevel(
            self::LEVEL_BASIC,
            5,
            ['referral_bonus' => ['enabled' => true, 'percent' => 2]]
        );
        $this->seedActiveGrant(self::REFERRER, self::LEVEL_BASIC, 'referrer:active');
        $this->bootBenefits();
        $GLOBALS['hwe_test_loyalty']['referrers'][self::BUYER] = self::REFERRER;

        $this->assertCount(
            1,
            \apply_filters('hwe_loyalty_order_earnings', [], $this->order(55, self::BUYER), 100.0, [])
        );

        // El eliminador de `loyalty` anonimiza o desvincula la atribución: deja de haber referidor.
        unset($GLOBALS['hwe_test_loyalty']['referrers'][self::BUYER]);

        $this->assertSame(
            [],
            \apply_filters('hwe_loyalty_order_earnings', [], $this->order(56, self::BUYER), 100.0, []),
            'Sin atribución confirmada no se paga comisión extra.'
        );
    }

    public function testWithoutLoyaltyLoadedTheReferrerIsEmptyAndNothingIsLogged(): void
    {
        $GLOBALS['hwe_test_loyalty']['loaded'] = false;
        $GLOBALS['hwe_test_loyalty']['referrers'][self::BUYER] = self::REFERRER;

        $this->assertSame(0, \HWE_Memberships_LoyaltyBridge::referrerOf(self::BUYER));
        $this->assertSame(0, \HWE_Memberships_LoyaltyBridge::referrerOf(0));
        $this->assertSame([], $this->logged, 'La ausencia de loyalty no genera errores.');
    }

    // ------------------------------------------------------------------------
    //  Regalo al referido (§7.5)
    // ------------------------------------------------------------------------

    public function testTheRefereeReceivesTheGiftOnlyAfterVerifyingTheEmail(): void
    {
        $this->configureGift();
        $this->bootBenefits();

        // La atribución llega sin verificación: el referidor confirmado ya se lee del contrato,
        // pero no hay regalo.
        \do_action('hwe_loyalty_attribution_confirmed', self::REFEREE, self::REFERRER);
        $this->assertSame(
            self::REFERRER,
            (int) $GLOBALS['hwe_test_loyalty']['referrers'][self::REFEREE]
        );
        $this->assertArrayNotHasKey(
            \HWE_Memberships_LoyaltyBridge::REFERRER_META,
            $this->userMeta[self::REFEREE] ?? [],
            'El referidor ya no se cachea en una meta.'
        );
        $this->assertNull($this->wpdb->grantByKey('gift:' . self::REFEREE));

        // Una cuenta sin verificar (p. ej. creada en wp-admin) tampoco recibe el regalo aunque
        // llegue la acción de verificación sin la meta: se falla cerrado.
        \do_action('hwe_loyalty_attribution_confirmed', self::REFEREE2, self::REFERRER);
        \do_action('hwe_email_verified', self::REFEREE2);
        $this->assertNull($this->wpdb->grantByKey('gift:' . self::REFEREE2));

        // Un usuario verificado pero sin atribución tampoco.
        $this->userMeta[self::VISITOR]['hwe_email_verified'] = '1';
        \do_action('hwe_email_verified', self::VISITOR);
        $this->assertNull($this->wpdb->grantByKey('gift:' . self::VISITOR));

        // El referido verifica su correo: ahora sí, y lo recibe él (no el referidor).
        $this->userMeta[self::REFEREE]['hwe_email_verified'] = '1';
        \do_action('hwe_email_verified', self::REFEREE);

        $gift = $this->wpdb->grantByKey('gift:' . self::REFEREE);
        $this->assertNotNull($gift);
        $this->assertSame(self::REFEREE, (int) $gift['user_id']);
        $this->assertSame('referral_gift', $gift['origin']);
        $this->assertSame(self::LEVEL_GIFT, (int) $gift['level_id']);
        $this->assertSame(30, (int) $gift['duration_days']);
        $this->assertSame('active', $gift['status']);
        $this->assertSame('user', $gift['ref_type']);
        $this->assertSame((string) self::REFERRER, (string) $gift['ref_id']);
        $this->assertNull($this->wpdb->grantByKey('gift:' . self::REFERRER), 'El referidor no recibe membresía.');

        // El tope se apunta en la concesión activa del referidor.
        $referrerGrant = $this->wpdb->grantByKey('referrer:active');
        $this->assertNotNull($referrerGrant);
        $this->assertSame(1, (int) $referrerGrant['referral_gifts']);

        // Una sola vez por referido.
        \do_action('hwe_email_verified', self::REFEREE);
        $this->assertSame(1, $this->countGifts());

        // Sin puntos por activación: el regalo no es una compra.
        $this->assertSame([], $GLOBALS['hwe_test_loyalty']['credits']);
    }

    public function testWithVerificationOffTheGiftIsGrantedWhenTheAttributionIsConfirmed(): void
    {
        $this->config['memberships']['referral_gift_requires_email_verification'] = false;
        $this->configureGift();
        $this->bootBenefits();

        \do_action('hwe_loyalty_attribution_confirmed', self::REFEREE, self::REFERRER);

        $gift = $this->wpdb->grantByKey('gift:' . self::REFEREE);
        $this->assertNotNull($gift, 'Con la verificación apagada se concede al confirmar la atribución.');
        $this->assertSame(self::REFEREE, (int) $gift['user_id']);
        $this->assertSame(self::LEVEL_GIFT, (int) $gift['level_id']);
        $this->assertSame('', (string) ($this->userMeta[self::REFEREE]['hwe_email_verified'] ?? ''));
    }

    public function testAttributionAfterVerificationAlsoGrantsTheGift(): void
    {
        $this->configureGift();
        $this->bootBenefits();

        $this->userMeta[self::REFEREE]['hwe_email_verified'] = '1';
        \do_action('hwe_loyalty_attribution_confirmed', self::REFEREE, self::REFERRER);

        $this->assertNotNull(
            $this->wpdb->grantByKey('gift:' . self::REFEREE),
            'Si el correo ya estaba verificado al confirmarse la atribución, se concede igual.'
        );
    }

    public function testTheGiftRespectsTheCapAndLeavesHistoryForTheReferrer(): void
    {
        $this->configureGift(['max_per_period' => 1]);
        $this->bootBenefits();

        $this->confirmReferral(self::REFEREE, self::REFERRER);
        $this->assertNotNull($this->wpdb->grantByKey('gift:' . self::REFEREE));

        // Dos referidos verifican con el tope prácticamente agotado: solo uno se lleva el regalo.
        $this->confirmReferral(self::REFEREE2, self::REFERRER);
        $this->assertNull($this->wpdb->grantByKey('gift:' . self::REFEREE2));
        $this->assertSame(1, $this->countGifts(), 'Dos verificaciones no pasan del tope.');

        $limits = array_values(array_filter(
            $this->wpdb->event_rows,
            static fn (array $row): bool => \HWE_Memberships_Events::REFERRAL_GIFT_LIMIT === (string) ($row['type'] ?? '')
        ));
        $this->assertCount(1, $limits);
        $this->assertSame(self::REFERRER, (int) $limits[0]['user_id'], 'El tope queda en el historial del referidor.');
        $data = json_decode((string) $limits[0]['data'], true);
        $this->assertSame(self::REFEREE2, (int) $data['referee']);
        $this->assertSame(1, (int) $data['max_per_period']);
        $this->assertSame(1, (int) $data['used']);
        $this->assertSame(1, (int) $this->wpdb->grantByKey('referrer:active')['referral_gifts']);

        // El mismo referido que ya lo recibió no vuelve a consumir ni a dejar un tope nuevo.
        $this->confirmReferral(self::REFEREE, self::REFERRER);
        $this->assertSame(1, $this->countGifts());
        $this->assertCount(1, array_filter(
            $this->wpdb->event_rows,
            static fn (array $row): bool => \HWE_Memberships_Events::REFERRAL_GIFT_LIMIT === (string) ($row['type'] ?? '')
        ));
    }

    public function testTheGiftCapStartsOverWithANewReferrerGrant(): void
    {
        $this->configureGift(['max_per_period' => 1]);
        $this->bootBenefits();

        $this->confirmReferral(self::REFEREE, self::REFERRER);
        $this->assertSame(1, $this->countGifts());

        // Renovación: la concesión anterior termina y la nueva (mismo nivel) pasa a ser la
        // efectiva con su propio contador. Es la concesión que lleva el tope del periodo.
        $old = $this->wpdb->grantByKey('referrer:active');
        $this->assertNotNull($old);
        $this->wpdb->grants[(int) $old['id']]['status'] = 'ended';
        $this->wpdb->grants[(int) $old['id']]['ends_at'] = gmdate('Y-m-d H:i:s', $this->now - 60);

        $this->seedActiveGrant(self::REFERRER, self::LEVEL_BASIC, 'referrer:renewal', 0, 30);

        $this->confirmReferral(self::REFEREE2, self::REFERRER);

        $this->assertNotNull($this->wpdb->grantByKey('gift:' . self::REFEREE2), 'El contador vuelve a empezar.');
        $this->assertSame(2, $this->countGifts());
        $renewal = $this->wpdb->grantByKey('referrer:renewal');
        $this->assertNotNull($renewal);
        $this->assertSame(1, (int) $renewal['referral_gifts']);
    }

    public function testAFrozenReferrerOrADisabledBenefitGrantsNothing(): void
    {
        $this->configureGift();
        $this->bootBenefits();

        // Congelado: pierde el nivel efectivo y el beneficio.
        $this->wpdb->seedMember(self::REFERRER, ['frozen_at' => gmdate('Y-m-d H:i:s', $this->now - 3600)]);
        $this->confirmReferral(self::REFEREE, self::REFERRER);
        $this->assertNull($this->wpdb->grantByKey('gift:' . self::REFEREE));
        $this->assertSame(0, $this->countGifts());

        // Sin el beneficio (aunque el nivel y el referidor sigan activos).
        $this->wpdb->members[self::REFERRER]['frozen_at'] = null;
        $this->levelMeta[self::LEVEL_BASIC][\HWE_Memberships_Levels::BENEFITS_META]['referral_gift']['enabled'] = false;

        $this->confirmReferral(self::REFEREE2, self::REFERRER);
        $this->assertNull($this->wpdb->grantByKey('gift:' . self::REFEREE2));
        $this->assertSame(0, $this->countGifts());
    }

    public function testTheGiftIsQueuedWhenTheRefereeAlreadyHasAnEqualOrHigherLevel(): void
    {
        $this->configureGift(['level' => self::LEVEL_BASIC, 'days' => 15, 'max_per_period' => 2]);
        $this->makeLevel(self::LEVEL_HIGH, 10);
        $this->seedActiveGrant(self::REFERRER, self::LEVEL_BASIC, 'referrer:active');
        $highId = $this->seedActiveGrant(self::REFEREE, self::LEVEL_HIGH, 'referee:high', 0, 10);
        $this->bootBenefits();

        $this->confirmReferral(self::REFEREE, self::REFERRER);

        $gift = $this->wpdb->grantByKey('gift:' . self::REFEREE);
        $this->assertNotNull($gift);
        $this->assertSame('scheduled', $gift['status'], 'El regalo se encola detrás de la cobertura superior.');
        $this->assertSame(
            substr((string) $this->wpdb->grants[$highId]['ends_at'], 0, 19),
            substr((string) $gift['starts_at'], 0, 19)
        );
    }

    public function testTheGiftStateShowsTheRemainingGiftsForThePeriod(): void
    {
        $this->configureGift(['max_per_period' => 2]);
        $seeded = $this->wpdb->grantByKey('referrer:active');
        $this->assertNotNull($seeded);
        $this->wpdb->grants[(int) $seeded['id']]['referral_gifts'] = 1;
        $this->bootBenefits();

        $gift = \HWE_Memberships_Benefits::get('referral_gift');
        $this->assertNotNull($gift);
        $state = $gift->stateFor(self::REFERRER);

        $this->assertTrue($state['active']);
        $this->assertSame(2, $state['gifts_max']);
        $this->assertSame(1, $state['gifts_used']);
        $this->assertSame(1, $state['gifts_remaining']);
    }

    // ------------------------------------------------------------------------
    //  Convivencia con loyalty (§8)
    // ------------------------------------------------------------------------

    public function testWithLoyaltyOffNoMembershipHookIsRegisteredOnItsHooks(): void
    {
        $this->configureGift();
        $GLOBALS['hwe_test_loyalty']['points']    = false;
        $GLOBALS['hwe_test_loyalty']['referrals'] = false;
        $this->bootBenefits();

        foreach (array_keys($this->hooks) as $hook) {
            $this->assertStringStartsNotWith('hwe_loyalty_', (string) $hook, 'Ningún hook de loyalty queda enganchado.');
        }
        $this->assertArrayNotHasKey('hwe_email_verified', $this->hooks, 'El regalo no escucha sin «Referidos».');

        $active = array_keys(\HWE_Memberships_Benefits::activeFor(self::REFERRER));
        $this->assertSame([], array_values(array_intersect($active, ['activation_points', 'referral_bonus', 'referral_gift'])));

        foreach (['activation_points', 'referral_bonus', 'referral_gift'] as $key) {
            $benefit = \HWE_Memberships_Benefits::get($key);
            $this->assertNotNull($benefit);
            $this->assertFalse($benefit->isActiveFor(self::REFERRER), 'El beneficio no existe para el cliente.');
        }

        $this->assertSame([], $GLOBALS['hwe_test_loyalty']['credit_calls']);
    }

    public function testWithMembershipsOffNothingIsRegisteredAndLoyaltyStaysIntact(): void
    {
        $this->activePlugins = ['hwe-loyalty/hwe-loyalty.php'];
        $this->configureGift();

        $this->bootBenefits();

        $this->assertSame([], $this->hooks, 'Con el módulo apagado no se engancha nada.');
        $this->assertSame([], \HWE_Memberships_Benefits::activeFor(self::REFERRER));
        $this->assertSame([], $GLOBALS['hwe_test_loyalty']['credit_calls']);
    }

    // ------------------------------------------------------------------------
    //  Utilidades
    // ------------------------------------------------------------------------

    /** Contrato público de `loyalty` (existe solo con el plugin cargado; aquí se simula). */
    private function fakeLoyaltyContract(): void
    {
        Functions\when('hwe_loyalty_available')->alias(function (string $feature): bool {
            $state = $GLOBALS['hwe_test_loyalty'];
            if (empty($state['loaded'])) {
                return false;
            }

            return (bool) ($state[$feature] ?? false);
        });
        // Lectura del referidor confirmado: el estado simula la fila de `loyalty` y cambia cuando
        // el test reasigna, anonimiza o desvincula la atribución.
        Functions\when('hwe_loyalty_referrer_of')->alias(function (int $userId): ?int {
            $state = $GLOBALS['hwe_test_loyalty'];
            if (empty($state['loaded'])) {
                return null;
            }

            $referrer = (int) ($state['referrers'][$userId] ?? 0);

            return $referrer > 0 ? $referrer : null;
        });
        Functions\when('hwe_loyalty_credit')->alias(function (
            int $userId,
            int $points,
            string $type,
            string $key,
            array $ref = [],
            array $data = []
        ): ?int {
            $state = &$GLOBALS['hwe_test_loyalty'];
            $state['credit_calls'][] = compact('userId', 'points', 'type', 'key', 'ref', 'data');

            if (empty($state['loaded']) || empty($state['points']) || isset($state['credits'][$key])) {
                return null;
            }

            $state['credits'][$key] = [
                'user_id' => $userId,
                'points'  => $points,
                'type'    => $type,
                'ref'     => $ref,
                'data'    => $data,
            ];

            return count($state['credits']);
        });
        Functions\when('hwe_loyalty_reverse_credit')->alias(function (string $key, string $reversalKey): ?int {
            $state = &$GLOBALS['hwe_test_loyalty'];
            $state['reverse_calls'][] = [$key, $reversalKey];

            if (!isset($state['credits'][$key]) || isset($state['reversals'][$reversalKey])) {
                return null;
            }

            $state['reversals'][$reversalKey] = [
                'key'    => $key,
                'points' => (int) $state['credits'][$key]['points'],
            ];

            return 900 + count($state['reversals']);
        });
    }

    /**
     * Registro de hooks en memoria, en lugar del de WordPress: así se decide con precisión qué
     * queda enganchado y las acciones disparadas por `HWE_Memberships_Events` ejecutan de
     * verdad a sus consumidores.
     */
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
        Functions\when('do_action')->alias(function (string $hook, mixed ...$args): void {
            // `loyalty` escribe la fila de la atribución antes de disparar la acción; el doble
            // refleja ese estado para que las lecturas posteriores del contrato lo vean.
            if ('hwe_loyalty_attribution_confirmed' === $hook) {
                $referee  = (int) ($args[0] ?? 0);
                $referrer = (int) ($args[1] ?? 0);
                if ($referee > 0) {
                    if ($referrer > 0) {
                        $GLOBALS['hwe_test_loyalty']['referrers'][$referee] = $referrer;
                    } else {
                        unset($GLOBALS['hwe_test_loyalty']['referrers'][$referee]);
                    }
                }
            }

            foreach ($this->callbacks($hook, 'action') as $entry) {
                ($entry['callback'])(...array_slice($args, 0, $entry['accepted']));
            }
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
        $this->loadOwnFile('plugins/hwe-memberships/includes/LoyaltyBridge.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ActivationPoints.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ReferralBonus.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ReferralGift.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/InfoPerk.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Registry.php');
    }

    /** Registra el puente y los tipos de beneficio una sola vez, como haría el arranque del plugin. */
    private function bootBenefits(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        \HWE_Memberships_LoyaltyBridge::register();
        \HWE_Memberships_Benefits::register();
    }

    /** Niveles del escenario: activación, comisión y regalo, todos en el nivel básico. */
    private function configureGift(array $gift = []): void
    {
        $this->makeLevel(
            self::LEVEL_BASIC,
            5,
            [
                'activation_points' => ['enabled' => true, 'points' => 50],
                'referral_bonus'    => ['enabled' => true, 'percent' => 2],
                'referral_gift'     => array_merge(
                    ['enabled' => true, 'level' => self::LEVEL_GIFT, 'days' => 30, 'max_per_period' => 2],
                    $gift
                ),
            ]
        );
        $this->makeLevel(self::LEVEL_GIFT, 7);
        $this->seedActiveGrant(self::REFERRER, self::LEVEL_BASIC, 'referrer:active');
    }

    /** Atribución + verificación del referido, como las dispara `loyalty` y `hwe-auth`. */
    private function confirmReferral(int $refereeId, int $referrerId): void
    {
        \do_action('hwe_loyalty_attribution_confirmed', $refereeId, $referrerId);

        $requires = (bool) ($this->config['memberships']['referral_gift_requires_email_verification'] ?? true);
        if ($requires) {
            $this->userMeta[$refereeId]['hwe_email_verified'] = '1';
            \do_action('hwe_email_verified', $refereeId);
        }
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

    /** Concesión activa sembrada directamente en el doble de la base de datos. */
    private function seedActiveGrant(
        int $userId,
        int $levelId,
        string $key,
        int $gifts = 0,
        int $days = 30
    ): int {
        return $this->wpdb->seedGrant([
            'user_id'         => $userId,
            'level_id'        => $levelId,
            'idempotency_key' => $key,
            'duration_days'   => $days,
            'status'          => 'active',
            'starts_at'       => gmdate('Y-m-d H:i:s', $this->now - 3600),
            'ends_at'         => gmdate('Y-m-d H:i:s', $this->now + $days * DAY_IN_SECONDS),
            'referral_gifts'  => $gifts,
        ]);
    }

    private function countGifts(): int
    {
        return count(array_filter(
            $this->wpdb->grants,
            static fn (array $row): bool => 'referral_gift' === (string) ($row['origin'] ?? '')
        ));
    }

    private function order(int $id, int $customerId): \WC_Order
    {
        $order              = new \WC_Order();
        $order->id          = $id;
        $order->customer_id = $customerId;
        $order->status      = 'processing';
        $order->paid        = true;

        return $order;
    }
}
