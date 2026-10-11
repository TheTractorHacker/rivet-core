<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Storage;

use RivetCore\Rmm\Support\Sql;

/**
 * Named watermarks and notes of the storage jobs (`rmm_storage_state`, migration 0021): where the daily rollup stands, the last partition probe
 * and maintenance result. Reads tolerate a missing table (an install that has not run migration 0021 yet): the answer is simply "nothing".
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
        try {
            $r = $this->sql->one('SELECT watermark, detail, updated_at FROM rmm_storage_state WHERE name = ?', [$name]);
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
