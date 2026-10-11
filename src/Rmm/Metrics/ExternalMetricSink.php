<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Metrics;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Contracts\ExternalMetricStoreInterface;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;

/**
 * The "never block a check-in" buffer in front of an {@see ExternalMetricStoreInterface}.
 *
 * {@see ingest()} validates the samples exactly like {@see \RivetCore\Rmm\Support\DatabaseMetricSink} (key /^[a-z0-9_.]{1,64}$/, finite,
 * not negative, a utilization at most 100, a positive asset, instance and label cut to 64 characters), then writes ONE multi-row INSERT
 * into `rmm_metric_outbox` (JSON, at most {@see POINTS_PER_ROW} points a row) and returns. No network I/O, and any database or encoding
 * failure is swallowed and counted in {@see droppedCount()}: a metrics problem never fails a check-in.
 *
 * {@see flush()} (call it from cron every minute) sends the buffered rows to the store: it claims a row with a conditional UPDATE that sets
 * a 60 second lease (two workers never send the same row; a crashed worker's rows come back when the lease ends, and the store is
 * idempotent so a repeated send is harmless), deletes it on success, and on a transient failure keeps it with attempts + 1 and
 * next_attempt_at = now + min(900, 5 * 2^attempts) seconds, +/- 20% jitter ({@see backoffSeconds()}). A permanent failure deletes the row
 * (a poison batch never blocks the queue). A transient failure also ends the flush: the store is probably down, so the remaining rows wait
 * for the next run instead of each costing a timeout.
 *
 * Bounds: {@see trim()} (run at the start of every flush, and callable from housekeeping) deletes rows older than max_age_s and the oldest
 * rows beyond max_rows. When the store is down longer than that, the oldest history is lost; the local sink keeps the recent history.
 *
 * Options: max_rows (default 20000), max_age_s (86400), batch_rows (rows tried per round, 20), max_attempts (null = unlimited; a row that
 * failed that many times is dropped).
 *
 * @api
 */
final class ExternalMetricSink implements RmmMetricSinkInterface
{
    public const POINTS_PER_ROW = 500;
    public const ROWS_PER_STATEMENT = 20;
    public const LEASE_SECONDS = 60;
    public const BACKOFF_BASE_SECONDS = 5;
    public const BACKOFF_CAP_SECONDS = 900;
    private const KEY_RE = '/^[a-z0-9_.]{1,64}\z/';
    private const DELETE_BATCH = 5000;
    private const DELETE_ROUNDS = 20;

    private int $dropped = 0;
    private int $maxRows;
    private int $maxAgeSeconds;
    private int $batchRows;
    private ?int $maxAttempts;

    /**
     * @param array{max_rows?:int, max_age_s?:int, batch_rows?:int, max_attempts?:?int} $options
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ClockInterface $clock,
        private readonly ExternalMetricStoreInterface $store,
        array $options = [],
    ) {
        $this->maxRows = max(1, (int) ($options['max_rows'] ?? 20000));
        $this->maxAgeSeconds = max(60, (int) ($options['max_age_s'] ?? 86400));
        $this->batchRows = max(1, (int) ($options['batch_rows'] ?? 20));
        $this->maxAttempts = isset($options['max_attempts']) ? max(1, (int) $options['max_attempts']) : null;
    }

    /** Points lost since this object was created because the outbox could not be written (never validation rejects). */
    public function droppedCount(): int
    {
        return $this->dropped;
    }

