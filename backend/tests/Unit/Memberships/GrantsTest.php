<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TestMembershipsServiceWpdb;

require_once HWE_BACKEND_DIR . '/tests/Stubs/wp-memberships-service-wpdb.php';

/**
 * Servicio de concesiones (`HWE_Memberships_Grants`): el núcleo transaccional del módulo.
 *
 * Se prueba lo que decide el servicio, no el SQL literal: que toda escritura abra transacción
 * y bloquee la fila del socio, que una clave repetida no conceda dos veces, que las fechas y el
 * estado salgan de `Timeline`, que el historial guarde actor y nivel anterior/nuevo, que los
 * hooks se disparen solo tras el COMMIT y que la congelación detenga el reloj sin resucitar
 * concesiones ya vencidas. El doble de `$wpdb` mantiene el estado y deshace lo escrito en un
 * `ROLLBACK`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GrantsTest extends TestCase
{
    private const USER  = 7;
    private const OTHER = 8;
    private const LEVEL  = 101; // rango 5
    private const LEVEL2 = 102; // rango 10
    private const LEVEL3 = 103; // rango 20

    private int $now = 1_800_000_000;
    /** @var list<string> */
    private array $logged = [];
    /** @var array<int,\WP_Post> */
    private array $levelPosts = [];
    /** @var array<int,array<string,mixed>> */
    private array $levelMeta = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->now   = 1_800_000_000;
        $this->logged = [];
        $this->levelPosts = [];
        $this->levelMeta  = [];

        $GLOBALS['wpdb'] = new TestMembershipsServiceWpdb();

        $this->loadOwnFile('plugins/hwe-memberships/includes/Schema.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Levels.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Timeline.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Events.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Members.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Grants.php');
        \HWE_Memberships_Events::clear();

        $this->makeLevel(self::LEVEL, 5);
        $this->makeLevel(self::LEVEL2, 10);
        $this->makeLevel(self::LEVEL3, 20);

        Functions\when('get_post')->alias(fn (int $id) => $this->levelPosts[$id] ?? null);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->levelMeta[$id][$key] ?? ''
        );
        Functions\when('get_userdata')->alias(
            fn (int $id) => in_array($id, [self::USER, self::OTHER], true) ? (object) ['ID' => $id] : false
        );
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => trim((string) $value),
            'esc_html' => static fn ($value) => (string) $value,
            'esc_html__' => static fn ($value) => $value,
            '__' => static fn ($value) => $value,
        ]);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    private function wpdb(): TestMembershipsServiceWpdb
    {
        return $GLOBALS['wpdb'];
    }

    private function makeLevel(int $id, int $rank): void
    {
        $post               = new \WP_Post();
        $post->ID           = $id;
        $post->post_type    = \HWE_Memberships_Levels::POST_TYPE;
        $post->post_status  = 'publish';
        $post->post_title   = 'Nivel ' . $id;
        $post->post_name    = 'nivel-' . $id;
        $post->post_content = 'Descripción ' . $id;
        $this->levelPosts[$id] = $post;
        $this->levelMeta[$id]  = [\HWE_Memberships_Levels::RANK_META => (string) $rank];
    }

    private function day(int $days): string
    {
        return gmdate('Y-m-d H:i:s', $this->now + $days * DAY_IN_SECONDS);
    }

    private function plus(string $mysql, int $days): string
    {
        return gmdate('Y-m-d H:i:s', strtotime($mysql . ' UTC') + $days * DAY_IN_SECONDS);
    }

    private function at(string $mysql): DateTimeImmutable
    {
        return new DateTimeImmutable($mysql, new DateTimeZone('UTC'));
    }

    private function grant(
        int $userId = self::USER,
        int $levelId = self::LEVEL,
        ?int $days = 30,
        string $origin = 'order',
        string $key = 'grant:1',
        array $ref = [],
        int $actorId = 0,
        array $data = []
    ): ?int {
        return \HWE_Memberships_Grants::grant($userId, $levelId, $days, $origin, $key, $ref, $actorId, $data);
    }

    private function assertDbMoment(string $expected, mixed $actual, string $message = ''): void
    {
        $this->assertIsString($actual, $message);
        $this->assertSame($expected, substr($actual, 0, 19), $message);
    }

    private function assertDateMoment(string $expected, ?DateTimeImmutable $actual, string $message = ''): void
    {
        $this->assertNotNull($actual, $message);
        $this->assertSame('UTC', $actual->getTimezone()->getName(), $message);
        $this->assertSame($expected, $actual->format('Y-m-d H:i:s'), $message);
    }

    // ── Alta y transacción ──────────────────────────────────────────────────

    public function testGrantCreatesAnActiveGrantMemberRowAndEventInOneTransaction(): void
    {
        Actions\expectDone('hwe_membership_activated')
            ->once()
            ->with(self::USER, 1, self::LEVEL)
            ->whenHappen(function (): void {
                $this->wpdb()->events[] = 'action:hwe_membership_activated';
            });
        Actions\expectDone('hwe_membership_level_changed')
            ->once()
            ->with(self::USER, 0, self::LEVEL);

        $id = $this->grant(
            self::USER,
            self::LEVEL,
            30,
            'order',
            'order:55:item:9',
            ['type' => 'order', 'id' => 55]
        );

        $this->assertSame(1, $id);
        $row = $this->wpdb()->grantByKey('order:55:item:9');
        $this->assertNotNull($row);
        $this->assertSame(self::USER, (int) $row['user_id']);
        $this->assertSame(self::LEVEL, (int) $row['level_id']);
        $this->assertSame('order', $row['origin']);
        $this->assertSame('order', $row['ref_type']);
        $this->assertSame('55', (string) $row['ref_id']);
        $this->assertSame('active', $row['status']);
        $this->assertSame(30, (int) $row['duration_days']);
        $this->assertDbMoment($this->day(0), $row['starts_at']);
        $this->assertDbMoment($this->day(30), $row['ends_at']);

        $member = $this->wpdb()->members[self::USER];
        $this->assertSame(self::LEVEL, (int) $member['level_id']);
        $this->assertDbMoment($this->day(30), $member['covers_until']);
        $this->assertDbMoment($this->day(0), $member['member_since']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $member['member_number']);
        $this->assertNotSame((string) self::USER, (string) $member['member_number']);

        $model = \HWE_Memberships_Grants::forUser(self::USER);
        $this->assertCount(1, $model);
        $this->assertInstanceOf(DateTimeImmutable::class, $model[0]['created_at']);
        $this->assertDateMoment($this->day(0), $model[0]['created_at'], 'El modelo expone cuándo se creó la concesión.');

        $event = array_values($this->wpdb()->event_rows)[0];
        $this->assertSame('activated', $event['type']);
        $this->assertSame(0, (int) $event['level_from']);
        $this->assertSame(self::LEVEL, (int) $event['level_to']);
        $this->assertSame('system', $event['actor']);
        $this->assertNull($event['actor_id']);
        $this->assertSame($id, (int) $event['grant_id']);

        $this->assertContains('START TRANSACTION', $this->wpdb()->queries);
        $this->assertContains('COMMIT', $this->wpdb()->queries);
        $this->assertNotContains('ROLLBACK', $this->wpdb()->queries);

        $memberLocks = array_values(array_filter(
            $this->wpdb()->queries,
            static fn (string $query): bool => str_contains($query, 'FOR UPDATE')
                && str_contains($query, 'hwe_memberships_members'),
        ));
        $this->assertNotEmpty($memberLocks, 'Toda escritura bloquea la fila del socio.');
        $this->assertStringContainsString('WHERE user_id = %d FOR UPDATE', $memberLocks[0]);

        $grantLocks = array_values(array_filter(
            $this->wpdb()->queries,
            static fn (string $query): bool => str_contains($query, 'FOR UPDATE')
                && str_contains($query, 'hwe_memberships_grants'),
        ));
        $this->assertNotEmpty($grantLocks, 'Las concesiones vivas se leen bloqueadas.');

        $commit = array_search('COMMIT', $this->wpdb()->events, true);
        $action = array_search('action:hwe_membership_activated', $this->wpdb()->events, true);
        $this->assertIsInt($commit);
        $this->assertIsInt($action);
        $this->assertGreaterThan($commit, $action, 'La acción se dispara cuando la concesión ya está confirmada.');
    }

    public function testTheSameKeyIsGrantedOnlyOnce(): void
    {
        Actions\expectDone('hwe_membership_activated')->once();

        $first  = $this->grant(self::USER, self::LEVEL, 30, 'order', 'same-key');
        $second = $this->grant(self::USER, self::LEVEL, 30, 'order', 'same-key');

        $this->assertIsInt($first);
        $this->assertNull($second, 'La clave repetida no vuelve a conceder.');
        $this->assertCount(1, $this->wpdb()->grantsOf(self::USER));
        $this->assertCount(1, $this->wpdb()->event_rows);
        $this->assertSame('000001', $this->wpdb()->members[self::USER]['member_number']);
    }

    public function testGrantRollsBackWhenTheGrantInsertFailsAndDoesNotFireHooks(): void
    {
        Actions\expectDone('hwe_membership_activated')->never();
        Actions\expectDone('hwe_membership_level_changed')->never();
        $this->wpdb()->failInsertSuffix = 'hwe_memberships_grants';

        $id = $this->grant(self::USER, self::LEVEL, 30, 'order', 'fail:insert');

        $this->assertNull($id);
        $this->assertContains('ROLLBACK', $this->wpdb()->queries);
        $this->assertNotContains('COMMIT', $this->wpdb()->queries);
        $this->assertSame([], $this->wpdb()->grants);
        $this->assertSame([], $this->wpdb()->members);
        $this->assertSame([], $this->wpdb()->event_rows);
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
    }

    public function testGrantRollsBackWhenTheMemberCacheWriteFails(): void
    {
        Actions\expectDone('hwe_membership_activated')->never();
        $this->wpdb()->failUpdateSuffix = 'hwe_memberships_members';

        $id = $this->grant(self::USER, self::LEVEL, 30, 'order', 'fail:member');

        $this->assertNull($id);
        $this->assertContains('ROLLBACK', $this->wpdb()->queries);
        $this->assertNotContains('COMMIT', $this->wpdb()->queries);
        $this->assertSame([], $this->wpdb()->grants);
        $this->assertSame([], $this->wpdb()->event_rows);
    }

    public function testAFailedCommitDoesNotFireHooksAndClearsTheQueue(): void
    {
        Actions\expectDone('hwe_membership_activated')->once();
        Actions\expectDone('hwe_membership_level_changed')->once();

        $this->wpdb()->failCommit = true;
        $failed = $this->grant(self::USER, self::LEVEL, 30, 'order', 'commit:fail');
        $this->assertNull($failed);
        $this->assertSame([], $this->wpdb()->event_rows, 'El COMMIT fallido no deja historial.');
        $this->assertContains('ROLLBACK', $this->wpdb()->queries);

        $this->wpdb()->failCommit = false;
        $ok = $this->grant(self::USER, self::LEVEL, 30, 'order', 'commit:ok');
        $this->assertIsInt($ok);
        $this->assertCount(1, $this->wpdb()->event_rows, 'El intento fallido no deja hooks pendientes.');
    }

    // ── Renovar, mejorar y bajar ────────────────────────────────────────────

    public function testTheSameLevelRenewsAndChainsAfterTheCurrentEnd(): void
    {
        $firstEnd = $this->day(30);
        $this->grant(self::USER, self::LEVEL, 30, 'order', 'first');
        $this->now += 10 * DAY_IN_SECONDS;

        Actions\expectDone('hwe_membership_renewed')->once()->with(self::USER, 2, self::LEVEL);
        Actions\expectDone('hwe_membership_level_changed')->never();

        $second = $this->grant(self::USER, self::LEVEL, 30, 'order', 'renewal');
        $this->assertSame(2, $second);

        $renewal = $this->wpdb()->grantByKey('renewal');
        $this->assertNotNull($renewal);
        $this->assertSame('scheduled', $renewal['status']);
        $this->assertDbMoment($firstEnd, $renewal['starts_at']);
        $this->assertDbMoment($this->plus($firstEnd, 30), $renewal['ends_at']);

        $event = array_values($this->wpdb()->event_rows)[1];
        $this->assertSame('renewed', $event['type']);
        $this->assertSame(self::LEVEL, (int) $event['level_from']);
        $this->assertSame(self::LEVEL, (int) $event['level_to']);
    }

    public function testUpgradingStartsNowAndDefersTheLowerRemainingTime(): void
    {
        $this->grant(self::USER, self::LEVEL, 30, 'order', 'low');
        $this->now += 10 * DAY_IN_SECONDS;

        Actions\expectDone('hwe_membership_upgraded')->once()->with(self::USER, 2, self::LEVEL2);
        Actions\expectDone('hwe_membership_level_changed')->once()->with(self::USER, self::LEVEL, self::LEVEL2);

        $this->assertSame(2, $this->grant(self::USER, self::LEVEL2, 15, 'order', 'high'));

        $high = $this->wpdb()->grantByKey('high');
        $this->assertNotNull($high);
        $this->assertSame('active', $high['status']);
        $this->assertDbMoment($this->day(0), $high['starts_at']);
        $this->assertDbMoment($this->day(15), $high['ends_at']);

        $low = $this->wpdb()->grantByKey('low');
        $this->assertNotNull($low);
        $this->assertSame('scheduled', $low['status']);
        $this->assertDbMoment($this->day(15), $low['starts_at']);
        $this->assertDbMoment($this->day(35), $low['ends_at'], 'El resto inferior se aplaza tras la superior (20 días).');

        $this->assertSame(self::LEVEL2, (int) $this->wpdb()->members[self::USER]['level_id']);
        $event = array_values($this->wpdb()->event_rows)[1];
        $this->assertSame('upgraded', $event['type']);
    }

    public function testDowngradingIsDeferredWithItsOwnEvent(): void
    {
        $highEnd = $this->day(30);
        $this->grant(self::USER, self::LEVEL2, 30, 'order', 'high');
        $this->now += 10 * DAY_IN_SECONDS;

        Actions\expectDone('hwe_membership_deferred')->once()->with(self::USER, 2, self::LEVEL);
        Actions\expectDone('hwe_membership_level_changed')->never();

        $this->assertSame(2, $this->grant(self::USER, self::LEVEL, 10, 'order', 'low'));

        $low = $this->wpdb()->grantByKey('low');
        $this->assertNotNull($low);
        $this->assertSame('scheduled', $low['status']);
        $this->assertDbMoment($highEnd, $low['starts_at']);
        $this->assertDbMoment($this->plus($highEnd, 10), $low['ends_at']);

        $event = array_values($this->wpdb()->event_rows)[1];
        $this->assertSame('deferred', $event['type']);
        $this->assertSame(self::LEVEL2, (int) $event['level_from']);
        $this->assertSame(self::LEVEL2, (int) $event['level_to'], 'El nivel efectivo sigue siendo el superior.');
    }

    // ── Orígenes y actor ────────────────────────────────────────────────────

    public function testAdminOriginRecordsGrantedByAdminAndTheActor(): void
    {
        Actions\expectDone('hwe_membership_granted_by_admin')->once()->with(self::USER, 1, self::LEVEL);
        Actions\expectDone('hwe_membership_activated')->never();
        Actions\expectDone('hwe_membership_level_changed')->once()->with(self::USER, 0, self::LEVEL);

        $this->assertSame(
            1,
            $this->grant(self::USER, self::LEVEL, 30, 'admin', 'admin:1', [], 99, ['note' => 'Cortesía'])
        );

        $row = $this->wpdb()->grantByKey('admin:1');
        $this->assertNotNull($row);
        $this->assertSame('admin', $row['actor']);
        $this->assertSame(99, (int) $row['actor_id']);
        $this->assertSame('Cortesía', $row['note']);

        $event = array_values($this->wpdb()->event_rows)[0];
        $this->assertSame('granted_by_admin', $event['type']);
        $this->assertSame('admin', $event['actor']);
        $this->assertSame(99, (int) $event['actor_id']);
        $this->assertSame(self::LEVEL, (int) $event['level_to']);
    }

    public function testReferralGiftOriginRecordsItsOwnEvent(): void
    {
        Actions\expectDone('hwe_membership_referral_gift_granted')->once()->with(self::USER, 1, self::LEVEL);
        Actions\expectDone('hwe_membership_granted_by_admin')->never();

        $this->assertSame(
            1,
            $this->grant(self::USER, self::LEVEL, 30, 'referral_gift', 'gift:7', ['type' => 'user', 'id' => 8])
        );

        $event = array_values($this->wpdb()->event_rows)[0];
        $this->assertSame('referral_gift_granted', $event['type']);
    }

    public function testOriginsAreFilterableAndUnknownOriginsAreRejected(): void
    {
        $this->assertNull($this->grant(self::USER, self::LEVEL, 30, 'promo', 'promo:1'));
        $this->assertSame([], $this->wpdb()->grants);
        $this->assertNotEmpty($this->logged);

        Filters\expectApplied('hwe_memberships_grant_origins')
            ->once()
            ->andReturnUsing(static fn (array $origins): array => array_merge($origins, ['promo']));
        Actions\expectDone('hwe_membership_activated')->once();

        $this->assertSame(
            1,
            $this->grant(self::USER, self::LEVEL, 30, 'promo', 'promo:2')
        );

        $event = array_values($this->wpdb()->event_rows)[0];
        $this->assertSame('activated', $event['type'], 'Un origen extra usa el evento de estado.');
    }

    public function testAnIndefiniteGrantHasNoEndAndCanBeRenewed(): void
    {
        $this->assertSame(1, $this->grant(self::USER, self::LEVEL, 0, 'order', 'forever'));

        $row = $this->wpdb()->grantByKey('forever');
        $this->assertNotNull($row);
        $this->assertSame('active', $row['status']);
        $this->assertSame(0, (int) $row['duration_days']);
        $this->assertNull($row['ends_at']);
        $this->assertNull($this->wpdb()->members[self::USER]['covers_until']);
    }

    // ── Revocación ──────────────────────────────────────────────────────────

    public function testRevokeActiveGrantEndsItNowAndRecordsTheEvent(): void
    {
        $id = $this->grant(self::USER, self::LEVEL, 30, 'order', 'active');
        $this->wpdb()->seedToken(self::USER, 'token-active');
        $this->now += 10 * DAY_IN_SECONDS;

        Actions\expectDone('hwe_membership_revoked')->once()->with(self::USER, $id, self::LEVEL);
        Actions\expectDone('hwe_membership_level_changed')->once()->with(self::USER, self::LEVEL, 0);

        $this->assertTrue(\HWE_Memberships_Grants::revokeByKey('active', 42, 'refund'));

        $row = $this->wpdb()->grantByKey('active');
        $this->assertNotNull($row);
        $this->assertSame('revoked', $row['status']);
        $this->assertDbMoment($this->day(0), $row['ends_at'], 'La activa termina ahora.');
        $this->assertDbMoment($this->day(0), $row['revoked_at']);
        $this->assertNull($this->wpdb()->members[self::USER]['level_id']);
        $this->assertNull($this->wpdb()->members[self::USER]['covers_until']);
        $this->assertDbMoment($this->day(0), $this->wpdb()->tokens[0]['revoked_at']);

        $event = array_values($this->wpdb()->event_rows)[1];
        $this->assertSame('revoked', $event['type']);
        $this->assertSame(self::LEVEL, (int) $event['level_from']);
        $this->assertSame(0, (int) $event['level_to']);
        $this->assertSame('admin', $event['actor']);
        $this->assertSame(42, (int) $event['actor_id']);
        $this->assertNotNull($event['data']);
        $this->assertStringContainsString('refund', (string) $event['data']);
    }

    public function testRevokeScheduledGrantKeepsTheRowButTakesItOutOfTheQueue(): void
    {
        $this->grant(self::USER, self::LEVEL2, 30, 'order', 'high');
        $scheduledStart = $this->day(30);
        $scheduledEnd   = $this->day(40);
        $this->now += 10 * DAY_IN_SECONDS;
        $lowId = $this->grant(self::USER, self::LEVEL, 10, 'order', 'low');

        Actions\expectDone('hwe_membership_revoked')->once()->with(self::USER, $lowId, self::LEVEL);
        Actions\expectDone('hwe_membership_level_changed')->never();

        $this->assertTrue(\HWE_Memberships_Grants::revokeByKey('low'));

        $low = $this->wpdb()->grantByKey('low');
        $this->assertNotNull($low);
        $this->assertSame('revoked', $low['status']);
        $this->assertDbMoment($scheduledStart, $low['starts_at'], 'La fila conserva sus fechas como historial.');
        $this->assertDbMoment($scheduledEnd, $low['ends_at']);
        $this->assertDbMoment($this->day(0), $low['revoked_at']);

        $state = \HWE_Memberships_Members::state(self::USER);
        $this->assertSame(self::LEVEL2, $state['level_id']);
        $this->assertSame([], $state['queue']);
    }

    public function testRevokingAnIndefiniteReleasesTheLowerQueue(): void
    {
        $this->grant(self::USER, self::LEVEL, 30, 'order', 'low');
        $this->grant(self::USER, self::LEVEL2, 0, 'order', 'forever');
        $this->now += 10 * DAY_IN_SECONDS;

        Actions\expectDone('hwe_membership_revoked')->once()->with(self::USER, 2, self::LEVEL2);
        Actions\expectDone('hwe_membership_level_changed')->once()->with(self::USER, self::LEVEL2, self::LEVEL);

        $this->assertTrue(\HWE_Memberships_Grants::revokeByKey('forever'));

        $low = $this->wpdb()->grantByKey('low');
        $this->assertNotNull($low);
        $this->assertSame('active', $low['status'], 'La cola liberada empieza ahora.');
        $this->assertDbMoment($this->day(0), $low['starts_at'], 'La cola se libera al quitar la indefinida.');
        $this->assertDbMoment($this->day(30), $low['ends_at']);
        $this->assertNull($low['queued_seconds']);

        $forever = $this->wpdb()->grantByKey('forever');
        $this->assertNotNull($forever);
        $this->assertSame('revoked', $forever['status']);
        $this->assertSame(self::LEVEL, (int) $this->wpdb()->members[self::USER]['level_id']);
    }

    public function testRevokeUnknownKeyOrAlreadyRevokedReturnsFalseWithoutNewEvents(): void
    {
        $this->assertFalse(\HWE_Memberships_Grants::revokeByKey('nope'));

        $this->grant(self::USER, self::LEVEL, 30, 'order', 'once');
        $this->assertTrue(\HWE_Memberships_Grants::revokeByKey('once'));
        $eventCount = count($this->wpdb()->event_rows);
        $this->wpdb()->queries = [];

        $this->assertFalse(\HWE_Memberships_Grants::revokeByKey('once'));
        $this->assertCount($eventCount, $this->wpdb()->event_rows);
        $this->assertContains('ROLLBACK', $this->wpdb()->queries);
        $this->assertNotContains('COMMIT', $this->wpdb()->queries);
    }

    public function testAKeyFromARevokedGrantCannotBeGrantedAgain(): void
    {
        $this->grant(self::USER, self::LEVEL, 30, 'order', 'refunded');
        $this->assertTrue(\HWE_Memberships_Grants::revokeByKey('refunded'));
        $eventCount = count($this->wpdb()->event_rows);

        $this->assertNull($this->grant(self::USER, self::LEVEL, 30, 'order', 'refunded'));
        $this->assertCount(1, $this->wpdb()->grantsOf(self::USER));
        $this->assertCount($eventCount, $this->wpdb()->event_rows);
    }

    // ── Quitar y congelar ───────────────────────────────────────────────────

    public function testRemoveAllEndsActiveRevokesScheduledAndKeepsHistory(): void
    {
        $this->grant(self::USER, self::LEVEL, 30, 'order', 'low');
        $this->grant(self::USER, self::LEVEL2, 30, 'order', 'high');
        $this->wpdb()->seedToken(self::USER, 'token-remove');
        $number = $this->wpdb()->members[self::USER]['member_number'];
        $this->now += 10 * DAY_IN_SECONDS;

        Actions\expectDone('hwe_membership_removed_by_admin')->once()->with(self::USER, 0, self::LEVEL2);
        Actions\expectDone('hwe_membership_level_changed')->once()->with(self::USER, self::LEVEL2, 0);

        $this->assertTrue(\HWE_Memberships_Grants::removeAll(self::USER, 42, 'baja'));

        $this->assertCount(2, $this->wpdb()->grants, 'El historial no se borra.');
        $high = $this->wpdb()->grantByKey('high');
        $low  = $this->wpdb()->grantByKey('low');
        $this->assertNotNull($high);
        $this->assertNotNull($low);
        $this->assertSame('ended', $high['status']);
        $this->assertDbMoment($this->day(0), $high['ends_at']);
        $this->assertSame('revoked', $low['status']);
        $this->assertDbMoment($this->day(0), $low['revoked_at']);

        $member = $this->wpdb()->members[self::USER];
        $this->assertNull($member['level_id']);
        $this->assertNull($member['covers_until']);
        $this->assertNull($member['frozen_at']);
        $this->assertSame($number, $member['member_number'], 'El número de socio es estable.');
        $this->assertDbMoment($this->day(0), $this->wpdb()->tokens[0]['revoked_at']);

        $event = array_values($this->wpdb()->event_rows)[2];
        $this->assertSame('removed_by_admin', $event['type']);
        $this->assertSame(self::LEVEL2, (int) $event['level_from']);
        $this->assertSame(0, (int) $event['level_to']);
    }

    public function testRemoveAllWithoutLiveGrantsIsRejectedAndWritesNothing(): void
    {
        $this->wpdb()->seedMember(self::USER);

        $this->assertFalse(\HWE_Memberships_Grants::removeAll(self::USER, 42, 'baja'));
        $this->assertSame([], $this->wpdb()->event_rows);
        $this->assertNotEmpty($this->logged);
    }

    public function testRemoveAllClearsFreezeAndKeepsTheMemberNumber(): void
    {
        $this->grant(self::USER, self::LEVEL, 30, 'order', 'frozen');
        $number = $this->wpdb()->members[self::USER]['member_number'];
        $this->assertTrue(\HWE_Memberships_Grants::freeze(self::USER, 42));

        Actions\expectDone('hwe_membership_removed_by_admin')->once()->with(self::USER, 0, 0);
        Actions\expectDone('hwe_membership_level_changed')->never();

        $this->assertTrue(\HWE_Memberships_Grants::removeAll(self::USER, 42, 'baja'));

        $member = $this->wpdb()->members[self::USER];
        $this->assertNull($member['frozen_at']);
        $this->assertNull($member['level_id']);
        $this->assertSame($number, $member['member_number']);
        $this->assertSame('none', \HWE_Memberships_Members::state(self::USER)['status']);
        $this->assertSame('ended', $this->wpdb()->grantByKey('frozen')['status']);
    }

    public function testFreezeRequiresMembershipAndAnUnexpiredCoverage(): void
    {
        $this->assertFalse(\HWE_Memberships_Grants::freeze(self::USER, 42));
        $this->assertArrayNotHasKey(self::USER, $this->wpdb()->members);
        $this->assertNotEmpty($this->logged);

        // Una concesión activa por estado pero vencida por fechas no es membresía.
        $this->wpdb()->seedGrant([
            'user_id'    => self::OTHER,
            'idempotency_key' => 'expired',
            'starts_at'  => $this->day(-2),
            'ends_at'    => $this->day(-1),
            'status'     => 'active',
        ]);
        $this->logged = [];
        $this->assertFalse(\HWE_Memberships_Grants::freeze(self::OTHER, 42));
        $this->assertNotEmpty($this->logged);
        $this->assertNull($this->wpdb()->members[self::OTHER]['frozen_at'] ?? null);
    }

    public function testFreezeClearsEffectiveLevelRevokesTokensAndRecordsEvent(): void
    {
        $this->grant(self::USER, self::LEVEL, 30, 'order', 'member');
        $this->wpdb()->seedToken(self::USER, 'token-freeze');
        $this->now += 10 * DAY_IN_SECONDS;

        Actions\expectDone('hwe_membership_frozen')->once()->with(self::USER, 42);
        Actions\expectDone('hwe_membership_level_changed')->once()->with(self::USER, self::LEVEL, 0);

        $this->assertTrue(\HWE_Memberships_Grants::freeze(self::USER, 42));

        $member = $this->wpdb()->members[self::USER];
        $this->assertDbMoment($this->day(0), $member['frozen_at']);
        $this->assertNull($member['level_id']);
        $this->assertNull($member['covers_until']);
        $this->assertDbMoment($this->day(0), $this->wpdb()->tokens[0]['revoked_at']);

        $event = array_values($this->wpdb()->event_rows)[1];
        $this->assertSame('frozen', $event['type']);
        $this->assertSame(self::LEVEL, (int) $event['level_from']);
        $this->assertSame(0, (int) $event['level_to']);
        $this->assertSame(42, (int) $event['actor_id']);

        $state = \HWE_Memberships_Members::state(self::USER);
        $this->assertSame('frozen', $state['status']);
        $this->assertSame(0, $state['level_id']);
        $this->assertSame(0, \HWE_Memberships_Members::effectiveRank(self::USER));
        $this->assertTrue(\HWE_Memberships_Members::isFrozen(self::USER));
    }

    public function testFreezeTwiceIsRejectedWithAReason(): void
    {
        $this->grant(self::USER, self::LEVEL, 30, 'order', 'member');
        $this->assertTrue(\HWE_Memberships_Grants::freeze(self::USER, 42));
        $this->logged = [];

        $this->assertFalse(\HWE_Memberships_Grants::freeze(self::USER, 42));
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('congelado', $this->logged[0]);
        $this->assertCount(2, $this->wpdb()->event_rows, 'No se duplica el evento de congelación.');
    }

    public function testNothingExpiresWhileFrozen(): void
    {
        $this->grant(self::USER, self::LEVEL, 1, 'order', 'short');
        $this->assertTrue(\HWE_Memberships_Grants::freeze(self::USER, 42));
        $this->now += 100 * DAY_IN_SECONDS;

        $state = \HWE_Memberships_Members::state(self::USER);
        $this->assertSame('frozen', $state['status']);
        $this->assertNull($state['ends_at']);
        $this->assertSame(0, \HWE_Memberships_Members::effectiveRank(self::USER));
        $this->assertSame('active', $this->wpdb()->grantByKey('short')['status'], 'La tarea diaria no debe caducarla.');
    }

    public function testUnfreezeRequiresFrozenState(): void
    {
        $this->grant(self::USER, self::LEVEL, 30, 'order', 'member');

        $this->assertFalse(\HWE_Memberships_Grants::unfreeze(self::USER, 42));
        $this->assertNotEmpty($this->logged);
        $this->assertNull($this->wpdb()->members[self::USER]['frozen_at']);
        $this->assertCount(1, $this->wpdb()->event_rows);
    }

    public function testUnfreezeShiftsActiveAndScheduledDatesAndAccumulatesFrozenSeconds(): void
    {
        $originalStart = $this->day(0);
        $this->grant(self::USER, self::LEVEL2, 30, 'order', 'high');
        $this->grant(self::USER, self::LEVEL, 30, 'order', 'low');
        $highEnd = $this->day(30);
        $lowStart = $this->day(30);
        $lowEnd   = $this->day(60);
        $this->now += 10 * DAY_IN_SECONDS;
        $this->assertTrue(\HWE_Memberships_Grants::freeze(self::USER, 42));
        $this->now += 5 * DAY_IN_SECONDS;

        Actions\expectDone('hwe_membership_unfrozen')->once()->with(self::USER, 42);
        Actions\expectDone('hwe_membership_level_changed')->once()->with(self::USER, 0, self::LEVEL2);

        $this->assertTrue(\HWE_Memberships_Grants::unfreeze(self::USER, 42));

        $high = $this->wpdb()->grantByKey('high');
        $low  = $this->wpdb()->grantByKey('low');
        $this->assertNotNull($high);
        $this->assertNotNull($low);
        $this->assertDbMoment($originalStart, $high['starts_at'], 'El inicio de una activa no se mueve.');
        $this->assertDbMoment($this->plus($highEnd, 5), $high['ends_at']);
        $this->assertDbMoment($this->plus($lowStart, 5), $low['starts_at']);
        $this->assertDbMoment($this->plus($lowEnd, 5), $low['ends_at']);
        $this->assertSame(5 * DAY_IN_SECONDS, (int) $high['frozen_seconds']);

        $member = $this->wpdb()->members[self::USER];
        $this->assertNull($member['frozen_at']);
        $this->assertSame(5 * DAY_IN_SECONDS, (int) $member['frozen_seconds']);
        $this->assertSame(self::LEVEL2, (int) $member['level_id']);
        $this->assertDbMoment($this->plus($highEnd, 5), $member['covers_until']);

        $event = array_values($this->wpdb()->event_rows)[3];
        $this->assertSame('unfrozen', $event['type']);
        $this->assertSame(0, (int) $event['level_from']);
        $this->assertSame(self::LEVEL2, (int) $event['level_to']);
    }

    public function testUnfreezeShiftsAGrantCreatedBeforeTheFreezeByTheWholeFreeze(): void
    {
        $day0 = $this->day(0);
        $this->grant(self::USER, self::LEVEL, 30, 'order', 'before');
        $this->assertTrue(\HWE_Memberships_Grants::freeze(self::USER, 42));

        $this->now += 7 * DAY_IN_SECONDS;

        Actions\expectDone('hwe_membership_unfrozen')->once()->with(self::USER, 42);
        Actions\expectDone('hwe_membership_level_changed')->once()->with(self::USER, 0, self::LEVEL);

        $this->assertTrue(\HWE_Memberships_Grants::unfreeze(self::USER, 42));

        $before = $this->wpdb()->grantByKey('before');
        $this->assertNotNull($before);
        $this->assertDbMoment($day0, $before['starts_at']);
        $this->assertDbMoment($this->plus($day0, 37), $before['ends_at'], 'Existió toda la congelación: 7 días.');
        $this->assertSame(7 * DAY_IN_SECONDS, (int) $before['frozen_seconds']);
    }

    public function testUnfreezeReturnsOnlyTheTimeEachGrantWasFrozen(): void
    {
        $day0 = $this->day(0);
        $this->grant(self::USER, self::LEVEL2, 30, 'order', 'base');
        $this->assertTrue(\HWE_Memberships_Grants::freeze(self::USER, 42));

        $this->now += 5 * DAY_IN_SECONDS;
        $this->assertSame(2, $this->grant(self::USER, self::LEVEL3, 30, 'order', 'during'));

        $this->now += 5 * DAY_IN_SECONDS;

        Actions\expectDone('hwe_membership_unfrozen')->once()->with(self::USER, 42);
        Actions\expectDone('hwe_membership_level_changed')->once()->with(self::USER, 0, self::LEVEL3);

        $this->assertTrue(\HWE_Memberships_Grants::unfreeze(self::USER, 42));

        $base   = $this->wpdb()->grantByKey('base');
        $during = $this->wpdb()->grantByKey('during');
        $this->assertNotNull($base);
        $this->assertNotNull($during);

        $this->assertDbMoment($this->plus($day0, 5), $during['starts_at'], 'El inicio real de la concesión no se mueve.');
        $this->assertDbMoment(
            $this->plus($day0, 40),
            $during['ends_at'],
            'La creada durante la congelación solo recupera los 5 días que estuvo congelada.'
        );

        $this->assertDbMoment($this->plus($day0, 40), $base['starts_at'], 'La concesión desplazada queda contigua.');
        $this->assertDbMoment($this->plus($day0, 65), $base['ends_at'], 'Conserva sus 25 días restantes.');

        $this->assertSame(10 * DAY_IN_SECONDS, (int) $base['frozen_seconds']);
        $this->assertSame(5 * DAY_IN_SECONDS, (int) $during['frozen_seconds']);
        $this->assertSame(10 * DAY_IN_SECONDS, (int) $this->wpdb()->members[self::USER]['frozen_seconds']);
        $this->assertSame(self::LEVEL3, (int) $this->wpdb()->members[self::USER]['level_id']);
        $this->assertDbMoment($this->plus($day0, 40), $this->wpdb()->members[self::USER]['covers_until']);
    }

    public function testUnfreezeKeepsARenewalCreatedDuringTheFreezeContiguous(): void
    {
        $firstEnd = $this->day(30);
        $this->grant(self::USER, self::LEVEL, 30, 'order', 'first');
        $this->assertTrue(\HWE_Memberships_Grants::freeze(self::USER, 42));

        $this->now += 5 * DAY_IN_SECONDS;
        $this->assertSame(2, $this->grant(self::USER, self::LEVEL, 30, 'order', 'renewal'));

        $renewal = $this->wpdb()->grantByKey('renewal');
        $this->assertNotNull($renewal);
        $this->assertDbMoment($firstEnd, $renewal['starts_at']);

        $this->now += 5 * DAY_IN_SECONDS;

        Actions\expectDone('hwe_membership_unfrozen')->once()->with(self::USER, 42);

        $this->assertTrue(\HWE_Memberships_Grants::unfreeze(self::USER, 42));

        $first   = $this->wpdb()->grantByKey('first');
        $renewal = $this->wpdb()->grantByKey('renewal');
        $this->assertNotNull($first);
        $this->assertNotNull($renewal);

        $shiftedEnd = $this->plus($firstEnd, 10);
        $this->assertDbMoment($shiftedEnd, $first['ends_at']);
        $this->assertDbMoment($shiftedEnd, $renewal['starts_at'], 'La renovación sigue contigua, sin solape.');
        $this->assertDbMoment($this->plus($shiftedEnd, 30), $renewal['ends_at']);

        $queue = \HWE_Memberships_Members::state(self::USER)['queue'];
        $this->assertCount(1, $queue);
        $this->assertDateMoment($shiftedEnd, $queue[0]['starts_at'], 'La cola empieza exactamente donde termina la activa.');
    }

    public function testUnfreezeDoesNotResurrectAGrantExpiredBeforeTheFreeze(): void
    {
        $frozenAt   = $this->day(-5);
        $endsAt     = $this->day(-6);
        $createdAt  = $this->day(-20);
        $this->wpdb()->seedMember(self::USER, ['frozen_at' => $frozenAt]);
        $grantId = $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'idempotency_key' => 'old',
            'starts_at' => $this->day(-10),
            'ends_at' => $endsAt,
            'created_at' => $createdAt,
            'status' => 'active',
        ]);

        Actions\expectDone('hwe_membership_unfrozen')->once()->with(self::USER, 42);
        Actions\expectDone('hwe_membership_level_changed')->never();
        Actions\expectDone('hwe_membership_expired')
            ->once()
            ->with(self::USER, $grantId, self::LEVEL)
            ->whenHappen(function (): void {
                $this->wpdb()->events[] = 'action:hwe_membership_expired';
            });

        $this->assertTrue(\HWE_Memberships_Grants::unfreeze(self::USER, 42));

        $old = $this->wpdb()->grantByKey('old');
        $this->assertNotNull($old);
        $this->assertSame('ended', $old['status']);
        $this->assertDbMoment($endsAt, $old['ends_at'], 'Una concesión ya vencida no se desplaza.');
        $this->assertSame('none', \HWE_Memberships_Members::state(self::USER)['status']);
        $this->assertSame(5 * DAY_IN_SECONDS, (int) $this->wpdb()->members[self::USER]['frozen_seconds']);

        $expired = array_values(array_filter(
            $this->wpdb()->event_rows,
            static fn (array $event): bool => 'expired' === $event['type']
        ));
        $this->assertCount(1, $expired, 'El cierre deja historial de caducidad.');
        $this->assertSame($grantId, (int) $expired[0]['grant_id']);
        $this->assertSame('system', $expired[0]['actor']);
        $this->assertStringContainsString('"level_id":' . self::LEVEL, (string) $expired[0]['data']);

        $commit = array_search('COMMIT', $this->wpdb()->events, true);
        $action = array_search('action:hwe_membership_expired', $this->wpdb()->events, true);
        $this->assertIsInt($commit);
        $this->assertIsInt($action);
        $this->assertGreaterThan($commit, $action, 'El hook de caducidad se dispara tras el COMMIT.');
    }

    // ── Tarea diaria ────────────────────────────────────────────────────────

    public function testDueUserIdsFindsCandidatesByDateWithoutRepeatingUsers(): void
    {
        $this->wpdb()->seedGrant([
            'user_id'         => self::USER,
            'idempotency_key' => 'due:1',
            'status'          => 'scheduled',
            'starts_at'       => $this->day(-1),
            'ends_at'         => $this->day(29),
        ]);
        $this->wpdb()->seedGrant([
            'user_id'         => self::USER,
            'idempotency_key' => 'due:2',
            'status'          => 'active',
            'starts_at'       => $this->day(-10),
            'ends_at'         => $this->day(-1),
        ]);
        $this->wpdb()->seedGrant([
            'user_id'         => self::OTHER,
            'idempotency_key' => 'due:3',
            'status'          => 'active',
            'starts_at'       => $this->day(-10),
            'ends_at'         => $this->day(-1),
        ]);

        $now = $this->at($this->day(0));

        $this->assertSame([self::USER, self::OTHER], \HWE_Memberships_Grants::dueUserIds(10, $now));
        $this->assertSame([self::USER], \HWE_Memberships_Grants::dueUserIds(1, $now), 'El límite corta la lista.');
    }

    public function testDueUserIdsUsesAUnionOfIndexedSubqueriesOrderedByOldestDueDate(): void
    {
        $this->wpdb()->seedGrant([
            'user_id'         => self::USER,
            'idempotency_key' => 'union:oldest',
            'status'          => 'active',
            'starts_at'       => $this->day(-20),
            'ends_at'         => $this->day(-5),
        ]);
        $this->wpdb()->seedGrant([
            'user_id'         => self::OTHER,
            'idempotency_key' => 'union:newer',
            'status'          => 'scheduled',
            'starts_at'       => $this->day(-3),
            'ends_at'         => $this->day(27),
        ]);

        $now = $this->at($this->day(0));

        $this->assertSame(
            [self::USER, self::OTHER],
            \HWE_Memberships_Grants::dueUserIds(10, $now, 7),
            'El vencimiento más antiguo va primero, no el user_id.'
        );

        $queries = array_values(array_filter(
            $this->wpdb()->queries,
            static fn (string $query): bool => str_contains($query, 'UNION')
        ));
        $this->assertNotEmpty($queries);
        $sql = (string) end($queries);

        $this->assertStringContainsString('UNION', $sql);
        $this->assertStringContainsString('MIN(g.starts_at)', $sql);
        $this->assertStringContainsString('MIN(g.ends_at)', $sql);
        $this->assertStringContainsString('GROUP BY g.user_id', $sql);
        $this->assertStringContainsString('GROUP BY d.user_id', $sql);
        $this->assertStringContainsString('ORDER BY MIN(d.due_at)', $sql);
        $this->assertGreaterThanOrEqual(4, substr_count($sql, 'LIMIT'), 'Cada subconsulta y el resultado tienen su LIMIT.');
        $this->assertStringNotContainsString(') OR (', $sql, 'Las condiciones por fecha no se mezclan en un OR.');
    }

    public function testDueUserIdsDoesNotLetOneUserWithManyGrantsCrowdOutOthers(): void
    {
        foreach ([5, 4, 3] as $offset) {
            $this->wpdb()->seedGrant([
                'user_id'         => self::USER,
                'idempotency_key' => 'many:' . $offset,
                'status'          => 'active',
                'starts_at'       => $this->day(-40),
                'ends_at'         => $this->day(-$offset),
            ]);
        }
        $this->wpdb()->seedGrant([
            'user_id'         => self::OTHER,
            'idempotency_key' => 'many:other',
            'status'          => 'active',
            'starts_at'       => $this->day(-40),
            'ends_at'         => $this->day(-1),
        ]);

        $this->assertSame(
            [self::USER, self::OTHER],
            \HWE_Memberships_Grants::dueUserIds(2, $this->at($this->day(0))),
            'Un socio con muchas concesiones ocupa un solo puesto del lote.'
        );
    }

    public function testDueUserIdsSkipsFrozenMembersAndHonorsTheNoticeWindow(): void
    {
        $this->wpdb()->seedMember(self::OTHER, ['frozen_at' => $this->day(-1)]);
        $this->wpdb()->seedGrant([
            'user_id'         => self::OTHER,
            'idempotency_key' => 'frozen:due',
            'status'          => 'active',
            'starts_at'       => $this->day(-40),
            'ends_at'         => $this->day(-1),
        ]);
        $this->wpdb()->seedGrant([
            'user_id'         => self::USER,
            'idempotency_key' => 'notice:due',
            'status'          => 'active',
            'starts_at'       => $this->day(-1),
            'ends_at'         => $this->day(3),
        ]);

        $now = $this->at($this->day(0));

        $this->assertSame([], \HWE_Memberships_Grants::dueUserIds(10, $now, 0), 'Sin días de aviso no hay candidato.');
        $this->assertSame(
            [self::USER],
            \HWE_Memberships_Grants::dueUserIds(10, $now, 7),
            'El aviso solo mira activas dentro de la ventana y nunca a un congelado.'
        );
    }

    public function testMaterializeActivatesAScheduledGrantOnlyOnce(): void
    {
        $this->wpdb()->seedMember(self::USER);
        $this->wpdb()->seedGrant([
            'user_id'         => self::USER,
            'idempotency_key' => 'materialize:once',
            'level_id'        => self::LEVEL,
            'status'          => 'scheduled',
            'starts_at'       => $this->day(-1),
            'ends_at'         => $this->day(29),
        ]);

        $now = $this->at($this->day(0));

        $this->assertSame(1, \HWE_Memberships_Grants::materialize(self::USER, $now));
        $this->assertSame('active', $this->wpdb()->grantByKey('materialize:once')['status']);
        $this->assertSame(self::LEVEL, (int) $this->wpdb()->members[self::USER]['level_id']);
        $this->assertSame(0, \HWE_Memberships_Grants::materialize(self::USER, $now), 'La segunda pasada no repite.');
        $this->assertSame(['activated'], $this->wpdb()->eventTypes());
    }

    public function testMaterializeReturnsMinusOneWhenAWriteFails(): void
    {
        $this->wpdb()->seedMember(self::USER);
        $this->wpdb()->seedGrant([
            'user_id'         => self::USER,
            'idempotency_key' => 'materialize:fail',
            'level_id'        => self::LEVEL,
            'status'          => 'scheduled',
            'starts_at'       => $this->day(-1),
            'ends_at'         => $this->day(29),
        ]);
        $this->wpdb()->failGrantUpdatesForUsers = [self::USER];

        $this->assertSame(-1, \HWE_Memberships_Grants::materialize(self::USER, $this->at($this->day(0))));
        $this->assertSame('scheduled', $this->wpdb()->grantByKey('materialize:fail')['status']);
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
    }

    // ── Regalo de referido (Tarea 15) ───────────────────────────────────────

    public function testGrantReferralGiftGrantsTheRefereeStoresTheReferrerAndCountsTheGift(): void
    {
        $referrerGrant = $this->wpdb()->seedGrant([
            'user_id'         => self::USER,
            'level_id'        => self::LEVEL,
            'idempotency_key' => 'referrer:active',
            'starts_at'       => $this->day(-1),
            'ends_at'         => $this->day(30),
            'status'          => 'active',
        ]);

        Actions\expectDone('hwe_membership_referral_gift_granted')
            ->once()
            ->with(self::OTHER, 2, self::LEVEL);
        Actions\expectDone('hwe_membership_level_changed')
            ->once()
            ->with(self::OTHER, 0, self::LEVEL);

        $result = \HWE_Memberships_Grants::grantReferralGift(self::USER, self::OTHER, self::LEVEL, 30, 2);

        $this->assertSame('granted', $result['reason']);
        $this->assertSame(2, (int) $result['grant_id']);
        $this->assertSame(1, (int) $result['used']);
        $this->assertSame(2, (int) $result['max']);

        $gift = $this->wpdb()->grantByKey('gift:' . self::OTHER);
        $this->assertNotNull($gift);
        $this->assertSame(self::OTHER, (int) $gift['user_id']);
        $this->assertSame('referral_gift', $gift['origin']);
        $this->assertSame('user', $gift['ref_type']);
        $this->assertSame((string) self::USER, (string) $gift['ref_id']);
        $this->assertSame('active', $gift['status']);

        $this->assertSame(1, (int) $this->wpdb()->grants[$referrerGrant]['referral_gifts']);
        $this->assertSame(self::LEVEL, (int) $this->wpdb()->members[self::OTHER]['level_id']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $this->wpdb()->members[self::OTHER]['member_number']);

        $this->assertContains('START TRANSACTION', $this->wpdb()->queries);
        $this->assertContains('COMMIT', $this->wpdb()->queries);

        $memberLocks = array_values(array_filter(
            $this->wpdb()->queries,
            static fn (string $query): bool => str_contains($query, 'FOR UPDATE')
                && str_contains($query, 'hwe_memberships_members'),
        ));
        $this->assertCount(2, $memberLocks, 'Se bloquean el referidor y el referido.');
    }

    public function testGrantReferralGiftIsIdempotentPerRefereeAndStopsAtTheCap(): void
    {
        Functions\when('get_userdata')->alias(
            fn (int $id) => in_array($id, [self::USER, self::OTHER, 9], true) ? (object) ['ID' => $id] : false
        );

        $this->wpdb()->seedGrant([
            'user_id'         => self::USER,
            'level_id'        => self::LEVEL,
            'idempotency_key' => 'referrer:active',
            'starts_at'       => $this->day(-1),
            'ends_at'         => $this->day(30),
            'status'          => 'active',
        ]);

        $first = \HWE_Memberships_Grants::grantReferralGift(self::USER, self::OTHER, self::LEVEL, 30, 1);
        $this->assertSame('granted', $first['reason']);

        $duplicate = \HWE_Memberships_Grants::grantReferralGift(self::USER, self::OTHER, self::LEVEL, 30, 1);
        $this->assertSame('duplicate', $duplicate['reason'], 'Una sola vez por referido.');

        $capped = \HWE_Memberships_Grants::grantReferralGift(self::USER, 9, self::LEVEL, 30, 1);
        $this->assertSame('limit', $capped['reason']);
        $this->assertSame(1, $capped['used']);
        $this->assertNull($this->wpdb()->grantByKey('gift:9'));
        $this->assertSame(1, count(array_filter(
            $this->wpdb()->grants,
            static fn (array $row): bool => 'referral_gift' === (string) ($row['origin'] ?? '')
        )));
    }

    public function testGrantReferralGiftRefusesAFrozenReferrerAndRollsBack(): void
    {
        $this->wpdb()->seedMember(self::USER, ['frozen_at' => $this->day(0)]);
        $this->wpdb()->seedGrant([
            'user_id'         => self::USER,
            'level_id'        => self::LEVEL,
            'idempotency_key' => 'referrer:active',
            'starts_at'       => $this->day(-1),
            'ends_at'         => $this->day(30),
            'status'          => 'active',
        ]);

        $result = \HWE_Memberships_Grants::grantReferralGift(self::USER, self::OTHER, self::LEVEL, 30, 2);

        $this->assertSame('frozen', $result['reason']);
        $this->assertNull($this->wpdb()->grantByKey('gift:' . self::OTHER));
        $this->assertContains('ROLLBACK', $this->wpdb()->queries);
        $this->assertNotContains('COMMIT', $this->wpdb()->queries);
    }

    public function testRowByIdReturnsTheGrantEvenWhenItIsNoLongerLive(): void
    {
        $id = $this->grant(self::USER, self::LEVEL, 30, 'order', 'row:by:id');
        $this->assertIsInt($id);

        $live = \HWE_Memberships_Grants::rowById($id);
        $this->assertNotNull($live);
        $this->assertSame('row:by:id', $live['key']);
        $this->assertSame('order', $live['origin']);
        $this->assertSame('active', $live['status']);
        $this->assertSame(self::USER, $live['user_id']);

        $this->assertTrue(\HWE_Memberships_Grants::revokeByKey('row:by:id'));
        $revoked = \HWE_Memberships_Grants::rowById($id);
        $this->assertNotNull($revoked, 'La fila se conserva para revertir sus puntos.');
        $this->assertSame('revoked', $revoked['status']);

        $this->assertNull(\HWE_Memberships_Grants::rowById(0));
        $this->assertNull(\HWE_Memberships_Grants::rowById(999));
    }

    // ── Número de socio y datos ─────────────────────────────────────────────

    public function testMemberNumberIsUniqueStableAndNotTheUserId(): void
    {
        $this->grant(self::USER, self::LEVEL, 30, 'order', 'user:first');
        $firstNumber = $this->wpdb()->members[self::USER]['member_number'];
        $firstSince  = $this->wpdb()->members[self::USER]['member_since'];
        $this->now  += 10 * DAY_IN_SECONDS;

        $this->grant(self::OTHER, self::LEVEL, 30, 'order', 'other:first');
        $otherNumber = $this->wpdb()->members[self::OTHER]['member_number'];
        $this->grant(self::USER, self::LEVEL2, 30, 'order', 'user:second');

        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $firstNumber);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $otherNumber);
        $this->assertNotSame($firstNumber, $otherNumber);
        $this->assertNotSame((string) self::USER, (string) $firstNumber);
        $this->assertSame($firstNumber, $this->wpdb()->members[self::USER]['member_number']);
        $this->assertSame($firstSince, $this->wpdb()->members[self::USER]['member_since']);
    }

    public function testInvalidInputIsRejectedBeforeOpeningATransaction(): void
    {
        $this->assertNull($this->grant(self::USER, 999, 30, 'order', 'bad-level'));
        $this->assertNull($this->grant(999, self::LEVEL, 30, 'order', 'bad-user'));
        $this->assertNull($this->grant(self::USER, self::LEVEL, -1, 'order', 'bad-days'));
        $this->assertNull($this->grant(self::USER, self::LEVEL, 30, 'order', ''));

        $this->assertSame([], $this->wpdb()->grants);
        $this->assertSame([], $this->wpdb()->members);
        $this->assertNotEmpty($this->logged);
        $this->assertNotContains('START TRANSACTION', $this->wpdb()->queries);
    }
}
