<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Enrollment;

use RivetCore\Rmm\Support\Sql;

/**
 * The database-backed attempt log behind the enrollment and installer-download rate limits (so they work without Redis). Both
 * share endpoint_agent_enroll_attempts; the salt of the address hash keeps their buckets apart.
 *
 * @api
 */
final class AttemptLog
{
    public function __construct(private readonly Sql $sql)
    {
    }

    public static function ipHash(string $salt, string $ip): string
    {
        return hash('sha256', $salt . $ip);
    }

    public function record(string $salt, string $ip, bool $ok, string $reason, string $selector): void
    {
        $this->sql->run('INSERT INTO endpoint_agent_enroll_attempts (ip_hash, ip_text, success, reason, token_selector, attempted_at) VALUES (?, ?, ?, ?, ?, ?)',
            [self::ipHash($salt, $ip), substr($ip, 0, 64), $ok ? 1 : 0, substr($reason, 0, 40), substr($selector, 0, 12), $this->sql->utcNow()]);
    }

    /** @return array{total:int,failures:int} attempts of this address bucket inside the window */
    public function byAddress(string $salt, string $ip, int $windowS): array
    {
        $row = $this->sql->one('SELECT COUNT(*) AS total, COALESCE(SUM(success = 0), 0) AS failures FROM endpoint_agent_enroll_attempts WHERE ip_hash = ? AND attempted_at > ?',
            [self::ipHash($salt, $ip), $this->sql->utcAt(-$windowS)]);

        return ['total' => (int) ($row['total'] ?? 0), 'failures' => (int) ($row['failures'] ?? 0)];
    }

    /** @return array{ok:int,bad:int} installer downloads and failures of one token selector inside the window */
    public function byInstallerSelector(string $selector, int $windowS): array
    {
        $row = $this->sql->one("SELECT COALESCE(SUM(success = 1), 0) AS ok, COALESCE(SUM(success = 0), 0) AS bad FROM endpoint_agent_enroll_attempts WHERE reason LIKE 'installer\\_%' AND token_selector = ? AND attempted_at > ?",
            [$selector, $this->sql->utcAt(-$windowS)]);

        return ['ok' => (int) ($row['ok'] ?? 0), 'bad' => (int) ($row['bad'] ?? 0)];
    }
}