    public function ingest(array $samples, int $integrationId): void
    {
        try {
            $points = [];
            foreach ($samples as $s) {
                $key = $s['key'];
                $value = $s['value'];
                if ($s['asset_id'] <= 0 || preg_match(self::KEY_RE, $key) !== 1 || !is_finite((float) $value) || $value < 0 || (str_ends_with($key, '.utilization') && $value > 100)) {
                    continue;
                }
                $points[] = [$s['asset_id'], $key, $s['instance'] === null ? null : mb_substr($s['instance'], 0, 64), $value, $s['at']->getTimestamp(),
                    $s['label'] === null ? null : mb_substr($s['label'], 0, 64)];
            }
        } catch (\Throwable) {
            ++$this->dropped;

            return;
        }
        if ($points === []) {
            return;
        }
        $now = $this->utc(0);
        try {
            $rows = [];
            foreach (array_chunk($points, self::POINTS_PER_ROW) as $chunk) {
                $rows[] = [count($chunk), json_encode($chunk, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            }
        } catch (\Throwable) {
            $this->dropped += count($points);

            return;
        }
        foreach (array_chunk($rows, self::ROWS_PER_STATEMENT) as $group) {
            try {
                $params = [];
                foreach ($group as [$n, $json]) {
                    array_push($params, $now, $n, $json, $now);
                }
                $this->database->execute('INSERT INTO rmm_metric_outbox (created_at, points, payload, next_attempt_at) VALUES '
                    . implode(',', array_fill(0, count($group), '(?, ?, ?, ?)')), $params);
            } catch (\Throwable) {
                foreach ($group as [$n]) {
                    $this->dropped += $n;
                }
            }
        }
    }

    /**
     * Send what is due. $maxBatches is the number of rounds (each tries up to batch_rows rows).
     *
     * @return array{sent:int, failed:int, permanent:int, dropped:int, pending:int} rows; dropped = rows trimmed or given up on
     */
    public function flush(int $maxBatches = 20): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'permanent' => 0, 'dropped' => $this->trim(), 'pending' => 0];
        $stop = false;
        for ($round = 0; $round < max(1, $maxBatches) && !$stop; ++$round) {
            $now = $this->utc(0);
            $due = $this->database->fetchAll('SELECT outbox_id FROM rmm_metric_outbox WHERE next_attempt_at <= ? AND (lease_until IS NULL OR lease_until < ?) ORDER BY outbox_id LIMIT ' . $this->batchRows, [$now, $now]);
            if ($due === []) {
                break;
            }
            $claimed = 0;
            foreach ($due as $candidate) {
                $id = (int) $candidate['outbox_id'];
                $now = $this->utc(0);
                $won = $this->database->execute('UPDATE rmm_metric_outbox SET lease_until = ? WHERE outbox_id = ? AND next_attempt_at <= ? AND (lease_until IS NULL OR lease_until < ?)',
                    [$this->utc(self::LEASE_SECONDS), $id, $now, $now])->affectedRows;
                if ($won < 1) {
                    continue;
                }
                ++$claimed;
                $row = $this->database->fetchOne('SELECT payload, attempts FROM rmm_metric_outbox WHERE outbox_id = ?', [$id]);
                if ($row === null) {
                    continue;
                }
                $points = self::decode((string) $row['payload']);
                if ($points === null) {
                    $this->database->execute('DELETE FROM rmm_metric_outbox WHERE outbox_id = ?', [$id]);
                    ++$out['permanent'];
                    continue;
                }
                try {
                    $this->store->write($points);
                    $this->database->execute('DELETE FROM rmm_metric_outbox WHERE outbox_id = ?', [$id]);
                    ++$out['sent'];
                } catch (\Throwable $e) {
                    $permanent = $e instanceof ExternalStoreException && $e->permanent;
                    $attempts = (int) $row['attempts'];
                    if ($permanent) {
                        $this->database->execute('DELETE FROM rmm_metric_outbox WHERE outbox_id = ?', [$id]);
                        ++$out['permanent'];
                        continue;
                    }
                    ++$out['failed'];
                    if ($this->maxAttempts !== null && $attempts + 1 >= $this->maxAttempts) {
                        $this->database->execute('DELETE FROM rmm_metric_outbox WHERE outbox_id = ?', [$id]);
                        ++$out['dropped'];
                    } else {
                        $this->database->execute('UPDATE rmm_metric_outbox SET attempts = attempts + 1, next_attempt_at = ?, lease_until = NULL, last_error = ? WHERE outbox_id = ?',
                            [$this->utc(self::backoffSeconds($attempts, random_int(-200, 200) / 1000)), self::describe($e), $id]);
                    }
                    $stop = true;
                    break;
                }
            }
            if ($claimed === 0) {
                break;
            }
        }
        $out['pending'] = $this->pending();

        return $out;
    }

