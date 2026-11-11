<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Contracts\BackgroundSessionStoreInterface;
use RivetCore\Rmm\Support\Sql;

/**
 * Database store of Background Mode sessions (`rmm_background_sessions`) and single-use check tokens (`rmm_background_tokens`, migration 0049). A token is consumed by one
 * conditional UPDATE (`used_at IS NULL AND expires_at > now`), so two concurrent callers cannot both win. Expired rows are removed when a token is issued.
 *
 * @api
 */
final class DbBackgroundSessionStore implements BackgroundSessionStoreInterface
{
    /** How long a used or expired token row is kept (for the replay answer), in seconds. */
    private const TOKEN_KEEP_S = 86400;

    public function __construct(private readonly Sql $sql)
    {
    }

    public function startSession(string $sessionId, int $deviceId, int $clientId, int $assetId, int $userId, string $userName, string $policy, string $notice): void
    {
        $this->sql->run('INSERT INTO rmm_background_sessions (session_id, device_id, client_id, asset_id, user_id, user_name, policy, notice, started_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$sessionId, $deviceId, $clientId, $assetId, $userId, mb_substr($userName, 0, 100), substr($policy, 0, 24), substr($notice, 0, 10), $this->sql->utcNow()]);
    }

    public function endSession(string $sessionId, string $reason): bool
    {
        return $this->sql->run('UPDATE rmm_background_sessions SET ended_at = ?, end_reason = ? WHERE session_id = ? AND ended_at IS NULL', [$this->sql->utcNow(), substr($reason, 0, 40), $sessionId]) === 1;
    }

    public function findSession(string $sessionId): ?array
    {
        $r = $this->sql->one('SELECT session_id, device_id, client_id, asset_id, user_id, user_name, policy, notice, ended_at FROM rmm_background_sessions WHERE session_id = ?', [$sessionId]);
        if ($r === null) {
            return null;
        }

        return ['session_id' => (string) $r['session_id'], 'device_id' => (int) $r['device_id'], 'client_id' => (int) $r['client_id'], 'asset_id' => (int) $r['asset_id'],
            'user_id' => (int) $r['user_id'], 'user_name' => (string) $r['user_name'], 'policy' => (string) $r['policy'], 'notice' => (string) $r['notice'], 'ended' => $r['ended_at'] !== null];
    }

    public function issueToken(string $tokenHash, string $sessionId, int $userId, int $deviceId, int $clientId, int $ttlSeconds): void
    {
        $this->sql->run('DELETE FROM rmm_background_tokens WHERE expires_at < ?', [$this->sql->utcAt(-self::TOKEN_KEEP_S)]);
        $this->sql->run('INSERT INTO rmm_background_tokens (token_hash, session_id, user_id, device_id, client_id, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$tokenHash, $sessionId, $userId, $deviceId, $clientId, $this->sql->utcNow(), $this->sql->utcAt($ttlSeconds)]);
    }

    public function peekToken(string $tokenHash): ?array
    {
        $r = $this->sql->one('SELECT session_id, user_id, device_id, client_id, expires_at, used_at FROM rmm_background_tokens WHERE token_hash = ?', [$tokenHash]);
        if ($r === null) {
            return null;
        }
        $state = $r['used_at'] !== null ? 'used' : ((string) $r['expires_at'] <= $this->sql->utcNow() ? 'expired' : 'ok');

        return ['session_id' => (string) $r['session_id'], 'user_id' => (int) $r['user_id'], 'device_id' => (int) $r['device_id'], 'client_id' => (int) $r['client_id'], 'state' => $state];
    }

    public function consumeToken(string $tokenHash): bool
    {
        $now = $this->sql->utcNow();

        return $this->sql->run('UPDATE rmm_background_tokens SET used_at = ? WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [$now, $tokenHash, $now]) === 1;
    }
}
