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
 * Operaciones en masa del módulo `memberships` (spec §11.3): vista previa por caso, lotes con
 * Action Scheduler, modos de asignación, retirada y congelación.
 *
 * Se prueba la DECISIÓN del lote, no el SQL literal: que la vista previa cuente cada caso y
 * liste lo que no encontró, que `only_new`/`extend_same`/`replace` hagan exactamente lo que
 * dicen, que cada concesión use la clave `bulk:<lote>:<usuario>` (reanudar no duplica), que el
 * progreso viva en la tabla de lotes con un cursor y nunca en opciones, que sin Action Scheduler
 * no arranque nada y que la pantalla exija capacidad y nonce.
 *
 * El doble de `$wpdb` envuelve al de los servicios (`TestMembershipsServiceWpdb`) y añade la
 * tabla de lotes y las consultas de candidatos sobre `wp_users`/`wp_usermeta`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BulkTest extends TestCase
{
    private const ACTOR = 1;
    private const LEVEL_A = 201; // rango 5
    private const LEVEL_B = 202; // rango 10

    private int $now = 1_800_000_000;

    /** @var array<int,\WP_Post> */
    private array $posts = array();

    /** @var array<int,array<string,mixed>> */
    private array $meta = array();

    /** @var array<int,array{email:string,roles:list<string>}> */
    private array $accounts = array();

    /** @var list<int> Usuarios que `get_userdata` no encuentra (borrado a mitad de lote). */
    private array $blockedUsers = array();

    /** @var list<string> */
    private array $roles = array('customer', 'subscriber');

    /** @var list<string> */
    private array $caps = array('hwe_memberships_view', 'hwe_memberships_manage');

    /** @var list<string> */
    private array $logged = array();

    /** @var list<array{0:string,1:string,2:list<mixed>,3:string}> */
    private array $scheduled = array();

    /** @var list<string> */
    private array $died = array();

    /** @var list<string> */
    private array $redirects = array();

    /** @var array<string,mixed> */
    private array $transients = array();

    private bool $nonceValid = true;

    protected function setUp(): void
    {
        parent::setUp();

        $_POST = array();
        $_GET  = array();

        $this->now          = 1_800_000_000;
        $this->posts        = array();
        $this->meta         = array();
        $this->accounts     = array();
        $this->blockedUsers = array();
        $this->roles        = array('customer', 'subscriber');
        $this->caps         = array('hwe_memberships_view', 'hwe_memberships_manage');
        $this->logged       = array();
        $this->scheduled    = array();
        $this->died         = array();
        $this->redirects    = array();
        $this->transients   = array();
        $this->nonceValid   = true;

        $GLOBALS['wpdb'] = new TestMembershipsBulkWpdb();

        $this->loadOwnFile('plugins/hwe-memberships/includes/Schema.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Levels.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Timeline.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Events.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Members.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Grants.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Settings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Bulk.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Admin/Admin.php');
        \HWE_Memberships_Events::clear();

        $this->level(self::LEVEL_A, 5);
        $this->level(self::LEVEL_B, 10);

        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => 'active_plugins' === $name
                ? array('hwe-memberships/hwe-memberships.php')
                : $default
        );
        Functions\when('get_post')->alias(fn (int $id) => $this->posts[$id] ?? null);
        Functions\when('get_posts')->alias(function (array $args): array {
            $statuses = (array) ($args['post_status'] ?? array('publish'));
            $out      = array();
            foreach ($this->posts as $post) {
                if (in_array($post->post_status, $statuses, true)) {
                    $out[] = $post;
                }
            }
            return $out;
        });
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->meta[$id][$key] ?? ''
        );
        Functions\when('get_userdata')->alias(function (int $id) {
            if (in_array($id, $this->blockedUsers, true) || !isset($this->accounts[$id])) {
                return false;
            }
            return (object) array(
                'ID'           => $id,
                'user_email'   => $this->accounts[$id]['email'],
                'user_login'   => 'user-' . $id,
                'display_name' => 'Usuario ' . $id,
            );
        });
        Functions\when('get_user_by')->alias(function (string $field, mixed $value) {
            $needle = is_scalar($value) ? strtolower(trim((string) $value)) : '';
            foreach ($this->accounts as $id => $account) {
                $haystack = match ($field) {
                    'email' => strtolower($account['email']),
                    'login' => 'user-' . $id,
                    default => '',
                };
                if ('' !== $needle && $haystack === $needle) {
                    return (object) array(
                        'ID'           => (int) $id,
                        'user_email'   => $account['email'],
                        'user_login'   => 'user-' . $id,
                        'display_name' => 'Usuario ' . $id,
                    );
                }
            }
            return false;
        });
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed ...$args): mixed {
            return 'hwe_memberships_participant_roles' === $hook ? $this->roles : $value;
        });
        Functions\when('current_user_can')->alias(
            fn (string $cap, mixed ...$args): bool => in_array($cap, $this->caps, true)
        );
        Functions\when('get_current_user_id')->justReturn(self::ACTOR);
        Functions\when('wp_verify_nonce')->alias(fn (mixed $nonce, mixed $action): bool => $this->nonceValid);
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('wp_die')->alias(function (string $message): void {
            $this->died[] = $message;
        });
        Functions\when('wp_safe_redirect')->alias(function (string $url): void {
            $this->redirects[] = $url;
        });
        Functions\when('wp_nonce_field')->alias(function (string $action, string $name): void {
            echo '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="nonce">';
        });
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;
            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key) => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);
            return true;
        });
        Functions\when('is_wp_error')->alias(static fn (mixed $thing): bool => $thing instanceof \WP_Error);
        Functions\when('is_email')->alias(
            static fn (mixed $email): bool => is_string($email) && false !== filter_var($email, FILTER_VALIDATE_EMAIL)
        );
        Functions\stubs(array(
            '__'                     => static fn ($value): string => (string) $value,
            'esc_html__'             => static fn ($value): string => (string) $value,
            'esc_attr__'             => static fn ($value): string => (string) $value,
            'esc_html'               => static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
            'esc_attr'               => static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
            'esc_url'                => static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
            'sanitize_text_field'    => static fn ($value): string => trim(strip_tags((string) $value)),
            'sanitize_textarea_field' => static fn ($value): string => trim(strip_tags((string) $value)),
            'sanitize_key'           => static fn ($value): string => strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)),
            'sanitize_email'         => static fn ($value): string => trim((string) $value),
            'wp_unslash'             => static fn ($value) => $value,
            'absint'                 => static fn ($value): int => abs((int) $value),
            'admin_url'              => static fn (string $path = ''): string => 'https://example.test/wp-admin/' . ltrim($path, '/'),
            'add_query_arg'          => static function (mixed $args, string $url = ''): string {
                $args  = is_array($args) ? $args : array();
                $query = http_build_query(array_filter($args, static fn ($value): bool => '' !== $value && null !== $value));
                return '' === $query ? $url : $url . (str_contains($url, '?') ? '&' : '?') . $query;
            },
            'wp_date'                => static fn (string $format, int $timestamp): string => gmdate($format, $timestamp),
            'number_format_i18n'     => static fn ($value, int $decimals = 0): string => number_format((float) $value, $decimals),
        ));
    }

    protected function tearDown(): void
    {
        $_POST = array();
        $_GET  = array();
        parent::tearDown();
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    private function wpdb(): TestMembershipsBulkWpdb
    {
        return $GLOBALS['wpdb'];
    }

    private function service(): TestMembershipsServiceWpdb
    {
        return $this->wpdb()->service;
    }

    private function level(int $id, int $rank, string $status = 'publish'): void
    {
        $post               = new \WP_Post();
        $post->ID           = $id;
        $post->post_type    = \HWE_Memberships_Levels::POST_TYPE;
        $post->post_status  = $status;
        $post->post_title   = 'Nivel ' . $id;
        $post->post_name    = 'nivel-' . $id;
        $post->post_content = '';

        $this->posts[$id] = $post;
        $this->meta[$id]  = array(\HWE_Memberships_Levels::RANK_META => (string) $rank);
    }

    private function account(int $id, string $email, string $role = 'customer'): void
    {
        $this->accounts[$id] = array('email' => $email, 'roles' => array($role));
        $this->wpdb()->accounts = $this->accounts;
    }

    private function day(int $offset): string
    {
        return gmdate('Y-m-d H:i:s', $this->now + $offset * DAY_IN_SECONDS);
    }

    private function grant(
        int $userId,
        int $levelId = self::LEVEL_A,
        string $status = 'active',
        int $start = -1,
        ?int $end = 30,
        ?string $key = null
    ): int {
        return $this->service()->seedGrant(array(
            'user_id'         => $userId,
            'level_id'        => $levelId,
            'status'          => $status,
            'starts_at'       => $this->day($start),
            'ends_at'         => null === $end ? null : $this->day($end),
            'idempotency_key' => $key ?? ('seed:' . $userId . ':' . $levelId . ':' . $status . ':' . $start . ':' . ($end ?? 'x')),
        ));
    }

    private function freezeMember(int $userId): void
    {
        $this->service()->seedMember($userId, array(
            'frozen_at'     => $this->day(-2),
            'member_number' => 'M-' . $userId,
            'member_since'  => $this->day(-30),
        ));
    }

    private function withScheduler(): void
    {
        Functions\when('as_enqueue_async_action')->alias(
            function (string $hook, array $args = array(), string $group = ''): int {
                $this->scheduled[] = array('enqueue', $hook, array_values($args), $group);
                return 70 + count($this->scheduled);
            }
        );
        Functions\when('as_schedule_single_action')->alias(
            function (int $timestamp, string $hook, array $args = array(), string $group = ''): int {
                $this->scheduled[] = array('single', $hook, array_values($args), $group);
                return 90 + count($this->scheduled);
            }
        );
    }

    /** @param array<string,mixed> $selection @param array<string,mixed> $operation */
    private function startBatch(array $selection, array $operation): int
    {
        $result = \HWE_Memberships_Bulk::start($selection, $operation, self::ACTOR);
        $this->assertNotInstanceOf(\WP_Error::class, $result, 'El lote no debería fallar al arrancar.');

        return (int) $result;
    }

    /** @return array<string,mixed> */
    private function runToEnd(int $batchId, int $pageSize = 100, int $guard = 50): array
    {
        $last = array();
        for ($i = 0; $i < $guard; $i++) {
            $last = \HWE_Memberships_Bulk::process((string) $batchId, $pageSize);
            $status = (string) ($last['status'] ?? '');
            if (in_array($status, array('done', 'failed'), true)) {
                return $last;
            }
        }
        $this->fail('El lote no terminó dentro del límite del test.');
        return $last;
    }

    /** @return array<string,mixed> */
    private function batchRow(int $batchId): array
    {
        return $this->wpdb()->batches[$batchId] ?? array();
    }

    /** @return list<int> */
    private function liveGrantsOf(int $userId): array
    {
        $ids = array();
        foreach ($this->service()->grantsOf($userId) as $row) {
            if (in_array((string) ($row['status'] ?? ''), array('active', 'scheduled'), true)) {
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
    }

    /** @return list<array<string,mixed>> */
    private function eventsOf(int $userId): array
    {
        $out = array();
        foreach ($this->service()->event_rows as $row) {
            if ((int) ($row['user_id'] ?? 0) !== $userId) {
                continue;
            }
            $decoded = isset($row['data']) && is_string($row['data']) ? json_decode($row['data'], true) : array();
            $row['decoded'] = is_array($decoded) ? $decoded : array();
            $out[] = $row;
        }
        usort($out, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return $out;
    }

    /** @return list<string> */
    private function eventTypesOf(int $userId): array
    {
        return array_values(array_map(static fn (array $row): string => (string) $row['type'], $this->eventsOf($userId)));
    }

    /** @return list<string> */
    private function flashedCodes(): array
    {
        $codes = array();
        foreach ($this->transients as $value) {
            if (is_array($value) && isset($value['code'])) {
                $codes[] = (string) $value['code'];
            }
        }
        return $codes;
    }

    // ── Vista previa ────────────────────────────────────────────────────────

    public function testPreviewOfParticipantsCountsOnlyNewAndExistingMembership(): void
    {
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $this->account(3, 'tres@example.test');
        $this->grant(1, self::LEVEL_A);

        $preview = \HWE_Memberships_Bulk::preview(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 30, 'mode' => 'only_new')
        );

        $this->assertSame(3, $preview['total']);
        $this->assertSame(2, $preview['affected']);
        $this->assertSame(1, $preview['skipped']);
        $this->assertSame(2, $preview['counts']['grant']);
        $this->assertSame(1, $preview['counts']['skip_has_membership']);
        $this->assertSame(array(), $preview['missing']);
    }

    public function testPreviewOfExtendSameSeparatesFrozenOtherLevelAndNoMembership(): void
    {
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $this->account(3, 'tres@example.test');
        $this->account(4, 'cuatro@example.test');
        $this->grant(1, self::LEVEL_A);
        $this->grant(2, self::LEVEL_B);
        $this->grant(4, self::LEVEL_A);
        $this->freezeMember(4);

        $preview = \HWE_Memberships_Bulk::preview(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 0, 'mode' => 'extend_same')
        );

        $this->assertSame(4, $preview['total']);
        $this->assertSame(1, $preview['counts']['grant']);
        $this->assertSame(1, $preview['counts']['skip_frozen']);
        $this->assertSame(1, $preview['counts']['skip_other_level']);
        $this->assertSame(1, $preview['counts']['skip_no_membership']);
        $this->assertSame(1, $preview['affected']);
    }

    public function testPreviewOfReplaceCountsReplacementsAndNewGrants(): void
    {
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $this->grant(1, self::LEVEL_A);

        $preview = \HWE_Memberships_Bulk::preview(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 90, 'mode' => 'replace')
        );

        $this->assertSame(2, $preview['affected']);
        $this->assertSame(1, $preview['counts']['replace']);
        $this->assertSame(1, $preview['counts']['grant']);
    }

    public function testPreviewOfRemoveCountsOnlyLiveMemberships(): void
    {
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $this->account(3, 'tres@example.test');
        $this->account(4, 'cuatro@example.test');

        $this->grant(1, self::LEVEL_A);                                  // vigente
        $this->grant(2, self::LEVEL_A, 'scheduled', 5, 65);               // en cola
        $this->grant(4, self::LEVEL_A, 'active', -60, -1);                // vencida por fechas

        $preview = \HWE_Memberships_Bulk::preview(
            array('type' => 'participants'),
            array('type' => 'remove')
        );

        $this->assertSame(4, $preview['total']);
        $this->assertSame(2, $preview['counts']['remove']);
        $this->assertSame(2, $preview['counts']['skip_no_membership']);
        $this->assertSame(2, $preview['affected']);
    }

    public function testPreviewOfFreezeAndUnfreezeCountsEachCase(): void
    {
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $this->account(3, 'tres@example.test');
        $this->grant(1, self::LEVEL_A);
        $this->grant(2, self::LEVEL_A);
        $this->freezeMember(2);

        $freeze = \HWE_Memberships_Bulk::preview(
            array('type' => 'participants'),
            array('type' => 'freeze')
        );
        $this->assertSame(1, $freeze['counts']['freeze']);
        $this->assertSame(1, $freeze['counts']['skip_frozen']);
        $this->assertSame(1, $freeze['counts']['skip_no_membership']);

        $unfreeze = \HWE_Memberships_Bulk::preview(
            array('type' => 'participants'),
            array('type' => 'unfreeze')
        );
        $this->assertSame(1, $unfreeze['counts']['unfreeze']);
        $this->assertSame(2, $unfreeze['counts']['skip_not_frozen']);
    }

    public function testPreviewOfALevelSelectionIncludesQueuedMemberships(): void
    {
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $this->account(3, 'tres@example.test');
        $this->grant(1, self::LEVEL_A);
        $this->grant(2, self::LEVEL_A, 'scheduled', 5, 65);
        $this->grant(3, self::LEVEL_B);

        $preview = \HWE_Memberships_Bulk::preview(
            array('type' => 'level', 'level_id' => self::LEVEL_A),
            array('type' => 'remove')
        );

        $this->assertSame(2, $preview['total']);
        $this->assertSame(2, $preview['counts']['remove']);
    }

    public function testPreviewOfAPastedListResolvesEmailsAndIdsAndReportsMissingEntries(): void
    {
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $this->account(3, 'tres@example.test');

        $preview = \HWE_Memberships_Bulk::preview(
            array('type' => 'list', 'list' => "1\nDOS@example.test\n999\nnadie@example.test\n"),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 30, 'mode' => 'only_new')
        );

        $this->assertSame(2, $preview['total']);
        $this->assertSame(2, $preview['affected']);
        $this->assertSame(array('999', 'nadie@example.test'), $preview['missing']);
        $this->assertSame(2, $preview['missing_count']);
    }

    public function testPreviewRejectsAPastedListOverTheLimit(): void
    {
        $entries = array();
        for ($i = 0; $i < \HWE_Memberships_Bulk::LIST_MAX + 1; $i++) {
            $entries[] = 'persona' . $i . '@example.test';
        }

        $preview = \HWE_Memberships_Bulk::preview(
            array('type' => 'list', 'list' => implode("\n", $entries)),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 30, 'mode' => 'only_new')
        );

        $this->assertSame('list_too_long', $preview['error']);
        $this->assertSame(0, $preview['affected']);
    }

    public function testPreviewRejectsAGrantWithoutAnExistingLevel(): void
    {
        $this->account(1, 'uno@example.test');

        $preview = \HWE_Memberships_Bulk::preview(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => 9999, 'days' => 30, 'mode' => 'only_new')
        );

        $this->assertSame('invalid_level', $preview['error']);
    }

    public function testPreviewRejectsANegativeDuration(): void
    {
        $this->account(1, 'uno@example.test');

        $preview = \HWE_Memberships_Bulk::preview(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => -5, 'mode' => 'only_new')
        );

        $this->assertSame('invalid_days', $preview['error']);
    }

    public function testPreviewRejectsAnEmptyPastedList(): void
    {
        $preview = \HWE_Memberships_Bulk::preview(
            array('type' => 'list', 'list' => " \n , ; "),
            array('type' => 'remove')
        );

        $this->assertSame('empty_list', $preview['error']);
    }

    // ── Operaciones por modo ────────────────────────────────────────────────

    public function testOnlyNewGrantsJustTheUsersWithoutMembership(): void
    {
        $this->withScheduler();
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $this->account(3, 'tres@example.test');
        $this->grant(1, self::LEVEL_A);

        $batchId = $this->startBatch(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 30, 'mode' => 'only_new')
        );
        $progress = $this->runToEnd($batchId);

        $this->assertSame('done', $progress['status']);
        $this->assertSame(3, $progress['total']);
        $this->assertSame(3, $progress['processed']);
        $this->assertSame(2, $progress['applied']);

        $this->assertCount(1, $this->liveGrantsOf(1), 'Quien ya tenía membresía no recibe otra.');

        foreach (array(2, 3) as $userId) {
            $key = 'bulk:' . $batchId . ':' . $userId;
            $row = $this->service()->grantByKey($key);

            $this->assertNotNull($row, 'Falta la concesión del usuario ' . $userId . '.');
            $this->assertSame(self::LEVEL_B, (int) $row['level_id']);
            $this->assertSame('bulk', $row['origin']);
            $this->assertSame('bulk', $row['ref_type']);
            $this->assertSame((string) $batchId, (string) $row['ref_id']);
            $this->assertSame('admin', $row['actor']);
            $this->assertSame(self::ACTOR, (int) $row['actor_id']);
            $this->assertSame(30, (int) $row['duration_days']);

            $events = $this->eventsOf($userId);
            $this->assertNotEmpty($events);
            $this->assertSame('activated', $events[0]['type']);
            $this->assertSame($batchId, (int) ($events[0]['decoded']['batch'] ?? 0));
            $this->assertSame('bulk', $events[0]['decoded']['origin'] ?? '');
        }

        $this->assertSame(array(), $this->eventsOf(1), 'El socio que se omite no deja historial.');
    }

    public function testExtendSameAddsTimeOnlyToUsersWithThatLevel(): void
    {
        $this->withScheduler();
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $this->account(3, 'tres@example.test');
        $this->grant(1, self::LEVEL_A, 'active', -1, 30, 'seed:uno');
        $this->grant(2, self::LEVEL_B, 'active', -1, 30, 'seed:dos');

        $batchId = $this->startBatch(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 45, 'mode' => 'extend_same')
        );
        $this->runToEnd($batchId);

        $this->assertNull($this->service()->grantByKey('bulk:' . $batchId . ':1'));
        $this->assertNull($this->service()->grantByKey('bulk:' . $batchId . ':3'));

        $row = $this->service()->grantByKey('bulk:' . $batchId . ':2');
        $this->assertNotNull($row);
        $this->assertSame(self::LEVEL_B, (int) $row['level_id']);
        $this->assertSame(45, (int) $row['duration_days']);
        $this->assertSame('bulk', $row['origin']);
        $this->assertCount(2, $this->liveGrantsOf(2));
    }

    public function testReplaceRemovesThenGrantsInTheSameBatch(): void
    {
        $this->withScheduler();
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $oldGrant = $this->grant(1, self::LEVEL_A, 'active', -1, 30, 'seed:uno');

        $batchId = $this->startBatch(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 60, 'mode' => 'replace')
        );
        $this->runToEnd($batchId);

        $this->assertSame('ended', $this->service()->grants[$oldGrant]['status']);
        $this->assertSame(array(), array_filter(
            $this->liveGrantsOf(1),
            static fn (int $id): bool => $id === $oldGrant
        ));

        $row = $this->service()->grantByKey('bulk:' . $batchId . ':1');
        $this->assertNotNull($row);
        $this->assertSame(self::LEVEL_B, (int) $row['level_id']);

        $types = $this->eventTypesOf(1);
        $this->assertSame(array('removed_by_admin', 'activated'), $types);

        $events = $this->eventsOf(1);
        $this->assertSame($batchId, (int) ($events[0]['decoded']['batch'] ?? 0));
        $this->assertSame('replace', $events[0]['decoded']['operation'] ?? '');
        $this->assertSame($batchId, (int) ($events[1]['decoded']['batch'] ?? 0));

        $this->assertNotNull($this->service()->grantByKey('bulk:' . $batchId . ':2'));
    }

    public function testRemoveRetiresEveryLiveMembershipAndWritesHistoryWithBatchAndAdmin(): void
    {
        $this->withScheduler();
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $this->account(3, 'tres@example.test');
        $this->grant(1, self::LEVEL_A);
        $this->grant(2, self::LEVEL_A, 'scheduled', 5, 65);

        $batchId = $this->startBatch(array('type' => 'participants'), array('type' => 'remove'));
        $progress = $this->runToEnd($batchId);

        $this->assertSame(2, $progress['applied']);
        $this->assertSame(array(), $this->liveGrantsOf(1));
        $this->assertSame(array(), $this->liveGrantsOf(2));

        foreach (array(1, 2) as $userId) {
            $events = $this->eventsOf($userId);
            $this->assertCount(1, $events);
            $this->assertSame('removed_by_admin', $events[0]['type']);
            $this->assertSame('admin', $events[0]['actor']);
            $this->assertSame(self::ACTOR, (int) $events[0]['actor_id']);
            $this->assertSame($batchId, (int) ($events[0]['decoded']['batch'] ?? 0));
            $this->assertSame('remove', $events[0]['decoded']['operation'] ?? '');
        }

        $this->assertSame(array(), $this->eventsOf(3));
    }

    public function testFreezeAndUnfreezeOperateOnlyWhereTheyApply(): void
    {
        $this->withScheduler();
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $this->account(3, 'tres@example.test');
        $this->grant(1, self::LEVEL_A);
        $this->grant(2, self::LEVEL_A);
        $this->freezeMember(2);

        $freezeId = $this->startBatch(array('type' => 'participants'), array('type' => 'freeze'));
        $freeze   = $this->runToEnd($freezeId);

        $this->assertSame(1, $freeze['applied']);
        $this->assertTrue(\HWE_Memberships_Members::isFrozen(1));
        $freezeEvents = $this->eventsOf(1);
        $this->assertSame('frozen', end($freezeEvents)['type']);
        $this->assertSame('admin', end($freezeEvents)['actor']);
        $this->assertSame(self::ACTOR, (int) end($freezeEvents)['actor_id']);
        $this->assertSame($freezeId, (int) (end($freezeEvents)['decoded']['batch'] ?? 0));
        $this->assertSame(array(), $this->eventsOf(3));

        $unfreezeId = $this->startBatch(array('type' => 'participants'), array('type' => 'unfreeze'));
        $unfreeze   = $this->runToEnd($unfreezeId);

        $this->assertSame(2, $unfreeze['applied']);
        $this->assertFalse(\HWE_Memberships_Members::isFrozen(1));
        $this->assertFalse(\HWE_Memberships_Members::isFrozen(2));
        $this->assertSame(array(), $this->eventsOf(3));

        $unfrozenEvents = $this->eventsOf(1);
        $this->assertSame('unfrozen', end($unfrozenEvents)['type']);
        $this->assertSame('admin', end($unfrozenEvents)['actor']);
        $this->assertSame(self::ACTOR, (int) end($unfrozenEvents)['actor_id']);
        $this->assertSame($unfreezeId, (int) (end($unfrozenEvents)['decoded']['batch'] ?? 0));
    }

    public function testAListBatchTouchesOnlyTheResolvedUsers(): void
    {
        $this->withScheduler();
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $this->account(3, 'tres@example.test');

        $batchId = $this->startBatch(
            array('type' => 'list', 'list' => "1, dos@example.test\n999"),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 30, 'mode' => 'only_new')
        );
        $progress = $this->runToEnd($batchId);

        $this->assertSame(2, $progress['total']);
        $this->assertNotNull($this->service()->grantByKey('bulk:' . $batchId . ':1'));
        $this->assertNotNull($this->service()->grantByKey('bulk:' . $batchId . ':2'));
        $this->assertNull($this->service()->grantByKey('bulk:' . $batchId . ':3'));
        $this->assertSame(array(), $this->eventsOf(3));
    }

    // ── Lotes, reanudación y progreso ───────────────────────────────────────

    public function testStartStoresTheSelectionAndEnqueuesTheFirstActionInItsGroup(): void
    {
        $this->withScheduler();
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');

        $batchId = $this->startBatch(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 30, 'mode' => 'only_new')
        );

        $row = $this->batchRow($batchId);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('grant', $row['operation']);
        $this->assertSame(2, (int) $row['total']);
        $this->assertSame(0, (int) $row['processed']);
        $this->assertSame(0, (int) $row['last_user_id']);
        $this->assertSame(self::ACTOR, (int) $row['actor_id']);

        $selection = json_decode((string) $row['selection'], true);
        $this->assertSame('participants', $selection['type']);
        $this->assertSame(array('customer', 'subscriber'), $selection['roles']);

        $params = json_decode((string) $row['params'], true);
        $this->assertSame('grant', $params['operation']['type']);
        $this->assertSame(self::LEVEL_B, $params['operation']['level_id']);
        $this->assertSame(30, $params['operation']['days']);
        $this->assertSame('only_new', $params['operation']['mode']);

        $this->assertSame(
            array(array('enqueue', \HWE_Memberships_Bulk::HOOK, array((string) $batchId), \HWE_Memberships_Bulk::GROUP)),
            $this->scheduled
        );
    }

    public function testWithoutActionSchedulerNoBatchIsCreatedAndTheReasonIsReported(): void
    {
        $this->account(1, 'uno@example.test');

        $result = \HWE_Memberships_Bulk::start(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 30, 'mode' => 'only_new'),
            self::ACTOR
        );

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('hwe_memberships_as_unavailable', $result->get_error_code());
        $this->assertSame(array(), $this->wpdb()->batches);
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
    }

    public function testProgressReportsTotalsAppliedAndStatus(): void
    {
        $this->withScheduler();
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $this->account(3, 'tres@example.test');

        $batchId = $this->startBatch(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 30, 'mode' => 'only_new')
        );

        $partial = \HWE_Memberships_Bulk::process((string) $batchId, 2);
        $this->assertSame('running', $partial['status']);
        $this->assertSame(3, $partial['total']);
        $this->assertSame(2, $partial['processed']);
        $this->assertSame(2, $partial['applied']);
        $this->assertSame(66, $partial['percent']);

        $done = $this->runToEnd($batchId, 2);
        $this->assertSame('done', $done['status']);
        $this->assertSame(3, $done['processed']);
        $this->assertSame(100, $done['percent']);
        $this->assertNotNull($done['finished_at']);
    }

    public function testProcessSchedulesTheNextPageOnlyWhileWorkRemains(): void
    {
        $this->withScheduler();
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');

        $batchId = $this->startBatch(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 30, 'mode' => 'only_new')
        );

        \HWE_Memberships_Bulk::process((string) $batchId, 1);
        $singles = array_values(array_filter(
            $this->scheduled,
            static fn (array $entry): bool => 'single' === $entry[0]
        ));
        $this->assertCount(1, $singles);
        $this->assertSame(array((string) $batchId), $singles[0][2]);
        $this->assertSame(\HWE_Memberships_Bulk::GROUP, $singles[0][3]);

        $done = \HWE_Memberships_Bulk::process((string) $batchId, 1);
        $this->assertSame('done', $done['status']);
        $singles = array_values(array_filter(
            $this->scheduled,
            static fn (array $entry): bool => 'single' === $entry[0]
        ));
        $this->assertCount(1, $singles, 'Al terminar no se programa otra pasada.');
    }

    public function testReprocessingTheSamePageDoesNotDuplicateGrantsOrHistory(): void
    {
        $this->withScheduler();
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $this->account(3, 'tres@example.test');

        $batchId = $this->startBatch(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 30, 'mode' => 'only_new')
        );
        $this->runToEnd($batchId);

        $grants = count($this->service()->grants);
        $events = count($this->service()->event_rows);

        // Se rebobina el cursor como si la primera pasada se hubiera cortado a mitad.
        $this->wpdb()->forceBatch($batchId, array('status' => 'pending', 'last_user_id' => 0, 'processed' => 0));
        $again = $this->runToEnd($batchId);

        $this->assertSame('done', $again['status']);
        $this->assertSame(0, $again['run']['applied'], 'Reprocesar no vuelve a aplicar nada.');
        $this->assertCount($grants, $this->service()->grants);
        $this->assertCount($events, $this->service()->event_rows);
        $this->assertCount(3, $this->service()->grants);
        $this->assertCount(3, $this->service()->event_rows);
    }

    public function testReprocessingAReplacePageKeepsTheGrantedLevel(): void
    {
        $this->withScheduler();
        $this->account(1, 'uno@example.test');
        $this->grant(1, self::LEVEL_A);

        $batchId = $this->startBatch(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 30, 'mode' => 'replace')
        );
        $this->runToEnd($batchId);

        $this->wpdb()->forceBatch($batchId, array('status' => 'pending', 'last_user_id' => 0, 'processed' => 0));
        $again = $this->runToEnd($batchId);

        $this->assertSame(0, $again['run']['applied']);
        $live = $this->liveGrantsOf(1);
        $this->assertCount(1, $live, 'El reintento no debe quitar la concesión que el mismo lote hizo.');
        $row = $this->service()->grants[$live[0]];
        $this->assertSame(self::LEVEL_B, (int) $row['level_id']);
        $this->assertSame(array('removed_by_admin', 'activated'), $this->eventTypesOf(1));
    }

    public function testAFailedUserDoesNotStopTheRestOfTheBatch(): void
    {
        $this->withScheduler();
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $this->account(3, 'tres@example.test');
        $this->blockedUsers = array(2);

        $batchId = $this->startBatch(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 30, 'mode' => 'only_new')
        );
        $progress = $this->runToEnd($batchId);

        $this->assertSame('done', $progress['status']);
        $this->assertSame(3, $progress['processed']);
        $this->assertSame(2, $progress['applied']);
        $this->assertNull($this->service()->grantByKey('bulk:' . $batchId . ':2'));
        $this->assertNotNull($this->service()->grantByKey('bulk:' . $batchId . ':1'));
        $this->assertNotNull($this->service()->grantByKey('bulk:' . $batchId . ':3'));
    }

    public function testBatchesKeepTheirProgressInTheirOwnTableAndNotInOptions(): void
    {
        $this->withScheduler();
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');

        $batchId = $this->startBatch(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 30, 'mode' => 'only_new')
        );
        $this->runToEnd($batchId);

        $insert = $this->wpdb()->batchInserts[0][1];
        $this->assertArrayNotHasKey('user_ids', $insert);
        $this->assertArrayHasKey('last_user_id', $insert);
        $this->assertArrayHasKey('selection', $insert);
        $this->assertArrayHasKey('processed', $insert);

        foreach ($this->service()->option_rows as $name => $value) {
            $this->assertStringNotContainsString('bulk:', (string) $name);
            $this->assertStringNotContainsString('"1","2"', (string) $value);
        }
    }

    public function testResumeRequeuesAFailedBatchFromItsCursor(): void
    {
        $this->withScheduler();
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');

        $batchId = $this->startBatch(
            array('type' => 'participants'),
            array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 30, 'mode' => 'only_new')
        );
        \HWE_Memberships_Bulk::process((string) $batchId, 1);
        $this->wpdb()->forceBatch($batchId, array('status' => 'failed'));

        $this->scheduled = array();
        $this->assertTrue(\HWE_Memberships_Bulk::resume((string) $batchId));
        $this->assertSame('pending', $this->batchRow($batchId)['status']);
        $this->assertSame(
            array(array('enqueue', \HWE_Memberships_Bulk::HOOK, array((string) $batchId), \HWE_Memberships_Bulk::GROUP)),
            $this->scheduled
        );

        $done = $this->runToEnd($batchId);
        $this->assertSame('done', $done['status']);
        $this->assertSame(2, $done['processed']);
        $this->assertCount(1, $this->liveGrantsOf(1), 'La primera página no se vuelve a conceder.');
    }

    public function testProgressOfAnUnknownBatchReportsNotFound(): void
    {
        $this->assertSame('not_found', \HWE_Memberships_Bulk::progress('999')['error']);
    }

    // ── Pantalla de administración ──────────────────────────────────────────

    public function testTheBulkScreenRegistersItsAdminPostActions(): void
    {
        Actions\expectAdded('admin_post_' . \HWE_Memberships_Admin_Bulk_Screen::ACTION_START)
            ->once();
        Actions\expectAdded('admin_post_' . \HWE_Memberships_Admin_Bulk_Screen::ACTION_RESUME)
            ->once();

        \HWE_Memberships_Admin_Bulk_Screen::register();
    }

    public function testStartingABatchFromTheScreenRequiresTheManageCapability(): void
    {
        $this->caps = array('hwe_memberships_view');
        $_POST      = $this->startPost();

        \HWE_Memberships_Admin_Bulk_Screen::handleStart();

        $this->assertNotEmpty($this->died);
        $this->assertStringContainsString('No autorizado', $this->died[0]);
        $this->assertSame(array(), $this->wpdb()->batches);
    }

    public function testStartingABatchFromTheScreenRejectsAnInvalidNonce(): void
    {
        $this->nonceValid = false;
        $_POST            = $this->startPost();

        \HWE_Memberships_Admin_Bulk_Screen::handleStart();

        $this->assertNotEmpty($this->died);
        $this->assertStringContainsString('Nonce no válido', $this->died[0]);
        $this->assertSame(array(), $this->wpdb()->batches);
    }

    public function testStartingABatchFromTheScreenFlashesTheBatchId(): void
    {
        $this->withScheduler();
        $this->account(1, 'uno@example.test');
        $this->account(2, 'dos@example.test');
        $_POST = $this->startPost();

        \HWE_Memberships_Admin_Bulk_Screen::handleStart();

        $this->assertSame(array(), $this->died);
        $this->assertCount(1, $this->wpdb()->batches);
        $batchId = (int) array_key_first($this->wpdb()->batches);

        $this->assertSame(array('bulk_started'), $this->flashedCodes());
        $flash = array_values($this->transients)[0];
        $this->assertSame($batchId, (int) $flash['batch']);
        $this->assertNotEmpty($this->redirects);
        $this->assertStringContainsString('batch=' . $batchId, $this->redirects[0]);
    }

    public function testTheScreenExplainsThatWithoutActionSchedulerNothingStarts(): void
    {
        $this->account(1, 'uno@example.test');
        $_POST = $this->startPost();

        \HWE_Memberships_Admin_Bulk_Screen::handleStart();

        $this->assertSame(array(), $this->died);
        $this->assertSame(array('bulk_no_scheduler'), $this->flashedCodes());
        $this->assertSame(array(), $this->wpdb()->batches);
    }

    public function testResumingABatchFromTheScreenRejectsAnInvalidNonce(): void
    {
        $this->nonceValid = false;
        $_POST = array(
            'batch' => '5',
            \HWE_Memberships_Admin_Bulk_Screen::NONCE_FIELD => 'nonce',
        );

        \HWE_Memberships_Admin_Bulk_Screen::handleResume();

        $this->assertNotEmpty($this->died);
        $this->assertStringContainsString('Nonce no válido', $this->died[0]);
        $this->assertSame(array(), $this->wpdb()->batchUpdates);
    }

    public function testThePreviewRendersEachCaseAndEscapesTheMissingEntries(): void
    {
        $this->account(1, 'uno@example.test');

        $request = array(
            'selection' => array('type' => 'list', 'level_id' => 0, 'list' => "1\nnadie@example.test"),
            'operation' => array('type' => 'grant', 'level_id' => self::LEVEL_B, 'days' => 30, 'mode' => 'only_new'),
        );
        $preview = \HWE_Memberships_Bulk::preview($request['selection'], $request['operation']);

        // La lista se sanea al resolverla; se fuerza un token con etiquetas para comprobar que
        // el render las escapa igualmente.
        $preview['missing']       = array('<script>alert(1)</script>');
        $preview['missing_count'] = 1;

        ob_start();
        \HWE_Memberships_Admin_Bulk_Screen::renderPreview($preview, $request);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Se concederá', $html);
        $this->assertStringContainsString('No encontrados', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    /** @return array<string,string> */
    private function startPost(): array
    {
        return array(
            'hwe_bulk_selection'       => 'participants',
            'hwe_bulk_selection_level' => '0',
            'hwe_bulk_list'            => '',
            'hwe_bulk_operation'       => 'grant',
            'hwe_bulk_level'           => (string) self::LEVEL_B,
            'hwe_bulk_days'            => '30',
            'hwe_bulk_mode'            => 'only_new',
            \HWE_Memberships_Admin_Bulk_Screen::NONCE_FIELD => 'nonce',
        );
    }
}

/**
 * Doble de `$wpdb` para los lotes: envuelve al de los servicios y añade la tabla
 * `hwe_memberships_batches` y la lectura de candidatos sobre `wp_users`/`wp_usermeta`.
 *
 * Solo interpreta las consultas que emite `HWE_Memberships_Bulk`; el resto se delega en el
 * doble de los servicios (concesiones, socios, eventos y tokens).
 */
final class TestMembershipsBulkWpdb
{
    public string $prefix = 'wp_';
    public string $users = 'wp_users';
    public string $usermeta = 'wp_usermeta';
    public string $options = 'wp_options';
    public TestMembershipsServiceWpdb $service;

    public int $insert_id = 0;

    /** @var array<int,array<string,mixed>> */
    public array $batches = array();

    /** @var array<int,array{email:string,roles:list<string>}> */
    public array $accounts = array();

    /** @var list<array{0:string,1:array<string,mixed>}> */
    public array $batchInserts = array();

    /** @var list<array{0:int,1:array<string,mixed>}> */
    public array $batchUpdates = array();

    public bool $failBatchInsert = false;

    private int $nextBatchId = 1;

    public function __construct()
    {
        $this->service = new TestMembershipsServiceWpdb();
    }

    public function __get(string $name): mixed
    {
        return $this->service->{$name};
    }

    public function get_charset_collate(): string
    {
        return $this->service->get_charset_collate();
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        return $this->service->prepare($sql, ...$args);
    }

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        if (str_ends_with($table, 'hwe_memberships_batches')) {
            if ($this->failBatchInsert) {
                return false;
            }
            $id              = $this->nextBatchId++;
            $this->insert_id = $id;
            $this->batches[$id] = array_merge(
                array(
                    'id'          => $id,
                    'processed'   => 0,
                    'applied'     => 0,
                    'last_user_id' => 0,
                    'status'      => 'pending',
                    'finished_at' => null,
                ),
                $data,
                array('id' => $id)
            );
            $this->batchInserts[] = array($table, $data);
            return 1;
        }

        return $this->service->insert($table, $data, $format);
    }

    public function update(string $table, array $data, array $where, ?array $format = null, ?array $whereFormat = null): int|false
    {
        if (str_ends_with($table, 'hwe_memberships_batches')) {
            $id = (int) ($where['id'] ?? 0);
            if (!isset($this->batches[$id])) {
                return 0;
            }
            $this->batches[$id] = array_merge($this->batches[$id], $data);
            $this->batchUpdates[] = array($id, $data);
            return 1;
        }

        return $this->service->update($table, $data, $where, $format, $whereFormat);
    }

    public function query(string $sql): int|false
    {
        return $this->service->query($sql);
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        return $this->service->suppress_errors($suppress);
    }

    public function get_row(string $sql): ?object
    {
        if (str_contains($sql, 'hwe_memberships_batches')) {
            $args = $this->args($sql);
            $id   = (int) ($args[0] ?? 0);
            return isset($this->batches[$id]) ? (object) $this->batches[$id] : null;
        }

        return $this->service->get_row($sql);
    }

    /** @return list<object> */
    public function get_results(string $sql): array
    {
        if (str_contains($sql, 'hwe_memberships_batches')) {
            $args  = $this->args($sql);
            $limit = (int) ($args[0] ?? 0);
            $rows  = $this->batches;
            krsort($rows);
            $rows = array_values($rows);
            if ($limit > 0) {
                $rows = array_slice($rows, 0, $limit);
            }
            return array_map(static fn (array $row): object => (object) $row, $rows);
        }

        if (str_contains($sql, 'hwe_memberships_members') && str_contains($sql, 'user_id IN')) {
            $out = array();
            foreach (array_map('intval', $this->args($sql)) as $id) {
                if (!isset($this->service->members[$id])) {
                    continue;
                }
                $out[] = (object) array(
                    'user_id'   => $id,
                    'frozen_at' => $this->service->members[$id]['frozen_at'] ?? null,
                );
            }
            return $out;
        }

        if (str_contains($sql, 'hwe_memberships_grants') && str_contains($sql, 'user_id IN')) {
            $ids = array_map('intval', $this->args($sql));
            $out = array();
            foreach ($this->service->grants as $row) {
                if (!in_array((int) ($row['user_id'] ?? 0), $ids, true)) {
                    continue;
                }
                if (!in_array((string) ($row['status'] ?? ''), array('active', 'scheduled'), true)) {
                    continue;
                }
                $out[] = (object) array(
                    'user_id'  => (int) ($row['user_id'] ?? 0),
                    'level_id' => (int) ($row['level_id'] ?? 0),
                    'ends_at'  => $row['ends_at'] ?? null,
                );
            }
            return $out;
        }

        return $this->service->get_results($sql);
    }

    public function get_var(string $sql): string|int|null
    {
        if (str_contains($sql, 'COUNT(DISTINCT u.ID)')) {
            return count($this->candidateIds($sql));
        }

        return $this->service->get_var($sql);
    }

    /** @return list<string> */
    public function get_col(string $sql): array
    {
        if (str_contains($sql, 'FROM ' . $this->users . ' u')) {
            return array_map(static fn (int $id): string => (string) $id, $this->candidateIds($sql));
        }

        return $this->service->get_col($sql);
    }

    /** @param array<string,mixed> $data */
    public function forceBatch(int $batchId, array $data): void
    {
        if (isset($this->batches[$batchId])) {
            $this->batches[$batchId] = array_merge($this->batches[$batchId], $data);
        }
    }

    /** @return list<int> */
    private function candidateIds(string $sql): array
    {
        $args   = $this->args($sql);
        $index  = 0;
        $cursor = 0;
        if (str_contains($sql, 'u.ID > %d')) {
            $cursor = (int) ($args[$index++] ?? 0);
        }

        $noMembership = str_contains($sql, 'NOT EXISTS');
        $byLevel      = str_contains($sql, 'g.level_id = %d');
        $levelId      = 0;
        $now          = '';

        if ($byLevel) {
            $levelId = (int) ($args[$index++] ?? 0);
            $now     = (string) ($args[$index++] ?? '');
        } elseif ($noMembership) {
            $now = (string) ($args[$index++] ?? '');
        }

        $limit = str_contains($sql, 'LIMIT %d') ? (int) ($args[$index] ?? 0) : 0;

        preg_match_all("/LOCATE\\('\"([^\"]+)\";b:1;'/", $sql, $matches);
        $roles = $matches[1] ?? array();

        $ids = array();
        foreach ($this->accounts as $id => $account) {
            $id = (int) $id;
            if ($id <= $cursor) {
                continue;
            }
            if (array() === array_intersect($roles, $account['roles'])) {
                continue;
            }
            if ($noMembership && $this->hasLiveGrant($id, $now)) {
                continue;
            }
            if ($byLevel && !$this->hasLiveGrant($id, $now, $levelId)) {
                continue;
            }
            $ids[] = $id;
        }

        sort($ids);

        return $limit > 0 ? array_slice($ids, 0, $limit) : $ids;
    }

    private function hasLiveGrant(int $userId, string $now, int $levelId = 0): bool
    {
        foreach ($this->service->grants as $row) {
            if ((int) ($row['user_id'] ?? 0) !== $userId) {
                continue;
            }
            if (!in_array((string) ($row['status'] ?? ''), array('active', 'scheduled'), true)) {
                continue;
            }
            if ($levelId > 0 && (int) ($row['level_id'] ?? 0) !== $levelId) {
                continue;
            }
            $end = $row['ends_at'] ?? null;
            if (null !== $end && '' !== $end && (string) $end <= $now) {
                continue;
            }
            return true;
        }

        return false;
    }

    /** @return list<mixed> */
    private function args(string $sql): array
    {
        if (preg_match('~/\\* (\\[.*\\]) \\*/$~s', $sql, $matches)) {
            $decoded = json_decode($matches[1], true);
            return is_array($decoded) ? array_values($decoded) : array();
        }
        return array();
    }
}
