<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Capacity;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\RmmState;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Staged load shedding (scaling item S7). Three signals, all cheap: the `rmm.ingest` backlog (pending jobs), a database latency probe
 * (a primary-key SELECT on the settings row, median of three, in milliseconds) and the global check-in rate (rows of
 * endpoint_agent_checkins received in the last minute). Each has a threshold per level in limits_json (0 = that level never triggers on
 * that signal); the raw level is the highest level any signal reaches.
 *
 *  - L0 normal.
 *  - L1 drop optional samples: buffered backlog samples older than 15 minutes are acknowledged but not ingested.
 *  - L2 lengthen intervals: `next_check_in_s` in the check-in response is doubled (agents already obey it).
 *  - L3 refuse new work: check-ins are answered 503 + a jittered Retry-After (limits shed_retry_min_s..shed_retry_max_s) before any work
 *    is done. Enrollment, job reports and revocation are never shed.
 *
 * HYSTERESIS. Escalation is immediate (straight to the raw level). Recovery steps down ONE level after two consecutive evaluations
 * whose raw level is below the current one, so a flapping signal cannot oscillate the fleet and a herd returning after an outage is
 * let in gradually. The streak lives in `rmm_shed.json` next to the state file; without a state directory there is nowhere to keep it
 * and each healthy evaluation steps down.
 *
 * {@see evaluate()} is for the maintenance cron; {@see tick()} is called on the device request path and runs an evaluation at most
 * once per {@see EVAL_INTERVAL_S} (a non-blocking lock in the state directory keeps concurrent requests from evaluating together).
 * Every transition is written to `endpoint_agent_settings.shed_level` (which refreshes the state file) and audited.
 *
 * @api
 */
final class LoadShedder
{
    public const EVAL_INTERVAL_S = 10;
    /** Consecutive evaluations below the current level that earn one step down. */
    public const HEALTHY_EVALS = 2;
    public const MEMORY_FILE = 'rmm_shed.json';
    public const LOCK_FILE = 'rmm_shed.lock';

    /** @var \Closure():float */
    private readonly \Closure $dbProbe;

