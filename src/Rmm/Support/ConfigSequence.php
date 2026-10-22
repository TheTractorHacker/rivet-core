<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Support;

use RivetCore\Rmm\Crypto\CanonicalJson;

/**
 * The `seq` of a signed agent configuration (change monitor, hypervisor): a monotonic counter that does not depend on the clock or on any `updated_at` column.
 *
 * An agent keeps the highest `seq` it accepted and refuses a lower one (so an old signed configuration cannot be replayed). The counter is stored per configuration together with a
 * hash of the configuration body (everything but `seq`, `version` and `signature`). When the body changes the counter becomes `max(stored + 1, now)`: it always increases, a clock
 * that stepped backwards cannot lower it, and after a database restore the first change jumps to the real current time, above anything offered before. When the body is the same the
 * same `seq` is offered again (a changed seq would make agents re-apply an unchanged configuration).
 *
 * Without migration 0038 the table is missing: the caller's legacy value (the settings row's `updated_at`) is returned, which is exactly the previous behaviour.
 *
 * @api
 */
final class ConfigSequence
{
    private const TABLE = 'endpoint_agent_config_seq';

    public function __construct(private readonly Sql $sql)
    {
    }

    /**
     * @param string $name e.g. `change:linux`
     * @param array<string,mixed> $body the configuration without seq, version and signature
     * @param int $legacy the value offered before this counter existed (used to start the counter, and when the table is missing)
     */
    public function seq(string $name, array $body, int $legacy): int
    {
        $legacy = max(1, $legacy);
        if (!$this->available()) {
            return $legacy;
        }
        $hash = hash('sha256', CanonicalJson::encode($body));
        $now = max(1, $this->sql->time());
        try {
            $row = $this->sql->one('SELECT seq, body_hash FROM `' . self::TABLE . '` WHERE name = ?', [$name]);
            if ($row === null) {
                // first sight: start at the value agents have already been offered, so nothing regresses
                $this->sql->run('INSERT IGNORE INTO `' . self::TABLE . '` (name, seq, body_hash, updated_at) VALUES (?, ?, ?, ?)', [$name, $legacy, $hash, $this->sql->utcNow()]);
                $row = $this->sql->one('SELECT seq, body_hash FROM `' . self::TABLE . '` WHERE name = ?', [$name]);
            }
            if ($row !== null && (string) $row['body_hash'] !== $hash) {
                // a conditional update: when two requests see the same change only the first one bumps
                $this->sql->run('UPDATE `' . self::TABLE . '` SET seq = GREATEST(seq + 1, ?), body_hash = ?, updated_at = ? WHERE name = ? AND body_hash <> ?', [$now, $hash, $this->sql->utcNow(), $name, $hash]);
                $row = $this->sql->one('SELECT seq, body_hash FROM `' . self::TABLE . '` WHERE name = ?', [$name]);
            }

            return $row === null ? $legacy : max(1, (int) $row['seq']);
        } catch (\Throwable $e) {
            error_log('rmm config seq: ' . $e->getMessage());

            return $legacy;
        }
    }

    /** @var \WeakMap<Sql,array{0:bool,1:int}>|null */
    private static ?\WeakMap $seen = null;

    /** Forget what {@see available()} learned (tests that create or drop the table on a live connection). */
    public static function forget(): void
    {
        self::$seen = null;
    }

    private function available(): bool
    {
        self::$seen ??= new \WeakMap();
        $hit = self::$seen[$this->sql] ?? null;
        if ($hit !== null && ($hit[0] || time() - $hit[1] < 30)) {
            return $hit[0];
        }
        $row = $this->sql->one('SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [self::TABLE]);
        $ok = (int) ($row['c'] ?? 0) === 1;
        self::$seen[$this->sql] = [$ok, time()];

        return $ok;
    }
}
