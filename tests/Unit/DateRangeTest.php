<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Ui\DateRange;

final class DateRangeTest extends TestCase
{
    private static function at(string $ymdHis, string $tz = 'UTC'): DateTimeImmutable
    {
        return new DateTimeImmutable($ymdHis, new DateTimeZone($tz));
    }

    /** @return array<string,array{string,string,string,string,int}> */
    public static function presetCases(): array
    {
        // [preset, now, expectedFrom, expectedTo, weekStart]
        return [
            'today' => ['today', '2026-03-11 10:00:00', '2026-03-11', '2026-03-11', 1],
            'yesterday' => ['yesterday', '2026-03-11 10:00:00', '2026-03-10', '2026-03-10', 1],
            'yesterday year boundary' => ['yesterday', '2026-01-01 00:00:00', '2025-12-31', '2025-12-31', 1],
            'thisweek monday (Wed)' => ['thisweek', '2026-03-11 10:00:00', '2026-03-09', '2026-03-11', 1],
            'thisweek on the Monday' => ['thisweek', '2026-03-09 10:00:00', '2026-03-09', '2026-03-09', 1],
            'thisweek sunday start (Wed)' => ['thisweek', '2026-03-11 10:00:00', '2026-03-08', '2026-03-11', 7],
            'thisweek sunday start via 0' => ['thisweek', '2026-03-11 10:00:00', '2026-03-08', '2026-03-11', 0],
            'thisweek on a Sunday, monday start' => ['thisweek', '2026-03-08 10:00:00', '2026-03-02', '2026-03-08', 1],
            'thisweek on a Sunday, sunday start' => ['thisweek', '2026-03-08 10:00:00', '2026-03-08', '2026-03-08', 7],
            'thisweek bad weekStart falls back to Monday' => ['thisweek', '2026-03-11 10:00:00', '2026-03-09', '2026-03-11', 9],
            'lastweek monday' => ['lastweek', '2026-03-11 10:00:00', '2026-03-02', '2026-03-08', 1],
            'lastweek sunday' => ['lastweek', '2026-03-11 10:00:00', '2026-03-01', '2026-03-07', 7],
            'lastweek spans the year (Jan 1 2026 = Thu)' => ['lastweek', '2026-01-01 09:00:00', '2025-12-22', '2025-12-28', 1],
            'lastweek spans the year, Sunday start' => ['lastweek', '2026-01-01 09:00:00', '2025-12-21', '2025-12-27', 7],
            'thismonth' => ['thismonth', '2026-03-11 10:00:00', '2026-03-01', '2026-03-11', 1],
            'lastmonth' => ['lastmonth', '2026-03-11 10:00:00', '2026-02-01', '2026-02-28', 1],
            'lastmonth leap year from Jan 31 + March' => ['lastmonth', '2028-03-31 10:00:00', '2028-02-01', '2028-02-29', 1],
            'lastmonth from January' => ['lastmonth', '2026-01-31 10:00:00', '2025-12-01', '2025-12-31', 1],
            'lastmonth from March 31 of a non-leap year' => ['lastmonth', '2026-03-31 10:00:00', '2026-02-01', '2026-02-28', 1],
            'thisyear' => ['thisyear', '2026-03-11 10:00:00', '2026-01-01', '2026-03-11', 1],
            'lastyear' => ['lastyear', '2026-03-11 10:00:00', '2025-01-01', '2025-12-31', 1],
            'thisquarter Q1' => ['thisquarter', '2026-03-31 10:00:00', '2026-01-01', '2026-03-31', 1],
            'thisquarter first day of Q2' => ['thisquarter', '2026-04-01 00:00:00', '2026-04-01', '2026-04-01', 1],
            'thisquarter Q4' => ['thisquarter', '2026-12-31 23:59:59', '2026-10-01', '2026-12-31', 1],
            'lastquarter from Q1 is previous Q4' => ['lastquarter', '2026-02-10 10:00:00', '2025-10-01', '2025-12-31', 1],
            'lastquarter from Q2' => ['lastquarter', '2026-04-01 00:00:00', '2026-01-01', '2026-03-31', 1],
            'lastquarter from Q4' => ['lastquarter', '2026-10-06 10:00:00', '2026-07-01', '2026-09-30', 1],
            'last7' => ['last7', '2026-03-11 10:00:00', '2026-03-05', '2026-03-11', 1],
            'last14' => ['last14', '2026-03-11 10:00:00', '2026-02-26', '2026-03-11', 1],
            'last30' => ['last30', '2026-03-11 10:00:00', '2026-02-10', '2026-03-11', 1],
            'last90 across leap day' => ['last90', '2028-03-31 10:00:00', '2028-01-02', '2028-03-31', 1],
            'last12months' => ['last12months', '2026-03-11 10:00:00', '2025-04-01', '2026-03-11', 1],
            'last12months in January' => ['last12months', '2026-01-15 10:00:00', '2025-02-01', '2026-01-15', 1],
            'last12months in December' => ['last12months', '2026-12-31 10:00:00', '2026-01-01', '2026-12-31', 1],
            'next7' => ['next7', '2026-03-11 10:00:00', '2026-03-11', '2026-03-17', 1],
            'next30 across the year' => ['next30', '2026-12-20 10:00:00', '2026-12-20', '2027-01-18', 1],
            'alltime' => ['alltime', '2026-03-11 10:00:00', '1970-01-01', '2099-12-31', 1],
            'unknown preset' => ['bogus', '2026-03-11 10:00:00', '1970-01-01', '2099-12-31', 1],
            'empty preset' => ['', '2026-03-11 10:00:00', '1970-01-01', '2099-12-31', 1],
            'case and whitespace tolerated' => [' LastMonth ', '2026-03-11 10:00:00', '2026-02-01', '2026-02-28', 1],
            'leap day today' => ['today', '2028-02-29 12:00:00', '2028-02-29', '2028-02-29', 1],
            'last7 over leap day' => ['last7', '2028-03-03 12:00:00', '2028-02-26', '2028-03-03', 1],
        ];
    }

