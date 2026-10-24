<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TestMembershipsServiceWpdb;

require_once HWE_BACKEND_DIR . '/tests/Stubs/wp-memberships-service-wpdb.php';

/**
 * Plugin `hwe-memberships` (módulo `memberships`), tarea diaria (spec §5.5–§5.6 y §12).
 *
 * Se prueba la DECISIÓN del planificador y su integración con el servicio de concesiones:
 *  · activar programa un ÚNICO evento diario y desactivar retira el diario y su continuación;
 *  · la pasada materializa `scheduled → active` y `active → ended` por lotes, con tope por
 *    ejecución y una continuación única de cinco minutos si queda trabajo;
 *  · cada transición dispara sus hooks una sola vez (dos pasadas no repiten nada);
 *  · el aviso `expiring` sale una vez por concesión a los días configurados (0 = nunca);
 *  · un socio que falla siempre al frente no bloquea al resto del lote;
 *  · un socio congelado se salta (su reloj no corre) y con el módulo apagado no se toca nada.
 *
 * El doble de `$wpdb` mantiene las tablas del módulo en memoria y entiende la consulta de
 * candidatos por fecha; así el test comprueba que la tarea no recorre todos los usuarios.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SchedulerTest extends TestCase
{
    private const USER   = 7;
    private const OTHER  = 8;
    private const THIRD  = 9;
    private const LEVEL  = 101;
    private const LEVEL2 = 102;

    /** Reloj simulado (UTC). */
    private int $now = 1_800_000_000;
    /** @var list<string> */
    private array $logged = [];
    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var list<string> Plugins activos según `active_plugins`. */
    private array $activePlugins = ['hwe-memberships/hwe-memberships.php'];
    /** @var array<int,\WP_Post> */
    private array $levelPosts = [];
    /** @var array<int,array<string,string>> */
    private array $levelMeta = [];
    /** @var array<string,list<int>> Eventos programados por hook. */
    private array $events = [];
    private int $scheduleCalls = 0;
    private bool $scheduleResult = true;
    private int $singleScheduleCalls = 0;
    private bool $singleScheduleResult = true;
    private int $clearCalls = 0;
    /** @var list<array{0:string,1:mixed}> */
    private array $activationHooks = [];
    /** @var list<array{0:string,1:mixed}> */
    private array $deactivationHooks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->now            = 1_800_000_000;
        $this->logged         = [];
        $this->config         = ['memberships' => ['expiring_notice_days' => '7']];
        $this->activePlugins  = ['hwe-memberships/hwe-memberships.php'];
        $this->levelPosts     = [];
        $this->levelMeta      = [];
        $this->events         = [];
        $this->scheduleCalls        = 0;
        $this->scheduleResult       = true;
        $this->singleScheduleCalls  = 0;
        $this->singleScheduleResult = true;
        $this->clearCalls           = 0;
        $this->activationHooks   = [];
        $this->deactivationHooks = [];

        $GLOBALS['wpdb'] = new TestMembershipsServiceWpdb();

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            return match ($name) {
                'hwe_config'     => $this->config,
                'active_plugins' => $this->activePlugins,
                default          => $default,
            };
        });
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_next_scheduled')->alias(
            fn (string $hook): int|false => $this->events[$hook][0] ?? false
        );
        Functions\when('wp_schedule_event')->alias(
            function (int $timestamp, string $recurrence, string $hook): bool {
                ++$this->scheduleCalls;
                if (!$this->scheduleResult) {
                    return false;
                }
                $this->events[$hook][] = $timestamp;
                return true;
            }
        );
        Functions\when('wp_schedule_single_event')->alias(
            function (int $timestamp, string $hook): bool {
                ++$this->singleScheduleCalls;
                if (!$this->singleScheduleResult) {
                    return false;
                }
                $this->events[$hook][] = $timestamp;
                return true;
            }
        );
        Functions\when('wp_clear_scheduled_hook')->alias(
            function (string $hook): int {
                ++$this->clearCalls;
                $removed = count($this->events[$hook] ?? []);
                unset($this->events[$hook]);
                return $removed;
            }
        );
        Functions\when('get_post')->alias(fn (int $id) => $this->levelPosts[$id] ?? null);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->levelMeta[$id][$key] ?? ''
        );
        Functions\when('get_userdata')->alias(
            fn (int $id) => in_array($id, [self::USER, self::OTHER, self::THIRD], true)
                ? (object) ['ID' => $id]
                : false
        );
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args): mixed => $value
        );
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\stubs([
            'sanitize_text_field'       => static fn ($value) => is_string($value) ? trim($value) : '',
            'esc_html'                  => static fn ($value) => (string) $value,
            'esc_html__'                => static fn ($value) => (string) $value,
            '__'                        => static fn ($value) => (string) $value,
            'register_activation_hook'  => function (string $file, mixed $callback): void {
                $this->activationHooks[] = [$file, $callback];
            },
            'register_deactivation_hook' => function (string $file, mixed $callback): void {
                $this->deactivationHooks[] = [$file, $callback];
            },
        ]);

        $this->loadOwnFile('plugins/hwe-memberships/hwe-memberships.php');
        \HWE_Memberships_Events::clear();

        $this->makeLevel(self::LEVEL, 5);
    }

    private function wpdb(): TestMembershipsServiceWpdb
    {
        return $GLOBALS['wpdb'];
    }

    private function makeLevel(int $id, int $rank): void
    {
        $post                  = new \WP_Post();
        $post->ID              = $id;
        $post->post_type       = \HWE_Memberships_Levels::POST_TYPE;
        $post->post_status     = 'publish';
        $post->post_title      = 'Nivel ' . $id;
        $post->post_name       = 'nivel-' . $id;
        $post->post_content    = '';
        $this->levelPosts[$id] = $post;
        $this->levelMeta[$id]  = [\HWE_Memberships_Levels::RANK_META => (string) $rank];
    }

    /** Fecha MySQL (UTC) relativa al reloj del test. */
    private function at(int $days): string
    {
        return gmdate('Y-m-d H:i:s', $this->now + $days * DAY_IN_SECONDS);
    }

    /** @param array<string,mixed> $overrides */
    private function seedGrant(int $id, int $userId, array $overrides = []): int
    {
        return $this->wpdb()->seedGrant(array_merge([
            'id'              => $id,
            'user_id'         => $userId,
            'level_id'        => self::LEVEL,
            'idempotency_key' => 'seed:' . $id,
            'starts_at'       => $this->at(-1),
            'ends_at'         => $this->at(29),
            'status'          => 'active',
            'created_at'      => $this->at(-1),
            'updated_at'      => $this->at(-1),
        ], $overrides));
    }

    /** @param array<string,mixed> $overrides */
    private function seedMember(int $userId, array $overrides = []): void
    {
        $this->wpdb()->seedMember($userId, $overrides);
    }

    // ── Programación y retirada del evento ──────────────────────────────────

    public function testScheduleCreatesASingleDailyEventEvenWhenCalledTwice(): void
    {
        $this->assertSame('hwe_memberships_daily', \HWE_Memberships_Scheduler::HOOK);

        \HWE_Memberships_Scheduler::schedule();
        \HWE_Memberships_Scheduler::schedule();

        $this->assertCount(1, $this->events[\HWE_Memberships_Scheduler::HOOK]);
        $this->assertSame(1, $this->scheduleCalls, 'La segunda llamada ve el evento ya programado.');
        $this->assertSame(
            $this->now + HOUR_IN_SECONDS,
            $this->events[\HWE_Memberships_Scheduler::HOOK][0],
            'La primera pasada se deja para dentro de una hora.'
        );
    }

    public function testAFailedScheduleIsLoggedInsteadOfSwallowed(): void
    {
        $this->scheduleResult = false;

        \HWE_Memberships_Scheduler::schedule();

        $this->assertSame([], $this->events);
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
    }

    public function testUnscheduleRemovesEveryOccurrenceOfTheDailyAndContinuationEvents(): void
    {
        $this->events[\HWE_Memberships_Scheduler::HOOK] = [
            $this->now + HOUR_IN_SECONDS,
            $this->now + 2 * DAY_IN_SECONDS,
        ];
        $this->events[\HWE_Memberships_Scheduler::HOOK_CONTINUE] = [
            $this->now + 5 * MINUTE_IN_SECONDS,
        ];

        \HWE_Memberships_Scheduler::unschedule();

        $this->assertSame(2, $this->clearCalls);
        $this->assertArrayNotHasKey(\HWE_Memberships_Scheduler::HOOK, $this->events);
        $this->assertArrayNotHasKey(\HWE_Memberships_Scheduler::HOOK_CONTINUE, $this->events);
    }

    public function testActivationSchedulesTheEventAndDeactivationRemovesIt(): void
    {
        $activation = array_values(array_filter(
            $this->activationHooks,
            static fn (array $hook): bool => [\HWE_Memberships_Scheduler::class, 'schedule'] === $hook[1]
        ));
        $deactivation = array_values(array_filter(
            $this->deactivationHooks,
            static fn (array $hook): bool => [\HWE_Memberships_Scheduler::class, 'unschedule'] === $hook[1]
        ));

        $this->assertCount(1, $activation, 'El plugin programa la tarea al activarse.');
        $this->assertCount(1, $deactivation, 'El plugin retira la tarea al desactivarse.');
        $this->assertSame(
            realpath(HWE_WP_CONTENT_DIR . '/plugins/hwe-memberships/hwe-memberships.php'),
            realpath($activation[0][0])
        );

        ($activation[0][1])();
        $this->assertCount(1, $this->events[\HWE_Memberships_Scheduler::HOOK]);

        // Una continuación pendiente también se retira al desactivar.
        $this->events[\HWE_Memberships_Scheduler::HOOK_CONTINUE] = [
            $this->now + 5 * MINUTE_IN_SECONDS,
        ];

        ($deactivation[0][1])();
        $this->assertArrayNotHasKey(\HWE_Memberships_Scheduler::HOOK, $this->events);
        $this->assertArrayNotHasKey(\HWE_Memberships_Scheduler::HOOK_CONTINUE, $this->events);
        $this->assertSame(2, $this->clearCalls);
    }

    public function testRegisterHooksTheDailyRunTheContinuationAndTheScheduleRepair(): void
    {
        Actions\expectAdded(\HWE_Memberships_Scheduler::HOOK)
            ->once()
            ->with([\HWE_Memberships_Scheduler::class, 'run']);
        Actions\expectAdded(\HWE_Memberships_Scheduler::HOOK_CONTINUE)
            ->once()
            ->with([\HWE_Memberships_Scheduler::class, 'run']);
        Actions\expectAdded('init')
            ->once()
            ->with([\HWE_Memberships_Scheduler::class, 'schedule']);

        \HWE_Memberships_Scheduler::register();
    }

    public function testBootRegistersTheScheduler(): void
    {
        if (!class_exists('WooCommerce')) {
            class_alias(\stdClass::class, 'WooCommerce');
        }

        Actions\expectAdded(\HWE_Memberships_Scheduler::HOOK)
            ->once()
            ->with([\HWE_Memberships_Scheduler::class, 'run']);
        Actions\expectAdded(\HWE_Memberships_Scheduler::HOOK_CONTINUE)
            ->once()
            ->with([\HWE_Memberships_Scheduler::class, 'run']);

        \HWE_Memberships::boot();
    }

    public function testBootAndTheDailyRunDoNotIterateAllUsers(): void
    {
        if (!class_exists('WooCommerce')) {
            class_alias(\stdClass::class, 'WooCommerce');
        }

        Functions\expect('get_users')->never();
        Functions\expect('get_user_meta')->never();

        \HWE_Memberships::boot();
        \HWE_Memberships_Scheduler::run();
    }

    // ── Transiciones materializadas ─────────────────────────────────────────

    public function testMaterializesScheduledToActiveOncePerTransition(): void
    {
        $this->seedMember(self::USER);
        $id = $this->seedGrant(1, self::USER, [
            'status'    => 'scheduled',
            'starts_at' => $this->at(-1),
            'ends_at'   => $this->at(29),
        ]);

        Actions\expectDone('hwe_membership_activated')
            ->once()
            ->with(self::USER, $id, self::LEVEL);
        Actions\expectDone('hwe_membership_level_changed')
            ->once()
            ->with(self::USER, 0, self::LEVEL);
        Actions\expectDone('hwe_membership_expired')->never();

        $this->assertSame(1, \HWE_Memberships_Scheduler::run());

        $grant = $this->wpdb()->grantByKey('seed:1');
        $this->assertNotNull($grant);
        $this->assertSame('active', $grant['status']);
        $this->assertSame(self::LEVEL, (int) $this->wpdb()->members[self::USER]['level_id']);
        $this->assertSame(['activated'], $this->wpdb()->eventTypes());

        $this->assertSame(0, \HWE_Memberships_Scheduler::run(), 'La segunda pasada no repite transiciones.');
        $this->assertCount(1, $this->wpdb()->event_rows);
        $this->assertSame('active', $this->wpdb()->grantByKey('seed:1')['status']);
    }

    public function testMaterializesActiveToEndedRevokesTokensAndClearsTheLevel(): void
    {
        $this->seedMember(self::USER, [
            'level_id'     => self::LEVEL,
            'covers_until' => $this->at(30),
        ]);
        $id = $this->seedGrant(1, self::USER, [
            'status'    => 'active',
            'starts_at' => $this->at(-40),
            'ends_at'   => $this->at(-1),
        ]);
        $this->wpdb()->seedToken(self::USER, 'token-' . self::USER);

        Actions\expectDone('hwe_membership_expired')
            ->once()
            ->with(self::USER, $id, self::LEVEL);
        Actions\expectDone('hwe_membership_level_changed')
            ->once()
            ->with(self::USER, self::LEVEL, 0);
        Actions\expectDone('hwe_membership_activated')->never();

        $this->assertSame(1, \HWE_Memberships_Scheduler::run());

        $grant = $this->wpdb()->grantByKey('seed:1');
        $this->assertNotNull($grant);
        $this->assertSame('ended', $grant['status']);
        $this->assertNull($this->wpdb()->members[self::USER]['level_id']);
        $this->assertNull($this->wpdb()->members[self::USER]['covers_until']);
        $this->assertSame($this->at(0), substr((string) $this->wpdb()->tokens[0]['revoked_at'], 0, 19));
        $this->assertSame(['expired'], $this->wpdb()->eventTypes());

        $event = array_values($this->wpdb()->event_rows)[0];
        $this->assertSame(self::LEVEL, (int) $event['level_from']);
        $this->assertSame(0, (int) $event['level_to']);

        $this->assertSame(0, \HWE_Memberships_Scheduler::run());
        $this->assertCount(1, $this->wpdb()->event_rows);
    }

    public function testAChainOfExpiryAndActivationFiresTheLevelChangeOnlyOnce(): void
    {
        $this->makeLevel(self::LEVEL2, 10);
        $this->seedMember(self::USER, [
            'level_id'     => self::LEVEL2,
            'covers_until' => $this->at(-1),
        ]);
        $this->seedGrant(1, self::USER, [
            'level_id'  => self::LEVEL2,
            'status'    => 'active',
            'starts_at' => $this->at(-40),
            'ends_at'   => $this->at(-1),
        ]);
        $this->seedGrant(2, self::USER, [
            'level_id'  => self::LEVEL,
            'status'    => 'scheduled',
            'starts_at' => $this->at(-1),
            'ends_at'   => $this->at(29),
        ]);

        Actions\expectDone('hwe_membership_expired')->once()->with(self::USER, 1, self::LEVEL2);
        Actions\expectDone('hwe_membership_activated')->once()->with(self::USER, 2, self::LEVEL);
        Actions\expectDone('hwe_membership_level_changed')
            ->once()
            ->with(self::USER, self::LEVEL2, self::LEVEL);

        $this->assertSame(2, \HWE_Memberships_Scheduler::run());
        $this->assertSame('ended', $this->wpdb()->grantByKey('seed:1')['status']);
        $this->assertSame('active', $this->wpdb()->grantByKey('seed:2')['status']);
        $this->assertSame(self::LEVEL, (int) $this->wpdb()->members[self::USER]['level_id']);
        $this->assertSame(['expired', 'activated'], $this->wpdb()->eventTypes());

        $expired = array_values($this->wpdb()->event_rows)[0];
        $this->assertSame(self::LEVEL2, (int) $expired['level_from']);
        $this->assertSame(self::LEVEL, (int) $expired['level_to']);
    }

    public function testTheDailyRunStopsAtTheBatchCapAndContinuesNextRun(): void
    {
        foreach ([self::USER, self::OTHER, self::THIRD] as $index => $userId) {
            $this->seedMember($userId);
            $this->seedGrant($index + 1, $userId, [
                'status'    => 'scheduled',
                'starts_at' => $this->at(-1),
                'ends_at'   => $this->at(29),
            ]);
        }

        $this->assertSame(2, \HWE_Memberships_Scheduler::run(2));
        $this->assertSame('active', $this->wpdb()->grantByKey('seed:1')['status']);
        $this->assertSame('active', $this->wpdb()->grantByKey('seed:2')['status']);
        $this->assertSame('scheduled', $this->wpdb()->grantByKey('seed:3')['status']);
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('tope', $this->logged[0]);
        $this->assertArrayHasKey(\HWE_Memberships_Scheduler::HOOK_CONTINUE, $this->events);
        $this->assertSame(
            $this->now + \HWE_Memberships_Scheduler::CONTINUE_DELAY,
            $this->events[\HWE_Memberships_Scheduler::HOOK_CONTINUE][0],
            'El trabajo pendiente se retoma en una continuación programada.'
        );
        $this->assertSame(1, $this->singleScheduleCalls);

        $this->assertSame(1, \HWE_Memberships_Scheduler::run(2));
        $this->assertSame('active', $this->wpdb()->grantByKey('seed:3')['status']);
        $this->assertSame(1, $this->singleScheduleCalls, 'La continuación ya programada no se duplica.');
    }

    public function testNoContinuationIsScheduledWhenThereIsNoPendingWork(): void
    {
        $this->seedMember(self::USER);
        $this->seedGrant(1, self::USER, [
            'status'    => 'scheduled',
            'starts_at' => $this->at(-1),
            'ends_at'   => $this->at(29),
        ]);

        $this->assertSame(1, \HWE_Memberships_Scheduler::run(10));
        $this->assertArrayNotHasKey(\HWE_Memberships_Scheduler::HOOK_CONTINUE, $this->events);
        $this->assertSame(0, $this->singleScheduleCalls);
    }

    public function testASingleContinuationIsScheduledAcrossSuccessiveRunsWithPendingWork(): void
    {
        foreach ([self::USER, self::OTHER, self::THIRD] as $index => $userId) {
            $this->seedMember($userId);
            $this->seedGrant($index + 1, $userId, [
                'status'    => 'scheduled',
                'starts_at' => $this->at(-1),
                'ends_at'   => $this->at(29),
            ]);
        }

        $this->assertSame(1, \HWE_Memberships_Scheduler::run(1));
        $this->assertSame(1, $this->singleScheduleCalls);

        $this->assertSame(1, \HWE_Memberships_Scheduler::run(1));
        $this->assertSame(1, $this->singleScheduleCalls, 'La segunda pasada ve la continuación ya programada.');

        $this->assertSame(1, \HWE_Memberships_Scheduler::run(1));
        $this->assertSame(1, $this->singleScheduleCalls);
        $this->assertCount(1, $this->events[\HWE_Memberships_Scheduler::HOOK_CONTINUE]);
    }

    public function testAFailingUserAtTheFrontDoesNotBlockTheRest(): void
    {
        $this->seedMember(self::USER);
        $this->seedMember(self::OTHER);
        $this->seedGrant(1, self::USER, [
            'status'    => 'scheduled',
            'starts_at' => $this->at(-10),
            'ends_at'   => $this->at(20),
        ]);
        $this->seedGrant(2, self::OTHER, [
            'status'    => 'scheduled',
            'starts_at' => $this->at(-1),
            'ends_at'   => $this->at(29),
        ]);

        // El usuario con el vencimiento más antiguo falla siempre al materializar.
        $this->wpdb()->failGrantUpdatesForUsers = [self::USER];

        $this->assertSame(1, \HWE_Memberships_Scheduler::run(1));
        $this->assertSame('scheduled', $this->wpdb()->grantByKey('seed:1')['status']);
        $this->assertSame('active', $this->wpdb()->grantByKey('seed:2')['status'], 'El siguiente socio se procesa.');
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('usuario ' . self::USER, implode(' ', $this->logged));
        $this->assertSame(0, $this->singleScheduleCalls, 'Un fallo no programa una continuación en bucle.');
    }

    public function testNoticeOnlyWorkCountsTowardTheBatchCap(): void
    {
        foreach ([self::USER, self::OTHER, self::THIRD] as $index => $userId) {
            $this->seedMember($userId, ['level_id' => self::LEVEL]);
            $this->seedGrant($index + 1, $userId, [
                'status'    => 'active',
                'starts_at' => $this->at(-10),
                'ends_at'   => $this->at(3),
            ]);
        }

        $this->assertSame(0, \HWE_Memberships_Scheduler::run(2), 'Los avisos no son transiciones.');
        $this->assertNotNull($this->wpdb()->grantByKey('seed:1')['expiry_notified_at']);
        $this->assertNotNull($this->wpdb()->grantByKey('seed:2')['expiry_notified_at']);
        $this->assertNull($this->wpdb()->grantByKey('seed:3')['expiry_notified_at'], 'El tercero espera la continuación.');
        $this->assertSame(1, $this->singleScheduleCalls);

        $this->assertSame(0, \HWE_Memberships_Scheduler::run(2));
        $this->assertNotNull($this->wpdb()->grantByKey('seed:3')['expiry_notified_at']);
    }

    public function testTheExpiringNoticeFiresOncePerGrant(): void
    {
        $this->seedMember(self::USER, ['level_id' => self::LEVEL]);
        $id = $this->seedGrant(1, self::USER, [
            'status'    => 'active',
            'starts_at' => $this->at(-10),
            'ends_at'   => $this->at(3),
        ]);

        Actions\expectDone('hwe_membership_expiring')
            ->once()
            ->with(self::USER, $id, self::LEVEL, 7);

        $this->assertSame(0, \HWE_Memberships_Scheduler::run(), 'Un aviso no es una transición.');
        $this->assertSame(
            $this->at(0),
            substr((string) $this->wpdb()->grantByKey('seed:1')['expiry_notified_at'], 0, 19)
        );

        $this->assertSame(0, \HWE_Memberships_Scheduler::run());
        $this->assertSame(
            $this->at(0),
            substr((string) $this->wpdb()->grantByKey('seed:1')['expiry_notified_at'], 0, 19),
            'El aviso no se repite.'
        );
    }

    public function testTheExpiringNoticeIsDisabledWithZeroDays(): void
    {
        $this->config = ['memberships' => ['expiring_notice_days' => '0']];
        $this->seedMember(self::USER, ['level_id' => self::LEVEL]);
        $this->seedGrant(1, self::USER, [
            'status'    => 'active',
            'starts_at' => $this->at(-10),
            'ends_at'   => $this->at(3),
        ]);

        Actions\expectDone('hwe_membership_expiring')->never();

        $this->assertSame(0, \HWE_Memberships_Scheduler::run());
        $this->assertNull($this->wpdb()->grantByKey('seed:1')['expiry_notified_at']);
    }

    public function testFrozenMembersAreSkippedAndNothingExpires(): void
    {
        $this->seedMember(self::USER, [
            'level_id'  => null,
            'frozen_at' => $this->at(-1),
        ]);
        $this->seedGrant(1, self::USER, [
            'status'    => 'active',
            'starts_at' => $this->at(-40),
            'ends_at'   => $this->at(-1),
        ]);
        $this->seedGrant(2, self::USER, [
            'status'    => 'scheduled',
            'starts_at' => $this->at(-1),
            'ends_at'   => $this->at(29),
        ]);
        $this->wpdb()->seedToken(self::USER, 'token-' . self::USER);

        Actions\expectDone('hwe_membership_activated')->never();
        Actions\expectDone('hwe_membership_expired')->never();
        Actions\expectDone('hwe_membership_expiring')->never();
        Actions\expectDone('hwe_membership_level_changed')->never();

        $this->assertSame(0, \HWE_Memberships_Scheduler::run());
        $this->assertSame('active', $this->wpdb()->grantByKey('seed:1')['status']);
        $this->assertSame('scheduled', $this->wpdb()->grantByKey('seed:2')['status']);
        $this->assertNull($this->wpdb()->tokens[0]['revoked_at']);
        $this->assertSame([], $this->wpdb()->event_rows);
    }

    public function testTheModuleOffDoesNothing(): void
    {
        $this->activePlugins = [];
        $this->seedMember(self::USER);
        $this->seedGrant(1, self::USER, [
            'status'    => 'scheduled',
            'starts_at' => $this->at(-1),
            'ends_at'   => $this->at(29),
        ]);

        $this->assertSame(0, \HWE_Memberships_Scheduler::run());
        $this->assertSame('scheduled', $this->wpdb()->grantByKey('seed:1')['status']);
        $this->assertSame([], $this->wpdb()->queries, 'Con el módulo apagado ni siquiera se consulta.');
        $this->assertSame([], $this->logged, 'No es un error: no se registra nada.');
    }

    public function testScheduledGrantAlreadyPastItsEndIsClosedWithoutAnActivationNotice(): void
    {
        $this->seedMember(self::USER);
        $id = $this->seedGrant(1, self::USER, [
            'status'    => 'scheduled',
            'starts_at' => $this->at(-40),
            'ends_at'   => $this->at(-10),
        ]);

        Actions\expectDone('hwe_membership_activated')->never();
        Actions\expectDone('hwe_membership_expired')
            ->once()
            ->with(self::USER, $id, self::LEVEL);
        Actions\expectDone('hwe_membership_level_changed')->never();

        $this->assertSame(1, \HWE_Memberships_Scheduler::run());
        $this->assertSame('ended', $this->wpdb()->grantByKey('seed:1')['status']);
        $this->assertSame(['expired'], $this->wpdb()->eventTypes());
    }
}
