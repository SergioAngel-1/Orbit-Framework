<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin `hwe-loyalty` (módulo `loyalty`), parte de tareas programadas.
 *
 * Se prueba la DECISIÓN de la tarea diaria y no el SQL del libro (eso vive en `LedgerTest`):
 * que activar programa un ÚNICO evento diario, que desactivar lo retira, que la caducidad se
 * procesa por lotes hasta agotarse o hasta el tope por ejecución (y que al tocar el tope queda
 * constancia para la siguiente pasada), y que con «Puntos» apagado el evento no hace nada.
 *
 * La caducidad se sustituye por un doble de `HWE_Loyalty_Ledger` (Mockery, clase alias) y el
 * interruptor por un doble de `HWE_Loyalty_Settings`: así el test mide cuántos lotes pide el
 * planificador, no cómo caduca un abono. Además se comprueba que el arranque del plugin no
 * recorre todos los usuarios (`plugins_loaded` debe limitarse a enganchar hooks).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SchedulerTest extends TestCase
{
    /** Reloj simulado (UTC) para que la fecha del evento sea determinista. */
    private int $now = 1_800_000_000;
    /** @var array<string,list<int>> Eventos programados por hook. */
    private array $events = [];
    /** @var int Llamadas a `wp_schedule_event`. */
    private int $scheduleCalls = 0;
    /** @var bool Resultado que devuelve `wp_schedule_event`. */
    private bool $scheduleResult = true;
    /** @var int Llamadas a `wp_clear_scheduled_hook`. */
    private int $clearCalls = 0;
    /** @var list<array{0:string,1:mixed}> Hooks de activación registrados. */
    private array $activationHooks = [];
    /** @var list<array{0:string,1:mixed}> Hooks de desactivación registrados. */
    private array $deactivationHooks = [];
    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var list<string> Plugins activos según `active_plugins`. */
    private array $activePlugins = ['hwe-loyalty/hwe-loyalty.php'];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->now           = 1_800_000_000;
        $this->events         = [];
        $this->scheduleCalls  = 0;
        $this->scheduleResult = true;
        $this->clearCalls     = 0;
        $this->config        = [];
        $this->logged        = [];

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
        Functions\when('wp_clear_scheduled_hook')->alias(
            function (string $hook): int {
                ++$this->clearCalls;
                $removed = count($this->events[$hook] ?? []);
                unset($this->events[$hook]);
                return $removed;
            }
        );
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false) => match ($name) {
                'hwe_config'     => $this->config,
                'active_plugins' => $this->activePlugins,
                default          => $default,
            }
        );
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => trim((string) $value),
            'esc_url_raw'         => static fn ($value) => trim((string) $value),
        ]);
    }

    /** Carga solo el planificador (sin el resto del plugin): los dobles se declaran por test. */
    private function loadScheduler(): void
    {
        $this->loadOwnFile('plugins/hwe-loyalty/includes/Scheduler.php');
    }

    /**
     * Doble del libro que devuelve los lotes indicados, en orden.
     *
     * @param list<int> $batches Resultado de cada llamada a `expireDue`.
     */
    private function ledgerReturning(array $batches): Mockery\MockInterface
    {
        $ledger = Mockery::mock('alias:HWE_Loyalty_Ledger');
        $ledger->shouldReceive('expireDue')
            ->with(\HWE_Loyalty_Scheduler::BATCH)
            ->times(count($batches))
            ->andReturn(...$batches);

        return $ledger;
    }

    /** Doble del interruptor «Puntos activos». */
    private function pointsActive(bool $active): Mockery\MockInterface
    {
        $settings = Mockery::mock('alias:HWE_Loyalty_Settings');
        $settings->shouldReceive('pointsActive')->once()->andReturn($active);

        return $settings;
    }

    // ── Programación y retirada del evento ──────────────────────────────────

    public function testScheduleCreatesASingleDailyEventEvenWhenCalledTwice(): void
    {
        $this->loadScheduler();

        $this->assertSame('hwe_loyalty_daily', \HWE_Loyalty_Scheduler::HOOK, 'El hook es el contrato público de la tarea.');

        \HWE_Loyalty_Scheduler::schedule();
        \HWE_Loyalty_Scheduler::schedule();

        $this->assertCount(1, $this->events[\HWE_Loyalty_Scheduler::HOOK], 'Programar dos veces no duplica el evento.');
        $this->assertSame(1, $this->scheduleCalls, 'La segunda llamada ve el evento ya programado y no lo crea.');
        $this->assertSame(
            $this->now + HOUR_IN_SECONDS,
            $this->events[\HWE_Loyalty_Scheduler::HOOK][0],
            'La primera pasada se deja para dentro de una hora: no compite con la petición de activación.'
        );
    }

    public function testAFailedScheduleIsLoggedInsteadOfSwallowed(): void
    {
        $this->loadScheduler();

        $this->scheduleResult = false;

        \HWE_Loyalty_Scheduler::schedule();

        $this->assertSame([], $this->events, 'Si WordPress no programa el evento, no se inventa uno.');
        $this->assertNotEmpty($this->logged, 'Un fallo al programar la tarea diaria no se traga en silencio.');
        $this->assertStringContainsString('[hwe] loyalty:', $this->logged[0]);
    }

    public function testRegisterRepairsTheDailyEventOnInitAndDoesNotDuplicateIt(): void
    {
        $this->loadScheduler();

        Actions\expectAdded('init')
            ->once()
            ->with([\HWE_Loyalty_Scheduler::class, 'schedule']);
        Actions\expectAdded(\HWE_Loyalty_Scheduler::HOOK)
            ->once()
            ->with([\HWE_Loyalty_Scheduler::class, 'runExpiry']);

        \HWE_Loyalty_Scheduler::register();

        // El callback de `init` es `schedule()` (idempotente): sin evento lo crea...
        $this->assertArrayNotHasKey(\HWE_Loyalty_Scheduler::HOOK, $this->events);
        \HWE_Loyalty_Scheduler::schedule();
        $this->assertCount(1, $this->events[\HWE_Loyalty_Scheduler::HOOK]);

        // ...y con el evento ya presente no lo duplica.
        \HWE_Loyalty_Scheduler::schedule();
        $this->assertCount(1, $this->events[\HWE_Loyalty_Scheduler::HOOK]);
        $this->assertSame(1, $this->scheduleCalls);
    }

    public function testUnscheduleRemovesEveryEventOfTheHook(): void
    {
        $this->loadScheduler();

        $this->events[\HWE_Loyalty_Scheduler::HOOK] = [
            $this->now + HOUR_IN_SECONDS,
            $this->now + 2 * DAY_IN_SECONDS,
        ];

        \HWE_Loyalty_Scheduler::unschedule();

        $this->assertSame(1, $this->clearCalls);
        $this->assertArrayNotHasKey(
            \HWE_Loyalty_Scheduler::HOOK,
            $this->events,
            'Desactivar retira TODAS las ocurrencias del hook, no solo la primera.'
        );
    }

    public function testActivationSchedulesTheEventAndDeactivationRemovesIt(): void
    {
        Functions\stubs([
            'register_activation_hook'   => function (string $file, mixed $callback): void {
                $this->activationHooks[] = [$file, $callback];
            },
            'register_deactivation_hook' => function (string $file, mixed $callback): void {
                $this->deactivationHooks[] = [$file, $callback];
            },
        ]);

        $this->loadOwnFile('plugins/hwe-loyalty/hwe-loyalty.php');

        $pluginFile = realpath(HWE_WP_CONTENT_DIR . '/plugins/hwe-loyalty/hwe-loyalty.php');
        $this->assertCount(1, $this->activationHooks, 'El plugin registra su hook de activación.');
        $this->assertSame($pluginFile, realpath($this->activationHooks[0][0]), 'El hook se registra sobre __FILE__.');
        $this->assertSame([\HWE_Loyalty_Scheduler::class, 'schedule'], $this->activationHooks[0][1]);

        $this->assertCount(1, $this->deactivationHooks, 'El plugin registra su hook de desactivación.');
        $this->assertSame($pluginFile, realpath($this->deactivationHooks[0][0]), 'El hook se registra sobre __FILE__.');
        $this->assertSame([\HWE_Loyalty_Scheduler::class, 'unschedule'], $this->deactivationHooks[0][1]);

        // Ejecutar los callbacks como haría WordPress: activar programa, desactivar retira.
        ( $this->activationHooks[0][1] )();
        $this->assertCount(1, $this->events[\HWE_Loyalty_Scheduler::HOOK]);

        ( $this->deactivationHooks[0][1] )();
        $this->assertArrayNotHasKey(\HWE_Loyalty_Scheduler::HOOK, $this->events);
    }

    public function testBootHooksTheExpiryAsACallbackOfTheDailyEvent(): void
    {
        if (!class_exists('WooCommerce')) {
            class_alias(\stdClass::class, 'WooCommerce');
        }

        $this->loadOwnFile('plugins/hwe-loyalty/hwe-loyalty.php');

        // El cumpleaños de la Tarea 7 se colgará del mismo hook con otro `add_action`: la
        // caducidad tiene que ser un callback propio, no el cuerpo del evento.
        Actions\expectAdded(\HWE_Loyalty_Scheduler::HOOK)
            ->once()
            ->with([\HWE_Loyalty_Scheduler::class, 'runExpiry']);

        \HWE_Loyalty::boot();
    }

    public function testBootDoesNotIterateAllUsers(): void
    {
        if (!class_exists('WooCommerce')) {
            class_alias(\stdClass::class, 'WooCommerce');
        }

        $this->loadOwnFile('plugins/hwe-loyalty/hwe-loyalty.php');

        Functions\expect('get_users')->never();
        Functions\expect('get_user_meta')->never();

        \HWE_Loyalty::boot();
    }

    // ── Caducidad por lotes ─────────────────────────────────────────────────

    public function testTheDailyRunExpiresInBatchesUntilTheLedgerIsDrained(): void
    {
        $this->loadScheduler();

        $this->pointsActive(true);
        $this->ledgerReturning([
            \HWE_Loyalty_Scheduler::BATCH,
            \HWE_Loyalty_Scheduler::BATCH,
            7,
        ]);

        $this->assertSame(
            \HWE_Loyalty_Scheduler::BATCH * 2 + 7,
            \HWE_Loyalty_Scheduler::runExpiry(),
            'Devuelve el total procesado y para cuando un lote vuelve incompleto.'
        );
        $this->assertSame([], $this->logged, 'Agotar la caducidad no es un error: no se registra nada.');
    }

    public function testTheDailyRunStopsAtTheCapAndLeavesWorkLogged(): void
    {
        $this->loadScheduler();

        $this->pointsActive(true);
        $this->ledgerReturning(
            array_fill(0, \HWE_Loyalty_Scheduler::MAX_BATCHES, \HWE_Loyalty_Scheduler::BATCH)
        );

        $this->assertSame(
            \HWE_Loyalty_Scheduler::BATCH * \HWE_Loyalty_Scheduler::MAX_BATCHES,
            \HWE_Loyalty_Scheduler::runExpiry(),
            'El tope por ejecución evita que una tarea diaria se eternice.'
        );
        $this->assertNotEmpty($this->logged, 'Al tocar el tope queda constancia de que hay trabajo pendiente.');
        $this->assertStringContainsString('[hwe] loyalty:', $this->logged[0]);
        $this->assertStringContainsString('tope', $this->logged[0]);
    }

    public function testTheDailyRunDoesNothingWhenPointsAreDisabled(): void
    {
        $this->loadScheduler();

        $this->pointsActive(false);
        Mockery::mock('alias:HWE_Loyalty_Ledger')->shouldReceive('expireDue')->never();

        $this->assertSame(0, \HWE_Loyalty_Scheduler::runExpiry(), 'Con «Puntos» apagado no hay caducidad que procesar.');
        $this->assertSame([], $this->logged, 'No es un error: no se registra nada.');
    }
}
