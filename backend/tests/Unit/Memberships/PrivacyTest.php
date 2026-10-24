<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin `hwe-memberships` (módulo `memberships`), privacidad (tarea 23, spec §14).
 *
 * Se prueba el ciclo de privacidad de WordPress desde la DECISIÓN: qué exporta el exportador
 * (estado del socio, concesiones e historial, sin emails ni identidades de terceros), qué
 * conserva y qué borra el eliminador (las concesiones de origen pedido y su historial quedan
 * anonimizados por ser contabilidad de pedidos; el resto de concesiones y eventos desaparecen;
 * los tokens del carné y la fila del socio se borran) y qué pasa al eliminar una cuenta
 * (`delete_user` no hereda nada con el `reassign`). También se fija la paginación por lotes, la
 * idempotencia y que un fallo de escritura cierra en falso en vez de darse por terminado.
 *
 * El doble de `$wpdb` mantiene en memoria socios, concesiones, historial y tokens; entiende solo
 * las sentencias que emiten `HWE_Memberships_Members` (lectura), `HWE_Memberships_Grants`
 * (lectura para el resumen) y `HWE_Memberships_Privacy`; no interpreta SQL arbitrario.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PrivacyTest extends TestCase
{
    private const USER = 7;
    private const OTHER = 8;
    private const MANY = 11;
    private const MANY_ERASED = 12;
    private const NO_DATA = 13;
    private const DELETED = 14;
    private const REASSIGN = 15;

    private const USER_EMAIL = 'socia@example.test';
    private const OTHER_EMAIL = 'tercero@example.test';
    private const MANY_EMAIL = 'muchos@example.test';
    private const MANY_ERASED_EMAIL = 'borrados@example.test';
    private const NO_DATA_EMAIL = 'sindatos@example.test';
    private const DELETED_EMAIL = 'eliminada@example.test';

    private const LEVEL = 101;
    private const LEVEL_UP = 102;

    /** @var array<int,object> Usuarios que devuelve `get_userdata`. */
    private array $users = [];
    /** @var array<int,\WP_Post> Niveles que devuelve `get_post`. */
    private array $levelPosts = [];
    /** @var array<int,array<string,string>> Metas de los niveles. */
    private array $levelMeta = [];
    /** @var list<string> */
    private array $logged = [];
    private int $now = 1_800_000_000;
    private TestMembershipsPrivacyWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users      = $this->makeUsers();
        $this->levelPosts = [];
        $this->levelMeta  = [];
        $this->logged     = [];
        $this->now        = 1_800_000_000;

        $this->wpdb      = new TestMembershipsPrivacyWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->loadOwnFile('plugins/hwe-memberships/includes/Schema.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Levels.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Timeline.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Events.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Members.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Grants.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Privacy.php');
        \HWE_Memberships_Events::clear();

        $this->makeLevel(self::LEVEL, 'Nivel inicial', 5);
        $this->makeLevel(self::LEVEL_UP, 'Nivel avanzado', 10);

        Functions\when('get_post')->alias(fn (int $id) => $this->levelPosts[$id] ?? null);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->levelMeta[$id][$key] ?? ''
        );
        Functions\when('get_userdata')->alias(fn (int $id) => $this->users[$id] ?? false);
        Functions\when('get_user_by')->alias(function (string $field, mixed $value) {
            if ('email' !== $field) {
                return false;
            }
            foreach ($this->users as $user) {
                if (($user->user_email ?? '') === $value) {
                    return $user;
                }
            }
            return false;
        });
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value, mixed ...$args): mixed => $value);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => is_string($value) ? trim($value) : '',
        ]);
    }

    /** @return array<int,object> */
    private function makeUsers(): array
    {
        return [
            self::USER => (object) [
                'ID'           => self::USER,
                'user_email'   => self::USER_EMAIL,
                'user_login'   => 'socia_login',
                'display_name' => 'Ana Visible',
            ],
            self::OTHER => (object) [
                'ID'           => self::OTHER,
                'user_email'   => self::OTHER_EMAIL,
                'user_login'   => 'tercero_login',
                'display_name' => 'Dani Visible',
            ],
            self::MANY => (object) [
                'ID'           => self::MANY,
                'user_email'   => self::MANY_EMAIL,
                'user_login'   => 'muchos_login',
                'display_name' => 'Muchas Concesiones',
            ],
            self::MANY_ERASED => (object) [
                'ID'           => self::MANY_ERASED,
                'user_email'   => self::MANY_ERASED_EMAIL,
                'user_login'   => 'borrados_login',
                'display_name' => 'Muchas Borradas',
            ],
            self::NO_DATA => (object) [
                'ID'           => self::NO_DATA,
                'user_email'   => self::NO_DATA_EMAIL,
                'user_login'   => 'sindatos_login',
                'display_name' => 'Sin Datos',
            ],
            self::DELETED => (object) [
                'ID'           => self::DELETED,
                'user_email'   => self::DELETED_EMAIL,
                'user_login'   => 'eliminada_login',
                'display_name' => 'Cuenta Eliminada',
            ],
            self::REASSIGN => (object) [
                'ID'           => self::REASSIGN,
                'user_email'   => 'heredera@example.test',
                'user_login'   => 'heredera_login',
                'display_name' => 'Cuenta Heredera',
            ],
        ];
    }

    private function makeLevel(int $id, string $name, int $rank): void
    {
        $post                  = new \WP_Post();
        $post->ID              = $id;
        $post->post_type       = \HWE_Memberships_Levels::POST_TYPE;
        $post->post_status     = 'publish';
        $post->post_title      = $name;
        $post->post_name       = 'nivel-' . $id;
        $post->post_content    = '';
        $this->levelPosts[$id] = $post;
        $this->levelMeta[$id]  = [\HWE_Memberships_Levels::RANK_META => (string) $rank];
    }

    // ── Registro ─────────────────────────────────────────────────────────────

    public function test_register_hooks_exporters_erasers_and_the_delete_user_cleanup(): void
    {
        Filters\expectAdded('wp_privacy_personal_data_exporters')
            ->once()
            ->with([\HWE_Memberships_Privacy::class, 'registerExporter']);
        Filters\expectAdded('wp_privacy_personal_data_erasers')
            ->once()
            ->with([\HWE_Memberships_Privacy::class, 'registerEraser']);
        Actions\expectAdded('delete_user')
            ->once()
            ->with([\HWE_Memberships_Privacy::class, 'onDeleteUser'], 10, 3);

        \HWE_Memberships_Privacy::register();
    }

    // ── Exportador ───────────────────────────────────────────────────────────

    public function test_exporter_returns_state_grants_and_history(): void
    {
        $this->seedExportCase();

        $export = \HWE_Memberships_Privacy::exportPersonalData(self::USER_EMAIL, 1);

        $this->assertTrue($export['done']);
        $this->assertCount(5, $export['data'], 'El resumen, las dos concesiones y los dos eventos.');

        $summary = $this->valuesOf($this->itemNamed($export['data'], 'hwe-memberships-summary-' . self::USER));
        $this->assertSame('000042', $summary['Número de socio']);
        $this->assertNotSame('', $summary['Socio desde (UTC)']);
        $this->assertSame('Activo', $summary['Estado']);
        $this->assertSame('Nivel inicial', $summary['Nivel']);
        $this->assertNotSame('', $summary['Fin de la cobertura (UTC)']);

        $grant = $this->valuesOf($this->itemNamed($export['data'], 'hwe-memberships-grant-1'));
        $this->assertSame('Nivel inicial', $grant['Nivel']);
        $this->assertSame('order', $grant['Origen']);
        $this->assertSame('Activa', $grant['Estado']);
        $this->assertSame('30', $grant['Duración (días)']);
        $this->assertSame('55', $grant['Pedido']);
        $this->assertSame('Alta por pedido', $grant['Nota']);
        $this->assertArrayHasKey('Inicio (UTC)', $grant);
        $this->assertArrayHasKey('Fin (UTC)', $grant);
        $this->assertArrayHasKey('Creada (UTC)', $grant);

        $admin = $this->valuesOf($this->itemNamed($export['data'], 'hwe-memberships-grant-2'));
        $this->assertSame('Nivel avanzado', $admin['Nivel']);
        $this->assertSame('admin', $admin['Origen']);
        $this->assertSame('Terminada', $admin['Estado']);
        $this->assertArrayNotHasKey('Pedido', $admin, 'Solo se exporta la referencia al pedido, nunca otras.');
        $this->assertSame('Ajuste del equipo', $admin['Nota']);

        $event = $this->valuesOf($this->itemNamed($export['data'], 'hwe-memberships-event-2'));
        $this->assertNotSame('', $event['Fecha (UTC)']);
        $this->assertSame('Concesión por administrador', $event['Tipo']);
        $this->assertSame('Nivel avanzado', $event['Nivel nuevo']);
        $this->assertSame('Administrador', $event['Actor']);
    }

    public function test_exporter_never_includes_emails_logins_or_identities_of_third_parties(): void
    {
        $this->seedExportCase();

        $export = \HWE_Memberships_Privacy::exportPersonalData(self::USER_EMAIL, 1);
        $json   = (string) json_encode($export);

        $this->assertStringContainsString('Nivel inicial', $json);
        $this->assertStringNotContainsString(self::USER_EMAIL, $json, 'El export no repite el email del propio sujeto.');
        $this->assertStringNotContainsString('socia_login', $json);
        $this->assertStringNotContainsString(self::OTHER_EMAIL, $json);
        $this->assertStringNotContainsString('tercero_login', $json);
        $this->assertStringNotContainsString('Dani Visible', $json);
        $this->assertStringNotContainsString('"id":8', $json, 'Los datos estructurados en bruto no se exportan.');

        foreach ($export['data'] as $item) {
            foreach ($this->valuesOf($item) as $name => $value) {
                $this->assertStringNotContainsString('@', $value, 'Ningún campo del export lleva correos.');
                $this->assertNotSame('Email', $name);
                $this->assertNotSame('Login', $name);
                $this->assertNotSame('Actor ID', $name);
            }
        }
    }

    public function test_exporter_paginates_grants_and_events_without_duplicates(): void
    {
        for ($id = 1; $id <= 130; $id++) {
            $this->seedGrant($id, self::MANY);
            $this->seedEvent(1000 + $id, self::MANY, $id, 'activated');
        }

        $first  = \HWE_Memberships_Privacy::exportPersonalData(self::MANY_EMAIL, 1);
        $second = \HWE_Memberships_Privacy::exportPersonalData(self::MANY_EMAIL, 2);
        $third  = \HWE_Memberships_Privacy::exportPersonalData(self::MANY_EMAIL, 3);

        $this->assertFalse($first['done']);
        $this->assertCount(101, $first['data'], 'El resumen de la primera página más 100 elementos.');
        $this->assertFalse($second['done']);
        $this->assertCount(100, $second['data']);
        $this->assertTrue($third['done']);
        $this->assertCount(60, $third['data']);

        $ids = [];
        foreach (array_merge($first['data'], $second['data'], $third['data']) as $item) {
            $itemId = (string) $item['item_id'];
            if (str_starts_with($itemId, 'hwe-memberships-grant-') || str_starts_with($itemId, 'hwe-memberships-event-')) {
                $ids[] = $itemId;
            }
        }

        $this->assertCount(260, $ids);
        $this->assertCount(260, array_unique($ids), 'La paginación no repite concesiones ni eventos.');
    }

    // ── Eliminador ───────────────────────────────────────────────────────────

    public function test_eraser_anonymizes_order_accounting_and_deletes_the_rest(): void
    {
        $this->seedErasureCase();

        $result = \HWE_Memberships_Privacy::erasePersonalData(self::USER_EMAIL, 1);

        $this->assertTrue($result['done']);
        $this->assertTrue($result['items_removed']);
        $this->assertTrue($result['items_retained'], 'Las concesiones de pedido se conservan por obligación contable.');
        $this->assertStringContainsString('contable', implode(' ', $result['messages']));

        $order = $this->wpdb->grants[10];
        $this->assertSame(self::USER, (int) $order['user_id'], 'Sin user_id la concesión no cuadraría con su pedido.');
        $this->assertSame(self::LEVEL, (int) $order['level_id']);
        $this->assertSame(30, (int) $order['duration_days']);
        $this->assertSame('revoked', $order['status'], 'El borrado de datos termina la membresía.');
        $this->assertNotSame('', (string) $order['revoked_at']);
        $this->assertSame('order', $order['ref_type']);
        $this->assertSame('55', (string) $order['ref_id']);
        $this->assertSame(2, (int) $order['shipping_quota_used'], 'Los contadores de uso se conservan.');
        $this->assertSame($this->at(-5), substr((string) $order['starts_at'], 0, 19));
        $this->assertSame($this->at(0), substr((string) $order['ends_at'], 0, 19));
        $this->assertNull($order['note'], 'La nota libre puede llevar datos personales.');
        $this->assertNull($order['data'], 'Los datos estructurados pueden llevar identidades de terceros.');
        $this->assertNull($order['actor_id'], 'El actor que apunta al propio sujeto se desvincula.');

        $previous = $this->wpdb->grants[12];
        $this->assertSame('order', $previous['origin']);
        $this->assertSame('ended', $previous['status'], 'Una concesión terminada conserva su estado.');
        $this->assertNull($previous['revoked_at']);
        $this->assertSame($this->at(-70), substr((string) $previous['ends_at'], 0, 19));
        $this->assertNull($previous['note']);
        $this->assertNull($previous['data']);
        $this->assertSame('59', (string) $previous['ref_id'], 'La referencia contable se conserva.');

        $scheduled = $this->wpdb->grants[13];
        $this->assertSame('revoked', $scheduled['status'], 'Una concesión programada tampoco puede seguir viva.');
        $this->assertNotSame('', (string) $scheduled['revoked_at']);
        $this->assertSame($this->at(10), substr((string) $scheduled['starts_at'], 0, 19));
        $this->assertSame($this->at(40), substr((string) $scheduled['ends_at'], 0, 19), 'Solo la activa se cierra en el momento del borrado.');
        $this->assertNull($scheduled['note']);
        $this->assertNull($scheduled['data']);

        $this->assertArrayNotHasKey(11, $this->wpdb->grants, 'Las concesiones que no son de pedido se borran.');

        $event = $this->wpdb->events[100];
        $this->assertSame('activated', $event['type'], 'El historial contable se conserva.');
        $this->assertSame(self::LEVEL, (int) $event['level_to']);
        $this->assertNull($event['data']);
        $this->assertNull($event['actor_id']);
        $this->assertArrayNotHasKey(101, $this->wpdb->events, 'El historial de una concesión borrada se borra.');
        $this->assertArrayNotHasKey(102, $this->wpdb->events, 'El historial suelto del sujeto también se borra.');
        $this->assertSame([100, 200], array_keys($this->wpdb->events), 'La revocación no escribe historial nuevo; el ajeno se conserva.');
        $this->assertSame([], \HWE_Memberships_Events::pending(), 'La limpieza no dispara hooks.');

        $this->assertSame(self::OTHER, (int) $this->wpdb->grants[20]['user_id'], 'Lo que es de otro usuario no se toca.');
        $this->assertSame('Ajuste ajeno', $this->wpdb->grants[20]['note']);
        $this->assertSame('{"x":1}', $this->wpdb->grants[20]['data']);
        $this->assertSame('{"x":1}', $this->wpdb->events[200]['data']);
    }

    public function test_eraser_revokes_live_order_grants_without_writing_new_history(): void
    {
        $this->seedMember(self::USER);
        $this->seedGrant(10, self::USER, ['status' => 'active', 'note' => 'Activa', 'data' => '{"x":1}']);
        $this->seedGrant(11, self::USER, [
            'status'         => 'scheduled',
            'starts_at'      => $this->at(10),
            'ends_at'        => $this->at(40),
            'queued_seconds' => 3600,
            'note'           => 'Programada',
            'data'           => '{"x":2}',
        ]);
        $this->seedGrant(12, self::USER, [
            'status'    => 'ended',
            'starts_at' => $this->at(-100),
            'ends_at'   => $this->at(-70),
            'note'      => 'Terminada',
            'data'      => '{"x":3}',
        ]);
        $this->seedEvent(100, self::USER, 10, 'activated', ['data' => '{"key":"order:55"}']);

        $result = \HWE_Memberships_Privacy::erasePersonalData(self::USER_EMAIL, 1);

        $this->assertTrue($result['done']);
        $this->assertTrue($result['items_removed']);

        $active = $this->wpdb->grants[10];
        $this->assertSame('revoked', $active['status'], 'Una concesión viva no puede seguir viva tras el borrado.');
        $this->assertSame($this->at(0), substr((string) $active['revoked_at'], 0, 19));
        $this->assertSame($this->at(0), substr((string) $active['ends_at'], 0, 19));
        $this->assertNull($active['note']);
        $this->assertNull($active['data']);

        $scheduled = $this->wpdb->grants[11];
        $this->assertSame('revoked', $scheduled['status']);
        $this->assertSame($this->at(0), substr((string) $scheduled['revoked_at'], 0, 19));
        $this->assertSame($this->at(10), substr((string) $scheduled['starts_at'], 0, 19), 'La fecha de inicio se conserva.');
        $this->assertSame($this->at(40), substr((string) $scheduled['ends_at'], 0, 19), 'Solo la activa se cierra en el momento del borrado.');
        $this->assertNull($scheduled['note']);
        $this->assertNull($scheduled['data']);

        $ended = $this->wpdb->grants[12];
        $this->assertSame('ended', $ended['status'], 'Una concesión terminada no se toca.');
        $this->assertNull($ended['revoked_at']);
        $this->assertSame($this->at(-70), substr((string) $ended['ends_at'], 0, 19));
        $this->assertNull($ended['note']);
        $this->assertNull($ended['data']);

        $this->assertSame([100], array_keys($this->wpdb->events), 'No se escribe historial nuevo al revocar.');
        $this->assertSame([], \HWE_Memberships_Events::pending(), 'La limpieza no dispara hooks.');
    }

    public function test_eraser_removes_the_card_tokens_and_the_member_row(): void
    {
        $this->seedMember(self::USER);
        $this->seedToken(self::USER, str_repeat('a', 64));
        $this->seedToken(self::USER, str_repeat('b', 64), null, $this->at(-1));
        $this->seedGrant(10, self::USER);

        $result = \HWE_Memberships_Privacy::erasePersonalData(self::USER_EMAIL, 1);

        $this->assertTrue($result['done']);
        $this->assertTrue($result['items_removed']);
        $this->assertSame([], $this->wpdb->tokensOf(self::USER), 'Se borran todos los tokens, también los revocados.');
        $this->assertArrayNotHasKey(self::USER, $this->wpdb->members, 'La fila del socio desaparece.');
    }

    public function test_eraser_is_idempotent_and_keeps_reporting_the_accounting_retention(): void
    {
        $this->seedErasureCase();

        \HWE_Memberships_Privacy::erasePersonalData(self::USER_EMAIL, 1);
        $this->assertSame('revoked', $this->wpdb->grants[10]['status']);
        $this->assertSame('revoked', $this->wpdb->grants[13]['status']);
        $first = [$this->wpdb->grants, $this->wpdb->events, $this->wpdb->tokens, $this->wpdb->members];

        $second = \HWE_Memberships_Privacy::erasePersonalData(self::USER_EMAIL, 1);

        $this->assertTrue($second['done']);
        $this->assertFalse($second['items_removed'], 'La segunda pasada no encuentra nada que borrar.');
        $this->assertTrue($second['items_retained']);
        $this->assertStringContainsString('contable', implode(' ', $second['messages']));
        $this->assertSame($first[0], $this->wpdb->grants);
        $this->assertSame($first[1], $this->wpdb->events);
        $this->assertSame($first[2], $this->wpdb->tokens);
        $this->assertSame($first[3], $this->wpdb->members);
    }

    public function test_eraser_paginates_the_pending_work_in_batches(): void
    {
        for ($id = 1; $id <= 130; $id++) {
            $this->seedGrant($id, self::MANY_ERASED, ['note' => 'Nota con datos ' . $id]);
            $this->seedEvent(1000 + $id, self::MANY_ERASED, $id, 'activated', [
                'data'     => '{"key":"order:' . $id . '"}',
                'actor_id' => self::MANY_ERASED,
            ]);
        }

        $first = \HWE_Memberships_Privacy::erasePersonalData(self::MANY_ERASED_EMAIL, 1);

        $this->assertFalse($first['done']);
        $this->assertSame(100, $this->countAnonymizedGrants(self::MANY_ERASED), 'Un lote por página.');
        $this->assertSame(100, $this->countAnonymizedEvents(self::MANY_ERASED));

        $second = \HWE_Memberships_Privacy::erasePersonalData(self::MANY_ERASED_EMAIL, 2);

        $this->assertTrue($second['done']);
        $this->assertSame(130, $this->countAnonymizedGrants(self::MANY_ERASED));
        $this->assertSame(130, $this->countAnonymizedEvents(self::MANY_ERASED));
    }

    public function test_eraser_with_a_user_without_membership_data_removes_nothing(): void
    {
        $result = \HWE_Memberships_Privacy::erasePersonalData(self::NO_DATA_EMAIL, 1);

        $this->assertTrue($result['done']);
        $this->assertFalse($result['items_removed']);
        $this->assertFalse($result['items_retained']);
        $this->assertSame([], $result['messages']);
        $this->assertSame([], $this->wpdb->grants);
        $this->assertSame([], $this->wpdb->events);
        $this->assertSame([], $this->wpdb->tokens);
        $this->assertSame([], $this->wpdb->members);

        $unknown = \HWE_Memberships_Privacy::erasePersonalData('nadie@example.test', 1);
        $this->assertTrue($unknown['done']);
        $this->assertFalse($unknown['items_removed']);

        $export = \HWE_Memberships_Privacy::exportPersonalData(self::NO_DATA_EMAIL, 1);
        $this->assertTrue($export['done']);
        $this->assertCount(1, $export['data'], 'Solo el resumen, sin concesiones ni historial.');
        $this->assertSame('Sin membresía', $this->valuesOf($export['data'][0])['Estado']);

        $unknownExport = \HWE_Memberships_Privacy::exportPersonalData('nadie@example.test', 1);
        $this->assertTrue($unknownExport['done']);
        $this->assertSame([], $unknownExport['data']);
    }

    public function test_eraser_fails_closed_and_logs_when_a_write_fails(): void
    {
        $this->seedMember(self::USER);
        $this->seedGrant(10, self::USER, ['note' => 'Alta por pedido', 'data' => '{"order":55}']);

        $this->wpdb->failQueryNeedle = 'UPDATE ' . $this->wpdb->prefix . 'hwe_memberships_grants SET note';

        $result = \HWE_Memberships_Privacy::erasePersonalData(self::USER_EMAIL, 1);

        $this->assertFalse($result['done'], 'Un fallo de escritura no puede darse por terminado.');
        $this->assertFalse($result['items_removed']);
        $this->assertSame('Alta por pedido', $this->wpdb->grants[10]['note'], 'El ROLLBACK deja el estado anterior.');
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('no se pud', implode(' ', $this->logged));
    }

    // ── Cuenta eliminada (delete_user) ───────────────────────────────────────

    public function test_delete_user_keeps_order_accounting_and_ignores_reassign(): void
    {
        $this->seedDeletedAccountCase();

        \HWE_Memberships_Privacy::onDeleteUser(self::DELETED, self::REASSIGN, $this->users[self::DELETED]);

        $order = $this->wpdb->grants[30];
        $this->assertSame(self::DELETED, (int) $order['user_id'], 'La membresía no se hereda con `reassign`.');
        $this->assertSame(1, (int) $order['shipping_quota_used'], 'La contabilidad del pedido se conserva.');
        $this->assertSame('55', (string) $order['ref_id']);
        $this->assertSame('revoked', $order['status'], 'El borrado de la cuenta termina la membresía.');
        $this->assertNotSame('', (string) $order['revoked_at']);
        $this->assertSame($this->at(0), substr((string) $order['ends_at'], 0, 19));
        $this->assertNull($order['note']);
        $this->assertNull($order['data']);
        $this->assertNull($order['actor_id']);

        $scheduled = $this->wpdb->grants[32];
        $this->assertSame('revoked', $scheduled['status'], 'Una concesión programada tampoco sigue viva.');
        $this->assertNotSame('', (string) $scheduled['revoked_at']);
        $this->assertSame($this->at(40), substr((string) $scheduled['ends_at'], 0, 19));
        $this->assertNull($scheduled['note']);

        $ended = $this->wpdb->grants[33];
        $this->assertSame('ended', $ended['status'], 'Una concesión terminada conserva su estado.');
        $this->assertNull($ended['revoked_at']);
        $this->assertSame($this->at(-70), substr((string) $ended['ends_at'], 0, 19));
        $this->assertNull($ended['note']);
        $this->assertNull($ended['data']);

        $this->assertArrayNotHasKey(31, $this->wpdb->grants, 'Las concesiones que no son de pedido se borran.');
        $this->assertSame('renewed', $this->wpdb->events[300]['type']);
        $this->assertNull($this->wpdb->events[300]['data']);
        $this->assertNull($this->wpdb->events[300]['actor_id']);
        $this->assertArrayNotHasKey(301, $this->wpdb->events);
        $this->assertArrayNotHasKey(302, $this->wpdb->events);
        $this->assertSame([300, 400], array_keys($this->wpdb->events), 'La revocación no escribe historial nuevo; el ajeno se conserva.');
        $this->assertSame([], \HWE_Memberships_Events::pending(), 'La limpieza no dispara hooks.');

        $this->assertSame([], $this->wpdb->tokensOf(self::DELETED));
        $this->assertArrayNotHasKey(self::DELETED, $this->wpdb->members);
        $this->assertArrayHasKey(self::REASSIGN, $this->wpdb->members, 'La cuenta de reasignación no cambia.');

        $reassigned = array_keys(array_filter(
            $this->wpdb->grants,
            static fn (array $grant): bool => (int) $grant['user_id'] === self::REASSIGN
        ));
        $this->assertSame([40], $reassigned, 'Ninguna concesión de la cuenta eliminada pasa a la de reasignación.');
        $this->assertSame(self::DELETED, (int) $this->wpdb->grants[30]['user_id']);

        $this->assertSame('Compra de la otra cuenta', $this->wpdb->grants[40]['note']);
        $this->assertSame('{"x":2}', $this->wpdb->grants[40]['data']);
        $this->assertSame('{"x":2}', $this->wpdb->events[400]['data']);
        $this->assertCount(1, $this->wpdb->tokensOf(self::REASSIGN));
        $this->assertSame('42', $this->wpdb->option_rows[\HWE_Memberships_Grants::MEMBER_SEQUENCE_OPTION], 'El número de socio no se reutiliza.');
    }

    public function test_delete_user_is_idempotent_and_logs_without_pii(): void
    {
        $this->seedDeletedAccountCase();

        \HWE_Memberships_Privacy::onDeleteUser(self::DELETED, self::REASSIGN, $this->users[self::DELETED]);
        $this->assertSame('revoked', $this->wpdb->grants[30]['status']);
        $this->assertSame('revoked', $this->wpdb->grants[32]['status']);
        $first = [$this->wpdb->grants, $this->wpdb->events, $this->wpdb->tokens, $this->wpdb->members];

        \HWE_Memberships_Privacy::onDeleteUser(self::DELETED, self::REASSIGN, $this->users[self::DELETED]);

        $this->assertSame($first[0], $this->wpdb->grants);
        $this->assertSame($first[1], $this->wpdb->events);
        $this->assertSame($first[2], $this->wpdb->tokens);
        $this->assertSame($first[3], $this->wpdb->members);

        $this->assertNotEmpty($this->logged);
        foreach ($this->logged as $line) {
            $this->assertStringStartsWith('[hwe] memberships:', $line);
            $this->assertStringNotContainsString(self::DELETED_EMAIL, $line);
            $this->assertStringNotContainsString('eliminada_login', $line);
            $this->assertStringNotContainsString('Cuenta Eliminada', $line);
            $this->assertStringNotContainsString(self::USER_EMAIL, $line);
            $this->assertStringNotContainsString('Ana Visible', $line);
        }
    }

    // ── Utilidades del caso ──────────────────────────────────────────────────

    /** Caso de exportación: un socio con una concesión de pedido y otra de administración. */
    private function seedExportCase(): void
    {
        $this->seedMember(self::USER, [
            'level_id'       => self::LEVEL,
            'covers_until'   => $this->at(25),
            'member_number'  => '000042',
            'member_since'   => $this->at(-30),
        ]);
        $this->seedGrant(1, self::USER, [
            'level_id'  => self::LEVEL,
            'origin'    => 'order',
            'ref_type'  => 'order',
            'ref_id'    => '55',
            'note'      => 'Alta por pedido',
            'data'      => '{"source":"checkout","order":55,"email":"' . self::OTHER_EMAIL . '"}',
        ]);
        $this->seedGrant(2, self::USER, [
            'level_id'      => self::LEVEL_UP,
            'origin'        => 'admin',
            'status'        => 'ended',
            'starts_at'     => $this->at(-60),
            'ends_at'       => $this->at(-30),
            'duration_days' => 0,
            'ref_type'      => null,
            'ref_id'        => null,
            'note'          => 'Ajuste del equipo',
            'data'          => '{"ref":{"type":"user","id":8},"login":"tercero_login"}',
        ]);
        $this->seedEvent(1, self::USER, 1, 'activated', [
            'level_from' => 0,
            'level_to'   => self::LEVEL,
            'actor'      => 'system',
        ]);
        $this->seedEvent(2, self::USER, 2, 'granted_by_admin', [
            'level_from' => 0,
            'level_to'   => self::LEVEL_UP,
            'actor'      => 'admin',
            'actor_id'   => 99,
            'data'       => '{"ref":{"type":"user","id":8},"email":"' . self::OTHER_EMAIL . '"}',
        ]);
    }

    /** Caso del eliminador: contabilidad de pedido, una concesión manual y un usuario ajeno. */
    private function seedErasureCase(): void
    {
        $this->seedMember(self::USER);
        $this->seedToken(self::USER, str_repeat('a', 64));
        $this->seedToken(self::USER, str_repeat('b', 64), null, $this->at(-1));

        $this->seedGrant(10, self::USER, [
            'origin'             => 'order',
            'ref_type'           => 'order',
            'ref_id'             => '55',
            'note'               => 'Compra de Ana',
            'data'               => '{"buyer":"ana@example.test","order":55}',
            'actor_id'           => self::USER,
            'shipping_quota_used' => 2,
        ]);
        $this->seedGrant(11, self::USER, [
            'origin'     => 'admin',
            'ref_type'   => 'user',
            'ref_id'     => (string) self::OTHER,
            'note'       => 'Ajuste del equipo',
            'data'       => '{"reason":"soporte","user":8}',
            'actor_id'   => 99,
            'duration_days' => 0,
        ]);
        $this->seedGrant(12, self::USER, [
            'origin'        => 'order',
            'ref_type'      => 'order',
            'ref_id'        => '59',
            'status'        => 'ended',
            'starts_at'     => $this->at(-100),
            'ends_at'       => $this->at(-70),
            'note'          => 'Compra anterior',
            'data'          => '{"order":59}',
            'duration_days' => 30,
        ]);
        $this->seedGrant(13, self::USER, [
            'origin'    => 'order',
            'ref_type'  => 'order',
            'ref_id'    => '60',
            'status'    => 'scheduled',
            'starts_at' => $this->at(10),
            'ends_at'   => $this->at(40),
            'note'      => 'Compra futura',
            'data'      => '{"order":60}',
        ]);
        $this->seedEvent(100, self::USER, 10, 'activated', [
            'level_from' => 0,
            'level_to'   => self::LEVEL,
            'data'       => '{"key":"order:55:item:1"}',
            'actor_id'   => self::USER,
        ]);
        $this->seedEvent(101, self::USER, 11, 'granted_by_admin', [
            'level_from' => 0,
            'level_to'   => self::LEVEL,
            'actor'      => 'admin',
            'actor_id'   => 99,
            'data'       => '{"key":"admin:1"}',
        ]);
        $this->seedEvent(102, self::USER, null, 'frozen', [
            'level_from' => self::LEVEL,
            'level_to'   => 0,
            'data'       => '{"frozen_at":"2026-01-01 00:00:00"}',
            'actor_id'   => 99,
        ]);

        $this->seedMember(self::OTHER);
        $this->seedToken(self::OTHER, str_repeat('c', 64));
        $this->seedGrant(20, self::OTHER, [
            'origin' => 'admin',
            'note'   => 'Ajuste ajeno',
            'data'   => '{"x":1}',
        ]);
        $this->seedEvent(200, self::OTHER, 20, 'granted_by_admin', ['data' => '{"x":1}']);
    }

    /** Caso de `delete_user`: la cuenta eliminada y la cuenta de reasignación. */
    private function seedDeletedAccountCase(): void
    {
        $this->seedMember(self::DELETED, ['member_number' => '000077']);
        $this->seedToken(self::DELETED, str_repeat('d', 64));
        $this->seedGrant(30, self::DELETED, [
            'origin'              => 'order',
            'ref_type'            => 'order',
            'ref_id'              => '55',
            'note'                => 'Compra de la cuenta eliminada',
            'data'                => '{"buyer":"eliminada@example.test"}',
            'actor_id'            => self::DELETED,
            'shipping_quota_used' => 1,
        ]);
        $this->seedGrant(31, self::DELETED, [
            'origin'   => 'admin',
            'note'     => 'Ajuste manual',
            'data'     => '{"reason":"soporte"}',
            'actor_id' => 99,
        ]);
        $this->seedGrant(32, self::DELETED, [
            'origin'    => 'order',
            'ref_type'  => 'order',
            'ref_id'    => '56',
            'status'    => 'scheduled',
            'starts_at' => $this->at(10),
            'ends_at'   => $this->at(40),
            'note'      => 'Compra futura de la cuenta eliminada',
            'data'      => '{"order":56}',
        ]);
        $this->seedGrant(33, self::DELETED, [
            'origin'    => 'order',
            'ref_type'  => 'order',
            'ref_id'    => '57',
            'status'    => 'ended',
            'starts_at' => $this->at(-100),
            'ends_at'   => $this->at(-70),
            'note'      => 'Compra terminada de la cuenta eliminada',
            'data'      => '{"order":57}',
        ]);
        $this->seedEvent(300, self::DELETED, 30, 'renewed', [
            'level_from' => self::LEVEL,
            'level_to'   => self::LEVEL,
            'data'       => '{"key":"order:55:item:1"}',
            'actor_id'   => self::DELETED,
        ]);
        $this->seedEvent(301, self::DELETED, 31, 'granted_by_admin', [
            'data'     => '{"key":"admin:1"}',
            'actor_id' => 99,
        ]);
        $this->seedEvent(302, self::DELETED, null, 'unfrozen', [
            'data' => '{"frozen_seconds":3600}',
        ]);

        $this->seedMember(self::REASSIGN, ['member_number' => '000078']);
        $this->seedToken(self::REASSIGN, str_repeat('e', 64));
        $this->seedGrant(40, self::REASSIGN, [
            'origin' => 'order',
            'note'   => 'Compra de la otra cuenta',
            'data'   => '{"x":2}',
        ]);
        $this->seedEvent(400, self::REASSIGN, 40, 'activated', ['data' => '{"x":2}']);

        $this->wpdb->option_rows[\HWE_Memberships_Grants::MEMBER_SEQUENCE_OPTION] = '42';
    }

    /** @param array<string,mixed> $overrides */
    private function seedMember(int $userId, array $overrides = []): void
    {
        $this->wpdb->members[$userId] = array_merge([
            'user_id'        => $userId,
            'level_id'       => self::LEVEL,
            'covers_until'   => $this->at(25),
            'frozen_at'      => null,
            'frozen_seconds' => 0,
            'member_number'  => str_pad((string) (1000 + $userId), 6, '0', STR_PAD_LEFT),
            'member_since'   => $this->at(-30),
            'updated_at'     => $this->at(0),
        ], $overrides);
    }

    /** @param array<string,mixed> $overrides */
    private function seedGrant(int $id, int $userId, array $overrides = []): void
    {
        $this->wpdb->grants[$id] = array_merge([
            'id'                      => $id,
            'user_id'                 => $userId,
            'level_id'                => self::LEVEL,
            'origin'                  => 'order',
            'ref_type'                => 'order',
            'ref_id'                  => '55',
            'idempotency_key'         => 'seed:' . $id,
            'duration_days'           => 30,
            'queued_seconds'          => null,
            'starts_at'               => $this->at(-5),
            'ends_at'                 => $this->at(25),
            'status'                  => 'active',
            'actor'                   => 'system',
            'actor_id'                => null,
            'note'                    => null,
            'data'                    => null,
            'shipping_quota_used'     => 0,
            'shipping_quota_reserved' => 0,
            'referral_gifts'          => 0,
            'expiry_notified_at'      => null,
            'frozen_seconds'          => 0,
            'revoked_at'              => null,
            'created_at'              => $this->at(-5),
            'updated_at'              => $this->at(-5),
        ], $overrides);
    }

    /** @param array<string,mixed> $overrides */
    private function seedEvent(int $id, int $userId, ?int $grantId, string $type, array $overrides = []): void
    {
        $this->wpdb->events[$id] = array_merge([
            'id'         => $id,
            'user_id'    => $userId,
            'grant_id'   => $grantId,
            'type'       => $type,
            'level_from' => 0,
            'level_to'   => self::LEVEL,
            'actor'      => 'system',
            'actor_id'   => null,
            'data'       => null,
            'created_at' => $this->at(-5),
        ], $overrides);
    }

    private function seedToken(int $userId, string $hash, ?string $expires = null, ?string $revoked = null): void
    {
        $this->wpdb->tokens[] = [
            'user_id'    => $userId,
            'token_hash' => $hash,
            'expires_at' => $expires ?? $this->at(30),
            'revoked_at' => $revoked,
            'last_used_at' => null,
            'created_at' => $this->at(-1),
        ];
    }

    /** Fecha MySQL (UTC) relativa al reloj del test. */
    private function at(int $days): string
    {
        return gmdate('Y-m-d H:i:s', $this->now + $days * DAY_IN_SECONDS);
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

    private function countAnonymizedGrants(int $userId): int
    {
        $count = 0;
        foreach ($this->wpdb->grants as $grant) {
            if ((int) $grant['user_id'] === $userId
                && null === $grant['note']
                && null === $grant['data']
                && null === $grant['actor_id']) {
                $count++;
            }
        }
        return $count;
    }

    private function countAnonymizedEvents(int $userId): int
    {
        $count = 0;
        foreach ($this->wpdb->events as $event) {
            if ((int) $event['user_id'] === $userId
                && null === $event['data']
                && null === $event['actor_id']) {
                $count++;
            }
        }
        return $count;
    }
}

