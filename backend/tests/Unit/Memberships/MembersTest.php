<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TestMembershipsServiceWpdb;

require_once HWE_BACKEND_DIR . '/tests/Stubs/wp-memberships-service-wpdb.php';

/**
 * Lectura del estado de un socio (`HWE_Memberships_Members`): nivel efectivo, fin, cola de
 * tiempo programado, número de socio y congelación.
 *
 * Lo importante aquí es que sea una lectura **calculada por fechas**: no escribe nada, no
 * depende de que la tarea diaria haya materializado una caducidad y, si la caché de `members`
 * se quedó vieja, manda el cálculo de las concesiones. La congelación se superpone a las
 * fechas: mientras dura no hay nivel efectivo.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MembersTest extends TestCase
{
    private const USER  = 7;
    private const OTHER = 8;
    private const LEVEL  = 101; // rango 5
    private const LEVEL2 = 102; // rango 10
    private const LEVEL3 = 103; // rango 20

    private int $now = 1_800_000_000;
    /** @var array<int,\WP_Post> */
    private array $levelPosts = [];
    /** @var array<int,array<string,mixed>> */
    private array $levelMeta = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->now        = 1_800_000_000;
        $this->levelPosts = [];
        $this->levelMeta  = [];

        $GLOBALS['wpdb'] = new TestMembershipsServiceWpdb();

        $this->loadOwnFile('plugins/hwe-memberships/includes/Schema.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Levels.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Timeline.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Events.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Members.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Grants.php');

        $this->makeLevel(self::LEVEL, 5);
        $this->makeLevel(self::LEVEL2, 10);
        $this->makeLevel(self::LEVEL3, 20);

        Functions\when('get_post')->alias(fn (int $id) => $this->levelPosts[$id] ?? null);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->levelMeta[$id][$key] ?? ''
        );
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('error_log')->alias(static fn (string $message): bool => true);
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
        $this->levelPosts[$id] = $post;
        $this->levelMeta[$id]  = [\HWE_Memberships_Levels::RANK_META => (string) $rank];
    }

    private function day(int $days): string
    {
        return gmdate('Y-m-d H:i:s', $this->now + $days * DAY_IN_SECONDS);
    }

    private function at(string $mysql): DateTimeImmutable
    {
        return new DateTimeImmutable($mysql, new DateTimeZone('UTC'));
    }

    private function assertDateMoment(string $expected, ?DateTimeImmutable $actual, string $message = ''): void
    {
        $this->assertNotNull($actual, $message);
        $this->assertSame('UTC', $actual->getTimezone()->getName(), $message);
        $this->assertSame($expected, $actual->format('Y-m-d H:i:s'), $message);
    }

    private function assertNoWrites(): void
    {
        $this->assertSame([], $this->wpdb()->writeQueries(), 'state() no puede escribir ni abrir transacción.');
    }

    // --- caché por petición --------------------------------------------------------------------

    public function testStateReadsTheMemberAndItsGrantsOncePerRequest(): void
    {
        $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'level_id' => self::LEVEL,
            'idempotency_key' => 'memo',
            'starts_at' => $this->day(0),
            'ends_at' => $this->day(30),
        ]);
        $this->wpdb()->queries = [];

        $first  = \HWE_Memberships_Members::state(self::USER);
        $second = \HWE_Memberships_Members::state(self::USER);
        $third  = \HWE_Memberships_Members::state(self::USER);

        $reads = array_values(array_filter(
            $this->wpdb()->queries,
            static fn (string $sql): bool => str_contains($sql, 'hwe_memberships_members')
                || str_contains($sql, 'hwe_memberships_grants')
        ));

        $this->assertCount(2, $reads, 'Una lectura de la fila del socio y otra de sus concesiones.');
        $this->assertSame($first['grant_key'], $second['grant_key']);
        $this->assertSame($first['grant_key'], $third['grant_key']);
        $this->assertNoWrites();
    }

    public function testAStateWithAnExplicitInstantIsCalculatedForEachInstant(): void
    {
        $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'level_id' => self::LEVEL,
            'idempotency_key' => 'memo:instant',
            'starts_at' => $this->day(0),
            'ends_at' => $this->day(30),
        ]);

        $this->assertSame(
            self::LEVEL,
            \HWE_Memberships_Members::state(self::USER, $this->at($this->day(10)))['level_id']
        );
        $this->assertSame(
            'none',
            \HWE_Memberships_Members::state(self::USER, $this->at($this->day(40)))['status'],
            'Cada instante histórico se calcula por su cuenta.'
        );
    }

    public function testStateWithoutGrantsIsNoneAndDoesNotWrite(): void
    {
        $this->wpdb()->queries = [];

        $state = \HWE_Memberships_Members::state(self::USER);

        foreach (['level_id', 'rank', 'status', 'ends_at', 'queue', 'member_since', 'member_number', 'grant_key'] as $key) {
            $this->assertArrayHasKey($key, $state);
        }
        $this->assertSame(0, $state['level_id']);
        $this->assertSame(0, $state['rank']);
        $this->assertSame('none', $state['status']);
        $this->assertNull($state['ends_at']);
        $this->assertSame([], $state['queue']);
        $this->assertNull($state['member_since']);
        $this->assertSame('', $state['member_number']);
        $this->assertSame('', $state['grant_key']);
        $this->assertNoWrites();
    }

    public function testStateUsesTheHighestRankThatCoversTheInstant(): void
    {
        $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'level_id' => self::LEVEL,
            'idempotency_key' => 'low',
            'starts_at' => $this->day(0),
            'ends_at' => $this->day(30),
        ]);
        $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'level_id' => self::LEVEL2,
            'idempotency_key' => 'high',
            'starts_at' => $this->day(10),
            'ends_at' => $this->day(20),
        ]);

        $atStart = \HWE_Memberships_Members::state(self::USER, $this->at($this->day(0)));
        $this->assertSame(self::LEVEL, $atStart['level_id']);
        $this->assertSame(5, $atStart['rank']);
        $this->assertSame('low', $atStart['grant_key']);

        $atHigh = \HWE_Memberships_Members::state(self::USER, $this->at($this->day(10)));
        $this->assertSame(self::LEVEL2, $atHigh['level_id'], 'El inicio de la superior se incluye.');
        $this->assertSame(10, $atHigh['rank']);
        $this->assertSame('high', $atHigh['grant_key']);
        $this->assertDateMoment($this->day(20), $atHigh['ends_at']);

        $beforeHighEnd = \HWE_Memberships_Members::state(
            self::USER,
            $this->at(gmdate('Y-m-d H:i:s', strtotime($this->day(20) . ' UTC') - 1))
        );
        $this->assertSame('high', $beforeHighEnd['grant_key']);

        $atHighEnd = \HWE_Memberships_Members::state(self::USER, $this->at($this->day(20)));
        $this->assertSame('low', $atHighEnd['grant_key'], 'El fin de la superior se excluye.');
        $this->assertSame(5, $atHighEnd['rank']);

        $atLowEnd = \HWE_Memberships_Members::state(self::USER, $this->at($this->day(30)));
        $this->assertSame('none', $atLowEnd['status']);
        $this->assertSame(0, $atLowEnd['level_id']);
        $this->assertNoWrites();
    }

    public function testStateUsesDatesEvenWhenTheCachedMemberRowIsStale(): void
    {
        $this->wpdb()->seedMember(self::USER, [
            'level_id'      => self::LEVEL,
            'covers_until'  => $this->day(30),
            'member_number' => '000010',
            'member_since'  => $this->day(-60),
        ]);
        $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'level_id' => self::LEVEL,
            'idempotency_key' => 'expired',
            'starts_at' => $this->day(-10),
            'ends_at' => $this->day(-1),
        ]);

        $state = \HWE_Memberships_Members::state(self::USER);

        $this->assertSame('none', $state['status'], 'Manda el cálculo por fechas, no la caché.');
        $this->assertSame(0, $state['level_id']);
        $this->assertSame('', $state['grant_key']);
        $this->assertSame('000010', $state['member_number']);
        $this->assertDateMoment($this->day(-60), $state['member_since']);
        $this->assertSame(self::LEVEL, (int) $this->wpdb()->members[self::USER]['level_id'], 'La lectura no corrige la caché.');
        $this->assertNoWrites();
    }

    public function testStateReportsFrozenAndTheHistoricalStateBeforeTheFreeze(): void
    {
        $this->wpdb()->seedMember(self::USER, [
            'frozen_at'     => $this->day(-5),
            'member_number' => '000777',
            'member_since'  => $this->day(-100),
        ]);
        $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'level_id' => self::LEVEL,
            'idempotency_key' => 'active',
            'starts_at' => $this->day(-10),
            'ends_at' => $this->day(20),
        ]);

        $frozen = \HWE_Memberships_Members::state(self::USER);
        $this->assertSame('frozen', $frozen['status']);
        $this->assertSame(0, $frozen['level_id']);
        $this->assertSame(0, $frozen['rank']);
        $this->assertNull($frozen['ends_at']);
        $this->assertSame('', $frozen['grant_key']);
        $this->assertSame(0, \HWE_Memberships_Members::effectiveRank(self::USER));
        $this->assertTrue(\HWE_Memberships_Members::isFrozen(self::USER));

        $before = \HWE_Memberships_Members::state(self::USER, $this->at($this->day(-7)));
        $this->assertSame('active', $before['status'], 'Antes de la congelación el estado se calcula normal.');
        $this->assertSame(self::LEVEL, $before['level_id']);
        $this->assertDateMoment($this->day(20), $before['ends_at']);

        $this->assertNoWrites();
    }

    public function testStateShowsTheScheduledQueueAndMemberInformation(): void
    {
        $this->wpdb()->seedMember(self::USER, [
            'member_number' => '000123',
            'member_since'  => $this->day(-90),
        ]);
        $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'level_id' => self::LEVEL2,
            'idempotency_key' => 'current',
            'starts_at' => $this->day(0),
            'ends_at' => $this->day(30),
        ]);
        $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'level_id' => self::LEVEL,
            'idempotency_key' => 'queued-1',
            'starts_at' => $this->day(30),
            'ends_at' => $this->day(60),
            'status' => 'scheduled',
        ]);
        $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'level_id' => self::LEVEL,
            'idempotency_key' => 'queued-2',
            'starts_at' => $this->day(60),
            'ends_at' => $this->day(90),
            'status' => 'scheduled',
        ]);

        $state = \HWE_Memberships_Members::state(self::USER, $this->at($this->day(10)));

        $this->assertSame('active', $state['status']);
        $this->assertSame(self::LEVEL2, $state['level_id']);
        $this->assertSame(10, $state['rank']);
        $this->assertSame('current', $state['grant_key']);
        $this->assertDateMoment($this->day(30), $state['ends_at']);
        $this->assertSame('000123', $state['member_number']);
        $this->assertDateMoment($this->day(-90), $state['member_since']);

        $this->assertCount(1, $state['queue'], 'La cola se agrupa por nivel.');
        $entry = $state['queue'][0];
        $this->assertSame(self::LEVEL, $entry['level_id']);
        $this->assertSame(5, $entry['rank']);
        $this->assertSame(60 * DAY_IN_SECONDS, $entry['seconds']);
        $this->assertSame(60.0, $entry['days']);
        $this->assertDateMoment($this->day(30), $entry['starts_at']);
        $this->assertDateMoment($this->day(90), $entry['ends_at']);
        $this->assertFalse($entry['undated']);
        $this->assertFalse($entry['infinite']);

        $this->assertNoWrites();
    }

    public function testScheduledOnlyUserHasNoEffectiveLevelButShowsTheQueue(): void
    {
        $this->wpdb()->seedMember(self::USER, ['member_number' => '000321']);
        $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'level_id' => self::LEVEL,
            'idempotency_key' => 'future',
            'starts_at' => $this->day(30),
            'ends_at' => $this->day(60),
            'status' => 'scheduled',
        ]);

        $state = \HWE_Memberships_Members::state(self::USER);

        $this->assertSame('none', $state['status']);
        $this->assertSame(0, $state['level_id']);
        $this->assertSame(0, $state['rank']);
        $this->assertNull($state['ends_at']);
        $this->assertSame('', $state['grant_key']);
        $this->assertCount(1, $state['queue']);
        $this->assertSame(self::LEVEL, $state['queue'][0]['level_id']);
        $this->assertSame(0, \HWE_Memberships_Members::effectiveRank(self::USER));
    }

    public function testStateIgnoresEndedAndRevokedGrants(): void
    {
        $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'level_id' => self::LEVEL3,
            'idempotency_key' => 'ended',
            'starts_at' => $this->day(0),
            'ends_at' => $this->day(30),
            'status' => 'ended',
        ]);
        $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'level_id' => self::LEVEL3,
            'idempotency_key' => 'revoked',
            'starts_at' => $this->day(0),
            'ends_at' => $this->day(30),
            'status' => 'revoked',
            'revoked_at' => $this->day(-1),
        ]);
        $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'level_id' => self::LEVEL,
            'idempotency_key' => 'live',
            'starts_at' => $this->day(0),
            'ends_at' => $this->day(30),
        ]);

        $state = \HWE_Memberships_Members::state(self::USER);

        $this->assertSame(self::LEVEL, $state['level_id']);
        $this->assertSame(5, $state['rank']);
        $this->assertSame('live', $state['grant_key']);
        $this->assertSame([], $state['queue']);
    }

    public function testAnIndefiniteGrantHasNullEndsAt(): void
    {
        $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'level_id' => self::LEVEL2,
            'idempotency_key' => 'forever',
            'duration_days' => 0,
            'starts_at' => $this->day(-5),
            'ends_at' => null,
        ]);

        $state = \HWE_Memberships_Members::state(self::USER);

        $this->assertSame(self::LEVEL2, $state['level_id']);
        $this->assertSame(10, $state['rank']);
        $this->assertNull($state['ends_at']);
        $this->assertSame('forever', $state['grant_key']);
    }

    public function testEffectiveRankIsZeroForNonexpiredOnlyButKeepsTheLevelRankOtherwise(): void
    {
        $this->wpdb()->seedGrant([
            'user_id' => self::USER,
            'level_id' => self::LEVEL2,
            'idempotency_key' => 'active',
            'starts_at' => $this->day(0),
            'ends_at' => $this->day(30),
        ]);
        $this->wpdb()->seedGrant([
            'user_id' => self::OTHER,
            'level_id' => self::LEVEL3,
            'idempotency_key' => 'expired',
            'starts_at' => $this->day(-10),
            'ends_at' => $this->day(-1),
        ]);

        $this->assertSame(10, \HWE_Memberships_Members::effectiveRank(self::USER));
        $this->assertSame(0, \HWE_Memberships_Members::effectiveRank(self::OTHER), 'Una vencida no da rango.');
        $this->assertSame(0, \HWE_Memberships_Members::effectiveRank(999));
        $this->assertSame(0, \HWE_Memberships_Members::effectiveRank(0));
    }
}
