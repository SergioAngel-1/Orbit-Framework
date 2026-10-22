<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin `hwe-loyalty` (módulo `loyalty`), parte de libro mayor: el corazón contable.
 *
 * Lo que se prueba aquí es la DECISIÓN del libro, no el SQL literal: qué fila nace, cuánto
 * consume cada cargo, qué caducidad hereda una devolución, qué se rechaza y cuándo se dispara
 * una acción. El doble de `$wpdb` mantiene el estado mínimo (libro, consumos y saldos) e
 * interpreta solo las sentencias que el libro emite, para poder comprobar el efecto real:
 * saldo derivado, remanentes y consumo FIFO entre varios abonos.
 *
 * La lógica pura (reparto FIFO y caducidad de la devolución) se prueba también sin SQL, para
 * que el orden no dependa de que la base de datos respete un `ORDER BY`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LedgerTest extends TestCase
{
    private const USER = 7;
    private const MISSING_USER = 999;

    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var int Reloj simulado (UTC) que usan las caducidades. */
    private int $now = 1_800_000_000;
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [];
        $this->now    = 1_800_000_000;
        $this->logged = [];

        $GLOBALS['wpdb'] = new TestLedgerWpdb();

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false) => 'hwe_config' === $name ? $this->config : $default
        );
        Functions\when('get_userdata')->alias(
            fn (int $id) => self::USER === $id ? (object) ['ID' => self::USER] : false
        );
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });

        $this->loadOwnFile('plugins/hwe-loyalty/hwe-loyalty.php');
    }

    private function wpdb(): TestLedgerWpdb
    {
        return $GLOBALS['wpdb'];
    }

    private function day(int $days): string
    {
        return gmdate('Y-m-d H:i:s', $this->now + $days * DAY_IN_SECONDS);
    }

    private function iso(string $mysql): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', strtotime($mysql . ' UTC'));
    }

    /** @return list<array<string,mixed>> Filas del libro, en orden de inserción. */
    private function entries(): array
    {
        return array_values($this->wpdb()->ledger);
    }

    /** @return list<array<string,mixed>> */
    private function entryRowsOfType(string $type): array
    {
        return array_values(array_filter(
            $this->entries(),
            static fn (array $row): bool => $type === $row['type'],
        ));
    }

    // ── Abonos ──────────────────────────────────────────────────────────────

    public function testCreditCreatesAnEntryAndAddsToTheBalance(): void
    {
        $id = \HWE_Loyalty_Ledger::credit(self::USER, 100, 'cashback', 'earn:order:55', ['type' => 'order', 'id' => 55], 0, ['order' => 55]);

        $this->assertIsInt($id);
        $row = $this->entries()[0];
        $this->assertSame(self::USER, $row['user_id']);
        $this->assertSame(100, $row['points']);
        $this->assertSame(100, $row['remaining_points'], 'Un abono nace con todo su remanente disponible.');
        $this->assertSame('cashback', $row['type']);
        $this->assertSame('order', $row['ref_type']);
        $this->assertSame('55', $row['ref_id']);
        $this->assertSame('earn:order:55', $row['idempotency_key']);
        $this->assertSame('system', $row['actor']);
        $this->assertNull($row['expires_at'], 'Sin días de caducidad, el abono no caduca.');

        $balance = \HWE_Loyalty_Ledger::balance(self::USER);
        $this->assertSame(100, $balance['balance']);
        $this->assertSame(100, $balance['earned']);
        $this->assertSame(0, $balance['used']);
    }

    public function testTheSameKeyIsCreditedOnlyOnce(): void
    {
        $first  = \HWE_Loyalty_Ledger::credit(self::USER, 100, 'cashback', 'earn:order:55');
        $second = \HWE_Loyalty_Ledger::credit(self::USER, 100, 'cashback', 'earn:order:55');

        $this->assertIsInt($first);
        $this->assertNull($second, 'La segunda vez la clave ya existía: no se abona ni se falla.');
        $this->assertCount(1, $this->entries());
        $this->assertSame(100, \HWE_Loyalty_Ledger::balance(self::USER)['balance']);
    }

    public function testDefaultExpiryComesFromSettingsAndZeroMeansNoExpiry(): void
    {
        $this->config['loyalty'] = ['expiry_days' => 30];

        \HWE_Loyalty_Ledger::credit(self::USER, 10, 'registration', 'registration:user:7');

        $this->assertSame($this->day(30), $this->entries()[0]['expires_at']);
    }

    public function testAnExplicitZeroOverridesTheConfiguredExpiry(): void
    {
        $this->config['loyalty'] = ['expiry_days' => 30];

        \HWE_Loyalty_Ledger::credit(self::USER, 10, 'admin_credit', 'manual:1', [], 0);

        $this->assertNull($this->entries()[0]['expires_at'], '0 días = este abono no caduca.');
    }

    // ── Cargos y FIFO ───────────────────────────────────────────────────────

    public function testADebitLargerThanTheBalanceReturnsNullAndWritesNothing(): void
    {
        \HWE_Loyalty_Ledger::credit(self::USER, 50, 'cashback', 'earn:order:1');

        $id = \HWE_Loyalty_Ledger::debit(self::USER, 80, 'redeem', 'redeem:order:9');

        $this->assertNull($id);
        $this->assertCount(1, $this->entries(), 'No nace un cargo sin respaldo.');
        $this->assertSame(50, \HWE_Loyalty_Ledger::balance(self::USER)['balance']);
    }

    public function testTheDebitConsumesTheEarliestExpiryFirstAndLeavesUnlimitedCreditsForLast(): void
    {
        $late      = \HWE_Loyalty_Ledger::credit(self::USER, 100, 'cashback', 'earn:order:1', [], 10);
        $early     = \HWE_Loyalty_Ledger::credit(self::USER, 100, 'cashback', 'earn:order:2', [], 3);
        $unlimited = \HWE_Loyalty_Ledger::credit(self::USER, 100, 'cashback', 'earn:order:3', [], 0);

        $debit = \HWE_Loyalty_Ledger::debit(self::USER, 250, 'redeem', 'redeem:order:9');

        $this->assertIsInt($debit);
        $consumed = array_values(array_filter(
            $this->wpdb()->consumptions,
            static fn (array $row): bool => (int) $row['debit_id'] === $debit,
        ));
        $this->assertSame(
            [
                ['credit_id' => $early, 'points' => 100],
                ['credit_id' => $late, 'points' => 100],
                ['credit_id' => $unlimited, 'points' => 50],
            ],
            array_map(
                static fn (array $row): array => ['credit_id' => (int) $row['credit_id'], 'points' => (int) $row['points']],
                $consumed,
            ),
            'Primero el que caduca antes, después el siguiente y al final el que no caduca.'
        );
        $this->assertSame(0, $this->wpdb()->ledger[$early]['remaining_points']);
        $this->assertSame(0, $this->wpdb()->ledger[$late]['remaining_points']);
        $this->assertSame(50, $this->wpdb()->ledger[$unlimited]['remaining_points']);
        $this->assertSame(50, \HWE_Loyalty_Ledger::balance(self::USER)['balance']);

        $debitRow = $this->wpdb()->ledger[$debit];
        $this->assertSame(-250, $debitRow['points'], 'El cargo se guarda con signo negativo.');
        $this->assertNull($debitRow['remaining_points']);
    }

    public function testFifoAllocationIsPureAndOrderIndependentOfTheRows(): void
    {
        $allocation = \HWE_Loyalty_Ledger::allocate(70, [
            ['id' => 3, 'remaining_points' => 50, 'expires_at' => null],
            ['id' => 1, 'remaining_points' => 40, 'expires_at' => '2026-11-20 00:00:00'],
            ['id' => 2, 'remaining_points' => 0, 'expires_at' => '2026-11-10 00:00:00'],
            ['id' => 4, 'remaining_points' => 80, 'expires_at' => '2026-11-15 00:00:00'],
        ]);

        $this->assertSame(
            [['id' => 4, 'points' => 70]],
            $allocation,
            'Salta remanentes vacíos y consume por fecha; los ilimitados quedan al final.'
        );

        $this->assertSame(
            [['id' => 1, 'points' => 40], ['id' => 4, 'points' => 80], ['id' => 3, 'points' => 20]],
            \HWE_Loyalty_Ledger::allocate(140, [
                ['id' => 1, 'remaining_points' => 40, 'expires_at' => '2026-11-10 00:00:00'],
                ['id' => 3, 'remaining_points' => 50, 'expires_at' => null],
                ['id' => 4, 'remaining_points' => 80, 'expires_at' => '2026-11-15 00:00:00'],
            ]),
        );
    }

    public function testDebitOfAnUnknownTypeOrWithoutAKeyWritesNothing(): void
    {
        \HWE_Loyalty_Ledger::credit(self::USER, 100, 'cashback', 'earn:order:1');

        $this->assertNull(\HWE_Loyalty_Ledger::debit(self::USER, 10, 'not_a_type', 'debit:1'));
        $this->assertNull(\HWE_Loyalty_Ledger::debit(self::USER, 10, 'redeem', ''));

        $this->assertCount(1, $this->entries());
    }

    // ── Devolución del cargo ────────────────────────────────────────────────

    public function testReverseDebitRefundsExactlyWhatItConsumedWithTheFurthestExpiry(): void
    {
        $early = \HWE_Loyalty_Ledger::credit(self::USER, 50, 'cashback', 'earn:order:1', [], 5);
        $late  = \HWE_Loyalty_Ledger::credit(self::USER, 50, 'cashback', 'earn:order:2', [], 20);
        $debit = \HWE_Loyalty_Ledger::debit(self::USER, 100, 'redeem', 'redeem:order:9');
        $this->assertIsInt($debit);

        $refund = \HWE_Loyalty_Ledger::reverseDebit('redeem:order:9', 'refund:order:9');

        $this->assertIsInt($refund);
        $row = $this->wpdb()->ledger[$refund];
        $this->assertSame('redeem_refund', $row['type']);
        $this->assertSame(100, $row['points']);
        $this->assertSame(100, $row['remaining_points']);
        $this->assertSame(
            $this->wpdb()->ledger[$late]['expires_at'],
            $row['expires_at'],
            'La devolución caduca cuando el más lejano de los abonos consumidos.'
        );
        $this->assertNotSame($this->wpdb()->ledger[$early]['expires_at'], $row['expires_at']);

        $balance = \HWE_Loyalty_Ledger::balance(self::USER);
        $this->assertSame(100, $balance['balance']);
        $this->assertSame(0, $balance['used'], 'La devolución deshace el consumo, no genera ganancia nueva.');
    }

    public function testARefundOfAConsumptionThatIncludedANonExpiringCreditNeverExpires(): void
    {
        \HWE_Loyalty_Ledger::credit(self::USER, 60, 'cashback', 'earn:order:1', [], 5);
        \HWE_Loyalty_Ledger::credit(self::USER, 40, 'cashback', 'earn:order:2', [], 0);
        $this->assertIsInt(\HWE_Loyalty_Ledger::debit(self::USER, 100, 'redeem', 'redeem:order:9'));

        $refund = \HWE_Loyalty_Ledger::reverseDebit('redeem:order:9', 'refund:order:9');

        $this->assertIsInt($refund);
        $this->assertNull($this->wpdb()->ledger[$refund]['expires_at']);
    }

    public function testReverseDebitIsIdempotentAndOnlyRefundsAKnownDebit(): void
    {
        \HWE_Loyalty_Ledger::credit(self::USER, 100, 'cashback', 'earn:order:1');
        \HWE_Loyalty_Ledger::debit(self::USER, 100, 'redeem', 'redeem:order:9');

        $first  = \HWE_Loyalty_Ledger::reverseDebit('redeem:order:9', 'refund:order:9');
        $second = \HWE_Loyalty_Ledger::reverseDebit('redeem:order:9', 'refund:order:9');

        $this->assertIsInt($first);
        $this->assertNull($second);
        $this->assertCount(1, $this->entryRowsOfType('redeem_refund'));
        $this->assertNull(\HWE_Loyalty_Ledger::reverseDebit('redeem:order:404', 'refund:order:404'));
        $this->assertSame(100, \HWE_Loyalty_Ledger::balance(self::USER)['balance']);
    }

    public function testARefundPastExpiryKeepsTheDateItHad(): void
    {
        \HWE_Loyalty_Ledger::credit(self::USER, 100, 'cashback', 'earn:order:1', [], 5);
        \HWE_Loyalty_Ledger::debit(self::USER, 100, 'redeem', 'redeem:order:9');

        $this->now += 10 * DAY_IN_SECONDS;
        $refund     = \HWE_Loyalty_Ledger::reverseDebit('redeem:order:9', 'refund:order:9');

        $this->assertIsInt($refund);
        $this->assertSame($this->day(-5), $this->wpdb()->ledger[$refund]['expires_at'], 'Se devuelve tal cual, aunque ya haya vencido: la caducidad la reapa la tarea diaria.');
    }

    // ── Caducidad ───────────────────────────────────────────────────────────

    public function testExpireDueCreatesOneExpirePerDueCreditAndDoesNotRepeat(): void
    {
        $due   = \HWE_Loyalty_Ledger::credit(self::USER, 30, 'cashback', 'earn:order:1', [], 1);
        $later = \HWE_Loyalty_Ledger::credit(self::USER, 20, 'cashback', 'earn:order:2', [], 5);

        $this->now += 2 * DAY_IN_SECONDS;

        $this->assertSame(1, \HWE_Loyalty_Ledger::expireDue(10), 'Solo el abono vencido con remanente.');
        $expired = $this->entryRowsOfType('expire');
        $this->assertCount(1, $expired);
        $this->assertSame(-30, $expired[0]['points']);
        $this->assertSame('expire:entry:' . $due, $expired[0]['idempotency_key']);
        $this->assertSame(0, $this->wpdb()->ledger[$due]['remaining_points']);
        $this->assertSame(20, $this->wpdb()->ledger[$later]['remaining_points']);

        $this->assertSame(0, \HWE_Loyalty_Ledger::expireDue(10), 'La segunda pasada no repite.');
        $this->assertCount(1, $this->entryRowsOfType('expire'));
        $this->assertSame(20, \HWE_Loyalty_Ledger::balance(self::USER)['balance']);
    }

    public function testExpireDueProcessesAtMostOneBatch(): void
    {
        foreach ([1, 2, 3] as $index) {
            \HWE_Loyalty_Ledger::credit(self::USER, 10, 'cashback', 'earn:order:' . $index, [], 1);
        }
        $this->now += 2 * DAY_IN_SECONDS;

        $this->assertSame(2, \HWE_Loyalty_Ledger::expireDue(2));
        $this->assertSame(1, \HWE_Loyalty_Ledger::expireDue(2));
        $this->assertSame(0, \HWE_Loyalty_Ledger::expireDue(2));
    }

    // ── Transacción, bloqueo y acciones ─────────────────────────────────────

    public function testEveryMovementRunsInATransactionThatLocksTheBalanceRow(): void
    {
        \HWE_Loyalty_Ledger::credit(self::USER, 100, 'cashback', 'earn:order:1');

        $this->assertContains('START TRANSACTION', $this->wpdb()->queries);
        $this->assertContains('COMMIT', $this->wpdb()->queries);
        $this->assertNotContains('ROLLBACK', $this->wpdb()->queries);

        $locked = array_values(array_filter(
            $this->wpdb()->queries,
            static fn (string $query): bool => str_contains($query, 'FOR UPDATE')
                && str_contains($query, 'hwe_loyalty_balances'),
        ));
        $this->assertNotEmpty($locked, 'Toda operación bloquea la fila de saldo del usuario.');
        $this->assertStringContainsString('WHERE user_id = %d FOR UPDATE', $locked[0]);
    }

    public function testAFailedWriteRollsBackAndNeverFiresTheAction(): void
    {
        Actions\expectDone('hwe_loyalty_credited')->never();
        $this->wpdb()->failQueryNeedle = 'UPDATE wp_hwe_loyalty_balances';

        $id = \HWE_Loyalty_Ledger::credit(self::USER, 100, 'cashback', 'earn:order:1');

        $this->assertNull($id);
        $this->assertContains('ROLLBACK', $this->wpdb()->queries);
        $this->assertNotContains('COMMIT', $this->wpdb()->queries);
        $this->assertSame(0, \HWE_Loyalty_Ledger::balance(self::USER)['balance']);
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] loyalty:', $this->logged[0]);
    }

    public function testActionsAreFiredOnlyAfterCommit(): void
    {
        Actions\expectDone('hwe_loyalty_credited')
            ->once()
            ->with(self::USER, 100, 'cashback', ['type' => 'order', 'id' => '55'])
            ->whenHappen(function (): void {
                $this->wpdb()->events[] = 'action:hwe_loyalty_credited';
            });
        Actions\expectDone('hwe_loyalty_debited')
            ->once()
            ->whenHappen(function (): void {
                $this->wpdb()->events[] = 'action:hwe_loyalty_debited';
            });

        \HWE_Loyalty_Ledger::credit(self::USER, 100, 'cashback', 'earn:order:55', ['type' => 'order', 'id' => 55]);
        \HWE_Loyalty_Ledger::debit(self::USER, 40, 'redeem', 'redeem:order:9');

        $commit = array_search('COMMIT', $this->wpdb()->events, true);
        $credit = array_search('action:hwe_loyalty_credited', $this->wpdb()->events, true);
        $debit  = array_search('action:hwe_loyalty_debited', $this->wpdb()->events, true);

        $this->assertIsInt($commit);
        $this->assertIsInt($credit);
        $this->assertIsInt($debit);
        $this->assertGreaterThan($commit, $credit, 'El abono se anuncia cuando ya está confirmado.');
        $this->assertGreaterThan($commit, $debit, 'El cargo se anuncia cuando ya está confirmado.');
    }

    public function testExpiryIsAnnouncedAfterCommit(): void
    {
        \HWE_Loyalty_Ledger::credit(self::USER, 30, 'cashback', 'earn:order:1', [], 1);
        $this->now += 2 * DAY_IN_SECONDS;

        Actions\expectDone('hwe_loyalty_expired')
            ->once()
            ->with(self::USER, 30, 'expire', ['type' => 'entry', 'id' => '1'])
            ->whenHappen(function (): void {
                $this->wpdb()->events[] = 'action:hwe_loyalty_expired';
            });

        \HWE_Loyalty_Ledger::expireDue();

        $commit = array_search('COMMIT', $this->wpdb()->events, true);
        $action = array_search('action:hwe_loyalty_expired', $this->wpdb()->events, true);
        $this->assertIsInt($commit);
        $this->assertIsInt($action);
        $this->assertGreaterThan($commit, $action);
    }

    // ── Entradas inválidas ──────────────────────────────────────────────────

    public function testNonPositivePointsOrAnUnknownUserAreRejectedWithoutWriting(): void
    {
        $this->assertNull(\HWE_Loyalty_Ledger::credit(self::USER, 0, 'cashback', 'k:1'));
        $this->assertNull(\HWE_Loyalty_Ledger::credit(self::USER, -5, 'cashback', 'k:2'));
        $this->assertNull(\HWE_Loyalty_Ledger::credit(self::MISSING_USER, 10, 'cashback', 'k:3'));
        $this->assertNull(\HWE_Loyalty_Ledger::credit(0, 10, 'cashback', 'k:4'));
        $this->assertNull(\HWE_Loyalty_Ledger::debit(self::USER, 0, 'redeem', 'k:5'));

        $this->assertSame([], $this->entries());
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] loyalty:', $this->logged[0]);
    }

    public function testCreditRejectsATypeThatIsNotAnEarning(): void
    {
        $this->assertNull(\HWE_Loyalty_Ledger::credit(self::USER, 10, 'redeem', 'k:1'));
        $this->assertNull(\HWE_Loyalty_Ledger::debit(self::USER, 10, 'cashback', 'k:2'));
        $this->assertSame([], $this->entries());
    }

    public function testEntryTypesComeFromOnePlaceAndAreFilterable(): void
    {
        $this->assertTrue(\HWE_Loyalty_EntryTypes::isCredit('cashback'));
        $this->assertTrue(\HWE_Loyalty_EntryTypes::isDebit('redeem'));
        $this->assertFalse(\HWE_Loyalty_EntryTypes::isCredit('redeem'));
        $this->assertFalse(\HWE_Loyalty_EntryTypes::isDebit('cashback'));
        $this->assertSame('redeem_refund', \HWE_Loyalty_EntryTypes::refundFor('redeem'));

        Filters\expectApplied('hwe_loyalty_entry_types')
            ->once()
            ->andReturnUsing(static function (array $types): array {
                $types['campaign_bonus'] = 'credit';
                return $types;
            });

        $this->assertIsInt(\HWE_Loyalty_Ledger::credit(self::USER, 25, 'campaign_bonus', 'campaign:1'));
        $this->assertSame('campaign_bonus', $this->entries()[0]['type']);
    }

    // ── Lecturas ────────────────────────────────────────────────────────────

    public function testBalanceOfAUserWithoutMovementsIsZero(): void
    {
        $this->assertSame(
            ['balance' => 0, 'earned' => 0, 'used' => 0],
            \HWE_Loyalty_Ledger::balance(self::USER)
        );
    }

    public function testExpiringSoonSumsTheRemaindersInsideTheWindow(): void
    {
        \HWE_Loyalty_Ledger::credit(self::USER, 30, 'cashback', 'earn:order:1', [], 10);
        \HWE_Loyalty_Ledger::credit(self::USER, 40, 'cashback', 'earn:order:2', [], 60);
        \HWE_Loyalty_Ledger::credit(self::USER, 50, 'cashback', 'earn:order:3', [], 0);

        $soon = \HWE_Loyalty_Ledger::expiringSoon(self::USER);

        $this->assertSame(30, $soon['points']);
        $this->assertSame($this->iso($this->day(10)), $soon['date']);
        $this->assertSame(['points' => 0, 'date' => null], \HWE_Loyalty_Ledger::expiringSoon(self::MISSING_USER));
    }

    public function testHistoryIsPagedNewestFirstAndCarriesStructuredData(): void
    {
        \HWE_Loyalty_Ledger::credit(self::USER, 10, 'cashback', 'earn:order:1', ['type' => 'order', 'id' => 1], 0, ['order' => 1]);
        \HWE_Loyalty_Ledger::credit(self::USER, 20, 'admin_credit', 'manual:1', [], 0, ['order' => 2, 'note' => 'Ajuste']);

        $page = \HWE_Loyalty_Ledger::history(self::USER, 1, 10);

        $this->assertSame(2, $page['total']);
        $this->assertCount(2, $page['items']);
        $first = $page['items'][0];
        $this->assertSame('admin_credit', $first['type']);
        $this->assertSame(20, $first['points']);
        $this->assertSame(['order' => 2], $first['data'], 'La nota va en su columna, no dentro de data.');
        $this->assertSame('Ajuste', $first['note']);
        $this->assertSame(['type' => 'order', 'id' => '1'], $page['items'][1]['ref']);
        $this->assertSame($this->iso(gmdate('Y-m-d H:i:s', $this->now)), $first['created_at']);
    }

    public function testHistoryClampsThePageAndPageSize(): void
    {
        \HWE_Loyalty_Ledger::credit(self::USER, 10, 'cashback', 'earn:order:1');

        $page = \HWE_Loyalty_Ledger::history(self::USER, 0, 5000);

        $this->assertSame(1, $page['total']);
        $this->assertCount(1, $page['items']);
    }
}

