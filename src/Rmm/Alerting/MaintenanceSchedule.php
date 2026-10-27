<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

/**
 * The calendar arithmetic of maintenance windows, with no database: validation of a window definition and the question "is this window
 * open at this instant, and when does it open next". Pure and timezone aware.
 *
 * Window kinds. `once`: a UTC interval [starts_at, ends_at). `recurring`: a local start time (`local_start`, HH:MM, in `timezone`) on
 * the local days the recurrence selects, lasting `duration_min` REAL minutes (so a window that crosses a daylight-saving change keeps its
 * length), between the local dates `recur_from` and `recur_until` (both inclusive, both optional).
 *
 * Recurrence. `daily` every `recur_interval` days; `weekly` on the ISO weekdays of `recur_days` (1 Monday to 7 Sunday, "1,3,5") every
 * `recur_interval` weeks (weeks start on Monday); `monthly` on the day numbers of `recur_days` ("1,15,31", or the word `last`) every
 * `recur_interval` months. Calendar-month rule: a day number that the month does not have is CLAMPED to its last day, so "31" fires on
 * Feb 28 (or 29), Apr 30 and so on, and "29" fires on Feb 28 in a common year; nothing is ever skipped or carried into the next month. When
 * `recur_interval` is more than 1 the counting starts at `recur_from` (required then); with interval 1 `recur_from` only bounds the start.
 * Daylight saving: a local start time that does not exist (spring forward) opens one hour late on the new clock (02:30 becomes 03:30); an
 * ambiguous one (fall back) opens at its first occurrence. Both are resolved by Core itself, so they do not depend on the PHP version.
 *
 * @api
 */
final class MaintenanceSchedule
{
    public const MODES = ['mute', 'suppress'];
    public const SCOPES = ['all', 'client', 'site', 'group', 'tag', 'device'];
    public const KINDS = ['once', 'recurring'];
    public const FREQS = ['daily', 'weekly', 'monthly'];
    public const MAX_DURATION_MIN = 10080;
    public const MAX_ONCE_DAYS = 366;

