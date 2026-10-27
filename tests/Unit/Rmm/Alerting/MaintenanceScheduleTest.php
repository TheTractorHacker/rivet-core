<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\Alerting;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Alerting\MaintenanceSchedule as M;

final class MaintenanceScheduleTest extends TestCase
{
    private static function ts(string $utc): int
    {
        return (int) strtotime($utc . ' UTC');
    }

    /** @param array<string,mixed> $o @return array<string,mixed> */
    private function rec(array $o): array
    {
        [$row, $err] = M::validate($o + ['name' => 'w', 'kind' => 'recurring', 'timezone' => 'UTC', 'local_start' => '02:00', 'duration_min' => 60]);
        $this->assertNull($err);
        $this->assertNotNull($row);

        return $row;
    }

    public function testOnceWindowIsHalfOpen(): void
    {
        [$w] = M::validate(['name' => 'x', 'kind' => 'once', 'starts_at' => '2026-10-10T12:00:00Z', 'ends_at' => '2026-10-10T14:00:00Z']);
        $this->assertNotNull($w);
        $this->assertFalse(M::isActive($w, self::ts('2026-10-10 11:59:59')));
        $this->assertTrue(M::isActive($w, self::ts('2026-10-10 12:00:00')));
        $this->assertTrue(M::isActive($w, self::ts('2026-10-10 13:59:59')));
        $this->assertFalse(M::isActive($w, self::ts('2026-10-10 14:00:00')));
        $this->assertSame(self::ts('2026-10-10 14:00:00'), M::currentEnd($w, self::ts('2026-10-10 13:00:00')));
        $this->assertSame(self::ts('2026-10-10 12:00:00'), M::nextStart($w, self::ts('2026-10-10 00:00:00')));
        $this->assertNull(M::nextStart($w, self::ts('2026-10-10 12:00:00')));
    }

    public function testOnceWithAnOffsetOrAWallClockTime(): void
    {
        [$a] = M::validate(['name' => 'x', 'starts_at' => '2026-10-10T14:00:00+02:00', 'ends_at' => '2026-10-10T16:00:00+02:00']);
        $this->assertSame('2026-10-10 12:00:00', $a['starts_at'] ?? null);
        [$b] = M::validate(['name' => 'x', 'timezone' => 'America/New_York', 'starts_at' => '2026-10-10 08:00', 'ends_at' => '2026-10-10 10:00']);
        $this->assertSame('2026-10-10 12:00:00', $b['starts_at'] ?? null, 'EDT is UTC-4');
    }

    public function testDisabledWindowIsNeverActive(): void
    {
        $w = $this->rec(['recur_freq' => 'daily', 'enabled' => false]);
        $this->assertFalse(M::isActive($w, self::ts('2026-10-10 02:30:00')));
        $this->assertNull(M::nextStart($w, 0));
    }

    public function testDailyInAZone(): void
    {
        $w = $this->rec(['recur_freq' => 'daily', 'timezone' => 'America/New_York', 'local_start' => '22:00', 'duration_min' => 120]);
        // 22:00 EDT = 02:00 UTC next day
        $this->assertFalse(M::isActive($w, self::ts('2026-10-10 01:59:59')));
        $this->assertTrue(M::isActive($w, self::ts('2026-10-10 02:00:00')));
        $this->assertTrue(M::isActive($w, self::ts('2026-10-10 03:59:59')));
        $this->assertFalse(M::isActive($w, self::ts('2026-10-10 04:00:00')));
    }

    public function testAWindowThatCrossesMidnightIsActiveOnTheNextLocalDay(): void
    {
        $w = $this->rec(['recur_freq' => 'daily', 'local_start' => '22:00', 'duration_min' => 240]);
        $this->assertTrue(M::isActive($w, self::ts('2026-10-11 01:30:00')));
        $this->assertFalse(M::isActive($w, self::ts('2026-10-11 02:00:00')));
    }

    public function testSpringForwardKeepsTheRealDurationAndShiftsAMissingStart(): void
    {
        // Berlin 2026-03-29: 02:00 CET jumps to 03:00 CEST. 02:30 does not exist; it opens at 03:30 CEST = 01:30 UTC.
        $w = $this->rec(['recur_freq' => 'daily', 'timezone' => 'Europe/Berlin', 'local_start' => '02:30', 'duration_min' => 60]);
        $this->assertFalse(M::isActive($w, self::ts('2026-03-29 01:29:59')));
        $this->assertTrue(M::isActive($w, self::ts('2026-03-29 01:30:00')));
        $this->assertTrue(M::isActive($w, self::ts('2026-03-29 02:29:59')));
        $this->assertFalse(M::isActive($w, self::ts('2026-03-29 02:30:00')), '60 real minutes');
        // the day before and after are ordinary: 02:30 CET = 01:30 UTC, 02:30 CEST = 00:30 UTC
        $this->assertTrue(M::isActive($w, self::ts('2026-03-28 01:45:00')));
        $this->assertTrue(M::isActive($w, self::ts('2026-03-30 00:45:00')));
    }

