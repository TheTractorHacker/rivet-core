<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Enrollment;

use RivetCore\Rmm\Support\Sql;

/**
 * The database-backed attempt log behind the enrollment and installer-download rate limits (so they work without Redis). Both
 * share endpoint_agent_enroll_attempts; the salt of the address hash keeps their buckets apart.
 *
 * CORE-API-3 (red team 2026-11-21): the limiter bucket of an IPv6 address is its /64 (one subscriber's network; rotating addresses inside it no longer gives
 * unlimited attempts), the full address is still kept in `ip_text`. Past {@see AUDIT_FULL} rejected attempts of one kind in {@see AUDIT_WINDOW_S}, instance-wide,
 * the audit log is sampled ({@see auditSampling()}) instead of getting a row per attempt; nothing is ever refused for the instance as a whole (that would let
 * one attacker stop every device from enrolling), every attempt stays in the attempt table, and the per-address limits are unchanged.
 *
 * @api
 */
final class AttemptLog
{
    /** Kinds of attempts, told apart by the reason prefix the callers write. */
    public const KIND_ENROLL = 'enroll';
    public const KIND_PANEL = 'panel';
    public const KIND_INSTALLER = 'installer';
    /** Rejected attempts of one kind in this window (instance-wide) that each get an audit row of their own. */
    public const AUDIT_WINDOW_S = 600;
    public const AUDIT_FULL = 100;
    /** Past {@see AUDIT_FULL}, one audit row per this many attempts (by attempt id), saying how many it stands for. */
    public const AUDIT_SAMPLE_EVERY = 100;

    public function __construct(private readonly Sql $sql)
    {
    }

    /** The limiter key of an address: the address hashed with the bucket's salt; an IPv6 address counts as its /64 (an IPv4-mapped one as the IPv4 address). */
    public static function ipHash(string $salt, string $ip): string
    {
        return hash('sha256', $salt . self::limiterAddress($ip));
    }

    /** The address the limiter counts: IPv6 cut to its /64 ("2001:db8:1:2::/64"), anything else unchanged. */
    public static function limiterAddress(string $ip): string
    {
        if (!str_contains($ip, ':') || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }
        $bin = inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return $ip;
        }
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($bin, 12));   // ::ffff:a.b.c.d is the IPv4 address
        }

        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    public function record(string $salt, string $ip, bool $ok, string $reason, string $selector): void
    {
        $this->insert($salt, $ip, $ok, $reason, $selector);
    }

    /** {@see record()} that answers the new attempt's id (for {@see auditSampling()}). */
    public function insert(string $salt, string $ip, bool $ok, string $reason, string $selector): int
    {
        return $this->sql->insert('INSERT INTO endpoint_agent_enroll_attempts (ip_hash, ip_text, success, reason, token_selector, attempted_at) VALUES (?, ?, ?, ?, ?, ?)',
            [self::ipHash($salt, $ip), substr($ip, 0, 64), $ok ? 1 : 0, substr($reason, 0, 40), substr($selector, 0, 12), $this->sql->utcNow()]);
    }

    /**
     * CORE-API-3: does the rejected attempt `$attemptId` of this kind get an audit row? null: yes, as always (fewer than {@see AUDIT_FULL} rejections of its kind
     * in the window); '': no, it is only in the attempt table; any other string: yes, and append it to the row's text (it says how many attempts the row stands
     * for). Reads at most AUDIT_FULL + 1 recent rows and one id range, so a flood cannot make the check expensive.
     */
    public function auditSampling(string $kind, int $attemptId): ?string
    {
        [$where, $params] = self::kindWhere($kind);
        $recent = (int) $this->sql->val('SELECT COUNT(*) FROM (SELECT 1 FROM endpoint_agent_enroll_attempts WHERE attempted_at > ? AND success = 0 AND ' . $where . ' LIMIT ' . (self::AUDIT_FULL + 1) . ') t',
            array_merge([$this->sql->utcAt(-self::AUDIT_WINDOW_S)], $params));
        if ($recent <= self::AUDIT_FULL) {
            return null;
        }
        if ($attemptId % self::AUDIT_SAMPLE_EVERY !== 0) {
            return '';
        }
        $from = $attemptId - self::AUDIT_SAMPLE_EVERY + 1;
        $n = (int) $this->sql->val('SELECT COUNT(*) FROM endpoint_agent_enroll_attempts WHERE attempt_id BETWEEN ? AND ? AND success = 0 AND ' . $where, array_merge([$from, $attemptId], $params));

        return " [sampled: more than " . self::AUDIT_FULL . ' rejected attempts in ' . intdiv(self::AUDIT_WINDOW_S, 60) . " minutes across the instance, so one audit row per "
            . self::AUDIT_SAMPLE_EVERY . " attempts; this one stands for $n rejected attempts (#$from to #$attemptId), every attempt is in the enrollment attempt log]";
    }

    /** @return array{0:string,1:list<string>} */
    private static function kindWhere(string $kind): array
    {
        return match ($kind) {
            self::KIND_PANEL => ["reason LIKE ?", ['panel\\_%']],
            self::KIND_INSTALLER => ["reason LIKE ?", ['installer\\_%']],
            default => ["reason NOT LIKE ? AND reason NOT LIKE ?", ['panel\\_%', 'installer\\_%']],
        };
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