    #[DataProvider('presetCases')]
    public function testPresetResolution(string $preset, string $now, string $from, string $to, int $weekStart): void
    {
        $r = DateRange::resolve($preset, null, null, self::at($now), null, $weekStart);
        $this->assertSame($from, $r->from());
        $this->assertSame($to, $r->to());
    }

    public function testResolvedPresetId(): void
    {
        $now = self::at('2026-03-11 10:00:00');
        $this->assertSame('last30', DateRange::resolve('last30', null, null, $now)->preset());
        $this->assertSame('alltime', DateRange::resolve('nonsense', null, null, $now)->preset());
        $this->assertSame('custom', DateRange::resolve('custom', '2026-03-01', '2026-03-02', $now)->preset());
        $this->assertSame('alltime', DateRange::resolve('custom', null, null, $now)->preset());
    }

    public function testPresetIgnoresFromTo(): void
    {
        $r = DateRange::resolve('today', '2020-01-01', '2020-02-02', self::at('2026-03-11 10:00:00'));
        $this->assertSame('2026-03-11', $r->from());
    }

    /** @return array<string,array{?string,?string,string,string,string}> */
    public static function customCases(): array
    {
        // [from, to, expectedPreset, expectedFrom, expectedTo]
        return [
            'plain' => ['2026-03-03', '2026-03-09', 'custom', '2026-03-03', '2026-03-09'],
            'single day' => ['2026-03-03', '2026-03-03', 'custom', '2026-03-03', '2026-03-03'],
            'swapped' => ['2026-03-09', '2026-03-03', 'custom', '2026-03-03', '2026-03-09'],
            'only from' => ['2026-03-03', null, 'custom', '2026-03-03', '2099-12-31'],
            'only from, empty to' => ['2026-03-03', '', 'custom', '2026-03-03', '2099-12-31'],
            'only to' => [null, '2026-03-09', 'custom', '1970-01-01', '2026-03-09'],
            'both missing' => [null, null, 'alltime', '1970-01-01', '2099-12-31'],
            'both blank' => ['', '  ', 'alltime', '1970-01-01', '2099-12-31'],
            'leap day valid' => ['2028-02-29', '2028-03-01', 'custom', '2028-02-29', '2028-03-01'],
            'leap day invalid year' => ['2026-02-29', '2026-03-01', 'alltime', '1970-01-01', '2099-12-31'],
            'feb 30' => ['2026-02-30', '2026-03-01', 'alltime', '1970-01-01', '2099-12-31'],
            'zero date' => ['0000-00-00', '2026-03-01', 'alltime', '1970-01-01', '2099-12-31'],
            'month 13' => ['2026-13-01', '2026-03-01', 'alltime', '1970-01-01', '2099-12-31'],
            'invalid from, valid to' => ['garbage', '2026-03-01', 'alltime', '1970-01-01', '2099-12-31'],
            'valid from, invalid to' => ['2026-03-01', 'nope', 'alltime', '1970-01-01', '2099-12-31'],
            'sql injection' => ["2026-03-01' OR '1'='1", '2026-03-02', 'alltime', '1970-01-01', '2099-12-31'],
            'sql injection to' => ['2026-03-01', "2026-03-02'; DROP TABLE tickets;--", 'alltime', '1970-01-01', '2099-12-31'],
            'surrounding whitespace trimmed' => ["2026-03-01\n", "2026-03-02\n", 'custom', '2026-03-01', '2026-03-02'],
            'wrong format' => ['03/01/2026', '03/02/2026', 'alltime', '1970-01-01', '2099-12-31'],
            'datetime string' => ['2026-03-01 10:00:00', '2026-03-02', 'alltime', '1970-01-01', '2099-12-31'],
            'year clamped low' => ['1900-05-05', '2026-03-01', 'custom', '1970-01-01', '2026-03-01'],
            'year clamped high' => ['2026-03-01', '2500-05-05', 'custom', '2026-03-01', '2099-12-31'],
            'both clamped high' => ['2300-01-01', '2400-01-01', 'custom', '2099-12-31', '2099-12-31'],
        ];
    }

