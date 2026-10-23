<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 2) . '/Stubs/wc-order.php';

/**
 * Plugin `hwe-loyalty` (módulo `loyalty`), privacidad (tarea 12, spec §11).
 *
 * Se prueba el ciclo de privacidad de WordPress desde la DECISIÓN: qué exporta el exportador
 * (saldo, código, referidor y movimientos, sin identidad de terceros), qué conserva y qué borra
 * el eliminador (importes, tipos y fechas del libro; notas y datos identificativos fuera; red
 * desvinculada; código inservible) y qué pasa al eliminar una cuenta (`delete_user` sin
 * reasignar nada). También se fija la paginación por lotes y la idempotencia de las dos
 * herramientas.
 *
 * Foco de revisión 4: tras eliminar a un referidor, una compra cobrada de su antiguo referido
 * no genera comisión para ese id y la red de un tercero que lo contenía se lee sin errores.
 *
 * El doble de `$wpdb` mantiene en memoria las filas de referidos, del libro y de saldos. Entiende
 * solo las sentencias que emiten `HWE_Loyalty_Ledger`, `HWE_Loyalty_Referrals` y
 * `HWE_Loyalty_Privacy`; no interpreta SQL arbitrario.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PrivacyTest extends TestCase
{
    private const USER = 7;
    private const REFERRER = 8;
    private const REFERRED = 9;
    private const OTHER = 10;
    private const MANY = 11;
    private const MANY_ERASED = 12;
    private const NO_DATA = 13;
    private const REMAINING = 14;

    private const USER_EMAIL = 'sujeto@example.test';
    private const REFERRER_EMAIL = 'referidor@example.test';
    private const REFERRED_EMAIL = 'referido@example.test';

    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = [];
    /** @var array<int,object> Usuarios que devuelve `get_userdata`. */
    private array $users = [];
    /** @var array<int,\WC_Order> Pedidos que devuelve `wc_get_order`. */
    private array $orders = [];
    /** @var list<string> */
    private array $logged = [];
    private int $now = 1_800_000_000;
    private TestPrivacyWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'loyalty' => [
                'points_enabled'            => '1',
                'referrals_enabled'         => '1',
                'currency_name'             => 'punto',
                'currency_name_plural'      => 'puntos',
                'expiry_days'               => '0',
                'cashback_enabled'          => '0',
                'cashback_percent'          => '0',
                'commission_first_percent'  => '10',
                'commission_next_percent'   => '5',
                'level2_enabled'            => '0',
                'commission_level2_percent' => '0',
                'commission_duration_days'  => '0',
                'allowed_roles'             => 'customer, subscriber',
            ],
        ];
        $this->users = [
            self::USER => (object) [
                'ID'           => self::USER,
                'roles'        => ['customer'],
                'display_name' => 'Ana Visible',
                'user_email'   => self::USER_EMAIL,
                'user_login'   => 'ana_login',
            ],
            self::REFERRER => (object) [
                'ID'           => self::REFERRER,
                'roles'        => ['customer'],
                'display_name' => 'Beto Visible',
                'user_email'   => self::REFERRER_EMAIL,
                'user_login'   => 'beto_login',
            ],
            self::REFERRED => (object) [
                'ID'           => self::REFERRED,
                'roles'        => ['customer'],
                'display_name' => 'Carla Visible',
                'user_email'   => self::REFERRED_EMAIL,
                'user_login'   => 'carla_login',
            ],
            self::OTHER => (object) [
                'ID'           => self::OTHER,
                'roles'        => ['customer'],
                'display_name' => 'Dani Visible',
                'user_email'   => 'tercero@example.test',
                'user_login'   => 'dani_login',
            ],
            self::MANY => (object) [
                'ID'           => self::MANY,
                'roles'        => ['customer'],
                'display_name' => 'Muchos Movimientos',
                'user_email'   => 'muchos@example.test',
                'user_login'   => 'muchos_login',
            ],
            self::MANY_ERASED => (object) [
                'ID'           => self::MANY_ERASED,
                'roles'        => ['customer'],
                'display_name' => 'Muchos Borrados',
                'user_email'   => 'borrados@example.test',
                'user_login'   => 'borrados_login',
            ],
            self::NO_DATA => (object) [
                'ID'           => self::NO_DATA,
                'roles'        => ['customer'],
                'display_name' => 'Sin Datos',
                'user_email'   => 'sindatos@example.test',
                'user_login'   => 'sindatos_login',
            ],
            self::REMAINING => (object) [
                'ID'           => self::REMAINING,
                'roles'        => ['customer'],
                'display_name' => 'Red Restante',
                'user_email'   => 'restante@example.test',
                'user_login'   => 'restante_login',
            ],
        ];
        $this->orders = [];
        $this->logged = [];
        $this->now    = 1_800_000_000;

        $this->wpdb      = new TestPrivacyWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => is_string($value) ? trim($value) : '',
            'esc_url_raw'         => static fn ($value) => trim((string) $value),
            'get_post_meta'       => static fn (int $postId, string $key, bool $single = false) => '',
        ]);
        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            if ('hwe_config' === $name) {
                return $this->config;
            }
            if ('active_plugins' === $name) {
                return ['hwe-loyalty/hwe-loyalty.php'];
            }
            return $default;
        });
        Functions\when('get_userdata')->alias(fn (int $id) => $this->users[$id] ?? false);
        Functions\when('get_user_by')->alias(function (string $field, mixed $value) {
            if ('email' !== $field) {
                return false;
            }
            foreach ($this->users as $user) {
                if ($user->user_email === $value) {
                    return $user;
                }
            }
            return false;
        });
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('wc_get_order')->alias(fn (int $id) => $this->orders[$id] ?? false);
        Functions\when('wc_get_orders')->justReturn([]);
        Functions\when('wc_get_is_paid_statuses')->justReturn(['processing', 'completed']);
        Functions\when('wc_get_price_decimals')->justReturn(2);
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value, mixed ...$args): mixed => $value);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });

        $this->loadOwnFile('plugins/hwe-loyalty/hwe-loyalty.php');
    }

    // ── Registro ─────────────────────────────────────────────────────────────

    public function test_register_hooks_exporters_erasers_and_the_delete_user_cleanup(): void
    {
        Filters\expectAdded('wp_privacy_personal_data_exporters')
            ->once()
            ->with([\HWE_Loyalty_Privacy::class, 'registerExporter']);
        Filters\expectAdded('wp_privacy_personal_data_erasers')
            ->once()
            ->with([\HWE_Loyalty_Privacy::class, 'registerEraser']);
        Actions\expectAdded('delete_user')
            ->once()
            ->with([\HWE_Loyalty_Privacy::class, 'onDeleteUser'], 10, 3);

        \HWE_Loyalty_Privacy::register();
    }

    // ── Exportador ───────────────────────────────────────────────────────────

    public function test_exporter_returns_balance_code_referrer_attribution_and_movements(): void
    {
        $this->seedWallet(self::USER);
        $this->seedReferralsForExport();

        $export = \HWE_Loyalty_Privacy::exportPersonalData(self::USER_EMAIL, 1);

        $this->assertTrue($export['done']);
        $this->assertCount(5, $export['data'], 'El resumen y los cuatro movimientos.');

        $summary = $this->itemNamed($export['data'], 'hwe-loyalty-summary-' . self::USER);
        $values  = $this->valuesOf($summary);

        $this->assertSame('110', $values['Saldo']);
        $this->assertSame('150', $values['Total ganado']);
        $this->assertSame('40', $values['Total usado']);
        $this->assertSame('ABCDEFGH', $values['Código de referido']);
        $this->assertSame('Beto Visible', $values['Referidor']);
        $this->assertSame('Confirmada', $values['Estado de la atribución']);
        $this->assertNotSame('', $values['Fecha de atribución']);
        $this->assertArrayHasKey('Fecha de confirmación', $values);

        $transfer = $this->valuesOf($this->itemNamed($export['data'], 'hwe-loyalty-entry-3'));
        $this->assertSame('transfer_out', $transfer['Tipo']);
        $this->assertSame('-30', $transfer['Cantidad']);
        $this->assertSame('Beto Visible', $transfer['Otra parte']);
        $this->assertSame('Pago a Beto', $transfer['Nota']);
        $this->assertArrayHasKey('Fecha (UTC)', $transfer);

        $credit = $this->valuesOf($this->itemNamed($export['data'], 'hwe-loyalty-entry-1'));
        $this->assertSame('cashback', $credit['Tipo']);
        $this->assertSame('100', $credit['Cantidad']);
        $this->assertSame('55', $credit['Pedido']);
        $this->assertArrayHasKey('Caducidad (UTC)', $credit);
    }

    public function test_exporter_never_includes_email_or_login_of_third_parties(): void
    {
        $this->seedWallet(self::USER);
        $this->seedReferralsForExport();

        $export = \HWE_Loyalty_Privacy::exportPersonalData(self::USER_EMAIL, 1);
        $json   = (string) json_encode($export);

        $this->assertStringContainsString('Beto Visible', $json, 'Del referidor se exporta el nombre visible.');
        $this->assertStringNotContainsString(self::REFERRER_EMAIL, $json);
        $this->assertStringNotContainsString('beto_login', $json);
        $this->assertStringNotContainsString(self::REFERRED_EMAIL, $json);
        $this->assertStringNotContainsString('carla_login', $json);
        $this->assertStringNotContainsString(self::USER_EMAIL, $json, 'El export no repite el email del propio sujeto.');

        foreach ($export['data'] as $item) {
            foreach ($this->valuesOf($item) as $name => $value) {
                $this->assertStringNotContainsString('@', (string) $value, 'Ningún campo del export lleva correos.');
                $this->assertNotSame('Login', $name);
                $this->assertNotSame('Email', $name);
            }
        }
    }

    public function test_exporter_paginates_more_movements_than_one_batch_without_duplicates(): void
    {
        for ($id = 1; $id <= 130; $id++) {
            $this->seedEntry($id, self::MANY, 1, 'cashback', [
                'idempotency_key' => 'seed:' . $id,
                'remaining_points' => 1,
                'created_at' => gmdate('Y-m-d H:i:s', $this->now - $id * MINUTE_IN_SECONDS),
            ]);
        }

        $first  = \HWE_Loyalty_Privacy::exportPersonalData('muchos@example.test', 1);
        $second = \HWE_Loyalty_Privacy::exportPersonalData('muchos@example.test', 2);

        $this->assertFalse($first['done']);
        $this->assertCount(101, $first['data'], 'El resumen de la primera página más 100 movimientos.');

        $this->assertTrue($second['done']);
        $this->assertCount(30, $second['data']);

        $ids = [];
        foreach (array_merge($first['data'], $second['data']) as $item) {
            if (str_starts_with((string) $item['item_id'], 'hwe-loyalty-entry-')) {
                $ids[] = $item['item_id'];
            }
        }

        $this->assertCount(130, $ids);
        $this->assertCount(130, array_unique($ids), 'La paginación no repite movimientos.');
    }

    // ── Eliminador ───────────────────────────────────────────────────────────

    public function test_eraser_anonymizes_movements_but_keeps_amounts_types_and_dates(): void
    {
        $this->seedWallet(self::USER);
        $this->seedReferralsForExport();
        $this->seedEntry(10, self::REFERRER, 10, 'referral_commission_l1', [
            'idempotency_key' => 'commission:55:' . self::REFERRER,
            'ref_type'        => 'user',
            'ref_id'          => (string) self::USER,
            'note'            => 'Comisión por la compra de Ana Visible',
            'data'            => '{"order":55}',
        ]);

        $before = $this->wpdb->ledger[1];

        $result = \HWE_Loyalty_Privacy::erasePersonalData(self::USER_EMAIL, 1);

        $this->assertTrue($result['done']);
        $this->assertTrue($result['items_removed']);
        $this->assertTrue($result['items_retained'], 'El libro se conserva por obligación contable.');
        $this->assertNotEmpty($result['messages']);
        $this->assertStringContainsString('contable', implode(' ', $result['messages']));

        $cashback = $this->wpdb->ledger[1];
        $this->assertSame($before['points'], $cashback['points']);
        $this->assertSame($before['type'], $cashback['type']);
        $this->assertSame($before['created_at'], $cashback['created_at']);
        $this->assertSame($before['remaining_points'], $cashback['remaining_points']);
        $this->assertSame($before['expires_at'], $cashback['expires_at']);
        $this->assertSame(self::USER, (int) $cashback['user_id'], 'Sin user_id el saldo y la caducidad no cuadrarían.');
        $this->assertNull($cashback['note']);
        $this->assertNull($cashback['data'], 'Los datos estructurados pueden llevar ids de terceros.');
        $this->assertSame('55', $cashback['ref_id'], 'La referencia al pedido (contabilidad) se conserva.');

        $manual = $this->wpdb->ledger[2];
        $this->assertNull($manual['note'], 'La nota libre puede contener datos personales.');
        $this->assertNull($manual['data']);

        $transfer = $this->wpdb->ledger[3];
        $this->assertNull($transfer['data'], 'El id de la otra parte no se conserva.');
        $this->assertNull($transfer['note']);

        $commission = $this->wpdb->ledger[10];
        $this->assertSame('Comisión por la compra de Ana Visible', $commission['note'], 'Lo que pertenece a otro usuario no se toca.');
        $this->assertSame((string) self::USER, $commission['ref_id']);
    }

    public function test_eraser_unlinks_the_network_and_leaves_the_referral_code_unusable(): void
    {
        $this->seedWallet(self::USER);
        $this->seedReferralsForExport();

        $result = \HWE_Loyalty_Privacy::erasePersonalData(self::USER_EMAIL, 1);
        $this->assertTrue($result['done']);

        $row = $this->wpdb->referrals[self::USER];
        $this->assertNotSame('ABCDEFGH', $row['code']);
        $this->assertStringStartsWith('ERASED-', (string) $row['code']);
        $this->assertNull(\HWE_Loyalty_Referrals::userByCode((string) $row['code']), 'El código nuevo no sirve para atribuir.');
        $this->assertSame('', \HWE_Loyalty_Referrals::storedCode(self::USER), 'El código anonimizado tampoco se exporta.');
        $this->assertNull($row['referrer_id']);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['confirmed_at']);

        foreach ([self::REFERRED, self::OTHER] as $referred) {
            $child = $this->wpdb->referrals[$referred];
            $this->assertNull($child['referrer_id'], 'Los referidos quedan sin referidor.');
            $this->assertSame('pending', $child['status']);
            $this->assertNull($child['confirmed_at']);
        }

        $this->assertArrayHasKey(self::USER, $this->wpdb->balances, 'La cuenta sigue existiendo: su saldo se conserva.');
    }

    public function test_eraser_is_idempotent_and_keeps_reporting_the_accounting_retention(): void
    {
        $this->seedWallet(self::USER);
        $this->seedReferralsForExport();

        \HWE_Loyalty_Privacy::erasePersonalData(self::USER_EMAIL, 1);
        $code   = $this->wpdb->referrals[self::USER]['code'];
        $ledger = $this->wpdb->ledger;

        $second = \HWE_Loyalty_Privacy::erasePersonalData(self::USER_EMAIL, 1);

        $this->assertTrue($second['done']);
        $this->assertFalse($second['items_removed'], 'La segunda pasada no encuentra nada que borrar.');
        $this->assertTrue($second['items_retained']);
        $this->assertNotEmpty($second['messages']);
        $this->assertSame($code, $this->wpdb->referrals[self::USER]['code'], 'El código anonimizado no cambia otra vez.');
        $this->assertSame($ledger, $this->wpdb->ledger);
    }

    public function test_eraser_paginates_more_movements_than_one_batch(): void
    {
        for ($id = 1; $id <= 130; $id++) {
            $this->seedEntry($id, self::MANY_ERASED, 1, 'admin_credit', [
                'idempotency_key' => 'erase:' . $id,
                'remaining_points' => 1,
                'note'            => 'Nota con datos personales ' . $id,
            ]);
        }

        $first = \HWE_Loyalty_Privacy::erasePersonalData('borrados@example.test', 1);
        $this->assertFalse($first['done']);
        $this->assertSame(100, $this->countAnonymized(self::MANY_ERASED), 'Un lote por página.');

        $second = \HWE_Loyalty_Privacy::erasePersonalData('borrados@example.test', 2);
        $this->assertTrue($second['done']);
        $this->assertSame(130, $this->countAnonymized(self::MANY_ERASED));
    }

    public function test_eraser_with_a_user_without_loyalty_data_removes_nothing(): void
    {
        $result = \HWE_Loyalty_Privacy::erasePersonalData('sindatos@example.test', 1);

        $this->assertTrue($result['done']);
        $this->assertFalse($result['items_removed']);
        $this->assertFalse($result['items_retained']);
        $this->assertSame([], $result['messages']);
        $this->assertSame([], $this->wpdb->ledger);
        $this->assertArrayNotHasKey(self::NO_DATA, $this->wpdb->referrals);

        $unknown = \HWE_Loyalty_Privacy::erasePersonalData('nadie@example.test', 1);
        $this->assertTrue($unknown['done']);
        $this->assertFalse($unknown['items_removed']);

        $export = \HWE_Loyalty_Privacy::exportPersonalData('sindatos@example.test', 1);
        $this->assertTrue($export['done']);
        $this->assertCount(1, $export['data'], 'Solo el resumen, sin movimientos.');
        $this->assertSame('0', $this->valuesOf($export['data'][0])['Saldo']);
    }

    // ── Cuenta eliminada (delete_user) ───────────────────────────────────────

    public function test_delete_user_unlinks_children_forgets_the_row_and_anonymizes_the_ledger(): void
    {
        $this->seedWallet(self::REFERRER);
        $this->seedReferralsForExport();
        $this->wpdb->referrals[self::REFERRER] = [
            'user_id'       => self::REFERRER,
            'code'          => 'JKLMNPQR',
            'referrer_id'   => self::OTHER,
            'status'        => 'confirmed',
            'attributed_at' => gmdate('Y-m-d H:i:s', $this->now - 10 * DAY_IN_SECONDS),
            'confirmed_at'  => gmdate('Y-m-d H:i:s', $this->now - 10 * DAY_IN_SECONDS),
        ];
        $this->seedEntry(20, self::REFERRER, 100, 'cashback', [
            'idempotency_key' => 'order:77:buyer',
            'ref_type'        => 'order',
            'ref_id'          => '77',
            'note'            => 'Compra de ' . self::USER_EMAIL,
            'data'            => '{"order":77}',
        ]);
        $this->seedEntry(21, self::REFERRER, 20, 'admin_credit', [
            'idempotency_key' => 'adjust:1',
            'note'            => 'Ajuste con beto_login',
        ]);
        $this->seedEntry(22, self::USER, 10, 'referral_commission_l1', [
            'idempotency_key' => 'commission:55:' . self::USER,
            'ref_type'        => 'user',
            'ref_id'          => (string) self::REFERRER,
            'note'            => 'Comisión ya pagada',
            'data'            => '{"order":55}',
        ]);
        $this->seedBalance(self::REFERRER, 120, 140, 20);

        \HWE_Loyalty_Privacy::onDeleteUser(self::REFERRER, self::OTHER, $this->users[self::REFERRER]);

        $this->assertArrayNotHasKey(self::REFERRER, $this->wpdb->referrals, 'La fila del usuario eliminado se borra.');
        $this->assertArrayNotHasKey(self::REFERRER, $this->wpdb->balances, 'El saldo de una cuenta eliminada desaparece del circuito.');
        $this->assertArrayHasKey(self::USER, $this->wpdb->referrals);
        $this->assertNull($this->wpdb->referrals[self::USER]['referrer_id'], 'Nada se reasigna al usuario de reasignación.');
        $this->assertSame(self::USER, (int) $this->wpdb->referrals[self::REFERRED]['referrer_id'], 'Los referidos de otro usuario no se tocan.');
        $this->assertSame(self::USER, (int) $this->wpdb->referrals[self::OTHER]['referrer_id']);

        $entry = $this->wpdb->ledger[20];
        $this->assertSame(self::REFERRER, (int) $entry['user_id']);
        $this->assertSame(100, (int) $entry['points']);
        $this->assertSame('cashback', $entry['type']);
        $this->assertNull($entry['note']);
        $this->assertNull($entry['data']);
        $this->assertSame('77', $entry['ref_id']);
        $this->assertNull($this->wpdb->ledger[21]['note']);

        $paid = $this->wpdb->ledger[22];
        $this->assertSame('Comisión ya pagada', $paid['note'], 'Los abonos a otros con referencia al eliminado se conservan.');
        $this->assertSame((string) self::REFERRER, $paid['ref_id']);
    }

    public function test_delete_user_is_idempotent(): void
    {
        $this->seedWallet(self::REFERRER);
        $this->seedReferralsForExport();
        $this->seedEntry(20, self::REFERRER, 100, 'cashback', [
            'idempotency_key' => 'order:77:buyer',
            'note'            => 'Compra de ' . self::USER_EMAIL,
            'data'            => '{"order":77}',
        ]);

        \HWE_Loyalty_Privacy::onDeleteUser(self::REFERRER);
        $first = [$this->wpdb->referrals, $this->wpdb->balances, $this->wpdb->ledger];

        \HWE_Loyalty_Privacy::onDeleteUser(self::REFERRER);

        $this->assertSame($first[0], $this->wpdb->referrals);
        $this->assertSame($first[1], $this->wpdb->balances);
        $this->assertSame($first[2], $this->wpdb->ledger);

        foreach ($this->logged as $line) {
            $this->assertStringStartsWith('[hwe] loyalty:', $line);
            $this->assertStringNotContainsString(self::USER_EMAIL, $line);
            $this->assertStringNotContainsString(self::REFERRER_EMAIL, $line);
            $this->assertStringNotContainsString('Ana Visible', $line);
            $this->assertStringNotContainsString('Beto Visible', $line);
        }
    }

    public function test_after_deleting_a_referrer_future_purchases_do_not_pay_commission_and_network_reads_tolerate_the_orphan(): void
    {
        $this->seedWallet(self::USER);
        $this->seedReferralsForExport();
        // Un tercero tenía al referidor en su red directa (y al comprador en la de segundo nivel).
        $this->wpdb->referrals[self::REFERRER] = [
            'user_id'       => self::REFERRER,
            'code'          => 'JKLMNPQR',
            'referrer_id'   => self::OTHER,
            'status'        => 'confirmed',
            'attributed_at' => gmdate('Y-m-d H:i:s', $this->now - 10 * DAY_IN_SECONDS),
            'confirmed_at'  => gmdate('Y-m-d H:i:s', $this->now - 10 * DAY_IN_SECONDS),
        ];
        $this->wpdb->referrals[self::REMAINING] = [
            'user_id'       => self::REMAINING,
            'code'          => 'WXYZ2345',
            'referrer_id'   => self::OTHER,
            'status'        => 'confirmed',
            'attributed_at' => gmdate('Y-m-d H:i:s', $this->now - 9 * DAY_IN_SECONDS),
            'confirmed_at'  => gmdate('Y-m-d H:i:s', $this->now - 9 * DAY_IN_SECONDS),
        ];

        $this->orders[55]              = new \WC_Order();
        $this->orders[55]->id          = 55;
        $this->orders[55]->customer_id = self::USER;
        $this->orders[55]->status      = 'processing';
        $this->orders[55]->paid        = true;
        $this->orders[55]->line_items  = [$this->item(101, 1, 100.0)];

        $this->assertSame(self::REFERRER, \HWE_Loyalty_Referrals::referrerOf(self::USER));

        \HWE_Loyalty_Privacy::onDeleteUser(self::REFERRER);

        $this->assertNull(\HWE_Loyalty_Referrals::referrerOf(self::USER), 'La red queda desvinculada.');
        $this->assertNull(\HWE_Loyalty_Referrals::attributionOf(self::USER)['referrer_id']);

        // El referidor sigue existiendo en este punto (WordPress lo borra después del hook):
        // una compra cobrada entre medias no puede abonarle nada.
        \HWE_Loyalty_Earnings::processOrder(55);

        $commissions = array_values(array_filter(
            $this->wpdb->ledger,
            static fn (array $row): bool => str_starts_with((string) $row['type'], 'referral_commission')
        ));
        $this->assertSame([], $commissions, 'Ninguna comisión para un id que ya no es referidor.');
        $this->assertSame(0, $this->balanceOf(self::REFERRER), 'El saldo del eliminado no recibe nada.');

        // WordPress borra la cuenta después del hook: las lecturas de red de un tercero que la
        // contenía no pueden romper ni devolver un id inexistente.
        unset($this->users[self::REFERRER]);

        $this->assertSame([self::REMAINING], array_column($this->itemsOfNetwork(self::OTHER, 1), 'user_id'));
        $this->assertSame([], $this->itemsOfNetwork(self::OTHER, 2));
        $this->assertSame(
            ['level1' => 1, 'level2' => 0, 'pending' => 0, 'earned' => 0],
            \HWE_Loyalty_Referrals::networkTotals(self::OTHER)
        );
    }

    // ── Utilidades del caso ──────────────────────────────────────────────────

    /** Saldo del libro y una fila de referidos con código válido. */
    private function seedWallet(int $userId): void
    {
        $this->seedBalance($userId, 110, 150, 40);
        $this->wpdb->referrals[$userId] = [
            'user_id'       => $userId,
            'code'          => 'WXYZ2345',
            'referrer_id'   => null,
            'status'        => 'pending',
            'attributed_at' => gmdate('Y-m-d H:i:s', $this->now - 5 * DAY_IN_SECONDS),
            'confirmed_at'  => null,
        ];
    }

    /** Red del caso base: el sujeto tiene referidor y dos referidos. */
    private function seedReferralsForExport(): void
    {
        $this->wpdb->referrals[self::USER] = [
            'user_id'       => self::USER,
            'code'          => 'ABCDEFGH',
            'referrer_id'   => self::REFERRER,
            'status'        => 'confirmed',
            'attributed_at' => gmdate('Y-m-d H:i:s', $this->now - 5 * DAY_IN_SECONDS),
            'confirmed_at'  => gmdate('Y-m-d H:i:s', $this->now - 4 * DAY_IN_SECONDS),
        ];
        $this->wpdb->referrals[self::REFERRED] = [
            'user_id'       => self::REFERRED,
            'code'          => 'JKLMNPQR',
            'referrer_id'   => self::USER,
            'status'        => 'confirmed',
            'attributed_at' => gmdate('Y-m-d H:i:s', $this->now - 3 * DAY_IN_SECONDS),
            'confirmed_at'  => gmdate('Y-m-d H:i:s', $this->now - 2 * DAY_IN_SECONDS),
        ];
        $this->wpdb->referrals[self::OTHER] = [
            'user_id'       => self::OTHER,
            'code'          => 'STUVWXYZ',
            'referrer_id'   => self::USER,
            'status'        => 'pending',
            'attributed_at' => gmdate('Y-m-d H:i:s', $this->now - 1 * DAY_IN_SECONDS),
            'confirmed_at'  => null,
        ];

        $this->seedEntry(1, self::USER, 100, 'cashback', [
            'idempotency_key' => 'cashback:55:' . self::USER,
            'ref_type'        => 'order',
            'ref_id'          => '55',
            'remaining_points' => 100,
            'expires_at'      => gmdate('Y-m-d H:i:s', $this->now + 30 * DAY_IN_SECONDS),
            'data'            => '{"order":55}',
            'created_at'      => gmdate('Y-m-d H:i:s', $this->now - 4 * DAY_IN_SECONDS),
        ]);
        $this->seedEntry(2, self::USER, 50, 'admin_credit', [
            'idempotency_key' => 'admin:1',
            'remaining_points' => 50,
            'note'            => 'Ajuste manual del equipo',
            'created_at'      => gmdate('Y-m-d H:i:s', $this->now - 3 * DAY_IN_SECONDS),
        ]);
        $this->seedEntry(3, self::USER, -30, 'transfer_out', [
            'idempotency_key' => 'transfer:1:out',
            'ref_type'        => 'user',
            'ref_id'          => (string) self::REFERRER,
            'note'            => 'Pago a Beto',
            'data'            => '{"to":' . self::REFERRER . ',"fee":0,"received":30}',
            'created_at'      => gmdate('Y-m-d H:i:s', $this->now - 2 * DAY_IN_SECONDS),
        ]);
        $this->seedEntry(4, self::USER, -10, 'expire', [
            'idempotency_key' => 'expire:1',
            'data'            => '{"entry":1}',
            'created_at'      => gmdate('Y-m-d H:i:s', $this->now - 1 * DAY_IN_SECONDS),
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function seedEntry(int $id, int $userId, int $points, string $type, array $overrides = []): void
    {
        $this->wpdb->ledger[$id] = array_merge([
            'id'               => $id,
            'user_id'          => $userId,
            'points'           => $points,
            'type'             => $type,
            'ref_type'         => null,
            'ref_id'           => null,
            'idempotency_key'  => 'seed:' . $id,
            'remaining_points' => $points > 0 ? $points : null,
            'expires_at'       => null,
            'actor'            => 'system',
            'actor_id'         => null,
            'note'             => null,
            'data'             => null,
            'created_at'       => gmdate('Y-m-d H:i:s', $this->now - $id * MINUTE_IN_SECONDS),
        ], $overrides);
    }

    private function seedBalance(int $userId, int $balance, int $earned, int $used): void
    {
        $this->wpdb->balances[$userId] = [
            'balance' => $balance,
            'earned'  => $earned,
            'used'    => $used,
        ];
    }

    private function item(int $productId, int $quantity, float $total, float $tax = 0.0): TestPrivacyItem
    {
        return new TestPrivacyItem($productId, $quantity, $total, $tax);
    }

    /** @param list<array<string,mixed>> $items */
    private function itemNamed(array $items, string $itemId): array
    {
        foreach ($items as $item) {
            if ((string) $item['item_id'] === $itemId) {
                return $item;
            }
        }

        $this->fail('No se encontró el elemento «' . $itemId . '» en la exportación.');
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,string>
     */
    private function valuesOf(array $item): array
    {
        $values = [];
        foreach ($item['data'] as $datum) {
            $values[(string) $datum['name']] = (string) $datum['value'];
        }
        return $values;
    }

    private function countAnonymized(int $userId): int
    {
        $count = 0;
        foreach ($this->wpdb->ledger as $row) {
            if ((int) $row['user_id'] === $userId && null === $row['note'] && null === $row['data']) {
                $count++;
            }
        }
        return $count;
    }

    /** @return list<array<string,mixed>> */
    private function itemsOfNetwork(int $userId, int $level): array
    {
        return \HWE_Loyalty_Referrals::network($userId, $level, 1, 20)['items'];
    }

    private function balanceOf(int $userId): int
    {
        return (int) \HWE_Loyalty_Ledger::balance($userId)['balance'];
    }
}

/**
 * Línea de pedido mínima: lo que `HWE_Loyalty_Earnings` lee para calcular la base.
 */
final class TestPrivacyItem
{
    public function __construct(
        private int $productId,
        private int $quantity,
        private float $total,
        private float $tax = 0.0
    ) {
    }

    public function get_product_id(): int
    {
        return $this->productId;
    }

    public function get_quantity(): int
    {
        return $this->quantity;
    }

    public function get_total(): float
    {
        return $this->total;
    }

    public function get_total_tax(): float
    {
        return $this->tax;
    }
}

/**
 * Doble de `$wpdb` para privacidad: mantiene referidos, libro y saldos en memoria. Entiende las
 * sentencias de `HWE_Loyalty_Ledger::history()/balance()`, `HWE_Loyalty_Referrals` y las
 * anonimizaciones de `HWE_Loyalty_Privacy`; cualquier otra consulta devuelve vacío.
 */
final class TestPrivacyWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var array<int,array<string,mixed>> */
    public array $referrals = [];
    /** @var array<int,array<string,mixed>> */
    public array $ledger = [];
    /** @var array<int,array{balance:int,earned:int,used:int}> */
    public array $balances = [];
    /** @var list<string> */
    public array $queries = [];

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
            foreach ($this->ledger as $row) {
                if ((string) $row['idempotency_key'] === (string) $data['idempotency_key']) {
                    return false;
                }
            }
            $id = [] === $this->ledger ? 1 : max(array_keys($this->ledger)) + 1;
            $this->insert_id = $id;
            $this->ledger[$id] = $data + ['id' => $id];
            return 1;
        }

        if (str_ends_with($table, 'hwe_loyalty_referrals')) {
            foreach ($this->referrals as $row) {
                if ((int) $row['user_id'] === (int) $data['user_id']) {
                    return false;
                }
                if ((string) $row['code'] === (string) $data['code']) {
                    return false;
                }
            }
            $this->referrals[(int) $data['user_id']] = $data;
            return 1;
        }

        return 1;
    }

    public function query(string $sql): int|false
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_starts_with($sql, 'INSERT IGNORE INTO ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[0] ?? 0);
            $this->balances[$userId] ??= ['balance' => 0, 'earned' => 0, 'used' => 0];
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[count($args) - 1] ?? 0);
            $row    = $this->balances[$userId] ?? ['balance' => 0, 'earned' => 0, 'used' => 0];
            if (str_contains($sql, 'balance = balance +')) {
                $row['balance'] += (int) ($args[0] ?? 0);
                $row['earned']  += (int) ($args[1] ?? 0);
            } elseif (str_contains($sql, 'balance = balance -')) {
                $row['balance'] -= (int) ($args[0] ?? 0);
                $row['used']    += (int) ($args[1] ?? 0);
            }
            $this->balances[$userId] = $row;
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->prefix . 'hwe_loyalty_ledger')) {
            if (str_contains($sql, 'SET note = NULL, data = NULL WHERE id = %d')) {
                [$id, $userId] = [(int) ($args[0] ?? 0), (int) ($args[1] ?? 0)];
                if (!isset($this->ledger[$id]) || (int) $this->ledger[$id]['user_id'] !== $userId) {
                    return 0;
                }
                $this->ledger[$id]['note'] = null;
                $this->ledger[$id]['data'] = null;
                return 1;
            }

            if (str_contains($sql, 'remaining_points = remaining_points - %d')) {
                [$points, $id] = [(int) ($args[0] ?? 0), (int) ($args[1] ?? 0)];
                if (!isset($this->ledger[$id])) {
                    return 0;
                }
                $this->ledger[$id]['remaining_points'] = (int) $this->ledger[$id]['remaining_points'] - $points;
                return 1;
            }

            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->prefix . 'hwe_loyalty_referrals')) {
            if (str_contains($sql, 'SET referrer_id = NULL, status = %s, confirmed_at = NULL WHERE referrer_id = %d')) {
                [$status, $referrerId] = [(string) ($args[0] ?? ''), (int) ($args[1] ?? 0)];
                $changed = 0;
                foreach ($this->referrals as $userId => $row) {
                    if (null === $row['referrer_id'] || (int) $row['referrer_id'] !== $referrerId) {
                        continue;
                    }
                    $this->referrals[$userId]['referrer_id']   = null;
                    $this->referrals[$userId]['status']        = $status;
                    $this->referrals[$userId]['confirmed_at']  = null;
                    $changed++;
                }
                return $changed;
            }

            if (str_contains($sql, 'SET code = %s, referrer_id = NULL, status = %s, confirmed_at = NULL WHERE user_id = %d')) {
                [$code, $status, $userId] = [(string) ($args[0] ?? ''), (string) ($args[1] ?? ''), (int) ($args[2] ?? 0)];
                if (!isset($this->referrals[$userId])) {
                    return 0;
                }
                $this->referrals[$userId]['code']         = $code;
                $this->referrals[$userId]['referrer_id']  = null;
                $this->referrals[$userId]['status']       = $status;
                $this->referrals[$userId]['confirmed_at'] = null;
                return 1;
            }

            return 1;
        }

        if (str_starts_with($sql, 'DELETE FROM ' . $this->prefix . 'hwe_loyalty_referrals')) {
            $userId = (int) ($args[0] ?? 0);
            if (!isset($this->referrals[$userId])) {
                return 0;
            }
            unset($this->referrals[$userId]);
            return 1;
        }

        if (str_starts_with($sql, 'DELETE FROM ' . $this->prefix . 'hwe_loyalty_balances')) {
            $userId = (int) ($args[0] ?? 0);
            if (!isset($this->balances[$userId])) {
                return 0;
            }
            unset($this->balances[$userId]);
            return 1;
        }

        // START TRANSACTION, COMMIT y ROLLBACK no necesitan estado en el doble.
        return 1;
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

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_referrals')) {
            if (str_contains($sql, 'WHERE code = %s')) {
                $code = (string) ($args[0] ?? '');
                foreach ($this->referrals as $row) {
                    if ((string) $row['code'] === $code) {
                        return (object) $row;
                    }
                }
                return null;
            }

            $userId = (int) ($args[0] ?? 0);
            return isset($this->referrals[$userId]) ? (object) $this->referrals[$userId] : null;
        }

        return null;
    }

    public function get_var(string $sql): string|int|null
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_ledger')
            && str_contains($sql, 'AND (note IS NOT NULL OR data IS NOT NULL)')) {
            $userId = (int) ($args[0] ?? 0);
            $count  = 0;
            foreach ($this->ledger as $row) {
                if ((int) $row['user_id'] === $userId && (null !== $row['note'] || null !== $row['data'])) {
                    $count++;
                }
            }
            return $count;
        }

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_ledger')
            && str_contains($sql, 'COUNT(*)')) {
            $userId = (int) ($args[0] ?? 0);
            $count  = 0;
            foreach ($this->ledger as $row) {
                if ((int) $row['user_id'] === $userId) {
                    $count++;
                }
            }
            return $count;
        }

        if (str_contains($sql, 'idempotency_key')) {
            foreach ($this->ledger as $row) {
                if ((string) $row['idempotency_key'] === (string) ($args[0] ?? '')) {
                    return (int) $row['id'];
                }
            }
            return null;
        }

        if (str_contains($sql, 'r.status = %s')) {
            $status = (string) ($args[0] ?? '');
            $userId = (int) ($args[1] ?? 0);
            $count  = 0;
            foreach ($this->referrals as $row) {
                if ((string) $row['status'] !== $status) {
                    continue;
                }
                $referrer = null === $row['referrer_id'] ? null : (int) $row['referrer_id'];
                if ($referrer === $userId || $this->parentOf($referrer) === $userId) {
                    $count++;
                }
            }
            return $count;
        }

        if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'hwe_loyalty_referrals')) {
            $userId = (int) ($args[0] ?? 0);
            $count  = 0;
            foreach ($this->referrals as $row) {
                $referrer = null === $row['referrer_id'] ? null : (int) $row['referrer_id'];
                if (str_contains($sql, 'INNER JOIN')) {
                    $parent = null === $referrer ? null : ($this->referrals[$referrer] ?? null);
                    if (null !== $parent && null !== $parent['referrer_id'] && (int) $parent['referrer_id'] === $userId) {
                        $count++;
                    }
                } elseif ($referrer === $userId) {
                    $count++;
                }
            }
            return $count;
        }

        if (str_contains($sql, 'COALESCE(SUM')) {
            return 0;
        }

        return null;
    }

    /** @return list<object> */
    public function get_results(string $sql): array
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_ledger')
            && str_contains($sql, 'SELECT id, data')) {
            $userId = (int) ($args[0] ?? 0);
            $limit  = (int) ($args[1] ?? 100);
            $rows   = [];
            foreach ($this->ledger as $row) {
                if ((int) $row['user_id'] === $userId && (null !== $row['note'] || null !== $row['data'])) {
                    $rows[] = $row;
                }
            }
            usort($rows, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);

            return array_map(
                static fn (array $row): object => (object) ['id' => $row['id'], 'data' => $row['data']],
                array_slice($rows, 0, $limit)
            );
        }

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_ledger')) {
            $userId = (int) ($args[0] ?? 0);
            $limit  = (int) ($args[1] ?? 100);
            $offset = (int) ($args[2] ?? 0);
            $rows   = [];
            foreach ($this->ledger as $row) {
                if ((int) $row['user_id'] === $userId) {
                    $rows[] = $row;
                }
            }
            usort($rows, static function (array $left, array $right): int {
                return [(string) $right['created_at'], (int) $right['id']] <=> [(string) $left['created_at'], (int) $left['id']];
            });

            return array_map(
                static fn (array $row): object => (object) $row,
                array_slice($rows, $offset, $limit)
            );
        }

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_loyalty_referrals')) {
            $userId = (int) ($args[0] ?? 0);
            $limit  = (int) ($args[1] ?? 20);
            $offset = (int) ($args[2] ?? 0);
            $rows   = [];
            foreach ($this->referrals as $row) {
                $referrer = null === $row['referrer_id'] ? null : (int) $row['referrer_id'];
                if (str_contains($sql, 'INNER JOIN')) {
                    $parent = null === $referrer ? null : ($this->referrals[$referrer] ?? null);
                    if (null === $parent || null === $parent['referrer_id'] || (int) $parent['referrer_id'] !== $userId) {
                        continue;
                    }
                } elseif ($referrer !== $userId) {
                    continue;
                }
                $rows[] = $row;
            }
            usort($rows, static fn (array $left, array $right): int => (int) $left['user_id'] <=> (int) $right['user_id']);

            return array_map(
                static fn (array $row): object => (object) $row,
                array_slice($rows, $offset, $limit)
            );
        }

        return [];
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        return false;
    }

    private function parentOf(?int $userId): ?int
    {
        if (null === $userId || !isset($this->referrals[$userId])) {
            return null;
        }
        $row = $this->referrals[$userId];
        return null === $row['referrer_id'] ? null : (int) $row['referrer_id'];
    }

    /** @return array<int,mixed> */
    private function args(string $sql): array
    {
        if (preg_match('~/\\* (\\[.*\\]) \\*/$~s', $sql, $matches)) {
            $decoded = json_decode($matches[1], true);
            return is_array($decoded) ? array_values($decoded) : [];
        }
        return [];
    }
}
