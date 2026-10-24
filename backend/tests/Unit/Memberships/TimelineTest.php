<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use DateTimeImmutable;
use DateTimeZone;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Línea de tiempo de concesiones (`HWE_Memberships_Timeline`), lógica pura y fechas fijas en UTC.
 *
 * Cubre la tabla de casos de §5.4: primera concesión, renovación encadenada, mejora que aplaza
 * el tiempo inferior, bajada programada, varias mejoras y una bajada, la indefinida que bloquea
 * la cola, la revocación que no mueve las demás, el desplazamiento por congelación, el nivel
 * efectivo en los bordes exactos y el tiempo programado por nivel.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TimelineTest extends TestCase
{
    private const DAY = 86400;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadOwnFile('plugins/hwe-memberships/includes/Timeline.php');
    }

    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    /**
     * Concesión del modelo en memoria.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function grant(
        string $key,
        int $levelId,
        int $rank,
        int $days,
        ?string $start,
        ?string $end = null,
        string $status = 'active',
        array $extra = []
    ): array {
        return array_merge(
            [
                'key'      => $key,
                'level_id' => $levelId,
                'rank'     => $rank,
                'days'     => $days,
                'starts_at' => null === $start ? null : $this->at($start),
                'ends_at'   => null === $end ? null : $this->at($end),
                'status'    => $status,
            ],
            $extra
        );
    }

    /**
     * @param list<array<string,mixed>> $grants
     * @return array<string,mixed>|null
     */
    private function byKey(array $grants, string $key): ?array
    {
        foreach ($grants as $grant) {
            if ($grant['key'] === $key) {
                return $grant;
            }
        }
        return null;
    }

    /** Compara fechas por su valor en UTC (no por identidad de objeto). */
    private function assertMoment(?string $expected, ?DateTimeImmutable $actual, string $message = ''): void
    {
        if (null === $expected) {
            $this->assertNull($actual, $message);
            return;
        }
        $this->assertNotNull($actual, $message);
        $this->assertSame('UTC', $actual->getTimezone()->getName(), 'Todas las fechas del modelo van en UTC.');
        $this->assertSame(
            $this->at($expected)->format('Y-m-d H:i:s'),
            $actual->format('Y-m-d H:i:s'),
            $message
        );
    }

    // --- colocación ----------------------------------------------------------------------------

    public function testTheFirstGrantStartsNowAndEndsAfterItsPaidDays(): void
    {
        $now = $this->at('2026-01-01 12:00:00');

        $out = \HWE_Memberships_Timeline::place(
            [],
            ['key' => 'order:1:item:1', 'level_id' => 10, 'rank' => 5, 'days' => 30],
            $now
        );

        $this->assertCount(1, $out);
        $grant = $this->byKey($out, 'order:1:item:1');
        $this->assertNotNull($grant);
        $this->assertSame('active', $grant['status']);
        $this->assertMoment('2026-01-01 12:00:00', $grant['starts_at']);
        $this->assertMoment('2026-01-31 12:00:00', $grant['ends_at']);
        $this->assertSame(30, $grant['days']);
    }

    public function testPlacingTheSameKeyTwiceIsIdempotent(): void
    {
        $new = ['key' => 'order:7:item:1', 'level_id' => 10, 'rank' => 5, 'days' => 30];
        $now = $this->at('2026-01-01 00:00:00');

        $first  = \HWE_Memberships_Timeline::place([], $new, $now);
        $second = \HWE_Memberships_Timeline::place($first, $new, $this->at('2026-01-10 00:00:00'));

        $this->assertCount(1, $second);
        $grant = $this->byKey($second, 'order:7:item:1');
        $this->assertNotNull($grant);
        $this->assertMoment('2026-01-01 00:00:00', $grant['starts_at']);
        $this->assertMoment('2026-01-31 00:00:00', $grant['ends_at']);
    }

    public function testRenewingTheSameLevelChainsAfterItsCurrentEnd(): void
    {
        $grants = [$this->grant('a', 10, 5, 30, '2026-01-01 00:00:00', '2026-01-31 00:00:00')];

        $out = \HWE_Memberships_Timeline::place(
            $grants,
            ['key' => 'b', 'level_id' => 10, 'rank' => 5, 'days' => 30],
            $this->at('2026-01-11 00:00:00')
        );

        $first = $this->byKey($out, 'a');
        $this->assertNotNull($first);
        $this->assertSame('active', $first['status']);
        $this->assertMoment('2026-01-01 00:00:00', $first['starts_at']);
        $this->assertMoment('2026-01-31 00:00:00', $first['ends_at']);

        $renewal = $this->byKey($out, 'b');
        $this->assertNotNull($renewal);
        $this->assertSame('scheduled', $renewal['status']);
        $this->assertMoment('2026-01-31 00:00:00', $renewal['starts_at']);
        $this->assertMoment('2026-03-02 00:00:00', $renewal['ends_at']);
    }

    public function testUpgradingStartsNowAndDefersTheLowerRemainingTime(): void
    {
        $grants = [$this->grant('low', 10, 5, 30, '2026-01-01 00:00:00', '2026-01-31 00:00:00')];

        $out = \HWE_Memberships_Timeline::place(
            $grants,
            ['key' => 'high', 'level_id' => 20, 'rank' => 10, 'days' => 15],
            $this->at('2026-01-11 00:00:00')
        );

        $high = $this->byKey($out, 'high');
        $this->assertNotNull($high);
        $this->assertSame('active', $high['status']);
        $this->assertMoment('2026-01-11 00:00:00', $high['starts_at']);
        $this->assertMoment('2026-01-26 00:00:00', $high['ends_at']);

        $low = $this->byKey($out, 'low');
        $this->assertNotNull($low);
        $this->assertSame('scheduled', $low['status']);
        $this->assertMoment('2026-01-26 00:00:00', $low['starts_at'], 'Solo se aplaza el tiempo que quedaba.');
        $this->assertMoment('2026-02-15 00:00:00', $low['ends_at'], 'Se conservan los 20 días que le quedaban.');
    }

    public function testDowngradingIsScheduledAfterTheHigherCoverage(): void
    {
        $grants = [$this->grant('high', 20, 10, 30, '2026-01-01 00:00:00', '2026-01-31 00:00:00')];

        $out = \HWE_Memberships_Timeline::place(
            $grants,
            ['key' => 'low', 'level_id' => 10, 'rank' => 5, 'days' => 10],
            $this->at('2026-01-11 00:00:00')
        );

        $high = $this->byKey($out, 'high');
        $this->assertNotNull($high);
        $this->assertMoment('2026-01-01 00:00:00', $high['starts_at']);
        $this->assertMoment('2026-01-31 00:00:00', $high['ends_at']);

        $low = $this->byKey($out, 'low');
        $this->assertNotNull($low);
        $this->assertSame('scheduled', $low['status']);
        $this->assertMoment('2026-01-31 00:00:00', $low['starts_at']);
        $this->assertMoment('2026-02-10 00:00:00', $low['ends_at']);
    }

    public function testTwoUpgradesAndADowngradeKeepTheOrderAndThePaidTime(): void
    {
        $now = $this->at('2026-01-01 00:00:00');

        $grants = \HWE_Memberships_Timeline::place([], ['key' => 'l5', 'level_id' => 5, 'rank' => 5, 'days' => 30], $now);
        $grants = \HWE_Memberships_Timeline::place($grants, ['key' => 'l10', 'level_id' => 10, 'rank' => 10, 'days' => 30], $now);
        $grants = \HWE_Memberships_Timeline::place($grants, ['key' => 'l20', 'level_id' => 20, 'rank' => 20, 'days' => 30], $now);
        $grants = \HWE_Memberships_Timeline::place($grants, ['key' => 'l5b', 'level_id' => 5, 'rank' => 5, 'days' => 30], $now);

        $this->assertCount(4, $grants);

        $l20 = $this->byKey($grants, 'l20');
        $this->assertNotNull($l20);
        $this->assertSame('active', $l20['status']);
        $this->assertMoment('2026-01-01 00:00:00', $l20['starts_at']);
        $this->assertMoment('2026-01-31 00:00:00', $l20['ends_at']);

        $l10 = $this->byKey($grants, 'l10');
        $this->assertNotNull($l10);
        $this->assertMoment('2026-01-31 00:00:00', $l10['starts_at']);
        $this->assertMoment('2026-03-02 00:00:00', $l10['ends_at']);

        $l5 = $this->byKey($grants, 'l5');
        $this->assertNotNull($l5);
        $this->assertMoment('2026-03-02 00:00:00', $l5['starts_at']);
        $this->assertMoment('2026-04-01 00:00:00', $l5['ends_at']);

        $l5b = $this->byKey($grants, 'l5b');
        $this->assertNotNull($l5b);
        $this->assertSame('scheduled', $l5b['status']);
        $this->assertMoment('2026-04-01 00:00:00', $l5b['starts_at']);
        $this->assertMoment('2026-05-01 00:00:00', $l5b['ends_at']);
    }

    public function testAnIndefiniteGrantBlocksTheLowerQueueAndRevokingItFreesTheQueue(): void
    {
        $now = $this->at('2026-01-01 00:00:00');

        $grants = \HWE_Memberships_Timeline::place(
            [$this->grant('low', 10, 5, 30, '2026-01-01 00:00:00', '2026-01-31 00:00:00')],
            ['key' => 'forever', 'level_id' => 20, 'rank' => 10, 'days' => 0],
            $now
        );

        $forever = $this->byKey($grants, 'forever');
        $this->assertNotNull($forever);
        $this->assertSame('active', $forever['status']);
        $this->assertMoment('2026-01-01 00:00:00', $forever['starts_at']);
        $this->assertNull($forever['ends_at']);

        $low = $this->byKey($grants, 'low');
        $this->assertNotNull($low);
        $this->assertSame('scheduled', $low['status']);
        $this->assertNull($low['starts_at'], 'La indefinida deja la cola inferior sin fecha.');
        $this->assertNull($low['ends_at']);

        $out = \HWE_Memberships_Timeline::revoke($grants, 'forever', $this->at('2026-01-11 00:00:00'));

        $revoked = $this->byKey($out, 'forever');
        $this->assertNotNull($revoked);
        $this->assertSame('ended', $revoked['status']);
        $this->assertMoment('2026-01-11 00:00:00', $revoked['ends_at']);

        $freed = $this->byKey($out, 'low');
        $this->assertNotNull($freed);
        $this->assertMoment('2026-01-11 00:00:00', $freed['starts_at'], 'La cola arranca al liberarse.');
        $this->assertMoment('2026-02-10 00:00:00', $freed['ends_at'], 'Conserva sus 30 días pagados.');
    }

    public function testAnIndefiniteUpgradeKeepsTheExactRemainingSecondsOfALowerActiveGrant(): void
    {
        $grants = \HWE_Memberships_Timeline::place(
            [$this->grant('low', 10, 5, 30, '2026-01-01 00:00:00', '2026-01-31 00:00:00')],
            ['key' => 'forever', 'level_id' => 20, 'rank' => 10, 'days' => 0],
            $this->at('2026-01-11 12:00:00')
        );

        $low = $this->byKey($grants, 'low');
        $this->assertNotNull($low);
        $this->assertSame('scheduled', $low['status']);
        $this->assertNull($low['starts_at']);
        $this->assertSame(
            19 * self::DAY + 12 * 3600,
            $low['queued_seconds'],
            'Se guarda el tiempo exacto que quedaba, no los días completos.'
        );

        $out = \HWE_Memberships_Timeline::revoke($grants, 'forever', $this->at('2026-01-11 12:00:00'));

        $freed = $this->byKey($out, 'low');
        $this->assertNotNull($freed);
        $this->assertMoment('2026-01-11 12:00:00', $freed['starts_at']);
        $this->assertMoment('2026-01-31 00:00:00', $freed['ends_at'], 'Se conserva hasta el segundo.');
    }

    public function testRevokingAnActiveGrantDoesNotMoveTheOthers(): void
    {
        $now    = $this->at('2026-01-01 00:00:00');
        $grants = \HWE_Memberships_Timeline::place([], ['key' => 'low', 'level_id' => 10, 'rank' => 5, 'days' => 30], $now);
        $grants = \HWE_Memberships_Timeline::place($grants, ['key' => 'high', 'level_id' => 20, 'rank' => 10, 'days' => 30], $now);

        $out = \HWE_Memberships_Timeline::revoke($grants, 'high', $this->at('2026-01-11 00:00:00'));

        $high = $this->byKey($out, 'high');
        $this->assertNotNull($high);
        $this->assertSame('ended', $high['status']);
        $this->assertMoment('2026-01-11 00:00:00', $high['ends_at']);

        $low = $this->byKey($out, 'low');
        $this->assertNotNull($low);
        $this->assertMoment('2026-01-31 00:00:00', $low['starts_at'], 'La revocación no recorre las demás.');
        $this->assertMoment('2026-03-02 00:00:00', $low['ends_at']);
    }

    public function testRevokingAScheduledGrantRemovesIt(): void
    {
        $grants = [$this->grant('queued', 10, 5, 30, '2026-01-31 00:00:00', '2026-03-02 00:00:00', 'scheduled')];

        $this->assertSame([], \HWE_Memberships_Timeline::revoke($grants, 'queued', $this->at('2026-01-11 00:00:00')));
    }

    public function testRevokingAnUnknownKeyLeavesTheTimelineUntouched(): void
    {
        $grants = [$this->grant('a', 10, 5, 30, '2026-01-01 00:00:00', '2026-01-31 00:00:00')];

        $this->assertSame($grants, \HWE_Memberships_Timeline::revoke($grants, 'nope', $this->at('2026-01-11 00:00:00')));
    }

    // --- congelación ---------------------------------------------------------------------------

    public function testShiftMovesOnlyTheGrantsThatHaveNotEnded(): void
    {
        $week = 7 * self::DAY;
        $grants = [
            $this->grant('active', 10, 5, 30, '2026-01-01 00:00:00', '2026-01-31 00:00:00'),
            $this->grant('future', 10, 5, 30, '2026-01-31 00:00:00', '2026-03-02 00:00:00', 'scheduled'),
            $this->grant('ended', 10, 5, 30, '2025-12-02 00:00:00', '2026-01-01 00:00:00', 'ended'),
            $this->grant('revoked', 10, 5, 30, '2026-01-01 00:00:00', '2026-01-11 00:00:00', 'revoked'),
            $this->grant('undated', 10, 5, 20, null, null, 'scheduled', ['queued_seconds' => 20 * self::DAY]),
        ];

        $out = \HWE_Memberships_Timeline::shift($grants, $week);

        $active = $this->byKey($out, 'active');
        $this->assertNotNull($active);
        $this->assertMoment('2026-01-01 00:00:00', $active['starts_at'], 'Un inicio pasado no se mueve.');
        $this->assertMoment('2026-02-07 00:00:00', $active['ends_at']);

        $future = $this->byKey($out, 'future');
        $this->assertNotNull($future);
        $this->assertMoment('2026-02-07 00:00:00', $future['starts_at']);
        $this->assertMoment('2026-03-09 00:00:00', $future['ends_at']);

        $ended = $this->byKey($out, 'ended');
        $this->assertNotNull($ended);
        $this->assertMoment('2025-12-02 00:00:00', $ended['starts_at']);
        $this->assertMoment('2026-01-01 00:00:00', $ended['ends_at']);

        $revoked = $this->byKey($out, 'revoked');
        $this->assertNotNull($revoked);
        $this->assertMoment('2026-01-11 00:00:00', $revoked['ends_at']);

        $undated = $this->byKey($out, 'undated');
        $this->assertNotNull($undated);
        $this->assertNull($undated['starts_at']);
        $this->assertNull($undated['ends_at']);
        $this->assertSame(20 * self::DAY, $undated['queued_seconds']);
    }

    public function testShiftSinceMovesEachGrantByItsOwnFrozenTime(): void
    {
        $grants = [
            $this->grant('before', 10, 5, 30, '2026-01-01 00:00:00', '2026-01-31 00:00:00'),
            $this->grant('during', 10, 5, 30, '2026-01-15 00:00:00', '2026-02-14 00:00:00'),
            $this->grant('future', 20, 10, 30, '2026-03-01 00:00:00', '2026-03-31 00:00:00', 'scheduled'),
            $this->grant('undated', 10, 5, 20, null, null, 'scheduled', ['queued_seconds' => 20 * self::DAY]),
            $this->grant('ended', 10, 5, 30, '2025-12-01 00:00:00', '2026-01-01 00:00:00', 'ended'),
        ];

        $out = \HWE_Memberships_Timeline::shiftSince(
            $grants,
            [
                'before'  => $this->at('2026-01-10 00:00:00'),
                'during'  => $this->at('2026-01-15 00:00:00'),
                'future'  => $this->at('2026-01-10 00:00:00'),
                'undated' => $this->at('2026-01-10 00:00:00'),
                'ended'   => $this->at('2026-01-10 00:00:00'),
            ],
            $this->at('2026-01-20 00:00:00')
        );

        $before = $this->byKey($out, 'before');
        $this->assertNotNull($before);
        $this->assertMoment('2026-01-01 00:00:00', $before['starts_at'], 'Una activa ya empezada no mueve su inicio.');
        $this->assertMoment('2026-02-10 00:00:00', $before['ends_at'], 'Existió toda la congelación: 10 días.');

        $during = $this->byKey($out, 'during');
        $this->assertNotNull($during);
        $this->assertMoment('2026-01-15 00:00:00', $during['starts_at']);
        $this->assertMoment('2026-02-19 00:00:00', $during['ends_at'], 'Solo existió los últimos 5 días de la congelación.');

        $future = $this->byKey($out, 'future');
        $this->assertNotNull($future);
        $this->assertMoment('2026-03-11 00:00:00', $future['starts_at']);
        $this->assertMoment('2026-04-10 00:00:00', $future['ends_at'], 'Conserva su duración pagada.');

        $undated = $this->byKey($out, 'undated');
        $this->assertNotNull($undated);
        $this->assertNull($undated['starts_at']);
        $this->assertNull($undated['ends_at']);
        $this->assertSame(20 * self::DAY, $undated['queued_seconds']);

        $ended = $this->byKey($out, 'ended');
        $this->assertNotNull($ended);
        $this->assertMoment('2025-12-01 00:00:00', $ended['starts_at']);
        $this->assertMoment('2026-01-01 00:00:00', $ended['ends_at'], 'Lo terminado no se toca.');
    }

    public function testShiftSinceReanchorsAScheduledChainWithoutGapsOrOverlaps(): void
    {
        $grants = [
            $this->grant('current', 20, 10, 30, '2026-01-01 00:00:00', '2026-01-31 00:00:00'),
            $this->grant('renewal', 20, 10, 30, '2026-01-31 00:00:00', '2026-03-02 00:00:00', 'scheduled'),
            $this->grant('later', 10, 5, 30, '2026-03-02 00:00:00', '2026-04-01 00:00:00', 'scheduled'),
        ];

        $out = \HWE_Memberships_Timeline::shiftSince(
            $grants,
            [
                'current' => $this->at('2026-01-10 00:00:00'),
                'renewal' => $this->at('2026-01-15 00:00:00'),
                'later'   => $this->at('2026-01-15 00:00:00'),
            ],
            $this->at('2026-01-20 00:00:00')
        );

        $current = $this->byKey($out, 'current');
        $renewal = $this->byKey($out, 'renewal');
        $later   = $this->byKey($out, 'later');
        $this->assertNotNull($current);
        $this->assertNotNull($renewal);
        $this->assertNotNull($later);

        $this->assertMoment('2026-01-01 00:00:00', $current['starts_at']);
        $this->assertMoment('2026-02-10 00:00:00', $current['ends_at']);
        $this->assertMoment(
            '2026-02-10 00:00:00',
            $renewal['starts_at'],
            'La renovación creada durante la congelación sigue pegada al fin desplazado.'
        );
        $this->assertMoment('2026-03-12 00:00:00', $renewal['ends_at']);
        $this->assertMoment(
            '2026-03-12 00:00:00',
            $later['starts_at'],
            'La cadena completa se reancla sin huecos ni solapes.'
        );
        $this->assertMoment('2026-04-11 00:00:00', $later['ends_at']);
    }

    public function testShiftSinceAnchorsEachScheduledGrantToItsPrecedingCoverageNotALaterSibling(): void
    {
        $grants = [
            $this->grant('upgrade', 30, 20, 30, '2026-01-15 00:00:00', '2026-02-14 00:00:00'),
            $this->grant('first', 20, 10, 30, '2026-02-14 00:00:00', '2026-03-16 00:00:00', 'scheduled'),
            $this->grant('second', 20, 10, 30, '2026-03-16 00:00:00', '2026-04-15 00:00:00', 'scheduled'),
        ];

        $out = \HWE_Memberships_Timeline::shiftSince(
            $grants,
            [
                'upgrade' => $this->at('2026-01-15 00:00:00'),
                'first'   => $this->at('2026-01-10 00:00:00'),
                'second'  => $this->at('2026-01-10 00:00:00'),
            ],
            $this->at('2026-01-20 00:00:00')
        );

        $upgrade = $this->byKey($out, 'upgrade');
        $first   = $this->byKey($out, 'first');
        $second  = $this->byKey($out, 'second');
        $this->assertNotNull($upgrade);
        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $this->assertMoment('2026-02-19 00:00:00', $upgrade['ends_at']);
        $this->assertMoment('2026-02-19 00:00:00', $first['starts_at'], 'La primera programada sigue a la mejora, no a su hermana.');
        $this->assertMoment('2026-03-21 00:00:00', $first['ends_at']);
        $this->assertMoment('2026-03-21 00:00:00', $second['starts_at']);
        $this->assertMoment('2026-04-20 00:00:00', $second['ends_at']);
    }

    // --- nivel efectivo ------------------------------------------------------------------------

    public function testEffectiveUsesTheHighestRankAndIncludesTheStartExcludesTheEnd(): void
    {
        $grants = [
            $this->grant('low', 10, 5, 30, '2026-01-01 00:00:00', '2026-01-31 00:00:00'),
            $this->grant('high', 20, 10, 10, '2026-01-11 00:00:00', '2026-01-21 00:00:00'),
            $this->grant('revoked', 30, 30, 30, '2026-01-01 00:00:00', '2026-01-31 00:00:00', 'revoked'),
        ];

        $this->assertSame('low', \HWE_Memberships_Timeline::effective($grants, $this->at('2026-01-01 00:00:00'))['key']);
        $this->assertSame('high', \HWE_Memberships_Timeline::effective($grants, $this->at('2026-01-11 00:00:00'))['key']);
        $this->assertSame('high', \HWE_Memberships_Timeline::effective($grants, $this->at('2026-01-20 23:59:59'))['key']);
        $this->assertSame('low', \HWE_Memberships_Timeline::effective($grants, $this->at('2026-01-21 00:00:00'))['key'], 'El fin se excluye.');
        $this->assertSame('low', \HWE_Memberships_Timeline::effective($grants, $this->at('2026-01-30 23:59:59'))['key']);
        $this->assertNull(\HWE_Memberships_Timeline::effective($grants, $this->at('2026-01-31 00:00:00')));
        $this->assertNull(\HWE_Memberships_Timeline::effective($grants, $this->at('2025-12-31 23:59:59')));
    }

    public function testEffectiveIgnoresGrantsThatNeverStarted(): void
    {
        $grants = [$this->grant('future', 10, 5, 30, '2026-01-31 00:00:00', '2026-03-02 00:00:00', 'scheduled')];

        $this->assertNull(\HWE_Memberships_Timeline::effective($grants, $this->at('2026-01-11 00:00:00')));
    }

    // --- cola ----------------------------------------------------------------------------------

    public function testQueueAddsTheScheduledTimePerLevel(): void
    {
        $grants = [
            $this->grant('current', 20, 10, 30, '2026-01-01 00:00:00', '2026-01-31 00:00:00'),
            $this->grant('a', 10, 5, 30, '2026-01-31 00:00:00', '2026-03-02 00:00:00', 'scheduled'),
            $this->grant('b', 10, 5, 30, '2026-03-02 00:00:00', '2026-04-01 00:00:00', 'scheduled'),
        ];

        $queue = \HWE_Memberships_Timeline::queue($grants, $this->at('2026-01-11 00:00:00'));

        $this->assertCount(1, $queue);
        $entry = $queue[0];
        $this->assertSame(10, $entry['level_id']);
        $this->assertSame(5, $entry['rank']);
        $this->assertSame(60 * self::DAY, $entry['seconds']);
        $this->assertSame(60.0, $entry['days']);
        $this->assertMoment('2026-01-31 00:00:00', $entry['starts_at']);
        $this->assertMoment('2026-04-01 00:00:00', $entry['ends_at']);
        $this->assertFalse($entry['undated']);
        $this->assertFalse($entry['infinite']);
    }

    public function testQueueReportsUndatedAndIndefiniteTime(): void
    {
        $grants = [
            $this->grant('forever', 20, 10, 0, '2026-01-01 00:00:00', null, 'active'),
            $this->grant('blocked', 10, 5, 30, null, null, 'scheduled', ['queued_seconds' => 30 * self::DAY]),
            $this->grant('late-forever', 30, 20, 0, '2026-01-31 00:00:00', null, 'scheduled'),
        ];

        $queue = \HWE_Memberships_Timeline::queue($grants, $this->at('2026-01-11 00:00:00'));

        $this->assertCount(2, $queue);

        $blocked = null;
        $late    = null;
        foreach ($queue as $entry) {
            if (10 === $entry['level_id']) {
                $blocked = $entry;
            }
            if (30 === $entry['level_id']) {
                $late = $entry;
            }
        }

        $this->assertNotNull($blocked);
        $this->assertTrue($blocked['undated']);
        $this->assertFalse($blocked['infinite']);
        $this->assertSame(30 * self::DAY, $blocked['seconds']);
        $this->assertSame(30.0, $blocked['days']);
        $this->assertNull($blocked['starts_at']);
        $this->assertNull($blocked['ends_at']);

        $this->assertNotNull($late);
        $this->assertFalse($late['undated']);
        $this->assertTrue($late['infinite']);
        $this->assertSame(0, $late['seconds']);
        $this->assertSame(0.0, $late['days']);
        $this->assertMoment('2026-01-31 00:00:00', $late['starts_at']);
        $this->assertNull($late['ends_at']);
    }
}
