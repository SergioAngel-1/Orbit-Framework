<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Actions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once HWE_BACKEND_DIR . '/tests/Stubs/wp-memberships-service-wpdb.php';
require_once __DIR__ . '/ActivationPointsQueueFixture.php';

/**
 * Plugin `hwe-memberships`, reversión de los puntos por activación con `loyalty` apagado (M-3).
 *
 * Con `loyalty` cargado, `ActivationPoints` engancha `hwe_membership_revoked` y revierte el
 * abono en el acto. Sin él no hay contraparte: la revocación se perdería si no se recuerda. Se
 * prueba la DECISIÓN de la cola:
 *  · sin `loyalty`, la revocación de una compra queda registrada con su clave de idempotencia;
 *  · la misma revocación no crea dos entradas;
 *  · la cola no se procesa mientras `loyalty` siga ausente;
 *  · una concesión que no es de compra no se encola.
 *
 * Cada test corre en su propio proceso para que las funciones del contrato de `loyalty` no
 * existan de verdad al comprobarse con `function_exists()`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ActivationPointsQueueOffTest extends TestCase
{
    use ActivationPointsQueueFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQueueFixture();
    }

    public function testWithoutLoyaltyARevocationIsQueuedInsteadOfLost(): void
    {
        $benefit = $this->benefit();

        Actions\expectAdded('hwe_membership_revoked')
            ->once()
            ->with([$benefit, 'onRevoked'], 10, 3);

        $benefit->register();

        $grantId = $this->seedOrderGrant('order:55:item:9');
        $benefit->onRevoked(self::BUYER, $grantId, self::LEVEL);

        $queue = $this->queue();
        $this->assertArrayHasKey('order:55:item:9', $queue);
        $this->assertSame(self::BUYER, $queue['order:55:item:9']['user_id']);
        $this->assertSame($grantId, $queue['order:55:item:9']['grant_id']);
        $this->assertSame(self::LEVEL, $queue['order:55:item:9']['level_id']);
        $this->assertSame(0, $queue['order:55:item:9']['attempts']);
        $this->assertNotEmpty(array_filter(
            $this->logged,
            static fn (string $line): bool => str_contains($line, '[hwe] memberships:') && str_contains($line, 'queda pendiente')
        ));
    }

    public function testTheSameRevocationQueuesOnlyOneEntry(): void
    {
        $benefit = $this->benefit();
        $grantId = $this->seedOrderGrant('order:56:item:1');

        $benefit->onRevoked(self::BUYER, $grantId, self::LEVEL);
        $benefit->onRevoked(self::BUYER, $grantId, self::LEVEL);

        $this->assertCount(1, $this->queue());
        $this->assertSame(0, $this->queue()['order:56:item:1']['attempts'], 'Reencolar no cuenta como intento.');
    }

    public function testTheQueueIsLeftUntouchedWhileLoyaltyIsOff(): void
    {
        $this->seedQueueEntry('order:57:item:2');

        \HWE_Memberships_Benefit_Activation_Points::flushPendingIfAny();

        $this->assertCount(1, $this->queue());
        $this->assertSame(0, \HWE_Memberships_Benefit_Activation_Points::flushPending());
        $this->assertCount(1, $this->queue());
    }

    public function testARevocationOutsideAnOrderIsIgnored(): void
    {
        $benefit = $this->benefit();
        $grantId = \HWE_Memberships_Grants::grant(
            self::BUYER,
            self::LEVEL,
            30,
            'admin',
            'admin:1',
            array()
        );

        $this->assertIsInt($grantId);
        $benefit->onRevoked(self::BUYER, $grantId, self::LEVEL);

        $this->assertSame([], $this->queue());
    }
}
