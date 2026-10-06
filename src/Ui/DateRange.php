<?php

declare(strict_types=1);

namespace RivetCore\Ui;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Timezone-aware date-range resolver shared by both editions' list filters and reports (Service Desk first).
 *
 * A range is an INCLUSIVE pair of calendar dates (`Y-m-d`). All day arithmetic is done on calendar dates (never on
 * 86400-second offsets), so DST transition days are 23/25 hours long but still exactly one day. "Today" is the date
 * of `$now` in the supplied timezone.
 *
 * Preset ids (the first ten are the legacy ids already used in editions' URLs as `canned_date=`):
 *  - today / yesterday
 *  - thisweek   week start .. today (week start per `$weekStart`)
 *  - lastweek   the previous full week
 *  - thismonth  1st of this month .. today;  lastmonth  the previous full calendar month
 *  - thisyear   Jan 1 .. today;              lastyear   the previous full calendar year
 *  - thisquarter  first day of this calendar quarter .. today;  lastquarter  the previous full quarter
 *  - last7 / last14 / last30 / last90   rolling: the N days ending today, inclusive (today - (N-1) .. today)
 *  - last12months   first day of the month 11 months ago .. today (12 calendar months, this one partial)
 *  - next7 / next30   upcoming, for due dates: today .. today + (N-1)
 *  - alltime    1970-01-01 .. 2099-12-31 (also the fallback for an unknown/empty preset or an invalid custom range)
 *  - custom     explicit from/to (`Y-m-d`); swapped if reversed; years clamped to 1970..2099; only a "from" means
 *               "on or after" (to = 2099-12-31), only a "to" means "up to" (from = 1970-01-01)
 *
 * @api
 */
final class DateRange
{
    public const MIN_DATE = '1970-01-01';
    public const MAX_DATE = '2099-12-31';

    /** @var list<array{id:string,label:string,group:string}> */
    private const CATALOG = [
        ['id' => 'today', 'label' => 'Today', 'group' => 'Quick'],
        ['id' => 'yesterday', 'label' => 'Yesterday', 'group' => 'Quick'],
        ['id' => 'last7', 'label' => 'Last 7 days', 'group' => 'Days'],
        ['id' => 'last14', 'label' => 'Last 14 days', 'group' => 'Days'],
        ['id' => 'last30', 'label' => 'Last 30 days', 'group' => 'Days'],
        ['id' => 'last90', 'label' => 'Last 90 days', 'group' => 'Days'],
        ['id' => 'thisweek', 'label' => 'This week', 'group' => 'Weeks'],
        ['id' => 'lastweek', 'label' => 'Last week', 'group' => 'Weeks'],
        ['id' => 'thismonth', 'label' => 'This month', 'group' => 'Months'],
        ['id' => 'lastmonth', 'label' => 'Last month', 'group' => 'Months'],
        ['id' => 'last12months', 'label' => 'Last 12 months', 'group' => 'Months'],
        ['id' => 'thisquarter', 'label' => 'This quarter', 'group' => 'Quarters/Years'],
        ['id' => 'lastquarter', 'label' => 'Last quarter', 'group' => 'Quarters/Years'],
        ['id' => 'thisyear', 'label' => 'This year', 'group' => 'Quarters/Years'],
        ['id' => 'lastyear', 'label' => 'Last year', 'group' => 'Quarters/Years'],
        ['id' => 'next7', 'label' => 'Next 7 days', 'group' => 'Upcoming'],
        ['id' => 'next30', 'label' => 'Next 30 days', 'group' => 'Upcoming'],
        ['id' => 'alltime', 'label' => 'All time', 'group' => 'Other'],
        ['id' => 'custom', 'label' => 'Custom range', 'group' => 'Other'],
    ];

    private function __construct(
        private readonly string $preset,
        private readonly string $from,
        private readonly string $to,
    ) {
    }

    /**
     * @param string  $preset    preset id (see class docs); unknown/empty => alltime
     * @param int     $weekStart 1=Monday .. 7=Sunday (0 also means Sunday); anything else falls back to Monday
     */
    public static function resolve(
        string $preset,
        ?string $from = null,
        ?string $to = null,
        ?DateTimeImmutable $now = null,
        ?DateTimeZone $tz = null,
        int $weekStart = 1,
    ): DateRange {
        $preset = strtolower(trim($preset));
        if ($now === null) {
            $tz ??= new DateTimeZone('UTC');
            $now = new DateTimeImmutable('now', $tz);
        } elseif ($tz !== null) {
            $now = $now->setTimezone($tz);
        }
        $today = self::utc($now->format('Y-m-d'));

        $ws = $weekStart === 0 ? 7 : ($weekStart >= 1 && $weekStart <= 7 ? $weekStart : 1);
        $y = (int) $today->format('Y');
        $m = (int) $today->format('n');

        switch ($preset) {
            case 'today':
                return self::make('today', $today, $today);
            case 'yesterday':
                $d = $today->modify('-1 day');

                return self::make('yesterday', $d, $d);
            case 'thisweek':
                return self::make('thisweek', self::weekStart($today, $ws), $today);
            case 'lastweek':
                $s = self::weekStart($today, $ws)->modify('-7 days');

                return self::make('lastweek', $s, $s->modify('+6 days'));
            case 'thismonth':
                return self::make('thismonth', $today->setDate($y, $m, 1), $today);
            case 'lastmonth':
                return self::make('lastmonth', $today->setDate($y, $m - 1, 1), $today->setDate($y, $m, 0));
            case 'thisyear':
                return self::make('thisyear', $today->setDate($y, 1, 1), $today);
            case 'lastyear':
                return self::make('lastyear', $today->setDate($y - 1, 1, 1), $today->setDate($y - 1, 12, 31));
            case 'thisquarter':
                return self::make('thisquarter', $today->setDate($y, self::quarterMonth($m), 1), $today);
            case 'lastquarter':
                $qm = self::quarterMonth($m);

                return self::make('lastquarter', $today->setDate($y, $qm - 3, 1), $today->setDate($y, $qm, 0));
            case 'last7':
            case 'last14':
            case 'last30':
            case 'last90':
                $n = (int) substr($preset, 4);

                return self::make($preset, $today->modify('-' . ($n - 1) . ' days'), $today);
            case 'last12months':
                return self::make($preset, $today->setDate($y, $m - 11, 1), $today);
            case 'next7':
            case 'next30':
                $n = (int) substr($preset, 4);

                return self::make($preset, $today, $today->modify('+' . ($n - 1) . ' days'));
            case 'custom':
                return self::custom($from, $to);
            default:
                return self::allTime();
        }
    }

    /** @return list<array{id:string,label:string,group:string}> */
    public static function presets(): array
    {
        return self::CATALOG;
    }

    public static function isPreset(string $id): bool
    {
        foreach (self::CATALOG as $p) {
            if ($p['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /** The resolved preset id ('custom' for a custom range, 'alltime' after any fallback). */
    public function preset(): string
    {
        return $this->preset;
    }

    /** First day, inclusive, `Y-m-d`. */
    public function from(): string
    {
        return $this->from;
    }

    /** Last day, inclusive, `Y-m-d`. */
    public function to(): string
    {
        return $this->to;
    }

    public function isAllTime(): bool
    {
        return $this->preset === 'alltime';
    }

    public function label(): string
    {
        if ($this->preset !== 'custom') {
            foreach (self::CATALOG as $p) {
                if ($p['id'] === $this->preset) {
                    return $p['label'];
                }
            }
        }
        $a = self::utc($this->from);
        $b = self::utc($this->to);
        if ($this->to === self::MAX_DATE && $this->from !== self::MIN_DATE) {
            return 'From ' . $a->format('M j, Y');
        }
        if ($this->from === self::MIN_DATE && $this->to !== self::MAX_DATE) {
            return 'Up to ' . $b->format('M j, Y');
        }
        if ($this->from === $this->to) {
            return $a->format('M j, Y');
        }
        if ($a->format('Y') === $b->format('Y')) {
            return $a->format('M j') . ' - ' . $b->format('M j, Y');
        }

        return $a->format('M j, Y') . ' - ' . $b->format('M j, Y');
    }

    /** Inclusive length in calendar days; 0 for all time. */
    public function days(): int
    {
        if ($this->isAllTime()) {
            return 0;
        }

        return (int) self::utc($this->from)->diff(self::utc($this->to))->days + 1;
    }

    /**
     * The immediately preceding period of the same length, as a custom range (so it round-trips through
     * {@see toQuery()}). Calendar presets step by calendar unit: lastmonth => the month before, lastquarter => the
     * quarter before, lastyear => the year before. To-date presets (thismonth, thisquarter, thisyear) compare against
     * the same number of days from the start of the previous month/quarter/year (clamped to its end). Everything
     * else (today, rolling, weeks, upcoming, custom) is the N days directly before `from`. All time returns itself.
     * Results are clamped to 1970..2099.
     */
    public function previous(): DateRange
    {
        if ($this->isAllTime()) {
            return $this;
        }
        $from = self::utc($this->from);
        $y = (int) $from->format('Y');
        $m = (int) $from->format('n');
        $len = $this->days();

        switch ($this->preset) {
            case 'lastmonth':
                return self::make('custom', $from->setDate($y, $m - 1, 1), $from->setDate($y, $m, 0));
            case 'lastquarter':
                return self::make('custom', $from->setDate($y, $m - 3, 1), $from->setDate($y, $m, 0));
            case 'lastyear':
                return self::make('custom', $from->setDate($y - 1, 1, 1), $from->setDate($y - 1, 12, 31));
            case 'thismonth':
                $s = $from->setDate($y, $m - 1, 1);

                return self::make('custom', $s, self::minDate($s->modify('+' . ($len - 1) . ' days'), $from->setDate($y, $m, 0)));
            case 'thisquarter':
                $s = $from->setDate($y, $m - 3, 1);

                return self::make('custom', $s, self::minDate($s->modify('+' . ($len - 1) . ' days'), $from->setDate($y, $m, 0)));
            case 'thisyear':
                $s = $from->setDate($y - 1, 1, 1);

                return self::make('custom', $s, self::minDate($s->modify('+' . ($len - 1) . ' days'), $from->setDate($y - 1, 12, 31)));
            default:
                $end = $from->modify('-1 day');

                return self::make('custom', $end->modify('-' . ($len - 1) . ' days'), $end);
        }
    }

    /**
     * Half-open datetime bounds for sargable SQL: `col >= :a AND col < :b`. The values are plain
     * `Y-m-d H:i:s` strings in the caller's (DB session) timezone.
     *
     * @return array{0:string,1:string}
     */
    public function sqlBounds(): array
    {
        return [
            $this->from . ' 00:00:00',
            self::utc($this->to)->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
        ];
    }

    /**
     * Canonical GET params. Preset ranges carry only `canned_date` (so saved views stay rolling); custom ranges carry
     * the explicit dates.
     *
     * @return array<string,string>
     */
    public function toQuery(): array
    {
        if ($this->preset === 'custom') {
            return ['canned_date' => 'custom', 'dtf' => $this->from, 'dtt' => $this->to];
        }

        return ['canned_date' => $this->preset];
    }

    private static function custom(?string $from, ?string $to): DateRange
    {
        $f = $from === null ? '' : trim($from);
        $t = $to === null ? '' : trim($to);
        if ($f === '' && $t === '') {
            return self::allTime();
        }
        $a = $f === '' ? self::utc(self::MIN_DATE) : self::parse($f);
        $b = $t === '' ? self::utc(self::MAX_DATE) : self::parse($t);
        if ($a === null || $b === null) {
            return self::allTime();
        }
        if ($a > $b) {
            [$a, $b] = [$b, $a];
        }

        return self::make('custom', $a, $b);
    }

    private static function parse(string $s): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $s, $mt) !== 1) {
            return null;
        }
        if (!checkdate((int) $mt[2], (int) $mt[3], (int) $mt[1])) {
            return null;
        }

        return self::utc($s);
    }

    private static function allTime(): DateRange
    {
        return new self('alltime', self::MIN_DATE, self::MAX_DATE);
    }

    private static function make(string $preset, DateTimeImmutable $from, DateTimeImmutable $to): DateRange
    {
        $min = self::utc(self::MIN_DATE);
        $max = self::utc(self::MAX_DATE);
        $from = $from < $min ? $min : ($from > $max ? $max : $from);
        $to = $to < $min ? $min : ($to > $max ? $max : $to);

        return new self($preset, $from->format('Y-m-d'), $to->format('Y-m-d'));
    }

    private static function utc(string $ymd): DateTimeImmutable
    {
        return new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone('UTC'));
    }

    private static function minDate(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a < $b ? $a : $b;
    }

    /** First month (1,4,7,10) of the calendar quarter containing month $m. */
    private static function quarterMonth(int $m): int
    {
        return intdiv($m - 1, 3) * 3 + 1;
    }

    private static function weekStart(DateTimeImmutable $d, int $ws): DateTimeImmutable
    {
        $offset = ((int) $d->format('N') - $ws + 7) % 7;

        return $offset === 0 ? $d : $d->modify('-' . $offset . ' days');
    }
}