    /**
     * Validate an API/UI input and return the row fields to store.
     *
     * @param array<string,mixed> $in
     * @return array{0:?array<string,mixed>,1:?string} [row fields, error]
     */
    public static function validate(array $in): array
    {
        $name = is_string($in['name'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $in['name'])) : '';
        if ($name === '' || mb_strlen($name) > 100) {
            return [null, 'A window needs a name of 1 to 100 characters.'];
        }
        $mode = $in['mode'] ?? 'mute';
        if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
            return [null, 'mode must be mute or suppress.'];
        }
        $scope = $in['scope_type'] ?? 'all';
        if (!is_string($scope) || !in_array($scope, self::SCOPES, true)) {
            return [null, 'scope_type must be one of ' . implode(', ', self::SCOPES) . '.'];
        }
        $scopeId = $in['scope_id'] ?? 0;
        if ($scope === 'all') {
            $scopeId = 0;
        } elseif (!is_int($scopeId) || $scopeId < 1) {
            return [null, 'scope_id must be the id of the ' . $scope . '.'];
        }
        $tz = $in['timezone'] ?? 'UTC';
        if (!is_string($tz) || !self::validZone($tz)) {
            return [null, 'timezone must be an IANA name such as Europe/Berlin.'];
        }
        $kind = $in['kind'] ?? 'once';
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            return [null, 'kind must be once or recurring.'];
        }
        $note = is_string($in['note'] ?? null) ? mb_substr(trim($in['note']), 0, 300) : '';
        $row = ['name' => $name, 'mode' => $mode, 'scope_type' => $scope, 'scope_id' => $scopeId, 'timezone' => $tz, 'kind' => $kind, 'note' => $note,
            'enabled' => array_key_exists('enabled', $in) ? ((bool) $in['enabled'] ? 1 : 0) : 1,
            'starts_at' => null, 'ends_at' => null, 'recur_freq' => null, 'recur_interval' => 1, 'recur_days' => '', 'local_start' => '00:00',
            'duration_min' => 60, 'recur_from' => null, 'recur_until' => null];
        if ($kind === 'once') {
            $s = self::parseInstant($in['starts_at'] ?? null, $tz);
            $e = self::parseInstant($in['ends_at'] ?? null, $tz);
            if ($s === null || $e === null) {
                return [null, 'A one-time window needs starts_at and ends_at (RFC 3339, or YYYY-MM-DD HH:MM in the window\'s timezone).'];
            }
            if ($e <= $s || $e - $s > self::MAX_ONCE_DAYS * 86400) {
                return [null, 'ends_at must be after starts_at, at most ' . self::MAX_ONCE_DAYS . ' days later.'];
            }
            $row['starts_at'] = gmdate('Y-m-d H:i:s', $s);
            $row['ends_at'] = gmdate('Y-m-d H:i:s', $e);
            $row['duration_min'] = intdiv($e - $s, 60);

            return [$row, null];
        }
        $freq = $in['recur_freq'] ?? null;
        if (!is_string($freq) || !in_array($freq, self::FREQS, true)) {
            return [null, 'recur_freq must be daily, weekly or monthly.'];
        }
        $iv = $in['recur_interval'] ?? 1;
        if (!is_int($iv) || $iv < 1 || $iv > 52) {
            return [null, 'recur_interval must be a whole number from 1 to 52.'];
        }
        $start = $in['local_start'] ?? null;
        if (!is_string($start) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) !== 1) {
            return [null, 'local_start must be HH:MM (24 hour).'];
        }
        $dur = $in['duration_min'] ?? null;
        if (!is_int($dur) || $dur < 1 || $dur > self::MAX_DURATION_MIN) {
            return [null, 'duration_min must be a whole number from 1 to ' . self::MAX_DURATION_MIN . '.'];
        }
        $from = $in['recur_from'] ?? null;
        $until = $in['recur_until'] ?? null;
        foreach (['recur_from' => $from, 'recur_until' => $until] as $f => $v) {
            if ($v !== null && $v !== '' && !self::validDate($v)) {
                return [null, "$f must be a date YYYY-MM-DD."];
            }
        }
        $from = ($from === null || $from === '') ? null : (string) $from;
        $until = ($until === null || $until === '') ? null : (string) $until;
        if ($from !== null && $until !== null && $until < $from) {
            return [null, 'recur_until is before recur_from.'];
        }
        if ($iv > 1 && $from === null) {
            return [null, 'recur_from is required when recur_interval is more than 1.'];
        }
        $days = '';
        if ($freq === 'weekly') {
            $list = self::days($in['recur_days'] ?? null, 1, 7, false);
            if ($list === null) {
                return [null, 'A weekly window needs recur_days: ISO weekdays 1 (Monday) to 7 (Sunday), e.g. "1,3,5".'];
            }
            $days = implode(',', $list);
        } elseif ($freq === 'monthly') {
            $list = self::days($in['recur_days'] ?? null, 1, 31, true);
            if ($list === null) {
                return [null, 'A monthly window needs recur_days: day numbers 1 to 31 and/or "last", e.g. "1,15,last".'];
            }
            $days = implode(',', $list);
        }
        $row['recur_freq'] = $freq;
        $row['recur_interval'] = $iv;
        $row['recur_days'] = $days;
        $row['local_start'] = $start;
        $row['duration_min'] = $dur;
        $row['recur_from'] = $from;
        $row['recur_until'] = $until;

        return [$row, null];
    }

    /** @return list<int|string>|null sorted unique day tokens, or null when the input is not acceptable */
    private static function days(mixed $v, int $min, int $max, bool $allowLast): ?array
    {
        if (is_array($v)) {
            $v = implode(',', array_map(static fn ($x): string => is_scalar($x) ? (string) $x : '', $v));
        }
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        $out = [];
        foreach (explode(',', $v) as $p) {
            $p = strtolower(trim($p));
            if ($allowLast && $p === 'last') {
                $out['last'] = 'last';
            } elseif (preg_match('/^\d{1,2}$/', $p) === 1 && (int) $p >= $min && (int) $p <= $max) {
                $out[(int) $p] = (int) $p;
            } else {
                return null;
            }
        }
        $ints = array_values(array_filter($out, 'is_int'));
        sort($ints);

        return array_merge($ints, isset($out['last']) ? ['last'] : []);
    }

    public static function validZone(string $tz): bool
    {
        if ($tz === '' || strlen($tz) > 64 || preg_match('/^[A-Za-z0-9_\/+-]+$/', $tz) !== 1) {
            return false;
        }
        try {
            new \DateTimeZone($tz);
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    private static function validDate(mixed $v): bool
    {
        if (!is_string($v) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** An RFC 3339 instant, or a wall-clock time in $tz, as a Unix timestamp (null when unreadable). */
    private static function parseInstant(mixed $v, string $tz): ?int
    {
        if (!is_string($v) || strlen($v) > 40) {
            return null;
        }
        $hasZone = preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $v) === 1;
        if (!$hasZone && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $v) !== 1) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($v, new \DateTimeZone($tz)))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    // ------------------------------------------------------------------ evaluation

    /** @param array<string,mixed> $w a window row */
    public static function isActive(array $w, int $ts): bool
    {
        return self::currentEnd($w, $ts) !== null;
    }

    /**
     * When the occurrence that is open at $ts ends (Unix time), or null when the window is closed (or disabled).
     *
     * @param array<string,mixed> $w
     */
    public static function currentEnd(array $w, int $ts): ?int
    {
        if ((int) ($w['enabled'] ?? 1) !== 1) {
            return null;
        }
        if (($w['kind'] ?? 'once') === 'once') {
            $s = self::utc((string) ($w['starts_at'] ?? ''));
            $e = self::utc((string) ($w['ends_at'] ?? ''));

            return ($s !== null && $e !== null && $s <= $ts && $ts < $e) ? $e : null;
        }
        $tz = new \DateTimeZone((string) $w['timezone']);
        $local = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $back = intdiv((int) $w['duration_min'] + 1439, 1440) + 1;
        for ($i = 0; $i <= $back; ++$i) {
            $day = $local->modify("-$i day");
            if (!self::occursOn($w, $day)) {
                continue;
            }
            $start = self::startOn($w, $day, $tz);
            $end = $start + (int) $w['duration_min'] * 60;
            if ($start <= $ts && $ts < $end) {
                return $end;
            }
        }

        return null;
    }

    /**
     * The next time the window opens strictly after $after (Unix time), or null (disabled, over, or none within the horizon).
     *
     * @param array<string,mixed> $w
     */
    public static function nextStart(array $w, int $after, int $horizonDays = 400): ?int
    {
        if ((int) ($w['enabled'] ?? 1) !== 1) {
            return null;
        }
        if (($w['kind'] ?? 'once') === 'once') {
            $s = self::utc((string) ($w['starts_at'] ?? ''));

            return ($s !== null && $s > $after) ? $s : null;
        }
        $tz = new \DateTimeZone((string) $w['timezone']);
        $day = (new \DateTimeImmutable('@' . $after))->setTimezone($tz)->setTime(0, 0)->modify('-1 day');
        for ($i = 0; $i <= $horizonDays + 2; ++$i, $day = $day->modify('+1 day')) {
            if (!self::occursOn($w, $day)) {
                continue;
            }
            $start = self::startOn($w, $day, $tz);
            if ($start > $after) {
                return $start;
            }
        }

        return null;
    }

    /**
     * The Unix time the occurrence of local date $day opens. Resolved by hand instead of trusting the PHP version's handling of the two
     * odd cases: a local time that happens twice (clocks go back) opens at the FIRST one; a local time that does not exist (clocks go
     * forward) opens at the same wall-clock reading with the offset from before the jump, i.e. one hour "late" on the new clock.
     */
    /** @param array<string,mixed> $w */
    private static function startOn(array $w, \DateTimeImmutable $day, \DateTimeZone $tz): int
    {
        $wall = (int) (new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $w['local_start'] . ':00', new \DateTimeZone('UTC')))->getTimestamp();
        $before = $tz->getOffset(new \DateTimeImmutable('@' . ($wall - 86400)));
        $after = $tz->getOffset(new \DateTimeImmutable('@' . ($wall + 86400)));
        $valid = [];
        foreach (array_unique([$before, $after]) as $off) {
            $cand = $wall - $off;
            if ($tz->getOffset(new \DateTimeImmutable('@' . $cand)) === $off) {
                $valid[] = $cand;
            }
        }
        if ($valid === []) {
            return $wall - $before;   // inside the gap
        }

        return min($valid);
    }

    /**
     * Does the recurrence select this LOCAL calendar date (the time of day of $day is ignored)?
     *
     * @param array<string,mixed> $w a recurring window
     */
    public static function occursOn(array $w, \DateTimeImmutable $day): bool
    {
        $date = $day->format('Y-m-d');
        $from = (string) ($w['recur_from'] ?? '');
        $until = (string) ($w['recur_until'] ?? '');
        if (($from !== '' && $date < $from) || ($until !== '' && $date > $until)) {
            return false;
        }
        $iv = max(1, (int) ($w['recur_interval'] ?? 1));
        $anchor = new \DateTimeImmutable(($from !== '' ? $from : '1970-01-05') . ' 00:00:00', new \DateTimeZone('UTC'));   // 1970-01-05 is a Monday
        $d = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));
        switch ($w['recur_freq'] ?? '') {
            case 'daily':
                return intdiv((int) $anchor->diff($d)->format('%r%a'), 1) % $iv === 0;
            case 'weekly':
                if (!in_array((int) $d->format('N'), array_map('intval', explode(',', (string) $w['recur_days'])), true)) {
                    return false;
                }
                $aMon = $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' day');
                $dMon = $d->modify('-' . ((int) $d->format('N') - 1) . ' day');

                return intdiv((int) $aMon->diff($dMon)->format('%r%a'), 7) % $iv === 0;
            case 'monthly':
                $months = ((int) $d->format('Y') - (int) $anchor->format('Y')) * 12 + ((int) $d->format('n') - (int) $anchor->format('n'));
                if ($months % $iv !== 0) {
                    return false;
                }
                $dim = (int) $d->format('t');
                $dom = (int) $d->format('j');
                foreach (explode(',', (string) $w['recur_days']) as $tok) {
                    $target = $tok === 'last' ? $dim : min((int) $tok, $dim);
                    if ($target === $dom) {
                        return true;
                    }
                }

                return false;
        }

        return false;
    }

    private static function utc(string $s): ?int
    {
        if ($s === '') {
            return null;
        }
        $t = strtotime($s . ' UTC');

        return $t === false ? null : $t;
    }
}
