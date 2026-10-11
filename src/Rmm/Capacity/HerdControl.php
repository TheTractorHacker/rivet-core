<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Capacity;

use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\RmmState;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Thundering-herd control (scaling item S8): what keeps ten thousand agents that all come back at once after an outage from taking the server down again.
 * Agents already back off with full jitter and honour `Retry-After`; this is the server's half.
 *
 *  - WARM START. For `warm_start_s` seconds after the module is switched back on (or after a quiet gap longer than `warm_quiet_s` with no device request at all, which is
 *    what an outage of the web tier, the database or the network looks like from here) only a growing share of check-ins is admitted: `warm_start_pct` percent at the start,
 *    rising linearly to 100 %. Which devices are admitted is a hash of the device credential and a ten second slot, so the share is exact, needs no database and re-draws
 *    for every device every ten seconds. A refused check-in is answered `503 unavailable` with a JITTERED `Retry-After`, before authentication or any query. Off when
 *    `warm_start_s` is 0 (the default).
 *  - A TOKEN BUCKET PER CLIENT (`client_bucket_burst` tokens, refilled at `client_bucket_per_min` a minute; 0 = off, the default) for check-ins and, separately, enrollments:
 *    one tenant's fleet returning after its own outage cannot starve the others. Over the bucket: `503 unavailable` with a Retry-After of the time to the next token plus jitter.
 *
 * The warm window and the "last request seen" stamp live in `rmm_herd.json` in the state directory (shared by all web nodes, like the state file); without a state
 * directory only the explicit trigger (switching the module on) works and the window is kept in `rmm_storage_state`. Nothing here ever throws anything but the 503.
 *
 * @api
 */
final class HerdControl
{
    public const FILE = 'rmm_herd.json';
    public const SLOT_S = 10;
    public const SEEN_EVERY_S = 5;
    public const DB_ROW = 'warm_start';

