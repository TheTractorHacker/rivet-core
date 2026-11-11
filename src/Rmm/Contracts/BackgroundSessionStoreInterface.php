<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * Where {@see \RivetCore\Rmm\Remote\BackgroundSessionService} keeps Background Mode sessions and their single-use session-check tokens. Core ships the database
 * implementation ({@see \RivetCore\Rmm\Remote\DbBackgroundSessionStore}); the tokens are handed in already hashed (SHA-256 hex), so a store never sees a usable token.
 *
 * @api
 */
interface BackgroundSessionStoreInterface
{
    public function startSession(string $sessionId, int $deviceId, int $clientId, int $assetId, int $userId, string $userName, string $policy, string $notice): void;

    /** True when THIS call ended the session; false when it is unknown or already ended (so "Ended" is recorded once). */
    public function endSession(string $sessionId, string $reason): bool;

    /** @return array{session_id:string,device_id:int,client_id:int,asset_id:int,user_id:int,user_name:string,policy:string,notice:string,ended:bool}|null */
    public function findSession(string $sessionId): ?array;

    public function issueToken(string $tokenHash, string $sessionId, int $userId, int $deviceId, int $clientId, int $ttlSeconds): void;

    /**
     * Look at a token without using it. `state` is `ok`, `used` or `expired`.
     *
     * @return array{session_id:string,user_id:int,device_id:int,client_id:int,state:string}|null null for an unknown token
     */
    public function peekToken(string $tokenHash): ?array;

    /** Use the token: true for exactly one caller, ever (atomic); false when it is unknown, expired or already used. */
    public function consumeToken(string $tokenHash): bool;
}