/**
 * Doble de `$wpdb` para privacidad: mantiene socios, concesiones, historial y tokens en
 * memoria. Entiende las sentencias de lectura de `HWE_Memberships_Members`,
 * `HWE_Memberships_Grants::forUser()` y las de `HWE_Memberships_Privacy`; las escrituras
 * simulan la transacción con una foto para comprobar que un `ROLLBACK` no deja cambios.
 */
final class TestMembershipsPrivacyWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public string $last_error = '';
    /** @var array<int,array<string,mixed>> */
    public array $members = [];
    /** @var array<int,array<string,mixed>> */
    public array $grants = [];
    /** @var array<int,array<string,mixed>> */
    public array $events = [];
    /** @var list<array<string,mixed>> */
    public array $tokens = [];
    /** @var array<string,string> */
    public array $option_rows = [];
    /** @var list<string> */
    public array $queries = [];
    /** Si una consulta contiene esta cadena, devuelve `false` (fallo simulado). */
    public string $failQueryNeedle = '';

    /** @var array<string,mixed>|null */
    private ?array $snapshot = null;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }
        return $sql . ' /* ' . json_encode(array_values($args)) . ' */';
    }

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        return 1;
    }

    public function query(string $sql): int|false
    {
        $this->queries[] = $sql;
        $args            = $this->args($sql);

        if ('START TRANSACTION' === $sql) {
            $this->snapshot = [
                'members'     => $this->members,
                'grants'      => $this->grants,
                'events'      => $this->events,
                'tokens'      => $this->tokens,
                'option_rows' => $this->option_rows,
            ];
            return 1;
        }

        if ('COMMIT' === $sql) {
            $this->snapshot = null;
            return 1;
        }

        if ('ROLLBACK' === $sql) {
            if (null !== $this->snapshot) {
                $this->members     = $this->snapshot['members'];
                $this->grants      = $this->snapshot['grants'];
                $this->events      = $this->snapshot['events'];
                $this->tokens      = $this->snapshot['tokens'];
                $this->option_rows = $this->snapshot['option_rows'];
            }
            $this->snapshot = null;
            return 1;
        }

        if ('' !== $this->failQueryNeedle && str_contains($sql, $this->failQueryNeedle)) {
            $this->last_error = 'fallo simulado';
            return false;
        }

        $grants  = $this->prefix . 'hwe_memberships_grants';
        $events  = $this->prefix . 'hwe_memberships_events';
        $tokens  = $this->prefix . 'hwe_memberships_card_tokens';
        $members = $this->prefix . 'hwe_memberships_members';

        if (str_starts_with($sql, 'UPDATE ' . $grants)) {
            $ids = $this->idsIn($sql);

            if (str_contains($sql, 'SET note = NULL, data = NULL')) {
                $userId  = (int) ($args[0] ?? 0);
                $changed = 0;
                foreach ($ids as $id) {
                    if (!isset($this->grants[$id]) || (int) $this->grants[$id]['user_id'] !== $userId) {
                        continue;
                    }
                    if (null === $this->grants[$id]['note'] && null === $this->grants[$id]['data']) {
                        continue;
                    }
                    $this->grants[$id]['note'] = null;
                    $this->grants[$id]['data'] = null;
                    $changed++;
                }
                return $changed;
            }

            if (str_contains($sql, 'SET actor_id = NULL')) {
                $own     = (int) ($args[0] ?? 0);
                $changed = 0;
                foreach ($ids as $id) {
                    if (!isset($this->grants[$id])) {
                        continue;
                    }
                    if (null === $this->grants[$id]['actor_id'] || (int) $this->grants[$id]['actor_id'] !== $own) {
                        continue;
                    }
                    $this->grants[$id]['actor_id'] = null;
                    $changed++;
                }
                return $changed;
            }

            if (str_contains($sql, "SET status = 'revoked', revoked_at = %s, ends_at = %s")) {
                $revokedAt = (string) ($args[0] ?? '');
                $endsAt    = (string) ($args[1] ?? '');
                $updatedAt = (string) ($args[2] ?? '');
                $changed   = 0;
                foreach ($ids as $id) {
                    if (!isset($this->grants[$id]) || 'active' !== (string) $this->grants[$id]['status']) {
                        continue;
                    }
                    $this->grants[$id]['status']         = 'revoked';
                    $this->grants[$id]['revoked_at']     = $revokedAt;
                    $this->grants[$id]['ends_at']        = $endsAt;
                    $this->grants[$id]['queued_seconds'] = null;
                    $this->grants[$id]['updated_at']     = $updatedAt;
                    $changed++;
                }
                return $changed;
            }

            if (str_contains($sql, "SET status = 'revoked', revoked_at = %s, updated_at = %s")) {
                $revokedAt = (string) ($args[0] ?? '');
                $updatedAt = (string) ($args[1] ?? '');
                $changed   = 0;
                foreach ($ids as $id) {
                    if (!isset($this->grants[$id]) || 'scheduled' !== (string) $this->grants[$id]['status']) {
                        continue;
                    }
                    $this->grants[$id]['status']     = 'revoked';
                    $this->grants[$id]['revoked_at'] = $revokedAt;
                    $this->grants[$id]['updated_at'] = $updatedAt;
                    $changed++;
                }
                return $changed;
            }

            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $events)) {
            $ids = $this->idsIn($sql);

            if (str_contains($sql, 'SET data = NULL')) {
                $changed = 0;
                foreach ($ids as $id) {
                    if (!isset($this->events[$id]) || null === $this->events[$id]['data']) {
                        continue;
                    }
                    $this->events[$id]['data'] = null;
                    $changed++;
                }
                return $changed;
            }

            if (str_contains($sql, 'SET actor_id = NULL')) {
                $own     = (int) ($args[0] ?? 0);
                $changed = 0;
                foreach ($ids as $id) {
                    if (!isset($this->events[$id])) {
                        continue;
                    }
                    if (null === $this->events[$id]['actor_id'] || (int) $this->events[$id]['actor_id'] !== $own) {
                        continue;
                    }
                    $this->events[$id]['actor_id'] = null;
                    $changed++;
                }
                return $changed;
            }

            return 1;
        }

        if (str_starts_with($sql, 'DELETE FROM ' . $tokens)) {
            $userId = (int) ($args[0] ?? 0);
            $before = count($this->tokens);
            $this->tokens = array_values(array_filter(
                $this->tokens,
                static fn (array $token): bool => (int) $token['user_id'] !== $userId
            ));
            return $before - count($this->tokens);
        }

        if (str_starts_with($sql, 'DELETE FROM ' . $members)) {
            $userId = (int) ($args[0] ?? 0);
            if (!isset($this->members[$userId])) {
                return 0;
            }
            unset($this->members[$userId]);
            return 1;
        }

        if (str_starts_with($sql, 'DELETE FROM ' . $events)) {
            $ids     = $this->idsIn($sql);
            $deleted = 0;
            foreach ($ids as $id) {
                if (isset($this->events[$id])) {
                    unset($this->events[$id]);
                    $deleted++;
                }
            }
            return $deleted;
        }

        if (str_starts_with($sql, 'DELETE FROM ' . $grants)) {
            $ids     = $this->idsIn($sql);
            $userId  = (int) ($args[0] ?? 0);
            $deleted = 0;
            foreach ($ids as $id) {
                if (isset($this->grants[$id]) && (int) $this->grants[$id]['user_id'] === $userId) {
                    unset($this->grants[$id]);
                    $deleted++;
                }
            }
            return $deleted;
        }

        return 1;
    }

    public function get_row(string $sql): ?object
    {
        $this->queries[] = $sql;
        $args            = $this->args($sql);

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_memberships_members')) {
            $userId = (int) ($args[0] ?? 0);
            return isset($this->members[$userId]) ? (object) $this->members[$userId] : null;
        }

        return null;
    }

    /** @return list<object> */
    public function get_results(string $sql): array
    {
        $this->queries[] = $sql;
        $args            = $this->args($sql);
        $grants          = $this->prefix . 'hwe_memberships_grants';
        $events          = $this->prefix . 'hwe_memberships_events';

        if (str_contains($sql, 'FROM ' . $events) && str_contains($sql, 'OFFSET')) {
            [$userId, $limit, $offset] = [(int) ($args[0] ?? 0), (int) ($args[1] ?? 0), (int) ($args[2] ?? 0)];
            return $this->objects(array_slice($this->rowsOf($this->events, $userId), $offset, $limit));
        }

        if (str_contains($sql, 'FROM ' . $grants) && str_contains($sql, 'OFFSET')) {
            [$userId, $limit, $offset] = [(int) ($args[0] ?? 0), (int) ($args[1] ?? 0), (int) ($args[2] ?? 0)];
            return $this->objects(array_slice($this->rowsOf($this->grants, $userId), $offset, $limit));
        }

        if (str_contains($sql, 'FROM ' . $grants) && str_contains($sql, 'SELECT *')
            && str_contains($sql, "status IN ('active','scheduled')")) {
            $userId = (int) ($args[0] ?? 0);
            $rows   = array_values(array_filter(
                $this->rowsOf($this->grants, $userId),
                static fn (array $row): bool => in_array((string) $row['status'], ['active', 'scheduled'], true)
            ));
            usort($rows, static function (array $left, array $right): int {
                $start = strcmp((string) ($left['starts_at'] ?? ''), (string) ($right['starts_at'] ?? ''));
                return 0 !== $start ? $start : (int) $left['id'] <=> (int) $right['id'];
            });
            return $this->objects($rows);
        }

        if (str_contains($sql, 'FROM ' . $events) && str_contains($sql, 'grant_id IN (SELECT')) {
            $userId = (int) ($args[0] ?? 0);
            $own    = (int) ($args[2] ?? 0);
            $limit  = (int) ($args[3] ?? 0);
            $rows   = array_values(array_filter(
                $this->rowsOf($this->events, $userId),
                fn (array $row): bool => in_array((int) ($row['grant_id'] ?? 0), $this->retainedGrantIds($userId), true)
                    && (null !== $row['data'] || (null !== $row['actor_id'] && (int) $row['actor_id'] === $own))
            ));
            return $this->objects(array_slice($rows, 0, $limit));
        }

        if (str_contains($sql, 'FROM ' . $events) && str_contains($sql, 'NOT IN (SELECT')) {
            $userId = (int) ($args[0] ?? 0);
            $limit  = (int) ($args[2] ?? 0);
            $rows   = array_values(array_filter(
                $this->rowsOf($this->events, $userId),
                fn (array $row): bool => null === $row['grant_id']
                    || !in_array((int) $row['grant_id'], $this->retainedGrantIds($userId), true)
            ));
            return $this->objects(array_slice($rows, 0, $limit));
        }

        if (str_contains($sql, 'FROM ' . $grants) && str_contains($sql, 'note IS NOT NULL')) {
            $userId = (int) ($args[0] ?? 0);
            $own    = (int) ($args[1] ?? 0);
            $limit  = (int) ($args[2] ?? 0);
            $rows   = array_values(array_filter(
                $this->rowsOf($this->grants, $userId),
                static fn (array $row): bool => 'order' === (string) $row['origin']
                    && (null !== $row['note'] || null !== $row['data'] || (int) ($row['actor_id'] ?? 0) === $own
                        || in_array((string) $row['status'], ['active', 'scheduled'], true))
            ));
            return $this->objects(array_slice($rows, 0, $limit));
        }

        if (str_contains($sql, 'FROM ' . $grants) && str_contains($sql, "origin <> 'order'")) {
            $userId = (int) ($args[0] ?? 0);
            $limit  = (int) ($args[1] ?? 0);
            $rows   = array_values(array_filter(
                $this->rowsOf($this->grants, $userId),
                static fn (array $row): bool => 'order' !== (string) $row['origin']
            ));
            return $this->objects(array_slice($rows, 0, $limit));
        }

        return [];
    }

    public function get_var(string $sql): string|int|null
    {
        $this->queries[] = $sql;
        $args            = $this->args($sql);
        $grants          = $this->prefix . 'hwe_memberships_grants';
        $events          = $this->prefix . 'hwe_memberships_events';

        // Los recuentos de eventos llevan una subconsulta sobre `grants`: se comprueban antes
        // para no confundirlos con un recuento de concesiones.
        if (str_contains($sql, 'FROM ' . $events)) {
            $userId = (int) ($args[0] ?? 0);

            if (str_contains($sql, 'grant_id IN (SELECT')) {
                $own   = (int) ($args[2] ?? 0);
                $count = 0;
                foreach ($this->rowsOf($this->events, $userId) as $row) {
                    if (in_array((int) ($row['grant_id'] ?? 0), $this->retainedGrantIds($userId), true)
                        && (null !== $row['data'] || (null !== $row['actor_id'] && (int) $row['actor_id'] === $own))) {
                        $count++;
                    }
                }
                return $count;
            }

            if (str_contains($sql, 'NOT IN (SELECT')) {
                return count(array_filter(
                    $this->rowsOf($this->events, $userId),
                    fn (array $row): bool => null === $row['grant_id']
                        || !in_array((int) $row['grant_id'], $this->retainedGrantIds($userId), true)
                ));
            }

            return count($this->rowsOf($this->events, $userId));
        }

        if (str_contains($sql, 'FROM ' . $grants)) {
            $userId = (int) ($args[0] ?? 0);

            if (str_contains($sql, 'note IS NOT NULL')) {
                $own   = (int) ($args[1] ?? 0);
                $count = 0;
                foreach ($this->rowsOf($this->grants, $userId) as $row) {
                    if ('order' === (string) $row['origin']
                        && (null !== $row['note'] || null !== $row['data'] || (int) ($row['actor_id'] ?? 0) === $own
                            || in_array((string) $row['status'], ['active', 'scheduled'], true))) {
                        $count++;
                    }
                }
                return $count;
            }

            if (str_contains($sql, "origin <> 'order'")) {
                return count(array_filter(
                    $this->rowsOf($this->grants, $userId),
                    static fn (array $row): bool => 'order' !== (string) $row['origin']
                ));
            }

            if (str_contains($sql, "origin = 'order'")) {
                return count(array_filter(
                    $this->rowsOf($this->grants, $userId),
                    static fn (array $row): bool => 'order' === (string) $row['origin']
                ));
            }

            return count($this->rowsOf($this->grants, $userId));
        }

        return null;
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        return false;
    }

    /** @return list<array<string,mixed>> */
    public function tokensOf(int $userId): array
    {
        return array_values(array_filter(
            $this->tokens,
            static fn (array $token): bool => (int) $token['user_id'] === $userId
        ));
    }

    /** @return list<array<string,mixed>> Filas de un usuario ordenadas por id. */
    private function rowsOf(array $rows, int $userId): array
    {
        $mine = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (int) $row['user_id'] === $userId
        ));
        usort($mine, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
        return $mine;
    }

    /** @return list<int> */
    private function retainedGrantIds(int $userId): array
    {
        $ids = [];
        foreach ($this->rowsOf($this->grants, $userId) as $row) {
            if ('order' === (string) $row['origin']) {
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
    }

    /** @param list<array<string,mixed>> $rows @return list<object> */
    private function objects(array $rows): array
    {
        return array_map(static fn (array $row): object => (object) $row, $rows);
    }

    /** @return list<int> */
    private function idsIn(string $sql): array
    {
        if (!preg_match('/ IN \(([0-9,\s]+)\)/i', $sql, $matches)) {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', array_map('trim', explode(',', $matches[1]))),
            static fn (int $id): bool => $id > 0
        ));
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
