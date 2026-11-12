<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Rmm\Contracts\BackgroundSessionStoreInterface;

/** Background Mode session store in memory, with the same semantics as DbBackgroundSessionStore (single-use, expiry by the clock, hashes only). */
final class InMemoryBackgroundSessionStore implements BackgroundSessionStoreInterface
{
    /** @var array<string,array<string,mixed>> */
    public array $sessions = [];
    /** @var array<string,array<string,mixed>> token hash => row */
    public array $tokens = [];

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function startSession(string $sessionId, int $deviceId, int $clientId, int $assetId, int $userId, string $userName, string $policy, string $notice): void
    {
        $this->sessions[$sessionId] = ['session_id' => $sessionId, 'device_id' => $deviceId, 'client_id' => $clientId, 'asset_id' => $assetId, 'user_id' => $userId,
            'user_name' => $userName, 'policy' => $policy, 'notice' => $notice, 'ended' => false, 'end_reason' => ''];
    }

    public function endSession(string $sessionId, string $reason): bool
    {
        if (!isset($this->sessions[$sessionId]) || $this->sessions[$sessionId]['ended']) {
            return false;
        }
        $this->sessions[$sessionId]['ended'] = true;
        $this->sessions[$sessionId]['end_reason'] = $reason;

        return true;
    }

    public function findSession(string $sessionId): ?array
    {
        $s = $this->sessions[$sessionId] ?? null;
        if ($s === null) {
            return null;
        }
        unset($s['end_reason']);

        return $s;
    }

    public function issueToken(string $tokenHash, string $sessionId, int $userId, int $deviceId, int $clientId, int $ttlSeconds): void
    {
        $this->tokens[$tokenHash] = ['session_id' => $sessionId, 'user_id' => $userId, 'device_id' => $deviceId, 'client_id' => $clientId,
            'expires' => $this->clock->now()->getTimestamp() + $ttlSeconds, 'used' => false];
    }

    public function peekToken(string $tokenHash): ?array
    {
        $t = $this->tokens[$tokenHash] ?? null;
        if ($t === null) {
            return null;
        }
        $state = $t['used'] ? 'used' : ($t['expires'] <= $this->clock->now()->getTimestamp() ? 'expired' : 'ok');

        return ['session_id' => $t['session_id'], 'user_id' => $t['user_id'], 'device_id' => $t['device_id'], 'client_id' => $t['client_id'], 'state' => $state];
    }

    public function consumeToken(string $tokenHash): bool
    {
        if (($this->peekToken($tokenHash)['state'] ?? '') !== 'ok') {
            return false;
        }
        $this->tokens[$tokenHash]['used'] = true;

        return true;
    }
}
