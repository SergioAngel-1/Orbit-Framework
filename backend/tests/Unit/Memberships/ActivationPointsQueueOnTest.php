<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once HWE_BACKEND_DIR . '/tests/Stubs/wp-memberships-service-wpdb.php';
require_once __DIR__ . '/ActivationPointsQueueFixture.php';

/**
 * Plugin `hwe-memberships`, cola de reversiones de puntos con `loyalty` de vuelta (M-3).
 *
 * Se prueba que la cola se aplica una sola vez, que un abono que `loyalty` rechaza se reintenta
 * hasta el tope y se descarta con constancia, y que la tarea diaria también la vacía. El
 * contrato de `loyalty` se simula con `Functions\when()`: existe antes de cargar el beneficio,
 * como cuando el plugin está activo.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ActivationPointsQueueOnTest extends TestCase
{
    use ActivationPointsQueueFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQueueFixture();
        $this->defineLoyaltyContract();
    }

    public function testPendingReversalsAreAppliedOnceWhenLoyaltyIsBack(): void
    {
        $this->seedQueueEntry('order:55:item:9');

        $this->assertSame(1, \HWE_Memberships_Benefit_Activation_Points::flushPending());

        $this->assertSame(
            [['membership:order:55:item:9', 'membership:reversal:order:55:item:9']],
            $this->loyalty['reverse_calls']
        );
        $this->assertSame([], $this->queue(), 'La cola se vacía tras aplicar la reversión.');

        $this->assertSame(0, \HWE_Memberships_Benefit_Activation_Points::flushPending());
        $this->assertCount(1, $this->loyalty['reverse_calls'], 'Una segunda pasada no repite el movimiento.');
    }

    public function testAnUnappliedReversalIsRetriedAndThenDiscarded(): void
    {
        $this->loyalty['reverse'] = false;
        $this->seedQueueEntry('order:58:item:3');

        \HWE_Memberships_Benefit_Activation_Points::flushPending();
        $this->assertSame(1, $this->queue()['order:58:item:3']['attempts']);

        \HWE_Memberships_Benefit_Activation_Points::flushPending();
        $this->assertSame(2, $this->queue()['order:58:item:3']['attempts']);

        \HWE_Memberships_Benefit_Activation_Points::flushPending();
        $this->assertSame([], $this->queue(), 'Tras el tope se descarta para no reintentar sin fin.');
        $this->assertNotEmpty(array_filter(
            $this->logged,
            static fn (string $line): bool => str_contains($line, 'se descarta la reversión pendiente')
        ));
    }

    public function testTheDailyTaskFlushesTheQueue(): void
    {
        $this->loadOwnFile('plugins/hwe-memberships/includes/Scheduler.php');
        $this->seedQueueEntry('order:59:item:4');

        $this->assertSame(0, \HWE_Memberships_Scheduler::run(1), 'Sin transiciones que materializar, la tarea no toca socios.');

        $this->assertSame(
            [['membership:order:59:item:4', 'membership:reversal:order:59:item:4']],
            $this->loyalty['reverse_calls']
        );
        $this->assertSame([], $this->queue());
    }
}