    /** Rows waiting in the outbox (due or not). */
    public function pending(): int
    {
        return (int) ($this->database->fetchOne('SELECT COUNT(*) AS n FROM rmm_metric_outbox')['n'] ?? 0);
    }

    /**
     * Enforce the bound: delete rows older than max_age_s, then the oldest rows beyond max_rows. At most 100,000 rows per call (the next
     * call continues). Returns the rows deleted.
     */
    public function trim(): int
    {
        $deleted = 0;
        $old = $this->utc(-$this->maxAgeSeconds);
        for ($i = 0; $i < self::DELETE_ROUNDS; ++$i) {
            $n = $this->database->execute('DELETE FROM rmm_metric_outbox WHERE created_at < ? LIMIT ' . self::DELETE_BATCH, [$old])->affectedRows;
            $deleted += max(0, $n);
            if ($n < self::DELETE_BATCH) {
                break;
            }
        }
        $boundary = $this->database->fetchOne('SELECT outbox_id FROM rmm_metric_outbox ORDER BY outbox_id DESC LIMIT 1 OFFSET ' . $this->maxRows);
        if ($boundary !== null) {
            for ($i = 0; $i < self::DELETE_ROUNDS; ++$i) {
                $n = $this->database->execute('DELETE FROM rmm_metric_outbox WHERE outbox_id <= ? ORDER BY outbox_id LIMIT ' . self::DELETE_BATCH, [(int) $boundary['outbox_id']])->affectedRows;
                $deleted += max(0, $n);
                if ($n < self::DELETE_BATCH) {
                    break;
                }
            }
        }

        return $deleted;
    }

    /**
     * Seconds to wait after a transient failure: min(900, 5 * 2^attempts) where $attempts is the number of failures BEFORE this one, then
     * scaled by (1 + $jitter) with $jitter in -0.2..0.2 (clamped). At least 1.
     */
    public static function backoffSeconds(int $attempts, float $jitter = 0.0): int
    {
        $base = min(self::BACKOFF_CAP_SECONDS, self::BACKOFF_BASE_SECONDS * (2 ** min(max(0, $attempts), 20)));

        return max(1, (int) round($base * (1 + max(-0.2, min(0.2, $jitter)))));
    }

    /**
     * @return list<array{asset_id:int, key:string, instance:?string, value:int|float, at:\DateTimeImmutable, label:?string}>|null
     */
    private static function decode(string $payload): ?array
    {
        try {
            $rows = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($rows)) {
            return null;
        }
        $points = [];
        foreach ($rows as $r) {
            if (!is_array($r) || count($r) !== 6 || !is_int($r[0]) || !is_string($r[1]) || !(is_int($r[3]) || is_float($r[3])) || !is_int($r[4])) {
                return null;
            }
            $points[] = ['asset_id' => $r[0], 'key' => $r[1], 'instance' => is_string($r[2]) ? $r[2] : null, 'value' => $r[3],
                'at' => new \DateTimeImmutable('@' . $r[4]), 'label' => is_string($r[5]) ? $r[5] : null];
        }

        return $points;
    }

    /** A short single-line message for last_error, with anything that looks like a credential removed. */
    private static function describe(\Throwable $e): string
    {
        $m = $e instanceof ExternalStoreException ? $e->getMessage() : 'unexpected ' . $e::class;
        $m = (string) preg_replace('/\b(Bearer|Basic)\s+\S+/i', '$1 [redacted]', $m);
        $m = (string) preg_replace('#(https?://)[^/\s@]+@#i', '$1[redacted]@', $m);
        $m = (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $m);

        return mb_substr(trim($m), 0, 200);
    }

    private function utc(int $offsetSeconds): string
    {
        return $this->clock->now()->modify(($offsetSeconds >= 0 ? '+' : '') . $offsetSeconds . ' seconds')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