    #[DataProvider('customCases')]
    public function testCustom(?string $from, ?string $to, string $preset, string $expFrom, string $expTo): void
    {
        $r = DateRange::resolve('custom', $from, $to, self::at('2026-03-11 10:00:00'));
        $this->assertSame($preset, $r->preset());
        $this->assertSame($expFrom, $r->from());
        $this->assertSame($expTo, $r->to());
    }

    public function testNowDefaultsToCurrentTimeInTz(): void
    {
        $r = DateRange::resolve('today', null, null, null, new DateTimeZone('Pacific/Kiritimati'));
        $expected = (new DateTimeImmutable('now', new DateTimeZone('Pacific/Kiritimati')))->format('Y-m-d');
        $this->assertSame($expected, $r->from());
        $this->assertSame((new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d'), DateRange::resolve('today')->from());
    }

    public function testNowNearMidnightDiffersByTimezone(): void
    {
        // 2026-03-11 03:30 UTC is still Mar 10 evening in Chicago (CDT, UTC-5) and already Mar 11 in London / Tokyo.
        $now = self::at('2026-03-11 03:30:00', 'UTC');
        $this->assertSame('2026-03-11', DateRange::resolve('today', null, null, $now)->from());
        $this->assertSame('2026-03-10', DateRange::resolve('today', null, null, $now, new DateTimeZone('America/Chicago'))->from());
        $this->assertSame('2026-03-11', DateRange::resolve('today', null, null, $now, new DateTimeZone('Asia/Tokyo'))->from());
        $this->assertSame('2026-03-11', DateRange::resolve('today', null, null, $now, new DateTimeZone('Europe/London'))->from());
        // 23:30 UTC is already the next day in Tokyo.
        $late = self::at('2026-03-10 23:30:00', 'UTC');
        $this->assertSame('2026-03-10', DateRange::resolve('today', null, null, $late)->from());
        $this->assertSame('2026-03-11', DateRange::resolve('today', null, null, $late, new DateTimeZone('Asia/Tokyo'))->from());
    }

    public function testTzDefaultsToNowsOwnZoneWhenOmitted(): void
    {
        $now = self::at('2026-03-10 22:30:00', 'America/Chicago'); // 03:30 UTC next day
        $this->assertSame('2026-03-10', DateRange::resolve('today', null, null, $now)->from());
    }

    /** @return array<string,array{string,string,string,string,int,string}> */
    public static function dstCases(): array
    {
        // [tz, preset, now, expFrom, expTo, expDays]
        return [
            'chicago spring-forward day last7' => ['America/Chicago', 'last7', '2026-03-08 12:00:00', '2026-03-02', '2026-03-08', 7],
            'chicago day after spring-forward lastweek' => ['America/Chicago', 'lastweek', '2026-03-10 00:30:00', '2026-03-02', '2026-03-08', 7],
            'chicago fall-back day last7' => ['America/Chicago', 'last7', '2026-11-01 12:00:00', '2026-10-26', '2026-11-01', 7],
            'chicago fall-back thisweek sunday start' => ['America/Chicago', 'thisweek', '2026-11-01 01:30:00', '2026-11-01', '2026-11-01', 1],
            'london spring-forward last7' => ['Europe/London', 'last7', '2026-03-29 12:00:00', '2026-03-23', '2026-03-29', 7],
            'london fall-back last30' => ['Europe/London', 'last30', '2026-10-25 12:00:00', '2026-09-26', '2026-10-25', 30],
            'london fall-back yesterday' => ['Europe/London', 'yesterday', '2026-10-26 00:10:00', '2026-10-25', '2026-10-25', 1],
        ];
    }

    #[DataProvider('dstCases')]
    public function testDstDaysUseCalendarMath(string $tz, string $preset, string $now, string $from, string $to, int $days): void
    {
        $r = DateRange::resolve($preset, null, null, self::at($now, $tz), new DateTimeZone($tz), $preset === 'thisweek' ? 7 : 1);
        $this->assertSame($from, $r->from());
        $this->assertSame($to, $r->to());
        $this->assertSame($days, $r->days());
    }

    public function testDstDayHasExactlyOneCalendarDayInBounds(): void
    {
        $r = DateRange::resolve('custom', '2026-03-08', '2026-03-08');
        $this->assertSame(['2026-03-08 00:00:00', '2026-03-09 00:00:00'], $r->sqlBounds());
        $this->assertSame(1, $r->days());
    }

    public function testDaysAcrossDstSpan(): void
    {
        $r = DateRange::resolve('custom', '2026-03-01', '2026-03-31');
        $this->assertSame(31, $r->days());
        $this->assertSame(366, DateRange::resolve('custom', '2028-01-01', '2028-12-31')->days());
        $this->assertSame(0, DateRange::resolve('alltime')->days());
    }

    /** @return array<string,array{string,string,string,string}> */
    public static function previousCases(): array
    {
        // [preset, now, expFrom, expTo] -- now is 2026-03-11 (Wed) unless noted
        return [
            'today' => ['today', '2026-03-11 10:00:00', '2026-03-10', '2026-03-10'],
            'yesterday' => ['yesterday', '2026-03-11 10:00:00', '2026-03-09', '2026-03-09'],
            'thisweek (3 days) is the 3 days before' => ['thisweek', '2026-03-11 10:00:00', '2026-03-06', '2026-03-08'],
            'lastweek' => ['lastweek', '2026-03-11 10:00:00', '2026-02-23', '2026-03-01'],
            'thismonth (11 days) from prev month start' => ['thismonth', '2026-03-11 10:00:00', '2026-02-01', '2026-02-11'],
            'thismonth clamps to prev month end' => ['thismonth', '2026-03-31 10:00:00', '2026-02-01', '2026-02-28'],
            'lastmonth is the month before' => ['lastmonth', '2026-03-11 10:00:00', '2026-01-01', '2026-01-31'],
            'lastmonth in January' => ['lastmonth', '2026-01-11 10:00:00', '2025-11-01', '2025-11-30'],
            'lastmonth leap' => ['lastmonth', '2028-03-11 10:00:00', '2028-01-01', '2028-01-31'],
            'thisyear' => ['thisyear', '2026-03-11 10:00:00', '2025-01-01', '2025-03-11'],
            'thisyear clamps' => ['thisyear', '2028-12-31 10:00:00', '2027-01-01', '2027-12-31'],
            'lastyear' => ['lastyear', '2026-03-11 10:00:00', '2024-01-01', '2024-12-31'],
            'thisquarter' => ['thisquarter', '2026-05-10 10:00:00', '2026-01-01', '2026-02-09'],
            'thisquarter clamps' => ['thisquarter', '2026-12-31 10:00:00', '2026-07-01', '2026-09-30'],
            'lastquarter' => ['lastquarter', '2026-03-11 10:00:00', '2025-07-01', '2025-09-30'],
            'last7' => ['last7', '2026-03-11 10:00:00', '2026-02-26', '2026-03-04'],
            'last14' => ['last14', '2026-03-11 10:00:00', '2026-02-12', '2026-02-25'],
            'last30' => ['last30', '2026-03-11 10:00:00', '2026-01-11', '2026-02-09'],
            'last90' => ['last90', '2026-03-11 10:00:00', '2025-09-13', '2025-12-11'],
            'last12months' => ['last12months', '2026-03-11 10:00:00', '2024-04-21', '2025-03-31'],
            'next7' => ['next7', '2026-03-11 10:00:00', '2026-03-04', '2026-03-10'],
            'next30' => ['next30', '2026-03-11 10:00:00', '2026-02-09', '2026-03-10'],
        ];
    }

    #[DataProvider('previousCases')]
    public function testPrevious(string $preset, string $now, string $from, string $to): void
    {
        $r = DateRange::resolve($preset, null, null, self::at($now));
        $p = $r->previous();
        $this->assertSame($from, $p->from());
        $this->assertSame($to, $p->to());
        $this->assertSame('custom', $p->preset());
        // Adjacent-period presets are strictly before the current range.
        $this->assertLessThan($r->from(), $p->to());
    }

    public function testPreviousOfWeekIsAdjacentSameLengthWhenFullLength(): void
    {
        $r = DateRange::resolve('custom', '2026-03-03', '2026-03-09');
        $p = $r->previous();
        $this->assertSame('2026-02-24', $p->from());
        $this->assertSame('2026-03-02', $p->to());
        $this->assertSame($r->days(), $p->days());
    }

    public function testPreviousOfAllTimeIsItself(): void
    {
        $r = DateRange::resolve('alltime');
        $this->assertSame($r, $r->previous());
    }

    public function testPreviousClampsAtEpoch(): void
    {
        $p = DateRange::resolve('custom', '1970-01-01', '1970-01-10')->previous();
        $this->assertSame('1970-01-01', $p->from());
        $this->assertSame('1970-01-01', $p->to());
    }

    public function testPreviousCrossesLeapDay(): void
    {
        $p = DateRange::resolve('custom', '2028-03-01', '2028-03-07')->previous();
        $this->assertSame('2028-02-23', $p->from());
        $this->assertSame('2028-02-29', $p->to());
    }

    public function testSqlBounds(): void
    {
        $this->assertSame(
            ['2026-03-03 00:00:00', '2026-03-10 00:00:00'],
            DateRange::resolve('custom', '2026-03-03', '2026-03-09')->sqlBounds(),
        );
        $this->assertSame(
            ['2026-12-31 00:00:00', '2027-01-01 00:00:00'],
            DateRange::resolve('custom', '2026-12-31', '2026-12-31')->sqlBounds(),
        );
        $this->assertSame(
            ['2028-02-29 00:00:00', '2028-03-01 00:00:00'],
            DateRange::resolve('custom', '2028-02-29', '2028-02-29')->sqlBounds(),
        );
    }

    public function testSqlBoundsAtMaxDateDoNotOverflow(): void
    {
        $this->assertSame(
            ['1970-01-01 00:00:00', '2100-01-01 00:00:00'],
            DateRange::resolve('alltime')->sqlBounds(),
        );
        $this->assertSame(
            ['2099-12-31 00:00:00', '2100-01-01 00:00:00'],
            DateRange::resolve('custom', '2099-12-31', '2200-01-01')->sqlBounds(),
        );
    }

    public function testNextPresetsClampAtMax(): void
    {
        $r = DateRange::resolve('next30', null, null, self::at('2099-12-25 10:00:00'));
        $this->assertSame('2099-12-25', $r->from());
        $this->assertSame('2099-12-31', $r->to());
    }

    public function testLabels(): void
    {
        $now = self::at('2026-03-11 10:00:00');
        $this->assertSame('Today', DateRange::resolve('today', null, null, $now)->label());
        $this->assertSame('Last 7 days', DateRange::resolve('last7', null, null, $now)->label());
        $this->assertSame('This quarter', DateRange::resolve('thisquarter', null, null, $now)->label());
        $this->assertSame('All time', DateRange::resolve('alltime')->label());
        $this->assertSame('All time', DateRange::resolve('junk')->label());
        $this->assertSame('Mar 3 - Mar 9, 2026', DateRange::resolve('custom', '2026-03-03', '2026-03-09')->label());
        $this->assertSame('Dec 28, 2025 - Jan 3, 2026', DateRange::resolve('custom', '2025-12-28', '2026-01-03')->label());
        $this->assertSame('Mar 3, 2026', DateRange::resolve('custom', '2026-03-03', '2026-03-03')->label());
        $this->assertSame('From Mar 3, 2026', DateRange::resolve('custom', '2026-03-03', null)->label());
        $this->assertSame('Up to Mar 9, 2026', DateRange::resolve('custom', null, '2026-03-09')->label());
    }

    public function testEveryCatalogPresetResolvesAndHasLabel(): void
    {
        $now = self::at('2026-03-11 10:00:00');
        foreach (DateRange::presets() as $p) {
            $this->assertNotSame('', $p['label']);
            $this->assertNotSame('', $p['group']);
            $this->assertTrue(DateRange::isPreset($p['id']));
            $r = DateRange::resolve($p['id'], '2026-03-01', '2026-03-05', $now);
            $this->assertSame($p['id'], $r->preset(), $p['id']);
            if ($p['id'] !== 'custom') {
                $this->assertSame($p['label'], $r->label());
            }
        }
    }

    public function testCatalogOrderAndGroups(): void
    {
        $ids = array_column(DateRange::presets(), 'id');
        $this->assertSame([
            'today', 'yesterday', 'last7', 'last14', 'last30', 'last90', 'thisweek', 'lastweek', 'thismonth',
            'lastmonth', 'last12months', 'thisquarter', 'lastquarter', 'thisyear', 'lastyear', 'next7', 'next30',
            'alltime', 'custom',
        ], $ids);
        $groups = array_values(array_unique(array_column(DateRange::presets(), 'group')));
        $this->assertSame(['Quick', 'Days', 'Weeks', 'Months', 'Quarters/Years', 'Upcoming', 'Other'], $groups);
    }

    public function testIsPreset(): void
    {
        $this->assertTrue(DateRange::isPreset('thisweek'));
        $this->assertTrue(DateRange::isPreset('custom'));
        $this->assertFalse(DateRange::isPreset(''));
        $this->assertFalse(DateRange::isPreset('LastWeek'));
        $this->assertFalse(DateRange::isPreset("today'; --"));
    }

    public function testLegacyIdsAreStillPresets(): void
    {
        foreach (['today', 'yesterday', 'thisweek', 'lastweek', 'thismonth', 'lastmonth', 'thisyear', 'lastyear', 'alltime', 'custom'] as $id) {
            $this->assertTrue(DateRange::isPreset($id), $id);
        }
    }

    public function testIsAllTime(): void
    {
        $this->assertTrue(DateRange::resolve('alltime')->isAllTime());
        $this->assertTrue(DateRange::resolve('custom')->isAllTime());
        $this->assertFalse(DateRange::resolve('today')->isAllTime());
        $this->assertFalse(DateRange::resolve('custom', '1970-01-01', '2099-12-31')->isAllTime());
    }

    public function testToQueryShapes(): void
    {
        $now = self::at('2026-03-11 10:00:00');
        $this->assertSame(['canned_date' => 'last30'], DateRange::resolve('last30', '2026-01-01', '2026-01-02', $now)->toQuery());
        $this->assertSame(['canned_date' => 'alltime'], DateRange::resolve('alltime')->toQuery());
        $this->assertSame(
            ['canned_date' => 'custom', 'dtf' => '2026-03-03', 'dtt' => '2026-03-09'],
            DateRange::resolve('custom', '2026-03-09', '2026-03-03')->toQuery(),
        );
        $this->assertSame(
            ['canned_date' => 'custom', 'dtf' => '2026-03-03', 'dtt' => '2099-12-31'],
            DateRange::resolve('custom', '2026-03-03', null)->toQuery(),
        );
    }

    public function testToQueryRoundTripForEveryPresetAndPrevious(): void
    {
        $now = self::at('2026-03-11 10:00:00', 'America/Chicago');
        $tz = new DateTimeZone('America/Chicago');
        $ranges = [DateRange::resolve('custom', '2026-03-09', '2026-03-03', $now, $tz), DateRange::resolve('custom', null, '2026-03-03', $now, $tz)];
        foreach (DateRange::presets() as $p) {
            $r = DateRange::resolve($p['id'], '2026-03-01', '2026-03-05', $now, $tz);
            $ranges[] = $r;
            $ranges[] = $r->previous();
        }
        foreach ($ranges as $r) {
            $q = $r->toQuery();
            $again = DateRange::resolve($q['canned_date'], $q['dtf'] ?? null, $q['dtt'] ?? null, $now, $tz);
            $this->assertSame($r->preset(), $again->preset());
            $this->assertSame($r->from(), $again->from());
            $this->assertSame($r->to(), $again->to());
            $this->assertSame($r->toQuery(), $again->toQuery());
        }
    }

    public function testPresetToQueryHasNoDates(): void
    {
        foreach (DateRange::presets() as $p) {
            if ($p['id'] === 'custom') {
                continue;
            }
            $q = DateRange::resolve($p['id'], '2026-03-01', '2026-03-05', self::at('2026-03-11 10:00:00'))->toQuery();
            $this->assertArrayNotHasKey('dtf', $q);
            $this->assertArrayNotHasKey('dtt', $q);
        }
    }

    public function testRollingRangeFollowsNowWhileCustomDoesNot(): void
    {
        $q = DateRange::resolve('last7', null, null, self::at('2026-03-11 10:00:00'))->toQuery();
        $later = DateRange::resolve($q['canned_date'], null, null, self::at('2026-03-20 10:00:00'));
        $this->assertSame('2026-03-14', $later->from());
        $this->assertSame('2026-03-20', $later->to());
    }
}
