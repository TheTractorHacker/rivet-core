<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

/**
 * The watermark of the daily metric rollup, in the database that holds the tiers it describes (`rmm_metric_rollup_state`, decision 3 of #89 phase 1).
 *
 * @internal
 */
final class PostgresRollupState
{
    public const ROLLUP = 'metric_daily_rollup';

    public function __construct(private readonly PostgresConnectionInterface $db)
    {
    }

    /** @return array{watermark:?string,detail:?array<string,mixed>}|null */
    public function get(string $name = self::ROLLUP): ?array
    {
        $r = $this->db->queryOne('SELECT ' . PostgresSql::ts('watermark', 'watermark') . ', detail::text AS detail FROM rmm_metric_rollup_state WHERE name = ?', [$name]);
        if ($r === null) {
            return null;
        }
        $detail = is_string($r['detail'] ?? null) ? json_decode((string) $r['detail'], true) : null;

        return ['watermark' => $r['watermark'] === null ? null : (string) $r['watermark'], 'detail' => is_array($detail) ? $detail : null];
    }

    /** @param array<string,mixed>|null $detail */
    public function put(string $name, ?string $watermark, ?array $detail = null): void
    {
        $this->db->execute('INSERT INTO rmm_metric_rollup_state (name, watermark, detail, updated_at) VALUES (?, ?::timestamptz, ?::jsonb, now())
            ON CONFLICT (name) DO UPDATE SET watermark = EXCLUDED.watermark, detail = EXCLUDED.detail, updated_at = EXCLUDED.updated_at',
            [$name, $watermark, $detail === null ? null : json_encode($detail, JSON_THROW_ON_ERROR)]);
    }

    public function watermark(): ?string
    {
        return $this->get()['watermark'] ?? null;
    }
}
