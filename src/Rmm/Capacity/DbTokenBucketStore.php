<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Capacity;

use RivetCore\Rmm\Support\Sql;

/**
 * Token buckets in `rmm_token_buckets` (migration 0021): one short transaction per take (the bucket row is locked, refilled by elapsed time, decremented). Used
 * when no Redis is configured; costs about four statements per take, so enable the per-client bucket knowing that. Fails open on any database error.
 *
 * @api
 */
final class DbTokenBucketStore implements TokenBucketStoreInterface
{
    public function __construct(private readonly Sql $sql)
    {
    }

    public function take(string $key, int $burst, float $perSecond, int $nowMs): array
    {
        $key = substr($key, 0, 80);
        try {
            return $this->sql->transaction(function () use ($key, $burst, $perSecond, $nowMs): array {
                $row = $this->sql->one('SELECT tokens, refilled_ms FROM rmm_token_buckets WHERE bucket_key = ? FOR UPDATE', [$key]);
                if ($row === null) {
                    $this->sql->run('INSERT IGNORE INTO rmm_token_buckets (bucket_key, tokens, refilled_ms) VALUES (?, ?, ?)', [$key, $burst, $nowMs]);
                    $row = $this->sql->one('SELECT tokens, refilled_ms FROM rmm_token_buckets WHERE bucket_key = ? FOR UPDATE', [$key]) ?? ['tokens' => $burst, 'refilled_ms' => $nowMs];
                }
                $r = self::step((float) $row['tokens'], (int) $row['refilled_ms'], $burst, $perSecond, $nowMs);
                $this->sql->run('UPDATE rmm_token_buckets SET tokens = ?, refilled_ms = ? WHERE bucket_key = ?', [$r['tokens'], $nowMs, $key]);

                return ['ok' => $r['ok'], 'retry_ms' => $r['retry_ms']];
            });
        } catch (\Throwable) {
            return ['ok' => true, 'retry_ms' => 0];
        }
    }

    /**
     * The pure bucket arithmetic (also the specification of the Redis script).
     *
     * @return array{ok:bool,tokens:float,retry_ms:int}
     */
    public static function step(float $tokens, int $refilledMs, int $burst, float $perSecond, int $nowMs): array
    {
        $tokens = min((float) $burst, $tokens + max(0, $nowMs - $refilledMs) * $perSecond / 1000.0);
        if ($tokens >= 1.0) {
            return ['ok' => true, 'tokens' => $tokens - 1.0, 'retry_ms' => 0];
        }

        return ['ok' => false, 'tokens' => $tokens, 'retry_ms' => $perSecond > 0 ? (int) ceil((1.0 - $tokens) / $perSecond * 1000) : 60000];
    }
}
