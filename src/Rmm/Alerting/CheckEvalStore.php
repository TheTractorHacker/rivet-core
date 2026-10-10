<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

use RivetCore\Rmm\Support\Sql;

/**
 * The per-check evaluation state of Phase 3 (table rmm_check_eval): the effective threshold tier and the candidate tier with its duration
 * counters, the flap history and the optional per-device threshold override. A row exists
 * only for a check whose definition declares thresholds or flap detection, or that has a per-device override. Check-ins of one device
 * are serialised by the device row lock, so a device's rows have a single writer.
 *
 * @api
 */
final class CheckEvalStore
{
    public function __construct(private readonly Sql $sql)
    {
    }

    /**
     * @param list<string> $keys
     * @return array<string,array<string,mixed>> check key => row
     */
    public function load(int $deviceId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $out = [];
        foreach ($this->sql->all('SELECT * FROM rmm_check_eval WHERE device_id = ? AND check_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', array_merge([$deviceId], $keys)) as $r) {
            $out[(string) $r['check_key']] = $r;
        }

        return $out;
    }

    /** @return array<string,mixed> a row with every column at its default */
    public static function blank(): array
    {
        return ['tier' => 'ok', 'cand_tier' => 'ok', 'cand_since' => null, 'cand_count' => 0, 'last_reading' => null, 'flap_bits' => 0, 'flap_n' => 0, 'flapping' => 0,
            'override_json' => null, 'override_by' => 0, 'override_at' => null];
    }

    /** @param array<string,mixed> $row a full row as {@see blank()} shapes it */
    public function save(int $deviceId, string $key, array $row): void
    {
        $r = $row + self::blank();
        $this->sql->run('INSERT INTO rmm_check_eval (device_id, check_key, tier, cand_tier, cand_since, cand_count, last_reading, flap_bits, flap_n, flapping,
            override_json, override_by, override_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE tier = VALUES(tier), cand_tier = VALUES(cand_tier), cand_since = VALUES(cand_since), cand_count = VALUES(cand_count), last_reading = VALUES(last_reading),
            flap_bits = VALUES(flap_bits), flap_n = VALUES(flap_n), flapping = VALUES(flapping),
            override_json = VALUES(override_json), override_by = VALUES(override_by), override_at = VALUES(override_at), updated_at = VALUES(updated_at)',
            [$deviceId, $key, $r['tier'], $r['cand_tier'], $r['cand_since'], (int) $r['cand_count'], $r['last_reading'], (int) $r['flap_bits'], (int) $r['flap_n'], (int) $r['flapping'],
                $r['override_json'], (int) $r['override_by'], $r['override_at'], $this->sql->utcNow()]);
    }

    /**
     * Override of one device and check, or null.
     * @return array<string,mixed>|null the normalised thresholds
     */
    public function override(int $deviceId, string $key): ?array
    {
        $r = $this->sql->one('SELECT override_json FROM rmm_check_eval WHERE device_id = ? AND check_key = ?', [$deviceId, $key]);
        $d = $r === null || $r['override_json'] === null ? null : json_decode((string) $r['override_json'], true);

        return is_array($d) ? $d : null;
    }

    /**
     * @param array<string,mixed>|null $thresholds normalised thresholds, or null to remove the override
     */
    public function setOverride(int $deviceId, string $key, ?array $thresholds, int $userId): void
    {
        $row = $this->sql->one('SELECT * FROM rmm_check_eval WHERE device_id = ? AND check_key = ?', [$deviceId, $key]) ?? self::blank();
        $row['override_json'] = $thresholds === null ? null : json_encode($thresholds);
        $row['override_by'] = $thresholds === null ? 0 : $userId;
        $row['override_at'] = $thresholds === null ? null : $this->sql->utcNow();
        $this->save($deviceId, $key, $row);
    }

    /** Rows with nothing to remember (ok, not flapping, nothing held, no override) that have not changed for $days days. */
    public function prune(int $days, int $limit = 5000): int
    {
        return $this->sql->run("DELETE FROM rmm_check_eval WHERE tier = 'ok' AND cand_tier = 'ok' AND flapping = 0 AND override_json IS NULL AND updated_at < ? LIMIT " . $limit,
            [$this->sql->utcAt(-$days * 86400)]);
    }
}
