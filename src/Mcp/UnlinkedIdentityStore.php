<?php

declare(strict_types=1);

namespace RivetCore\Mcp;

use RivetCore\Database\DatabaseException;
use RivetCore\Database\DatabaseInterface;

/**
 * Remembers valid OAuth identities (issuer + immutable subject) that are not linked to an agent yet, so an
 * administrator can pick the agent from a list instead of copying subject ids by hand. Only tokens that
 * already passed signature, issuer, audience, scope and expiry checks are ever recorded.
 *
 * Logging: $logError accepts a PSR-3 LoggerInterface (preferred) or null (ErrorLogLogger). The Closure form (receives the
 * message string) is @deprecated: kept working through all of 1.x, removed in 2.0.
 *
 * @api
 */
final class UnlinkedIdentityStore
{
    public const MAX_PENDING = 200;
    public const KEEP_DAYS = 30;

    /** @param \Closure|\Psr\Log\LoggerInterface|null $logError the Closure form is @deprecated (kept through 1.x, removed in 2.0); pass a PSR-3 logger */
    public function __construct(private DatabaseInterface $database, private \Closure|\Psr\Log\LoggerInterface|null $logError = null)
    {
    }

    private static function clean(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '');

        return $v === '' ? null : mb_substr($v, 0, 200);
    }

    /**
     * Remember an unlinked but valid identity. Never throws: a failure here must not change the 403.
     *
     * @param array<string,mixed> $claims
     */
    public function record(string $issuer, string $subject, array $claims): void
    {
        try {
            $email = self::clean($claims['email'] ?? null);
            $name = self::clean($claims['name'] ?? ($claims['preferred_username'] ?? null));
            $touch = 'UPDATE mcp_unlinked_identities SET attempts = attempts + 1, last_seen_at = NOW(),
                        email = COALESCE(?, email), display_name = COALESCE(?, display_name)
                      WHERE issuer = ? AND subject = ?';
            if ($this->database->execute($touch, [$email, $name, $issuer, $subject])->affectedRows > 0) {
                return;
            }
            $count = (int) ($this->database->fetchOne('SELECT COUNT(*) AS c FROM mcp_unlinked_identities')['c'] ?? 0);
            if ($count >= self::MAX_PENDING) {
                return;
            }
            try {
                $this->database->execute(
                    'INSERT INTO mcp_unlinked_identities (issuer, subject, email, display_name) VALUES (?, ?, ?, ?)',
                    [$issuer, $subject, $email, $name]
                );
            } catch (DatabaseException) {
                // Lost a race with a concurrent first sighting: count this one as a repeat.
                $this->database->execute($touch, [$email, $name, $issuer, $subject]);
            }
        } catch (\Throwable $e) {
            \RivetCore\Support\ErrorLogLogger::resolve($this->logError)->error('MCP unlinked identity not recorded: ' . $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    public function pending(): array
    {
        $now = $this->database->fetchOne('SELECT NOW() AS n');
        $cutoff = (new \DateTimeImmutable((string) $now['n']))->modify('-' . self::KEEP_DAYS . ' days')->format('Y-m-d H:i:s');
        $this->database->execute('DELETE FROM mcp_unlinked_identities WHERE last_seen_at < ?', [$cutoff]);

        return $this->database->fetchAll('SELECT * FROM mcp_unlinked_identities ORDER BY last_seen_at DESC LIMIT ?', [self::MAX_PENDING]);
    }

    /** @return array{issuer:string, subject:string}|null */
    public function find(int $pendingId, bool $lock = false): ?array
    {
        return $this->database->fetchOne(
            'SELECT issuer, subject FROM mcp_unlinked_identities WHERE mcp_unlinked_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$pendingId]
        );
    }

    public function dismiss(int $pendingId): void
    {
        $this->database->execute('DELETE FROM mcp_unlinked_identities WHERE mcp_unlinked_id = ?', [$pendingId]);
    }
}
