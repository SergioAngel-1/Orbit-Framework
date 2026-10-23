<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin `hwe-loyalty` (módulo `loyalty`), parte de contrato público (tarea 14).
 *
 * Otro módulo (p. ej. membresías) tiene que poder abonar y revertir puntos SIN conocer las
 * clases internas de `loyalty`: solo las funciones globales `hwe_loyalty_available()`,
 * `hwe_loyalty_credit()` y `hwe_loyalty_reverse_credit()`. Aquí se prueba la DECISIÓN de ese
 * contrato: cuándo responde «no disponible» sin escribir, que el abono pasa por el libro con su
 * idempotencia, que un tipo no declarado se rechaza y que la reversión es idempotente y está
 * limitada al saldo, como `earn_reversal`.
 *
 * El doble de `$wpdb` mantiene el estado mínimo del libro (filas, consumos FIFO y saldos) e
 * interpreta solo las sentencias que emiten el libro y la lectura de la clave original.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ApiTest extends TestCase
{
    private const USER = 7;
    private const OTHER = 8;
    private const NON_PARTICIPANT = 9;
    private const MISSING_USER = 999;

    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var array<int,object> Usuarios que devuelve `get_userdata`. */
    private array $users = [];
    /** @var array<string,string> Tipos de movimiento declarados por otro módulo. */
    private array $extraEntryTypes = [];
    /** @var list<string> Plugins activos que lee `Modules::isActive()`. */
    private array $activePlugins = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var int Reloj simulado (UTC). */
    private int $now = 1_800_000_000;
    private TestApiWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'loyalty' => [
                'points_enabled'       => '1',
                'referrals_enabled'    => '1',
                'currency_name'        => 'punto',
                'currency_name_plural' => 'puntos',
                'expiry_days'          => '0',
                'allowed_roles'        => 'customer, subscriber',
            ],
        ];
        $this->users = [
            self::USER => (object) [
                'ID'    => self::USER,
                'roles' => ['customer'],
            ],
            self::OTHER => (object) [
                'ID'    => self::OTHER,
                'roles' => ['subscriber'],
            ],
            self::NON_PARTICIPANT => (object) [
                'ID'    => self::NON_PARTICIPANT,
                'roles' => ['administrator'],
            ],
        ];
        $this->extraEntryTypes = [];
        $this->activePlugins   = ['hwe-loyalty/hwe-loyalty.php'];
        $this->logged          = [];
        $this->now             = 1_800_000_000;

        $this->wpdb      = new TestApiWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => is_string($value) ? trim($value) : '',
            'esc_url_raw'         => static fn ($value) => trim((string) $value),
        ]);
        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            if ('hwe_config' === $name) {
                return $this->config;
            }
            if ('active_plugins' === $name) {
                return $this->activePlugins;
            }
            return $default;
        });
        Functions\when('get_userdata')->alias(fn (int $id) => $this->users[$id] ?? false);
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        // El contrato es el mismo filtro que ya usan los módulos para declarar sus tipos; aquí
        // el test decide qué tipos extra existen en cada caso.
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed ...$args): mixed {
            if ('hwe_loyalty_entry_types' === $hook && is_array($value)) {
                return array_merge($value, $this->extraEntryTypes);
            }
            return $value;
        });

        $this->loadOwnFile('plugins/hwe-loyalty/hwe-loyalty.php');
    }

    // ── Disponibilidad ──────────────────────────────────────────────────────

    public function testAvailableReflectsTheModuleAndTheSwitches(): void
    {
        $this->assertTrue(\hwe_loyalty_available('points'));
        $this->assertTrue(\hwe_loyalty_available('referrals'));
        $this->assertFalse(\hwe_loyalty_available('unknown_feature'));

        $this->config['loyalty']['referrals_enabled'] = '0';
        $this->assertTrue(\hwe_loyalty_available('points'));
        $this->assertFalse(\hwe_loyalty_available('referrals'), 'El interruptor de referidos manda.');

        $this->config['loyalty']['points_enabled'] = '0';
        $this->assertFalse(\hwe_loyalty_available('points'));
        $this->assertFalse(\hwe_loyalty_available('referrals'), 'Sin puntos no hay referidos.');

        $this->config['loyalty']['points_enabled']    = '1';
        $this->config['loyalty']['referrals_enabled'] = '1';
        $this->activePlugins                          = [];
        $this->assertFalse(\hwe_loyalty_available('points'), 'Con el plugin inactivo no hay programa.');
        $this->assertFalse(\hwe_loyalty_available('referrals'));
    }

    // ── Abono ───────────────────────────────────────────────────────────────

    public function testCreditUsesTheLedgerAndIsIdempotentByKey(): void
    {
        $this->extraEntryTypes = ['membership_activation' => 'credit'];

        $first = \hwe_loyalty_credit(
            self::USER,
            120,
            'membership_activation',
            'membership:grant:line:9',
            ['type' => 'order', 'id' => 55],
            ['order' => 55, 'tier' => 'gold']
        );

        $this->assertIsInt($first);
        $rows = $this->entriesOf(self::USER);
        $this->assertCount(1, $rows);
        $this->assertSame(120, (int) $rows[0]['points']);
        $this->assertSame('membership_activation', $rows[0]['type']);
        $this->assertSame('membership:grant:line:9', $rows[0]['idempotency_key']);
        $this->assertSame('order', $rows[0]['ref_type']);
        $this->assertSame('55', $rows[0]['ref_id']);
        $this->assertSame('system', $rows[0]['actor']);
        $this->assertStringContainsString('"tier":"gold"', (string) $rows[0]['data']);
        $this->assertSame(120, $this->balanceOf(self::USER));

        $this->assertNull(
            \hwe_loyalty_credit(self::USER, 120, 'membership_activation', 'membership:grant:line:9'),
            'La clave repetida devuelve null, como el libro.'
        );
        $this->assertCount(1, $this->entriesOf(self::USER));
        $this->assertSame(120, $this->balanceOf(self::USER));
    }

    public function testAnUndeclaredTypeIsRejectedWithoutWriting(): void
    {
        $this->assertNull(
            \hwe_loyalty_credit(self::USER, 10, 'membership_activation', 'membership:grant:1'),
            'Un tipo que nadie declaró como abono no entra en el libro.'
        );
        $this->assertNull(\hwe_loyalty_credit(self::USER, 10, 'redeem', 'membership:grant:2'));

        $this->assertSame([], $this->wpdb->ledger);
        $this->assertSame([], $this->wpdb->balances);
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] loyalty:', $this->logged[0]);
    }

    public function testCreditReturnsNullAndWritesNothingWhenPointsAreOff(): void
    {
        $this->config['loyalty']['points_enabled'] = '0';

        $this->assertNull(\hwe_loyalty_credit(self::USER, 50, 'cashback', 'membership:grant:1'));
        $this->assertSame([], $this->wpdb->queries, 'Ni una consulta: no se abre transacción.');
        $this->assertSame([], $this->wpdb->ledger);
    }

    public function testCreditReturnsNullAndWritesNothingWhenTheModuleIsOff(): void
    {
        $this->activePlugins = [];

        $this->assertNull(\hwe_loyalty_credit(self::USER, 50, 'cashback', 'membership:grant:1'));
        $this->assertSame([], $this->wpdb->queries);
        $this->assertSame([], $this->wpdb->ledger);
    }

    public function testCreditRejectsAUserWhoDoesNotParticipate(): void
    {
        $this->assertNull(\hwe_loyalty_credit(self::NON_PARTICIPANT, 50, 'cashback', 'membership:grant:1'));
        $this->assertNull(\hwe_loyalty_credit(self::MISSING_USER, 50, 'cashback', 'membership:grant:2'));

        $this->assertSame([], $this->wpdb->queries);
        $this->assertSame([], $this->wpdb->ledger);
    }

    // ── Reversión ───────────────────────────────────────────────────────────

    public function testReverseCreditIsLimitedToTheAvailableBalance(): void
    {
        \hwe_loyalty_credit(self::USER, 100, 'cashback', 'membership:grant:1', ['type' => 'order', 'id' => 55]);
        \HWE_Loyalty_Ledger::debit(self::USER, 30, 'redeem', 'test:spend:1');

        $reversal = \hwe_loyalty_reverse_credit('membership:grant:1', 'membership:reversal:1');

        $this->assertIsInt($reversal);
        $rows = $this->entriesOf(self::USER, 'earn_reversal');
        $this->assertCount(1, $rows);
        $this->assertSame(-70, (int) $rows[0]['points'], 'Solo se recupera lo que queda en el saldo.');
        $this->assertSame('order', $rows[0]['ref_type']);
        $this->assertSame('55', $rows[0]['ref_id']);
        $this->assertStringContainsString('"original_type":"cashback"', (string) $rows[0]['data']);
        $this->assertSame(0, $this->balanceOf(self::USER));
    }

    public function testReverseCreditIsIdempotentByReversalKey(): void
    {
        \hwe_loyalty_credit(self::USER, 100, 'cashback', 'membership:grant:1');
        $this->assertIsInt(\hwe_loyalty_reverse_credit('membership:grant:1', 'membership:reversal:1'));

        // Saldo nuevo después de la reversión: una segunda pasada con la misma clave no puede
        // volver a cargarlo (la idempotencia no depende de que el saldo esté a cero).
        \HWE_Loyalty_Ledger::credit(self::USER, 40, 'admin_credit', 'test:after:1');

        $this->assertNull(\hwe_loyalty_reverse_credit('membership:grant:1', 'membership:reversal:1'));
        $this->assertSame(40, $this->balanceOf(self::USER));
        $this->assertCount(1, $this->entriesOf(self::USER, 'earn_reversal'));
    }

    public function testReverseCreditReturnsNullForAnUnknownKeyOrADebit(): void
    {
        $this->assertNull(\hwe_loyalty_reverse_credit('does:not:exist', 'membership:reversal:1'));

        \hwe_loyalty_credit(self::USER, 50, 'cashback', 'membership:grant:1');
        \HWE_Loyalty_Ledger::debit(self::USER, 10, 'redeem', 'test:spend:1');

        $this->assertNull(\hwe_loyalty_reverse_credit('test:spend:1', 'membership:reversal:2'));
        $this->assertNull(\hwe_loyalty_reverse_credit('', 'membership:reversal:3'));
        $this->assertNull(\hwe_loyalty_reverse_credit('membership:grant:1', ''));

        $this->assertSame([], $this->entriesOf(self::USER, 'earn_reversal'));
        $this->assertSame(40, $this->balanceOf(self::USER));
        $this->assertNotEmpty($this->logged);
    }

    public function testReverseCreditWithoutBalanceReturnsNullAndRecoversNothing(): void
    {
        \hwe_loyalty_credit(self::USER, 100, 'cashback', 'membership:grant:1');
        \HWE_Loyalty_Ledger::debit(self::USER, 100, 'redeem', 'test:spend:1');

        $this->assertNull(\hwe_loyalty_reverse_credit('membership:grant:1', 'membership:reversal:1'));
        $this->assertSame([], $this->entriesOf(self::USER, 'earn_reversal'));
        $this->assertSame(0, $this->balanceOf(self::USER));
        $this->assertNotEmpty($this->logged);
    }

    public function testReversalStillWorksWhenTheProgrammeWasSwitchedOffAfterTheCredit(): void
    {
        \hwe_loyalty_credit(self::USER, 25, 'cashback', 'membership:grant:1');
        $this->config['loyalty']['points_enabled'] = '0';

        // Revertir es una corrección contable, no un beneficio: se aplica aunque el programa se
        // haya apagado después del abono.
        $this->assertIsInt(\hwe_loyalty_reverse_credit('membership:grant:1', 'membership:reversal:1'));
        $this->assertSame(0, $this->balanceOf(self::USER));
    }

    // ── Utilidades del caso ─────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function entriesOf(int $userId, ?string $type = null): array
    {
        return array_values(array_filter(
            $this->wpdb->ledger,
            static fn (array $row): bool => (int) $row['user_id'] === $userId
                && (null === $type || (string) $row['type'] === $type)
        ));
    }

    private function balanceOf(int $userId): int
    {
        return (int) \HWE_Loyalty_Ledger::balance($userId)['balance'];
    }
}