    /** @var \Closure():int */
    private readonly \Closure $clockMs;

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly RmmState $state,
        private readonly ?TokenBucketStoreInterface $buckets = null,
        ?\Closure $clockMs = null,
    ) {
        $this->clockMs = $clockMs ?? function (): int {
            $t = microtime(true);

            return $this->sql->time() * 1000 + (int) (($t - floor($t)) * 1000);
        };
    }

    // ------------------------------------------------------------------ the pure parts

    /**
     * The share (0..100, fractional) of check-ins admitted $elapsed seconds into a warm start of $duration seconds that begins at $startPct.
     */
    public static function admittedPercent(int $elapsed, int $duration, int $startPct): float
    {
        if ($duration <= 0 || $elapsed >= $duration) {
            return 100.0;
        }

        return min(100.0, $startPct + (100 - $startPct) * max(0, $elapsed) / $duration);
    }

    /** Whether a device is admitted in this ten second slot at the given share. Deterministic, uniform, independent per device and per slot. */
    public static function admits(string $deviceKey, int $nowSeconds, float $percent): bool
    {
        if ($percent >= 100.0) {
            return true;
        }

        return (crc32($deviceKey . '|' . intdiv($nowSeconds, self::SLOT_S)) % 10000) < (int) round($percent * 100);
    }

    /** The Retry-After window of a refused check-in: wide while the ramp is young, never under one slot. @return array{0:int,1:int} [min, max] seconds */
    public static function retryWindow(int $remainingS): array
    {
        return [self::SLOT_S, max(2 * self::SLOT_S, min(120, intdiv($remainingS, 6)))];
    }

    // ------------------------------------------------------------------ warm start

    /** Begin a warm start now (the module was switched on, or a quiet gap ended). A no-op while `warm_start_s` is 0. */
    public function startWarmStart(): void
    {
        $limits = $this->settings->limits();
        if ($limits['warm_start_s'] <= 0) {
            return;
        }
        $now = $this->sql->time();
        $mem = $this->read();
        $this->write(['from' => $now, 'until' => $now + $limits['warm_start_s'], 'pct' => $limits['warm_start_pct'], 'seen' => $now] + $mem);
    }

    /**
     * First thing a check-in does: note that the service is alive (and start a warm start if it has been dark for longer than `warm_quiet_s`), then admit or refuse the
     * device. Refusing costs one small file read; nothing else is touched.
     *
     * @param string|null $credential the Authorization header (only hashed into the admission slot; null/empty is admitted and fails authentication as usual)
     * @throws ApiError 503 unavailable with a jittered Retry-After
     */
    public function beforeCheckin(?string $credential): void
    {
        $limits = $this->settings->limits();
        if ($limits['warm_start_s'] <= 0) {
            return;
        }
        $now = $this->sql->time();
        $mem = $this->read();
        if ($mem['seen'] > 0 && $now - $mem['seen'] > $limits['warm_quiet_s'] && $now >= $mem['until']) {
            $mem = ['from' => $now, 'until' => $now + $limits['warm_start_s'], 'pct' => $limits['warm_start_pct'], 'seen' => $now];
            $this->write($mem);
        } elseif ($now - $mem['seen'] >= self::SEEN_EVERY_S) {
            $mem['seen'] = $now;
            $this->write($mem);
        }
        if ($now >= $mem['until'] || $credential === null || $credential === '') {
            return;
        }
        $pct = self::admittedPercent($now - $mem['from'], $mem['until'] - $mem['from'], $mem['pct']);
        if (self::admits($credential, $now, $pct)) {
            return;
        }
        [$lo, $hi] = self::retryWindow($mem['until'] - $now);

        throw new ApiError(503, 'unavailable', 'The service is starting up. Try again later.', ['Retry-After' => (string) random_int($lo, $hi)]);
    }

    /** @return array{active:bool,from:int,until:int,pct:int,admitted_percent:float,seen:int} for the capacity panel */
    public function status(): array
    {
        $mem = $this->read();
        $now = $this->sql->time();
        $active = $now < $mem['until'];

        return ['active' => $active, 'from' => $mem['from'], 'until' => $mem['until'], 'pct' => $mem['pct'], 'seen' => $mem['seen'],
            'admitted_percent' => $active ? round(self::admittedPercent($now - $mem['from'], $mem['until'] - $mem['from'], $mem['pct']), 1) : 100.0];
    }

    // ------------------------------------------------------------------ token buckets

    /**
     * Take one token from the client's bucket of this kind ('checkin' or 'enroll').
     *
     * @throws ApiError 503 unavailable with a Retry-After of the time to the next token plus jitter
     */
    public function clientBucket(string $kind, int $clientId): void
    {
        $limits = $this->settings->limits();
        if ($limits['client_bucket_burst'] <= 0 || $limits['client_bucket_per_min'] <= 0 || $this->buckets === null) {
            return;
        }
        $r = $this->buckets->take($kind . ':' . $clientId, $limits['client_bucket_burst'], $limits['client_bucket_per_min'] / 60.0, ($this->clockMs)());
        if ($r['ok']) {
            return;
        }
        $wait = (int) ceil($r['retry_ms'] / 1000);

        throw new ApiError(503, 'unavailable', 'The service is busy. Try again later.', ['Retry-After' => (string) min(300, $wait + random_int(1, max(2, intdiv($wait, 2) + 1)))]);
    }

    // ------------------------------------------------------------------ memory (file in the state directory, else a database row)

    /** @return array{from:int,until:int,pct:int,seen:int} */
    private function read(): array
    {
        $empty = ['from' => 0, 'until' => 0, 'pct' => 10, 'seen' => 0];
        $dir = $this->state->directory();
        if ($dir !== null) {
            $raw = @file_get_contents(rtrim($dir, '/') . '/' . self::FILE, false, null, 0, 4096);
            $d = is_string($raw) ? json_decode($raw, true) : null;
        } else {
            $row = $this->sql->one('SELECT detail FROM rmm_storage_state WHERE name = ?', [self::DB_ROW]);
            $d = is_string($row['detail'] ?? null) ? json_decode((string) $row['detail'], true) : null;
        }
        if (!is_array($d)) {
            return $empty;
        }

        return ['from' => (int) ($d['from'] ?? 0), 'until' => (int) ($d['until'] ?? 0), 'pct' => max(1, min(100, (int) ($d['pct'] ?? 10))), 'seen' => (int) ($d['seen'] ?? 0)];
    }

    /** @param array{from:int,until:int,pct:int,seen:int} $mem */
    private function write(array $mem): void
    {
        $dir = $this->state->directory();
        if ($dir === null) {
            try {
                $this->sql->run('INSERT INTO rmm_storage_state (name, detail, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE detail = VALUES(detail), updated_at = VALUES(updated_at)',
                    [self::DB_ROW, (string) json_encode($mem), $this->sql->utcNow()]);
            } catch (\Throwable) {
                // a guard that cannot be remembered is simply not in force
            }

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        $path = rtrim($dir, '/') . '/' . self::FILE;
        $tmp = $path . '.' . getmypid() . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($tmp, (string) json_encode($mem)) !== false) {
            @chmod($tmp, 0640);
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
            }
        }
    }
}
