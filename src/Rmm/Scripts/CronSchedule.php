<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Scripts;

/**
 * A five-field cron expression in UTC: `minute hour day-of-month month day-of-week`. Each field takes `*`, a number, a range `a-b`, a list
 * `a,b,c`, and a step `/n` after `*` or a range. Day-of-week is 0 to 7 (0 and 7 are Sunday). When both day fields are restricted a day
 * matches if EITHER does (the classic cron rule). Names, `@daily` shorthands and seconds are not supported.
 *
 * @api
 */
final class CronSchedule
{
    /** @param array{0:list<int>,1:list<int>,2:list<int>,3:list<int>,4:list<int>} $f */
    private function __construct(private readonly array $f, private readonly bool $domStar, private readonly bool $dowStar, public readonly string $expression)
    {
    }

    /** @throws \InvalidArgumentException */
    public static function parse(string $expr): self
    {
        $expr = trim(preg_replace('/\s+/', ' ', $expr) ?? '');
        $parts = explode(' ', $expr);
        if (count($parts) !== 5 || strlen($expr) > 100) {
            throw new \InvalidArgumentException('A cron expression has five fields: minute hour day-of-month month day-of-week.');
        }
        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
        $f = [];
        foreach ($parts as $i => $p) {
            $f[$i] = self::field($p, $ranges[$i][0], $ranges[$i][1], ['minute', 'hour', 'day-of-month', 'month', 'day-of-week'][$i]);
        }
        $f[4] = array_unique(array_map(static fn (int $d): int => $d === 7 ? 0 : $d, $f[4]));
        sort($f[4]);

        /** @var array{0:list<int>,1:list<int>,2:list<int>,3:list<int>,4:list<int>} $f */
        return new self($f, $parts[2] === '*', $parts[4] === '*', $expr);
    }

    /** @return list<int> */
    private static function field(string $p, int $min, int $max, string $label): array
    {
        $out = [];
        foreach (explode(',', $p) as $item) {
            if ($item === '') {
                throw new \InvalidArgumentException("Cron $label: empty item.");
            }
            $step = 1;
            if (str_contains($item, '/')) {
                [$item, $st] = explode('/', $item, 2);
                if (preg_match('/^\d{1,3}$/', $st) !== 1 || (int) $st < 1) {
                    throw new \InvalidArgumentException("Cron $label: the step must be a positive number.");
                }
                $step = (int) $st;
            }
            if ($item === '*') {
                [$a, $b] = [$min, $max];
                if ($max === 7) {
                    $b = 6;
                }
            } elseif (preg_match('/^(\d{1,2})-(\d{1,2})$/', $item, $m) === 1) {
                [$a, $b] = [(int) $m[1], (int) $m[2]];
            } elseif (preg_match('/^\d{1,2}$/', $item) === 1) {
                $a = (int) $item;
                $b = $step > 1 ? $max : $a;
            } else {
                throw new \InvalidArgumentException("Cron $label: unsupported value.");
            }
            if ($a < $min || $b > $max || $a > $b) {
                throw new \InvalidArgumentException("Cron $label: $min to $max.");
            }
            for ($v = $a; $v <= $b; $v += $step) {
                $out[$v] = $v;
            }
        }
        sort($out);

        return $out;
    }

    /** The first matching minute strictly after `$after`, or null when none exists within about five years. */
    public function next(\DateTimeImmutable $after): ?\DateTimeImmutable
    {
        $t = $after->setTimezone(new \DateTimeZone('UTC'))->setTime((int) $after->format('H'), (int) $after->format('i'), 0)->modify('+1 minute');
        [$mins, $hours, $doms, $months, $dows] = $this->f;
        $limit = $t->modify('+5 years');
        while ($t < $limit) {
            if (!in_array((int) $t->format('n'), $months, true)) {
                $t = $t->modify('first day of next month')->setTime(0, 0);
                continue;
            }
            $domOk = in_array((int) $t->format('j'), $doms, true);
            $dowOk = in_array((int) $t->format('w'), $dows, true);
            $dayOk = $this->domStar || $this->dowStar ? ($domOk && $dowOk) : ($domOk || $dowOk);
            if (!$dayOk) {
                $t = $t->modify('+1 day')->setTime(0, 0);
                continue;
            }
            foreach ($hours as $h) {
                if ($h < (int) $t->format('G')) {
                    continue;
                }
                foreach ($mins as $m) {
                    if ($h === (int) $t->format('G') && $m < (int) $t->format('i')) {
                        continue;
                    }

                    return $t->setTime($h, $m);
                }
            }
            $t = $t->modify('+1 day')->setTime(0, 0);
        }

        return null;
    }
}