/**
 * Doble de `$wpdb` con el estado mínimo del libro mayor: filas, consumos y saldos.
 *
 * Interpreta solo las sentencias que emiten `HWE_Loyalty_Ledger::credit()/debit()/balance()` y
 * la lectura de una fila por `idempotency_key` que hace el punto de entrada. Cualquier otra
 * consulta se registra y se responde como vacía, igual que un `$wpdb` real sin filas.
 */
final class TestApiWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var list<array{0:string,1:array<string,mixed>}> */
    public array $inserts = [];
    /** @var list<string> */
    public array $queries = [];
    /** @var array<int,array<string,mixed>> id => fila del libro */
    public array $ledger = [];
    /** @var list<array<string,mixed>> */
    public array $consumptions = [];
    /** @var array<int,array{balance:int,earned:int,used:int}> */
    public array $balances = [];
    /** @var array<string,mixed>|null */
    private ?array $snapshot = null;
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
            $this->consumptions[] = $data;
            return 1;
        }

        $this->insert_id = $this->nextId++;
        return 1;
    }

    public function query(string $sql): int|false
    {
        $this->queries[] = $sql;

        if ('START TRANSACTION' === $sql) {
            $this->snapshot = [
                'ledger'       => $this->ledger,
                'consumptions' => $this->consumptions,
                'balances'     => $this->balances,
                'nextId'       => $this->nextId,
            ];
            return 1;
        }

        if ('ROLLBACK' === $sql) {
            if (null !== $this->snapshot) {
                $this->ledger       = $this->snapshot['ledger'];
                $this->consumptions = $this->snapshot['consumptions'];
                $this->balances     = $this->snapshot['balances'];
                $this->nextId       = $this->snapshot['nextId'];
            }
            $this->snapshot = null;
            return 1;
        }

        if ('COMMIT' === $sql) {
            $this->snapshot = null;
            return 1;
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
            }

            $this->balances[$userId] = $row;
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->prefix . 'hwe_loyalty_ledger')) {
            [$points, $id, $minimum] = $args;
            $id = (int) $id;
            if (isset($this->ledger[$id]) && (int) $this->ledger[$id]['remaining_points'] >= (int) $minimum) {
                $this->ledger[$id]['remaining_points'] -= (int) $points;
                return 1;
            }
            return 0;
        }

        return 1;
    }

    public function get_var(string $sql): string|int|null
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'idempotency_key')) {
            return $this->ledgerIdByKey((string) ($args[0] ?? ''));
        }

        return null;
    }

    public function get_row(string $sql): ?object
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[0] ?? 0);
            return isset($this->balances[$userId])
                ? (object) ($this->balances[$userId] + ['user_id' => $userId])
                : null;
        }

        if (str_contains($sql, 'idempotency_key')) {
            $id = $this->ledgerIdByKey((string) ($args[0] ?? ''));
            return null === $id ? null : (object) $this->ledger[$id];
        }

        return null;
    }

    /** @return list<object> */
    public function get_results(string $sql): array
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'remaining_points > 0')) {
            $userId = (int) ($args[0] ?? 0);
            $rows   = [];
            foreach ($this->ledger as $row) {
                if (
                    (int) $row['user_id'] === $userId
                    && (int) $row['points'] > 0
                    && (int) $row['remaining_points'] > 0
                ) {
                    $rows[] = $row;
                }
            }

            usort($rows, static function (array $left, array $right): int {
                $leftExpiry  = $left['expires_at'] ?? null;
                $rightExpiry = $right['expires_at'] ?? null;
                if (null === $leftExpiry && null === $rightExpiry) {
                    return (int) $left['id'] <=> (int) $right['id'];
                }
                if (null === $leftExpiry) {
                    return 1;
                }
                if (null === $rightExpiry) {
                    return -1;
                }
                return strcmp((string) $leftExpiry, (string) $rightExpiry)
                    ?: ((int) $left['id'] <=> (int) $right['id']);
            });

            return array_map(static fn (array $row): object => (object) $row, $rows);
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
}