    public function testFallBackUsesTheFirstOccurrenceOfAnAmbiguousStart(): void
    {
        // Berlin 2026-10-25: 03:00 CEST falls back to 02:00 CET, so 02:30 happens twice. The window opens at the first one (00:30 UTC).
        $w = $this->rec(['recur_freq' => 'daily', 'timezone' => 'Europe/Berlin', 'local_start' => '02:30', 'duration_min' => 90]);
        $this->assertTrue(M::isActive($w, self::ts('2026-10-25 00:30:00')));
        $this->assertTrue(M::isActive($w, self::ts('2026-10-25 01:59:59')), '90 real minutes cover the repeated hour');
        $this->assertFalse(M::isActive($w, self::ts('2026-10-25 02:00:00')));
    }

    public function testMonthlyDay31IsClampedToTheLastDay(): void
    {
        $w = $this->rec(['recur_freq' => 'monthly', 'recur_days' => '31', 'local_start' => '10:00', 'duration_min' => 60]);
        foreach (['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30', '2026-09-30', '2026-12-31', '2028-02-29'] as $d) {
            $this->assertTrue(M::isActive($w, self::ts("$d 10:30:00")), $d);
        }
        foreach (['2026-02-27', '2026-04-29', '2026-03-01', '2028-02-28', '2026-02-28 11:00:00'] as $d) {
            $this->assertFalse(M::isActive($w, self::ts(strlen($d) > 10 ? $d : "$d 10:30:00")), $d);
        }
    }

    public function testMonthlyDay29And30InFebruary(): void
    {
        $w = $this->rec(['recur_freq' => 'monthly', 'recur_days' => '29,30', 'local_start' => '00:00', 'duration_min' => 30]);
        $this->assertTrue(M::isActive($w, self::ts('2026-02-28 00:10:00')), 'common year: both clamp to 28 (one occurrence)');
        $this->assertTrue(M::isActive($w, self::ts('2028-02-29 00:10:00')), 'leap year: 29 and 30 clamp to the 29th');
        $this->assertFalse(M::isActive($w, self::ts('2028-02-28 00:10:00')));
        $this->assertTrue(M::isActive($w, self::ts('2026-04-30 00:10:00')));
        $this->assertTrue(M::isActive($w, self::ts('2026-04-29 00:10:00')));
    }

    public function testMonthlyLastAndSeveralDays(): void
    {
        $w = $this->rec(['recur_freq' => 'monthly', 'recur_days' => 'last,1,15', 'local_start' => '01:00', 'duration_min' => 60]);
        foreach (['2026-02-01', '2026-02-15', '2026-02-28', '2026-11-30', '2028-02-29'] as $d) {
            $this->assertTrue(M::isActive($w, self::ts("$d 01:30:00")), $d);
        }
        $this->assertFalse(M::isActive($w, self::ts('2026-02-14 01:30:00')));
        $this->assertSame('1,15,last', $w['recur_days']);
    }

    public function testAWindowThatCrossesTheEndOfAMonth(): void
    {
        $w = $this->rec(['recur_freq' => 'monthly', 'recur_days' => 'last', 'local_start' => '22:00', 'duration_min' => 300]);
        $this->assertTrue(M::isActive($w, self::ts('2026-02-28 23:00:00')));
        $this->assertTrue(M::isActive($w, self::ts('2026-03-01 02:59:59')));
        $this->assertFalse(M::isActive($w, self::ts('2026-03-01 03:00:00')));
        $this->assertTrue(M::isActive($w, self::ts('2026-12-31 22:00:00')));
        $this->assertTrue(M::isActive($w, self::ts('2027-01-01 02:00:00')), 'and across the year');
    }

    public function testMonthlyEveryThirdMonthCountsFromRecurFrom(): void
    {
        $w = $this->rec(['recur_freq' => 'monthly', 'recur_interval' => 3, 'recur_days' => '15', 'recur_from' => '2026-01-01', 'local_start' => '12:00', 'duration_min' => 60]);
        foreach (['2026-01-15', '2026-04-15', '2026-07-15', '2026-10-15', '2027-01-15'] as $d) {
            $this->assertTrue(M::isActive($w, self::ts("$d 12:30:00")), $d);
        }
        foreach (['2026-02-15', '2026-03-15', '2026-05-15', '2026-11-15'] as $d) {
            $this->assertFalse(M::isActive($w, self::ts("$d 12:30:00")), $d);
        }
    }

    public function testWeeklyEveryOtherWeek(): void
    {
        // 2026-10-05 is a Monday
        $w = $this->rec(['recur_freq' => 'weekly', 'recur_interval' => 2, 'recur_days' => '1,3', 'recur_from' => '2026-10-05', 'local_start' => '03:00', 'duration_min' => 60]);
        foreach (['2026-10-05', '2026-10-07', '2026-10-19', '2026-10-21', '2026-11-02'] as $d) {
            $this->assertTrue(M::isActive($w, self::ts("$d 03:10:00")), $d);
        }
        foreach (['2026-10-06', '2026-10-12', '2026-10-14', '2026-10-26', '2026-10-04'] as $d) {
            $this->assertFalse(M::isActive($w, self::ts("$d 03:10:00")), $d);
        }
    }

