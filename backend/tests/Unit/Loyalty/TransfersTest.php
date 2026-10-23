<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Transferencias entre usuarios (`hwe-loyalty`, spec §8 y Tarea 9).
 *
 * Se prueba la decisión de negocio y de seguridad, no el SQL literal:
 *  · la puerta (interruptor, participantes y código) falla cerrada y sin distinguir un código
 *    inexistente de uno de un usuario que no participa (no se puede enumerar la red);
 *  · el mínimo, la comisión redondeada hacia arriba y el tope diario se aplican antes de mover
 *    nada, y el tope cuenta lo que SALE del emisor (incluida la comisión);
 *  · el cargo y el abono viven en una sola transacción: si el abono falla, el `ROLLBACK` deja
 *    el libro y los dos saldos como estaban;
 *  · la clave de idempotencia incluye al emisor y repetirla devuelve la misma respuesta sin
 *    mover puntos; con otros parámetros no mueve nada y responde `conflict`;
 *  · la respuesta solo enseña el nombre visible del receptor, nunca su email ni su login.
 *
 * El doble de `$wpdb` mantiene libro, consumos, saldos y referidos, y hace snapshots reales en
 * cada `START TRANSACTION` para que un `ROLLBACK` deshaga lo escrito, igual que InnoDB.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TransfersTest extends TestCase
{
    private const SENDER = 7;
    private const RECIPIENT = 8;
    private const ADMIN = 10;
    private const NON_PARTICIPANT = 99;

    private const SENDER_EMAIL = 'ana@example.com';
    private const RECIPIENT_EMAIL = 'beto@example.com';
    private const SENDER_LOGIN = 'ana_login';
    private const RECIPIENT_LOGIN = 'beto_login';

    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var array<int,object> */
    private array $users = [];
    /** @var list<string> */
    private array $activePlugins = ['hwe-loyalty/hwe-loyalty.php'];
    /** @var list<string> */
    private array $logged = [];
    /** @var int Reloj simulado (UTC). */
    private int $now = 1_800_000_000;
    /** @var int Contador para claves de siembra únicas. */
    private int $seed = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'loyalty' => [
                'points_enabled'       => '1',
                'transfers_enabled'    => '1',
                'allowed_roles'        => 'customer, subscriber',
                'transfer_min'         => '10',
                'transfer_fee_percent' => '0',
                'transfer_daily_cap'   => '0',
                'expiry_days'          => '0',
            ],
        ];
        $this->users = [
            self::SENDER => (object) [
                'ID' => self::SENDER, 'roles' => ['customer'], 'display_name' => 'Ana Visible',
                'user_login' => self::SENDER_LOGIN, 'user_email' => self::SENDER_EMAIL,
            ],
            self::RECIPIENT => (object) [
                'ID' => self::RECIPIENT, 'roles' => ['customer'], 'display_name' => 'Beto Visible',
                'user_login' => self::RECIPIENT_LOGIN, 'user_email' => self::RECIPIENT_EMAIL,
            ],
            self::ADMIN => (object) [
                'ID' => self::ADMIN, 'roles' => ['administrator'], 'display_name' => 'Admin',
                'user_login' => 'admin_login', 'user_email' => 'admin@example.com',
            ],
            self::NON_PARTICIPANT => (object) [
                'ID' => self::NON_PARTICIPANT, 'roles' => ['administrator'], 'display_name' => 'Sin Cuenta',
                'user_login' => 'sin_cuenta', 'user_email' => 'sin@example.com',
            ],
        ];
        $this->activePlugins = ['hwe-loyalty/hwe-loyalty.php'];
        $this->logged        = [];
        $this->now           = 1_800_000_000;
        $this->seed          = 0;

        $GLOBALS['wpdb'] = new TestTransfersWpdb();
        $GLOBALS['wpdb']->referrals = [
            1 => [
                'id' => 1, 'user_id' => self::SENDER, 'code' => 'ABCDEFGH',
                'referrer_id' => null, 'status' => 'pending',
                'attributed_at' => '2026-01-01 10:00:00', 'confirmed_at' => null,
            ],
            2 => [
                'id' => 2, 'user_id' => self::RECIPIENT, 'code' => 'JKMNPQRS',
                'referrer_id' => null, 'status' => 'pending',
                'attributed_at' => '2026-01-01 10:00:00', 'confirmed_at' => null,
            ],
            3 => [
                'id' => 3, 'user_id' => self::NON_PARTICIPANT, 'code' => 'ADMNCDE2',
                'referrer_id' => null, 'status' => 'pending',
                'attributed_at' => '2026-01-01 10:00:00', 'confirmed_at' => null,
            ],
        ];

        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => is_string($value) ? trim($value) : '',
            'esc_url_raw'         => static fn ($value) => trim((string) $value),
            '__'                  => static fn (string $text): string => $text,
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
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $value);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });

        $this->loadOwnFile('plugins/hwe-loyalty/hwe-loyalty.php');
    }

    private function wpdb(): TestTransfersWpdb
    {
        return $GLOBALS['wpdb'];
    }

    private function fund(int $userId, int $points): void
    {
        ++$this->seed;
        $id = \HWE_Loyalty_Ledger::credit($userId, $points, 'cashback', 'seed:' . $userId . ':' . $this->seed);
        $this->assertIsInt($id, 'La siembra del test debe poder abonar puntos.');
    }

    /** @return list<array<string,mixed>> */
    private function transferEntries(): array
    {
        return array_values(array_filter(
            $this->wpdb()->ledger,
            static fn (array $row): bool => in_array($row['type'] ?? '', ['transfer_out', 'transfer_fee', 'transfer_in'], true),
        ));
    }

    private function transfer(
        int $points = 100,
        string $code = 'JKMNPQRS',
        string $key = 'transfer-key-1',
        string $note = ''
    ): array {
        return \HWE_Loyalty_Transfers::transfer(self::SENDER, $code, $points, $key, $note);
    }

    // ── Puerta: interruptor y participantes ────────────────────────────────

    public function test_transfers_off_returns_disabled_without_moving_points(): void
    {
        $this->fund(self::SENDER, 500);
        $this->config['loyalty']['transfers_enabled'] = '0';

        $result = $this->transfer();

        $this->assertSame(['ok' => false, 'error' => 'disabled'], $result);
        $this->assertSame([], $this->transferEntries(), 'Con transferencias apagadas no nace ningún movimiento.');
        $this->assertSame(500, \HWE_Loyalty_Ledger::balance(self::SENDER)['balance']);
    }

    public function test_points_off_also_disables_transfers(): void
    {
        $this->config['loyalty']['points_enabled'] = '0';

        $this->assertSame(['ok' => false, 'error' => 'disabled'], $this->transfer());
    }

    public function test_sender_outside_the_program_is_disabled(): void
    {
        $this->fund(self::ADMIN, 500);

        $result = \HWE_Loyalty_Transfers::transfer(self::ADMIN, 'JKMNPQRS', 100, 'admin-key');

        $this->assertSame(['ok' => false, 'error' => 'disabled'], $result);
        $this->assertSame([], $this->transferEntries());
    }

    // ── Destino: mínimo, uno mismo y códigos no válidos ────────────────────

    public function test_below_the_configured_minimum_is_rejected_before_writing(): void
    {
        $this->fund(self::SENDER, 500);
        $this->config['loyalty']['transfer_min'] = '100';

        $result = $this->transfer(99);

        $this->assertSame(['ok' => false, 'error' => 'below_minimum'], $result);
        $this->assertSame([], $this->transferEntries());
    }

    public function test_a_self_transfer_is_rejected(): void
    {
        $this->fund(self::SENDER, 500);

        $result = $this->transfer(100, 'ABCDEFGH');

        $this->assertSame(['ok' => false, 'error' => 'self'], $result);
        $this->assertSame([], $this->transferEntries());
    }

    public function test_unknown_and_non_participant_codes_are_indistinguishable(): void
    {
        $this->fund(self::SENDER, 500);

        $unknown       = $this->transfer(100, 'ZZZZZZZZ', 'key-unknown');
        $nonParticipant = $this->transfer(100, 'ADMNCDE2', 'key-non-participant');

        $this->assertSame(['ok' => false, 'error' => 'invalid_recipient'], $unknown);
        $this->assertSame(
            $unknown,
            $nonParticipant,
            'No distinguir «no existe» de «no participa» evita enumerar la red.'
        );
        $this->assertSame([], $this->transferEntries());
    }

    // ── Saldo, comisión y tope diario ──────────────────────────────────────

    public function test_insufficient_balance_is_rejected(): void
    {
        $this->fund(self::SENDER, 50);
        $this->config['loyalty']['transfer_min'] = '10';

        $result = $this->transfer(100);

        $this->assertSame(['ok' => false, 'error' => 'insufficient'], $result);
        $this->assertSame([], $this->transferEntries());
        $this->assertSame(50, \HWE_Loyalty_Ledger::balance(self::SENDER)['balance']);
    }

    public function test_the_daily_cap_counts_what_leaves_the_sender_including_the_fee(): void
    {
        $this->fund(self::SENDER, 1_000);
        $this->config['loyalty']['transfer_fee_percent'] = '10';
        $this->config['loyalty']['transfer_daily_cap']   = '150';

        $first = $this->transfer(100, 'JKMNPQRS', 'cap-1');
        $this->assertTrue($first['ok']);

        $second = $this->transfer(60, 'JKMNPQRS', 'cap-2');
        $this->assertSame(['ok' => false, 'error' => 'daily_cap'], $second);
        $this->assertCount(3, $this->transferEntries(), 'Solo se escribieron los tres movimientos del primer envío.');
        $this->assertSame(900, \HWE_Loyalty_Ledger::balance(self::SENDER)['balance']);
        $this->assertSame(90, \HWE_Loyalty_Ledger::balance(self::RECIPIENT)['balance']);
    }

    public function test_zero_daily_cap_means_no_limit(): void
    {
        $this->fund(self::SENDER, 1_000);
        $this->config['loyalty']['transfer_daily_cap'] = '0';

        $this->assertTrue($this->transfer(100, 'JKMNPQRS', 'no-cap-1')['ok']);
        $this->assertTrue($this->transfer(100, 'JKMNPQRS', 'no-cap-2')['ok']);
        $this->assertSame(800, \HWE_Loyalty_Ledger::balance(self::SENDER)['balance']);
    }

    public function test_the_fee_is_rounded_up_and_the_recipient_gets_the_difference(): void
    {
        $this->fund(self::SENDER, 1_000);
        $this->config['loyalty']['transfer_fee_percent'] = '3';
        $this->config['loyalty']['expiry_days']          = '30';

        $result = $this->transfer(101);

        $this->assertTrue($result['ok']);
        $this->assertSame(101, $result['sent'], 'sent es todo lo que sale del emisor.');
        $this->assertSame(4, $result['fee'], 'ceil(101 × 3 / 100) = 4.');
        $this->assertSame(97, $result['received']);
        $this->assertSame('Beto Visible', $result['recipient_name']);

        $out = $this->wpdb()->entryOfType('transfer_out');
        $fee = $this->wpdb()->entryOfType('transfer_fee');
        $in  = $this->wpdb()->entryOfType('transfer_in');

        $this->assertSame(-97, $out['points']);
        $this->assertSame(-4, $fee['points']);
        $this->assertSame(97, $in['points']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $this->now + 30 * DAY_IN_SECONDS), $in['expires_at']);
        $this->assertSame('transfer:7:transfer-key-1:out', $out['idempotency_key']);
        $this->assertSame('transfer:7:transfer-key-1:fee', $fee['idempotency_key']);
        $this->assertSame('transfer:7:transfer-key-1:in', $in['idempotency_key']);

        $this->assertSame(899, \HWE_Loyalty_Ledger::balance(self::SENDER)['balance']);
        $this->assertSame(97, \HWE_Loyalty_Ledger::balance(self::RECIPIENT)['balance']);
    }

    public function test_the_same_key_returns_the_same_response_without_moving_more_points(): void
    {
        $this->fund(self::SENDER, 1_000);
        $this->config['loyalty']['transfer_fee_percent'] = '5';
        Actions\expectDone('hwe_loyalty_transferred')->once();

        $first  = $this->transfer(100, 'JKMNPQRS', 'same-key');
        $second = $this->transfer(100, 'JKMNPQRS', 'same-key');

        $this->assertSame($first, $second);
        $this->assertCount(3, $this->transferEntries(), 'Repetir la clave no duplica movimientos.');
        $this->assertSame(900, \HWE_Loyalty_Ledger::balance(self::SENDER)['balance']);
        $this->assertSame(95, \HWE_Loyalty_Ledger::balance(self::RECIPIENT)['balance']);
    }

    public function test_the_same_key_with_other_parameters_conflicts_without_moving_points(): void
    {
        $this->fund(self::SENDER, 1_000);

        $first = $this->transfer(100, 'JKMNPQRS', 'reused-key');
        $this->assertTrue($first['ok']);

        $second = $this->transfer(150, 'JKMNPQRS', 'reused-key');

        $this->assertSame(['ok' => false, 'error' => 'conflict'], $second);
        $this->assertCount(2, $this->transferEntries(), 'Sin comisión son dos movimientos: cargo y abono.');
        $this->assertSame(900, \HWE_Loyalty_Ledger::balance(self::SENDER)['balance']);
        $this->assertSame(100, \HWE_Loyalty_Ledger::balance(self::RECIPIENT)['balance']);
    }

    // ── Atomicidad y acción ────────────────────────────────────────────────

    public function test_a_failed_inbound_credit_rolls_everything_back(): void
    {
        $this->fund(self::SENDER, 1_000);
        $this->wpdb()->failLedgerType = 'transfer_in';
        $this->wpdb()->queries        = [];
        Actions\expectDone('hwe_loyalty_transferred')->never();

        $result = $this->transfer(100, 'JKMNPQRS', 'rollback-key');

        $this->assertSame(['ok' => false, 'error' => 'failed'], $result);
        $this->assertContains('ROLLBACK', $this->wpdb()->queries);
        $this->assertNotContains('COMMIT', $this->wpdb()->queries, 'El abono fallido no puede confirmar el cargo.');
        $this->assertSame([], $this->transferEntries(), 'El ROLLBACK deja el libro como estaba.');
        $this->assertSame(1_000, \HWE_Loyalty_Ledger::balance(self::SENDER)['balance']);
        $this->assertSame(0, \HWE_Loyalty_Ledger::balance(self::RECIPIENT)['balance']);
    }

    public function test_the_action_fires_once_after_the_commit_and_only_with_ids(): void
    {
        $this->fund(self::SENDER, 1_000);
        $this->config['loyalty']['transfer_fee_percent'] = '1';

        Actions\expectDone('hwe_loyalty_transferred')
            ->once()
            ->with(self::SENDER, self::RECIPIENT, 100, 99, 1)
            ->whenHappen(function (): void {
                $this->wpdb()->events[] = 'action:hwe_loyalty_transferred';
            });

        $this->assertTrue($this->transfer(100, 'JKMNPQRS', 'action-key')['ok']);

        $commit = array_search('COMMIT', $this->wpdb()->events, true);
        $action = array_search('action:hwe_loyalty_transferred', $this->wpdb()->events, true);
        $this->assertIsInt($commit);
        $this->assertIsInt($action);
        $this->assertGreaterThan($commit, $action, 'La acción se anuncia cuando la transacción ya está confirmada.');
    }

    // ── Privacidad de la respuesta y de los logs ───────────────────────────

    public function test_the_response_never_exposes_the_recipient_email_or_login(): void
    {
        $this->fund(self::SENDER, 1_000);

        $result = $this->transfer(100, 'JKMNPQRS', 'privacy-key');
        $json   = (string) json_encode($result);

        $this->assertSame(['ok', 'sent', 'received', 'fee', 'recipient_name'], array_keys($result));
        $this->assertSame('Beto Visible', $result['recipient_name']);
        $this->assertStringNotContainsString(self::RECIPIENT_EMAIL, $json);
        $this->assertStringNotContainsString(self::SENDER_EMAIL, $json);
        $this->assertStringNotContainsString(self::RECIPIENT_LOGIN, $json);
        $this->assertStringNotContainsString(self::SENDER_LOGIN, $json);
    }

    public function test_error_responses_are_only_a_stable_code(): void
    {
        $this->fund(self::SENDER, 50);

        $result = $this->transfer(100, 'JKMNPQRS', 'error-key');
        $json   = (string) json_encode($result);

        $this->assertSame(['ok' => false, 'error' => 'insufficient'], $result);
        $this->assertStringNotContainsString(self::RECIPIENT_EMAIL, $json);
        $this->assertStringNotContainsString(self::RECIPIENT_LOGIN, $json);
    }

    public function test_logs_carry_ids_or_reasons_but_never_the_code_or_pii(): void
    {
        $this->fund(self::SENDER, 500);

        $this->transfer(100, 'ZZZZZZZZ', 'log-key');

        $logs = implode("\n", $this->logged);
        $this->assertStringContainsString('[hwe] loyalty:', $logs);
        $this->assertStringNotContainsString('ZZZZZZZZ', $logs);
        $this->assertStringNotContainsString(self::RECIPIENT_EMAIL, $logs);
        $this->assertStringNotContainsString(self::RECIPIENT_LOGIN, $logs);
    }

    // ── Ruta interna ───────────────────────────────────────────────────────

    /** @return array<string,array<string,mixed>> */
    private function registeredRoutes(): array
    {
        $routes = [];
        Functions\when('register_rest_route')->alias(
            function (string $namespace, string $route, array $args) use (&$routes): bool {
                $routes[$namespace . $route] = $args;
                return true;
            }
        );

        \HWE_Loyalty_TransferRoutes::registerRoutes();

        return $routes;
    }

    public function test_register_hooks_the_transfer_route(): void
    {
        Actions\expectAdded('rest_api_init')
            ->once()
            ->with([\HWE_Loyalty_TransferRoutes::class, 'registerRoutes']);

        \HWE_Loyalty_TransferRoutes::register();
    }

    public function test_the_route_is_post_and_requires_the_internal_secret(): void
    {
        $routes = $this->registeredRoutes();

        $this->assertSame(['hwe/v1/loyalty/transfer'], array_keys($routes));
        $this->assertSame('POST', $routes['hwe/v1/loyalty/transfer']['methods']);
        $this->assertIsCallable($routes['hwe/v1/loyalty/transfer']['permission_callback']);

        Functions\when('hwe_has_internal_secret')->justReturn(false);
        $this->assertFalse(
            $routes['hwe/v1/loyalty/transfer']['permission_callback'](new \WP_REST_Request())
        );
    }

    public function test_with_the_secret_and_transfers_off_the_route_answers_404(): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(true);
        $this->config['loyalty']['transfers_enabled'] = '0';

        $permission = $this->registeredRoutes()['hwe/v1/loyalty/transfer']['permission_callback'];
        $closed     = $permission(new \WP_REST_Request());

        $this->assertInstanceOf(\WP_Error::class, $closed);
        $this->assertSame('rest_no_route', $closed->get_error_code());
        $this->assertSame(['status' => 404], $closed->get_error_data());
    }

    public function test_the_route_returns_the_result_or_the_error_code(): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(true);
        $this->fund(self::SENDER, 1_000);
        $this->config['loyalty']['transfer_fee_percent'] = '1';

        $ok = \HWE_Loyalty_TransferRoutes::handleTransfer(new \WP_REST_Request([
            'user_id'         => self::SENDER,
            'code'            => 'JKMNPQRS',
            'points'          => 100,
            'idempotency_key' => 'route-key',
            'note'            => '  Gracias  ',
        ]));

        $this->assertSame(200, $ok->get_status());
        $data = $ok->get_data();
        $this->assertSame(100, $data['sent']);
        $this->assertSame(99, $data['received']);
        $this->assertSame(1, $data['fee']);
        $this->assertSame('Beto Visible', $data['recipient_name']);
        $this->assertStringNotContainsString(self::RECIPIENT_EMAIL, (string) json_encode($data));
        $this->assertStringNotContainsString(self::RECIPIENT_LOGIN, (string) json_encode($data));

        $below = \HWE_Loyalty_TransferRoutes::handleTransfer(new \WP_REST_Request([
            'user_id'         => self::SENDER,
            'code'            => 'JKMNPQRS',
            'points'          => 5,
            'idempotency_key' => 'route-key-2',
        ]));
        $this->assertSame(422, $below->get_status());
        $this->assertSame(['error' => 'below_minimum'], $below->get_data());

        $missingKey = \HWE_Loyalty_TransferRoutes::handleTransfer(new \WP_REST_Request([
            'user_id' => self::SENDER,
            'code'    => 'JKMNPQRS',
            'points'  => 100,
        ]));
        $this->assertSame(400, $missingKey->get_status());
        $this->assertSame(['error' => 'invalid_request'], $missingKey->get_data());
    }

    public function test_the_route_returns_403_when_the_sender_cannot_participate(): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(true);
        $this->fund(self::ADMIN, 1_000);

        $response = \HWE_Loyalty_TransferRoutes::handleTransfer(new \WP_REST_Request([
            'user_id'         => self::ADMIN,
            'code'            => 'JKMNPQRS',
            'points'          => 100,
            'idempotency_key' => 'admin-route-key',
        ]));

        $this->assertSame(403, $response->get_status());
        $this->assertSame(['error' => 'disabled'], $response->get_data());
    }
}