    /**
     * @param (\Closure():float)|null $dbProbe milliseconds one probe query takes (default: time a settings-row SELECT); a seam for tests
     */
    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly IngestQueue $queue,
        private readonly RmmState $state,
        private readonly RmmAuditInterface $audit,
        ?\Closure $dbProbe = null,
    ) {
        $this->dbProbe = $dbProbe ?? function (): float {
            $t = hrtime(true);
            $this->sql->one('SELECT id FROM endpoint_agent_settings WHERE id = 1');

            return (hrtime(true) - $t) / 1e6;
        };
    }

    /**
     * The 503 a refused check-in gets: {"code":"unavailable"} with a Retry-After inside the configured shed window.
     *
     * @param array<string,int> $limits
     */
    public static function refusal(array $limits): ApiError
    {
        return new ApiError(503, 'unavailable', 'The service is busy. Try again later.', ['Retry-After' => (string) random_int($limits['shed_retry_min_s'], $limits['shed_retry_max_s'])]);
    }

    // ------------------------------------------------------------------ the pure parts

    /**
     * The level the signals alone call for (no hysteresis).
     *
     * @param array<string,int> $limits limits with defaults filled in ({@see RmmSettings::limits()})
     * @param array{backlog:int,db_ms:float,rate_per_min:int} $signals
     */
    public static function rawLevel(array $limits, array $signals): int
    {
        $level = 0;
        for ($n = 1; $n <= 3; ++$n) {
            $hit = ($limits["shed_backlog_l$n"] > 0 && $signals['backlog'] >= $limits["shed_backlog_l$n"])
                || ($limits["shed_db_ms_l$n"] > 0 && $signals['db_ms'] >= $limits["shed_db_ms_l$n"])
                || ($limits['shed_rate_per_min'] > 0 && $signals['rate_per_min'] >= $limits['shed_rate_per_min'] * [1 => 1, 2 => 2, 3 => 4][$n]);
            if ($hit) {
                $level = $n;
            }
        }

        return $level;
    }

    /**
     * One hysteresis step: escalate at once, recover one level per {@see HEALTHY_EVALS} consecutive evaluations below the current level.
     *
     * @return array{0:int,1:int} [new level, new healthy streak]
     */
    public static function step(int $current, int $raw, int $streak, int $healthyNeeded = self::HEALTHY_EVALS): array
    {
        if ($raw > $current) {
            return [$raw, 0];
        }
        if ($raw === $current) {
            return [$current, 0];
        }
        if ($streak + 1 >= $healthyNeeded) {
            return [$current - 1, 0];
        }

        return [$current, $streak + 1];
    }

    // ------------------------------------------------------------------ evaluation

    /** @return array{backlog:int,db_ms:float,rate_per_min:int} */
    public function signals(): array
    {
        $samples = [];
        for ($i = 0; $i < 3; ++$i) {
            $samples[] = ($this->dbProbe)();
        }
        sort($samples);

        try {
            $backlog = $this->queue->backlog()['pending'];
        } catch (\Throwable) {
            $backlog = 0;   // no job table (sync-only install): there is no queue to be behind on
        }

        return [
            'backlog' => $backlog,
            'db_ms' => round($samples[1], 2),
            'rate_per_min' => (int) $this->sql->val('SELECT COUNT(*) FROM endpoint_agent_checkins WHERE received_at >= ?', [$this->sql->utcAt(-60)]),
        ];
    }

    /**
     * Run one evaluation now (the cron entry). Pass $signals to evaluate injected numbers (tests, the simulator's inject mode).
     *
     * @param array{backlog:int,db_ms:float,rate_per_min:int}|null $signals
     * @return array{level:int,previous:int,raw:int,changed:bool,streak:int,signals:array{backlog:int,db_ms:float,rate_per_min:int}}
     */
    public function evaluate(?array $signals = null): array
    {
        $dir = $this->state->directory();
        $mem = $this->readMemory($dir);
        $previous = (int) ($this->settings->get(true)['shed_level'] ?? 0);
        $signals ??= $this->signals();
        $raw = self::rawLevel($this->settings->limits(), $signals);
        // Without a memory file there is no streak to keep: one healthy evaluation steps down.
        [$level, $streak] = self::step($previous, $raw, $dir === null ? self::HEALTHY_EVALS - 1 : (int) $mem['streak']);
        $changed = $level !== $previous;
        if ($changed) {
            $this->settings->set(['shed_level' => $level]);
            $this->audit->record('RMM Load Shed Level Changed', sprintf('Load shedding level %d to %d (raw %d; backlog %d, database probe %.1f ms, %d check-ins/min).', $previous, $level, $raw, $signals['backlog'], $signals['db_ms'], $signals['rate_per_min']), 0, 0);
        } elseif ($level > 0) {
            $this->state->sync(true);   // keep the level's timestamp fresh in the state file while it still holds
        }
        $this->writeMemory($dir, ['at' => $this->sql->time(), 'level' => $level, 'raw' => $raw, 'streak' => $streak, 'signals' => $signals]);

        return ['level' => $level, 'previous' => $previous, 'raw' => $raw, 'changed' => $changed, 'streak' => $streak, 'signals' => $signals];
    }

    /**
     * Request-path entry: evaluate when {@see EVAL_INTERVAL_S} have passed since the last evaluation, otherwise do nothing (one small
     * file read). Needs a state directory; without one only the cron evaluates. Never throws.
     *
     * @return array<string,mixed>|null the evaluation, or null when it was not due (or another request is doing it)
     */
    public function tick(): ?array
    {
        $dir = $this->state->directory();
        if ($dir === null || !$this->due($dir)) {
            return null;
        }
        $lock = @fopen(rtrim($dir, '/') . '/' . self::LOCK_FILE, 'c');
        if ($lock === false) {
            return null;
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return null;
            }
            if (!$this->due($dir)) {
                return null;   // another request evaluated while this one waited for the lock
            }

            return $this->evaluate();
        } catch (\Throwable $e) {
            error_log('rmm load shedder: ' . $e->getMessage());

            return null;
        } finally {
            fclose($lock);
        }
    }

    /**
     * Whether {@see EVAL_INTERVAL_S} have passed since the last evaluation (reads the memory file, so it must be asked again after waiting for the lock).
     *
     * @phpstan-impure
     */
    private function due(string $dir): bool
    {
        return $this->sql->time() - $this->readMemory($dir)['at'] >= self::EVAL_INTERVAL_S;
    }

    /** @return array{at:int,level:int,raw:int,streak:int,signals:array<string,mixed>} */
    public function readMemory(?string $dir): array
    {
        $empty = ['at' => 0, 'level' => 0, 'raw' => 0, 'streak' => 0, 'signals' => []];
        $raw = $dir === null ? false : @file_get_contents(rtrim($dir, '/') . '/' . self::MEMORY_FILE, false, null, 0, 16384);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($d) || !is_int($d['at'] ?? null) || !is_int($d['streak'] ?? null)) {
            return $empty;
        }

        return ['at' => $d['at'], 'level' => (int) ($d['level'] ?? 0), 'raw' => (int) ($d['raw'] ?? 0), 'streak' => max(0, $d['streak']), 'signals' => is_array($d['signals'] ?? null) ? $d['signals'] : []];
    }

    /** @param array<string,mixed> $mem */
    private function writeMemory(?string $dir, array $mem): void
    {
        if ($dir === null || !is_dir($dir)) {
            return;
        }
        $path = rtrim($dir, '/') . '/' . self::MEMORY_FILE;
        $tmp = $path . '.' . getmypid() . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($tmp, (string) json_encode($mem)) !== false) {
            @chmod($tmp, 0640);
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
            }
        }
    }
}