    public function testRecurrenceBounds(): void
    {
        $w = $this->rec(['recur_freq' => 'daily', 'recur_from' => '2026-10-10', 'recur_until' => '2026-10-12', 'local_start' => '04:00', 'duration_min' => 30]);
        $this->assertFalse(M::isActive($w, self::ts('2026-10-09 04:10:00')));
        $this->assertTrue(M::isActive($w, self::ts('2026-10-10 04:10:00')));
        $this->assertTrue(M::isActive($w, self::ts('2026-10-12 04:10:00')));
        $this->assertFalse(M::isActive($w, self::ts('2026-10-13 04:10:00')));
        $this->assertSame(self::ts('2026-10-11 04:00:00'), M::nextStart($w, self::ts('2026-10-10 05:00:00')));
        $this->assertNull(M::nextStart($w, self::ts('2026-10-12 05:00:00')));
    }

    public function testDailyEveryThirdDay(): void
    {
        $w = $this->rec(['recur_freq' => 'daily', 'recur_interval' => 3, 'recur_from' => '2026-10-01', 'local_start' => '04:00', 'duration_min' => 30]);
        $this->assertTrue(M::isActive($w, self::ts('2026-10-04 04:10:00')));
        $this->assertTrue(M::isActive($w, self::ts('2026-10-10 04:10:00')));
        $this->assertFalse(M::isActive($w, self::ts('2026-10-11 04:10:00')));
    }

    public function testNextStartAcrossAMonth(): void
    {
        $w = $this->rec(['recur_freq' => 'monthly', 'recur_days' => '31', 'local_start' => '10:00']);
        $this->assertSame(self::ts('2026-02-28 10:00:00'), M::nextStart($w, self::ts('2026-01-31 10:00:00')));
        $this->assertSame(self::ts('2026-03-31 10:00:00'), M::nextStart($w, self::ts('2026-02-28 10:00:00')));
    }

    /** @return list<array{array<string,mixed>,string}> */
    public static function bad(): array
    {
        return [
            [[], 'name'],
            [['name' => 'x', 'mode' => 'pause'], 'mode'],
            [['name' => 'x', 'scope_type' => 'planet'], 'scope_type'],
            [['name' => 'x', 'scope_type' => 'client'], 'scope_id'],
            [['name' => 'x', 'timezone' => 'Mars/Base'], 'timezone'],
            [['name' => 'x', 'kind' => 'sometimes'], 'kind'],
            [['name' => 'x'], 'one-time'],
            [['name' => 'x', 'starts_at' => '2026-10-10T12:00:00Z', 'ends_at' => '2026-10-10T11:00:00Z'], 'after starts_at'],
            [['name' => 'x', 'starts_at' => '2026-10-10T12:00:00Z', 'ends_at' => '2028-10-10T11:00:00Z'], 'days later'],
            [['name' => 'x', 'kind' => 'recurring', 'recur_freq' => 'yearly'], 'recur_freq'],
            [['name' => 'x', 'kind' => 'recurring', 'recur_freq' => 'daily', 'local_start' => '25:00', 'duration_min' => 5], 'local_start'],
            [['name' => 'x', 'kind' => 'recurring', 'recur_freq' => 'daily', 'local_start' => '10:00', 'duration_min' => 0], 'duration_min'],
            [['name' => 'x', 'kind' => 'recurring', 'recur_freq' => 'daily', 'local_start' => '10:00', 'duration_min' => 99999], 'duration_min'],
            [['name' => 'x', 'kind' => 'recurring', 'recur_freq' => 'weekly', 'local_start' => '10:00', 'duration_min' => 5], 'recur_days'],
            [['name' => 'x', 'kind' => 'recurring', 'recur_freq' => 'weekly', 'recur_days' => '8', 'local_start' => '10:00', 'duration_min' => 5], 'recur_days'],
            [['name' => 'x', 'kind' => 'recurring', 'recur_freq' => 'monthly', 'recur_days' => '32', 'local_start' => '10:00', 'duration_min' => 5], 'recur_days'],
            [['name' => 'x', 'kind' => 'recurring', 'recur_freq' => 'daily', 'recur_interval' => 2, 'local_start' => '10:00', 'duration_min' => 5], 'recur_from'],
            [['name' => 'x', 'kind' => 'recurring', 'recur_freq' => 'daily', 'recur_from' => '2026-02-30', 'local_start' => '10:00', 'duration_min' => 5], 'recur_from'],
            [['name' => 'x', 'kind' => 'recurring', 'recur_freq' => 'daily', 'recur_from' => '2026-02-01', 'recur_until' => '2026-01-01', 'local_start' => '10:00', 'duration_min' => 5], 'before'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad')]
    public function testValidationRefuses(array $in, string $needle): void
    {
        [$row, $err] = M::validate($in);
        $this->assertNull($row);
        $this->assertStringContainsString($needle, (string) $err);
    }
}