/**
 * Doble de `$wpdb` con estado y transacciones reales (snapshot + restore).
 *
 * Entiende solo las sentencias que emiten el libro y los referidos: crear/bloquear/ajustar
 * saldos, insertar movimientos y consumos, leer movimientos por clave, leer abonos vivos,
 * sumar lo transferido en el día y buscar una fila de referido por código. Cualquier otra
 * consulta se responde vacía, como un `$wpdb` real sin filas.
 */
final class TestTransfersWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var array<int,array<string,mixed>> */
    public array $ledger = [];
    /** @var list<array<string,mixed>> */
    public array $consumptions = [];
    /** @var array<int,array{balance:int,earned:int,used:int}> */
    public array $balances = [];
    /** @var array<int,array<string,mixed>> */
    public array $referrals = [];
    /** @var list<string> */
    public array $queries = [];
    /** @var list<string> */
    public array $events = [];
    /** @var string Tipo de movimiento del libro que debe fallar al insertarse. */
    public string $failLedgerType = '';
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
        if (str_ends_with($table, 'hwe_loyalty_ledger')) {
            if ('' !== $this->failLedgerType && ($data['type'] ?? '') === $this->failLedgerType) {
                return false;
            }

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

        if (str_contains($sql, 'SUM(-points)')) {
            $userId = (int) ($args[0] ?? 0);
            $since  = (string) ($args[1] ?? '');
            $total  = 0;
            foreach ($this->ledger as $row) {
                if (
                    (int) $row['user_id'] === $userId
                    && in_array($row['type'] ?? '', ['transfer_out', 'transfer_fee'], true)
                    && (string) ($row['created_at'] ?? '') >= $since
                ) {
                    $total += abs((int) $row['points']);
                }
            }
            return (string) $total;
        }

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

        if (str_contains($sql, 'hwe_loyalty_referrals')) {
            $code   = (string) ($args[0] ?? '');
            $userId = (int) ($args[0] ?? 0);
            foreach ($this->referrals as $row) {
                if (str_contains($sql, 'WHERE code = %s') && (string) $row['code'] === $code) {
                    return (object) $row;
                }
                if (str_contains($sql, 'WHERE user_id = %d') && (int) $row['user_id'] === $userId) {
                    return (object) $row;
                }
            }
            return null;
        }

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[0] ?? 0);
            if (!isset($this->balances[$userId])) {
                return null;
            }
            return (object) ($this->balances[$userId] + ['user_id' => $userId]);
        }

        if (str_contains($sql, 'idempotency_key') && !str_contains($sql, 'points < 0')) {
            $id = $this->ledgerIdByKey((string) ($args[0] ?? ''));
            return null === $id ? null : (object) $this->ledger[$id];
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

    /** @return array<string,mixed> */
    public function entryOfType(string $type): array
    {
        foreach ($this->ledger as $row) {
            if (($row['type'] ?? '') === $type) {
                return $row;
            }
        }
        return [];
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
}