/**
 * Doble de `$wpdb` con estado: libro, consumos y saldos.
 *
 * No interpreta SQL arbitrario. Solo entiende las sentencias que emite el libro, que son
 * estables y están documentadas en `HWE_Loyalty_Ledger`: crear la fila de saldo, bloquearla,
 * sumar/restar, mover el remanente de un abono, leer abonos vivos o vencidos, leer un cargo
 * por clave, leer los consumos de un cargo y paginar el historial. Cualquier otra consulta se
 * registra y se responde como vacía, igual que haría un `$wpdb` real sin filas.
 */
final class TestLedgerWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var list<array{0:string,1:array<string,mixed>}> */
    public array $inserts = [];
    /** @var list<string> */
    public array $queries = [];
    /** @var list<string> Consultas y acciones en orden, para comprobar COMMIT ↔ acción. */
    public array $events = [];
    /** @var array<int,array<string,mixed>> id => fila del libro */
    public array $ledger = [];
    /** @var list<array<string,mixed>> */
    public array $consumptions = [];
    /** @var array<int,array{balance:int,earned:int,used:int}> */
    public array $balances = [];
    public string $failQueryNeedle = '';
    public string $failInsertSuffix = '';
    private int $nextId = 1;

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
        $this->inserts[] = [$table, $data];

        if ('' !== $this->failInsertSuffix && str_ends_with($table, $this->failInsertSuffix)) {
            return false;
        }

        if (str_ends_with($table, 'hwe_loyalty_ledger')) {
            $key = (string) ($data['idempotency_key'] ?? '');
            if ('' !== $key && null !== $this->ledgerIdByKey($key)) {
                return false;
            }
            $id              = $this->nextId++;
            $this->insert_id = $id;
            $this->ledger[$id] = $data + ['id' => $id];
            return 1;
        }

        if (str_ends_with($table, 'hwe_loyalty_ledger_consumptions')) {
            $id              = $this->nextId++;
            $this->insert_id = $id;
            $this->consumptions[] = $data + ['id' => $id];
            return 1;
        }

        $this->insert_id = $this->nextId++;
        return 1;
    }

    public function query(string $sql): int|false
    {
        $this->queries[] = $sql;
        $this->events[]  = $sql;

        if ('' !== $this->failQueryNeedle && str_contains($sql, $this->failQueryNeedle)) {
            return false;
        }

        $args = $this->args($sql);

        if (str_starts_with($sql, 'INSERT IGNORE INTO ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[0] ?? 0);
            $this->balances[$userId] ??= ['balance' => 0, 'earned' => 0, 'used' => 0];
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) $args[count($args) - 1];
            $row    = $this->balances[$userId] ?? ['balance' => 0, 'earned' => 0, 'used' => 0];

            if (str_contains($sql, 'balance = balance +')) {
                $row['balance'] += (int) $args[0];
                $row['earned']  += (int) $args[1];
            } elseif (str_contains($sql, 'balance = balance -')) {
                $row['balance'] -= (int) $args[0];
                $row['used']    += (int) $args[1];
            } elseif (str_contains($sql, 'SET balance = %d, used = %d')) {
                $row['balance'] = (int) $args[0];
                $row['used']    = (int) $args[1];
            } elseif (str_contains($sql, 'SET balance = %d, updated_at')) {
                $row['balance'] = (int) $args[0];
            }

            $this->balances[$userId] = $row;
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->prefix . 'hwe_loyalty_ledger')) {
            if (str_contains($sql, 'remaining_points = remaining_points - %d WHERE id = %d')) {
                [$points, $id, $minimum] = $args;
                if (isset($this->ledger[(int) $id]) && (int) $this->ledger[(int) $id]['remaining_points'] >= (int) $minimum) {
                    $this->ledger[(int) $id]['remaining_points'] -= (int) $points;
                    return 1;
                }
                return 0;
            }
            if (str_contains($sql, 'SET remaining_points = 0 WHERE id = %d')) {
                $id = (int) ($args[0] ?? 0);
                if (isset($this->ledger[$id])) {
                    $this->ledger[$id]['remaining_points'] = 0;
                    return 1;
                }
                return 0;
            }
        }

        return 1;
    }

    public function get_var(string $sql): string|int|null
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'COUNT(*)')) {
            return count($this->ledgerFor((int) ($args[0] ?? 0)));
        }

        if (str_contains($sql, 'idempotency_key')) {
            return $this->ledgerIdByKey((string) ($args[0] ?? ''));
        }

        return null;
    }

    public function get_row(string $sql): ?object
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'COALESCE(SUM')) {
            return $this->expiring((int) ($args[0] ?? 0), (string) ($args[1] ?? ''), (string) ($args[2] ?? ''));
        }

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[0] ?? 0);
            if (!isset($this->balances[$userId])) {
                return null;
            }
            return (object) ($this->balances[$userId] + ['user_id' => $userId]);
        }

        if (str_contains($sql, 'idempotency_key') && str_contains($sql, 'points < 0')) {
            $id = $this->ledgerIdByKey((string) ($args[0] ?? ''));
            if (null === $id) {
                return null;
            }
            return (int) $this->ledger[$id]['points'] < 0 ? (object) $this->ledger[$id] : null;
        }

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_ledger WHERE id = %d')) {
            $id = (int) ($args[0] ?? 0);
            return isset($this->ledger[$id]) ? (object) $this->ledger[$id] : null;
        }

        return null;
    }

    /** @return list<object> */
    public function get_results(string $sql): array
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'JOIN ')) {
            return $this->consumptionsOf((int) ($args[0] ?? 0));
        }

        if (str_contains($sql, 'ORDER BY created_at DESC')) {
            return array_map(
                static fn (array $row): object => (object) $row,
                array_reverse($this->ledgerFor((int) ($args[0] ?? 0))),
            );
        }

        if (str_contains($sql, 'expires_at <=')) {
            $now   = (string) ($args[0] ?? '');
            $limit = (int) ($args[1] ?? 200);
            $due   = [];
            foreach ($this->ledger as $row) {
                if (
                    (int) $row['points'] > 0
                    && (int) $row['remaining_points'] > 0
                    && null !== $row['expires_at']
                    && $row['expires_at'] <= $now
                ) {
                    $due[] = (object) $row;
                }
            }
            return array_slice($due, 0, $limit);
        }

        if (str_contains($sql, 'remaining_points > 0')) {
            $rows = [];
            foreach ($this->ledgerFor((int) ($args[0] ?? 0)) as $row) {
                if ((int) $row['points'] > 0 && (int) $row['remaining_points'] > 0) {
                    $rows[] = (object) $row;
                }
            }
            return $rows;
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

    /** @return array<int,array<string,mixed>> */
    private function ledgerFor(int $userId): array
    {
        return array_values(array_filter(
            $this->ledger,
            static fn (array $row): bool => (int) $row['user_id'] === $userId,
        ));
    }

    private function ledgerIdByKey(string $key): ?int
    {
        if ('' === $key) {
            return null;
        }
        foreach ($this->ledger as $id => $row) {
            if (($row['idempotency_key'] ?? null) === $key) {
                return (int) $id;
            }
        }
        return null;
    }

    /** @return list<object> */
    private function consumptionsOf(int $debitId): array
    {
        $rows = [];
        foreach ($this->consumptions as $row) {
            if ((int) $row['debit_id'] === $debitId) {
                $credit = $this->ledger[(int) $row['credit_id']] ?? [];
                $rows[] = (object) [
                    'credit_id' => (int) $row['credit_id'],
                    'points'    => (int) $row['points'],
                    'expires_at' => $credit['expires_at'] ?? null,
                ];
            }
        }
        return $rows;
    }

    private function expiring(int $userId, string $from, string $until): object
    {
        $points = 0;
        $date   = null;
        foreach ($this->ledgerFor($userId) as $row) {
            if (
                (int) $row['points'] > 0
                && (int) $row['remaining_points'] > 0
                && null !== $row['expires_at']
                && $row['expires_at'] > $from
                && $row['expires_at'] <= $until
            ) {
                $points += (int) $row['remaining_points'];
                if (null === $date || $row['expires_at'] < $date) {
                    $date = $row['expires_at'];
                }
            }
        }
        return (object) ['points' => (string) $points, 'date' => $date];
    }
}
