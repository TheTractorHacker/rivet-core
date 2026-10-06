<?php

declare(strict_types=1);

namespace RivetCore\Bench;

/** One measured case: how many operations, how long they took, and per-operation latencies when they were sampled. */
final class Measurement
{
    /** @param list<int> $latenciesNs */
    public function __construct(public int $ops, public int $totalNs, public array $latenciesNs = [], public string $note = '')
    {
    }

    public function opsPerSec(): float
    {
        return $this->totalNs > 0 ? $this->ops / ($this->totalNs / 1e9) : 0.0;
    }

    public function percentileUs(float $p): ?float
    {
        if ($this->latenciesNs === []) {
            return null;
        }
        $l = $this->latenciesNs;
        sort($l);

        return $l[(int) min(count($l) - 1, floor($p * count($l)))] / 1000;
    }
}

/** Handed to each case so it can do untimed setup and then time exactly one loop or one bulk operation. */
final class Ctx
{
    public ?Measurement $result = null;

    /** Time $n calls of $op(i), sampling every call's latency. */
    public function loop(int $n, callable $op, string $note = ''): void
    {
        $lat = [];
        $start = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $t = hrtime(true);
            $op($i);
            $lat[] = hrtime(true) - $t;
        }
        $this->result = new Measurement($n, hrtime(true) - $start, $lat, $note);
    }

    /** Time one call that does $ops units of work in a single go ($fn may return a note). */
    public function bulk(int $ops, callable $fn): void
    {
        $start = hrtime(true);
        $note = $fn();
        $this->result = new Measurement($ops, hrtime(true) - $start, [], is_string($note) ? $note : '');
    }
}

/** A tiny benchmark runner: repeat each case, keep the median run by throughput, print a table and JSON. */
final class Harness
{
    /** @var array<string, array{callable, string}> */
    private array $cases = [];

    /** @var array<string, Measurement> */
    public array $results = [];

    public function case(string $id, string $unit, callable $fn): void
    {
        $this->cases[$id] = [$fn, $unit];
    }

    /** @param list<string> $only id prefixes; empty means all */
    public function run(int $runs, array $only, bool $quiet = false): void
    {
        foreach ($this->cases as $id => [$fn, $unit]) {
            if ($only !== [] && !array_filter($only, static fn (string $p): bool => str_starts_with($id, $p))) {
                continue;
            }
            $ms = [];
            for ($r = 0; $r < $runs; $r++) {
                $ctx = new Ctx();
                $fn($ctx);
                if ($ctx->result === null) {
                    throw new \LogicException("case $id recorded nothing");
                }
                $ms[] = $ctx->result;
            }
            usort($ms, static fn (Measurement $a, Measurement $b): int => $a->opsPerSec() <=> $b->opsPerSec());
            $this->results[$id] = $ms[intdiv(count($ms), 2)];
            if (!$quiet) {
                fwrite(STDERR, sprintf("  %-34s %12s %s\n", $id, number_format($this->results[$id]->opsPerSec(), 0), $unit));
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->results as $id => $m) {
            $row = ['ops' => $m->ops, 'seconds' => round($m->totalNs / 1e9, 4), 'ops_per_sec' => round($m->opsPerSec(), 1)];
            foreach (['p50' => .5, 'p95' => .95, 'p99' => .99] as $k => $p) {
                $v = $m->percentileUs($p);
                if ($v !== null) {
                    $row[$k . '_us'] = round($v, 1);
                }
            }
            if ($m->note !== '') {
                $row['note'] = $m->note;
            }
            $out[$id] = $row;
        }

        return $out;
    }

    /** @param array<string, array<string, mixed>> $thresholds id => [min_ops_per_sec => float] @return list<string> failures */
    public function check(array $thresholds): array
    {
        $fail = [];
        foreach ($thresholds as $id => $t) {
            if (!isset($this->results[$id])) {
                continue; // not run (--only) or needs Redis
            }
            $min = (float) ($t['min_ops_per_sec'] ?? 0);
            $got = $this->results[$id]->opsPerSec();
            if ($got < $min) {
                $fail[] = sprintf('%s: %.0f ops/s is below the threshold of %.0f', $id, $got, $min);
            }
        }

        return $fail;
    }
}
