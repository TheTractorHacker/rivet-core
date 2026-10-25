<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Storage;

use RivetCore\Rmm\Support\Sql;

/**
 * Named watermarks and notes of the storage jobs (`rmm_storage_state`, migration 0021): the last partition probe and maintenance result, the warm-start state of
 * the herd control and where the daily rollup stands. Reads tolerate a missing table (an install that has not run migration 0021 yet): the answer is simply "nothing".
 *
 * The ROLLUP watermark belongs with the metric tiers, so since migration 0043 it lives in `rmm_metric_rollup_state` (phase 2 of #89). It is read from there, falling back to the
 * old row of `rmm_storage_state` when the new table or row does not exist yet, and written to BOTH, so rolling back to the previous release (which reads the old row) loses nothing.
 *
 * @api
 */
final class StorageState
{
    public const ROLLUP = 'metric_daily_rollup';
    public const PROBE = 'partition_probe';
    public const MAINTENANCE = 'partition_maintenance';

    public function __construct(private readonly Sql $sql)
    {
    }

    /** @return array{watermark:?string,detail:?array<string,mixed>,updated_at:?string}|null */
    public function get(string $name): ?array
    {
        $r = null;
        if ($name === self::ROLLUP) {
            try {
                $r = $this->sql->one('SELECT watermark, detail, updated_at FROM rmm_metric_rollup_state WHERE name = ?', [$name]);
            } catch (\Throwable) {
                $r = null;   // migration 0043 not applied yet: the old row answers
            }
        }
        try {
            $r ??= $this->sql->one('SELECT watermark, detail, updated_at FROM rmm_storage_state WHERE name = ?', [$name]);
        } catch (\Throwable) {
            return null;
        }
        if ($r === null) {
            return null;
        }
        $detail = is_string($r['detail'] ?? null) ? json_decode((string) $r['detail'], true) : null;

        return ['watermark' => $r['watermark'] === null ? null : (string) $r['watermark'], 'detail' => is_array($detail) ? $detail : null, 'updated_at' => (string) $r['updated_at']];
    }

    /** @param array<string,mixed>|null $detail */
    public function put(string $name, ?string $watermark, ?array $detail = null): void
    {
        if ($name === self::ROLLUP) {
            try {
                $this->sql->run('INSERT INTO rmm_metric_rollup_state (name, watermark, detail, updated_at) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE watermark = VALUES(watermark), detail = VALUES(detail), updated_at = VALUES(updated_at)',
                    [$name, $watermark, $detail === null ? null : json_encode($detail), $this->sql->utcNow()]);
            } catch (\Throwable) {
                // migration 0043 not applied yet: the old row below is the only copy, as before
            }
        }
        $this->sql->run('INSERT INTO rmm_storage_state (name, watermark, detail, updated_at) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE watermark = VALUES(watermark), detail = VALUES(detail), updated_at = VALUES(updated_at)',
            [$name, $watermark, $detail === null ? null : json_encode($detail), $this->sql->utcNow()]);
    }

    /** The first day (UTC midnight) NOT yet rolled up into the daily tier, or null when no rollup has run. */
    public function rollupWatermark(): ?string
    {
        return $this->get(self::ROLLUP)['watermark'] ?? null;
    }
}
