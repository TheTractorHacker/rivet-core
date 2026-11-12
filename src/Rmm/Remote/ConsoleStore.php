<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Support\Sql;

/**
 * Database access of the Rivet Console redeem and events service (`rmm_console_codes`, `rmm_console_sessions`, migration 0051). A code and a report token are stored only as their
 * SHA-256. A code is consumed by ONE conditional UPDATE (`used_at IS NULL AND expires_at > now`), so of any number of concurrent redeems exactly one wins.
 *
 * @api
 */
final class ConsoleStore
{
    /** The report token is valid at most this long after the session started. */
    public const HARD_CAP_S = 43200;
    /** After the session ends the token still works for this long. */
    public const END_GRACE_S = 300;

    public function __construct(private readonly Sql $sql)
    {
    }

    /** Salted hash of a client address: enough to tell two addresses apart, useless to recover one. */
    public static function ipHash(?string $ip): string
    {
        return $ip === null || $ip === '' ? '' : hash('sha256', 'rivet-console-ip|' . $ip);
    }

    /** Core's own clock as RFC 3339 UTC (the receive time stored beside a claimed time). */
    public function isoNow(): string
    {
        return $this->sql->isoNow();
    }

    public function insertCode(string $codeHash, int $userId, string $userName, int $deviceId, int $clientId, int $assetId, string $node, string $kind, string $mode, string $sessionId, string $ipHash): void
    {
        // Expired rows of long ago are removed here too, so the table stays small without waiting for housekeeping.
        $this->sql->run('DELETE FROM rmm_console_codes WHERE expires_at < ?', [$this->sql->utcAt(-86400)]);
        $this->sql->run('INSERT INTO rmm_console_codes (code_hash, user_id, user_name, device_id, client_id, asset_id, node_id, kind, mode, session_id, ip_hash, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$codeHash, $userId, mb_substr($userName, 0, 100), $deviceId, $clientId, $assetId, $node, $kind, $mode, $sessionId, $ipHash, $this->sql->utcNow(), $this->sql->utcAt(ConsoleLink::CODE_TTL_S)]);
    }

    /**
     * The code's row when it is still redeemable (not used, not expired), else null. Does not consume.
     *
     * @return array{user_id:int,user_name:string,device_id:int,client_id:int,asset_id:int,node_id:string,kind:string,mode:string,session_id:string}|null
     */
    public function peekValid(string $codeHash): ?array
    {
        $r = $this->sql->one('SELECT user_id, user_name, device_id, client_id, asset_id, node_id, kind, mode, session_id FROM rmm_console_codes WHERE code_hash = ? AND used_at IS NULL AND expires_at > ?', [$codeHash, $this->sql->utcNow()]);

        return $r === null ? null : ['user_id' => (int) $r['user_id'], 'user_name' => (string) $r['user_name'], 'device_id' => (int) $r['device_id'], 'client_id' => (int) $r['client_id'],
            'asset_id' => (int) $r['asset_id'], 'node_id' => (string) $r['node_id'], 'kind' => (string) $r['kind'], 'mode' => (string) $r['mode'], 'session_id' => (string) $r['session_id']];
    }

    /** The one atomic step: true for exactly one caller per code. */
    public function consume(string $codeHash): bool
    {
        $now = $this->sql->utcNow();

        return $this->sql->run('UPDATE rmm_console_codes SET used_at = ? WHERE code_hash = ? AND used_at IS NULL AND expires_at > ?', [$now, $codeHash, $now]) === 1;
    }

    public function startSession(string $sessionId, string $tokenHash, int $userId, string $userName, int $deviceId, int $clientId, int $assetId, string $node, string $kind): void
    {
        $this->sql->run('INSERT INTO rmm_console_sessions (session_id, token_hash, user_id, user_name, device_id, client_id, asset_id, node_id, kind, started_at, token_expires_at, hard_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$sessionId, $tokenHash, $userId, mb_substr($userName, 0, 100), $deviceId, $clientId, $assetId, $node, $kind, $this->sql->utcNow(), $this->sql->utcAt(self::HARD_CAP_S), $this->sql->utcAt(self::HARD_CAP_S)]);
    }

    /**
     * The session a report token belongs to while the token is valid (not past its expiry, not past the hard cap), else null.
     *
     * @return array{session_id:string,user_id:int,user_name:string,device_id:int,client_id:int,asset_id:int,node_id:string,kind:string,ended:bool,events_count:int,truncated:bool}|null
     */
    public function sessionForToken(string $tokenHash): ?array
    {
        $now = $this->sql->utcNow();
        $r = $this->sql->one('SELECT session_id, user_id, user_name, device_id, client_id, asset_id, node_id, kind, ended_at, events_count, truncated FROM rmm_console_sessions WHERE token_hash = ? AND token_expires_at > ? AND hard_expires_at > ?', [$tokenHash, $now, $now]);

        return $r === null ? null : ['session_id' => (string) $r['session_id'], 'user_id' => (int) $r['user_id'], 'user_name' => (string) $r['user_name'], 'device_id' => (int) $r['device_id'],
            'client_id' => (int) $r['client_id'], 'asset_id' => (int) $r['asset_id'], 'node_id' => (string) $r['node_id'], 'kind' => (string) $r['kind'], 'ended' => $r['ended_at'] !== null,
            'events_count' => (int) $r['events_count'], 'truncated' => (int) $r['truncated'] === 1];
    }

    /** Mark the session ended (once) and cut its token to the grace period. True for the call that ended it. */
    public function endSession(string $sessionId): bool
    {
        $now = $this->sql->utcNow();
        $n = $this->sql->run('UPDATE rmm_console_sessions SET ended_at = ?, token_expires_at = LEAST(hard_expires_at, ?) WHERE session_id = ? AND ended_at IS NULL', [$now, $this->sql->utcAt(self::END_GRACE_S), $sessionId]);

        return $n === 1;
    }

    public function addEvents(string $sessionId, int $n): void
    {
        $this->sql->run('UPDATE rmm_console_sessions SET events_count = events_count + ? WHERE session_id = ?', [$n, $sessionId]);
    }

    /** True for the one caller that flips the truncated flag (so the "events truncated" row is written once). */
    public function markTruncated(string $sessionId): bool
    {
        return $this->sql->run('UPDATE rmm_console_sessions SET truncated = 1 WHERE session_id = ? AND truncated = 0', [$sessionId]) === 1;
    }
}
