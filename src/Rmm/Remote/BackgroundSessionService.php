<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\BackgroundSessionStoreInterface;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmBridgeInterface;
use RivetCore\Rmm\Technician\ActionResult;

/**
 * Authorization and audit plumbing of Background Mode. It decides, records and hands out a session; it does not run anything on a device and does not hide anything from
 * the person at one.
 *
 * THE GATE (one decision, used by the launch and by the Mesh plugin's session check, so they cannot drift apart), in this order, the first failure denies:
 * the `background_mode` switch (default off); `rmm.remote.launch`, `rmm.remote.manage` and `rmm.remote.background` for the device's client (this includes the module
 * switch, the user being active and the client being inside the user's scope; an unknown ability is denied); the `remote_tools` switch (the console has a terminal and
 * files); and the {@see BackgroundModePolicy} (which treats missing configuration as a denial).
 *
 * AUDIT, ALWAYS ON. Every outcome writes exactly one row through {@see RmmAuditInterface}, in every mode, and nothing here reads a setting that could turn that off:
 * `Background Session Started`, `Background Session Denied`, `Background Session Failed`, `Background Session Ended`, and for the plugin's check `Background Session
 * Verified` (allowed) or `Background Session Denied`. A session is never handed out unless its `Started` row was written first (an audit failure propagates and no URL
 * leaves). Row text names the technician (user id and name) and the device, and never contains a URL, a login token, a check token or a key.
 *
 * SESSION CHECK. The launch returns a random check token (256 bits, valid {@see TOKEN_TTL_S} seconds, stored only as its SHA-256, usable once). The Mesh plugin sends it to
 * {@see check()}, which re-runs the whole gate for the technician the token was issued to, so a switch turned off, a revoked user or a changed policy since the launch is
 * honoured. A used, expired, unknown or ended-session token is refused.
 *
 * @api
 */
final class BackgroundSessionService
{
    /** Lifetime of a session-check token in seconds: it only has to survive the console starting up. */
    public const TOKEN_TTL_S = 60;
    /** Why a session ended, as the plugin may report it; anything else is recorded as `other`. */
    public const END_REASONS = ['user_closed', 'timeout', 'revoked', 'error', 'plugin'];

    /**
     * @param \Closure(string):bool $featureOn whether a sub-switch is on (RmmSettings::featureOn); a closure that throws counts as off
     */
    public function __construct(
        private readonly RmmAuthorizer $authz,
        private readonly BackgroundModePolicy $policy,
        private readonly BackgroundSessionStoreInterface $store,
        private readonly RmmAuditInterface $audit,
        private readonly RmmBridgeInterface $bridge,
        private readonly \Closure $featureOn,
    ) {
    }

    /**
     * The gate, without any side effect.
     *
     * @return array{allowed:bool,http:int,code:string,message:string,notice:string,policy:string}
     */
    public function gate(int $userId, int $clientId): array
    {
        $deny = static fn (int $http, string $code, string $message, string $notice = BackgroundDecision::NOTICE_NONE): array => ['allowed' => false, 'http' => $http, 'code' => $code, 'message' => $message, 'notice' => $notice, 'policy' => ''];
        if (!$this->featureIsOn(RemoteProtocol::FEATURE_BACKGROUND_MODE)) {
            return $deny(403, 'feature_disabled', 'Background Mode is switched off. An administrator can turn it on in the RMM remote-tools settings.');
        }
        if (!$this->featureIsOn(RemoteProtocol::FEATURE_REMOTE_CONSOLE)) {
            return $deny(403, 'feature_disabled', 'Background Mode needs the Rivet Console, and it is switched off. An administrator can turn on "Use Rivet Console (desktop app)" in the RMM remote-tools settings.');
        }
        foreach ([RmmAbility::REMOTE_LAUNCH, RmmAbility::REMOTE_MANAGE, RmmAbility::REMOTE_BACKGROUND] as $ability) {
            $why = $this->authz->check($userId, $ability, $clientId);
            if ($why !== null) {
                return $deny(403, 'forbidden', $why);
            }
        }
        if (!$this->featureIsOn(RemoteProtocol::FEATURE_REMOTE_TOOLS)) {
            return $deny(403, 'feature_disabled', 'The remote tools are switched off, and the Background Mode console needs them.');
        }
        $decision = $this->policy->decide($userId, $clientId, $this->authz->allowed($userId, RmmAbility::ADMIN, 0));
        if (!$decision->allowed) {
            return $deny(403, $decision->code === 'approval_required' ? 'approval_required' : 'forbidden', $decision->message, $decision->notice);
        }

        return ['allowed' => true, 'http' => 200, 'code' => 'ok', 'message' => 'Allowed.', 'notice' => $decision->notice, 'policy' => $decision->code];
    }

    /**
     * Start a Background Mode session on a device the caller may already see.
     *
     * @param array<string,mixed> $dev the device row (device_id, client_id, asset_id)
     * @param \Closure():array<string,mixed> $meshLaunch mints the MeshCentral login URL: returns ok, code, message, url, session_id (see MeshService::launch())
     */
    public function launch(RmmPrincipal $who, array $dev, \Closure $meshLaunch, ?string $ip = null, ?string $userAgent = null): ActionResult
    {
        $deviceId = (int) $dev['device_id'];
        $client = (int) $dev['client_id'];
        $asset = (int) ($dev['asset_id'] ?? 0);
        $gate = $this->gate($who->userId, $client);
        if (!$gate['allowed']) {
            $this->audit->record('Background Session Denied', $this->who($who) . " was denied a Background Session on device $deviceId: {$gate['code']}, {$gate['message']}", $client, $asset);

            return ActionResult::fail($gate['http'], $gate['code'], $gate['message']);
        }
        $r = $meshLaunch();
        if (empty($r['ok'])) {
            $code = (string) ($r['code'] ?? 'mesh_unavailable');
            $this->audit->record('Background Session Failed', $this->who($who) . " Background Session on device $deviceId did not start: $code", $client, $asset);
            $http = ['unmapped' => 404, 'device_offline' => 409, 'not_configured' => 409, 'device_retired' => 409, 'mesh_unavailable' => 503][$code] ?? 409;

            return ActionResult::fail($http, $code, (string) ($r['message'] ?? 'The session could not be started.'));
        }
        $sessionId = is_string($r['session_id'] ?? null) && preg_match('/^[0-9a-f]{16}$/', $r['session_id']) === 1 ? $r['session_id'] : bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(32));
        $this->store->startSession($sessionId, $deviceId, $client, $asset, $who->userId, self::cleanName($who->userName), $gate['policy'], $gate['notice']);
        try {
            $this->store->issueToken(hash('sha256', $token), $sessionId, $who->userId, $deviceId, $client, self::TOKEN_TTL_S);
            // The audit row comes first: no row, no session (an exception here means the caller never receives the URL).
            $this->audit->record('Background Session Started', $this->who($who) . " started Background Session $sessionId on device $deviceId (policy {$gate['policy']}, end-user notice {$gate['notice']})", $client, $asset);
            $this->bridge->recordRemoteSession($asset, $client, $who->userId, 'meshcentral', 'meshcentral:session:' . $sessionId,
                $ip === null ? null : substr($ip, 0, 100), $userAgent === null ? null : substr($userAgent, 0, 300));
        } catch (\Throwable $e) {
            try {
                $this->store->endSession($sessionId, 'start_failed');
            } catch (\Throwable) {
                // the original failure is the one to report
            }
            throw $e;
        }

        return ActionResult::ok('Opening the Background Mode console.', 200, 'ok', ['url' => (string) ($r['url'] ?? ''), 'session_id' => $sessionId, 'mode' => 'background',
            'check_token' => $token, 'check_expires_in' => self::TOKEN_TTL_S, 'notice' => $gate['notice']]);
    }

    /**
     * The Mesh plugin's "may this session start" question. `$caller` is the authenticated API caller: the technician the token was issued to, or an administrator account
     * the plugin runs as. Whoever calls, the decision is about the token's technician. Allowed: ok, data session_id, device_id, notice. Anything else: 403 and data allow=false.
     */
    public function check(RmmPrincipal $caller, string $token): ActionResult
    {
        $hash = hash('sha256', $token);
        $row = preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? $this->store->peekToken($hash) : null;
        if ($row === null) {
            return $this->checkDenied($caller, 0, 0, 0, 'invalid_token', 'The session token is not valid.', 'unknown or malformed token');
        }
        $client = $row['client_id'];
        $sid = $row['session_id'];
        if ($caller->userId !== $row['user_id'] && !$this->authz->allowed($caller->userId, RmmAbility::ADMIN, 0)) {
            // Not consumed: someone else's refused attempt must not burn the technician's token.
            return $this->checkDenied($caller, $row['device_id'], $client, $this->assetOf($sid), 'forbidden', 'The session token is not valid.', "token of session $sid presented by another user");
        }
        if ($row['state'] !== 'ok' || !$this->store->consumeToken($hash)) {
            $replay = $row['state'] === 'expired' ? 'token_expired' : 'token_used';

            return $this->checkDenied($caller, $row['device_id'], $client, $this->assetOf($sid), $replay, 'The session token was already used or has expired.', "token of session $sid " . ($replay === 'token_expired' ? 'expired' : 'replayed'));
        }
        $session = $this->store->findSession($sid);
        if ($session === null || $session['ended'] || $session['user_id'] !== $row['user_id']) {
            return $this->checkDenied($caller, $row['device_id'], $client, $session['asset_id'] ?? 0, 'session_ended', 'The session is not active.', "session $sid is unknown or ended");
        }
        $gate = $this->gate($row['user_id'], $client);
        if (!$gate['allowed']) {
            return $this->checkDenied($caller, $row['device_id'], $client, $session['asset_id'], $gate['code'], $gate['message'], "session $sid for user {$row['user_id']} refused: {$gate['code']}");
        }
        $this->audit->record('Background Session Verified', 'Session check allowed Background Session ' . $sid . ' for user ' . $session['user_id'] . ' (' . self::cleanName($session['user_name']) . ') on device ' . $row['device_id']
            . ', checked by ' . $this->who($caller), $client, $session['asset_id']);

        return ActionResult::ok('Allowed.', 200, 'ok', ['allow' => true, 'session_id' => $sid, 'device_id' => $row['device_id'], 'notice' => $gate['notice']]);
    }

    /**
     * Record that a session ended (the plugin or the console calls this when the window closes). The technician who started it, or an administrator, may report it. Writes
     * `Background Session Ended` once; a second report changes nothing and writes nothing. Works with the switch off: a session that started must be able to end on the record.
     */
    public function end(RmmPrincipal $who, string $sessionId, string $reason = 'user_closed'): ActionResult
    {
        $session = preg_match('/^[0-9a-f]{16}$/', $sessionId) === 1 ? $this->store->findSession($sessionId) : null;
        if ($session === null || ($session['user_id'] !== $who->userId && !$this->authz->allowed($who->userId, RmmAbility::ADMIN, 0))) {
            return ActionResult::fail(404, 'not_found', 'Session not found.');
        }
        $reason = in_array($reason, self::END_REASONS, true) ? $reason : 'other';
        if (!$this->store->endSession($sessionId, $reason)) {
            return ActionResult::fail(409, 'already_ended', 'The session has already ended.');
        }
        $this->audit->record('Background Session Ended', 'Background Session ' . $sessionId . ' of user ' . $session['user_id'] . ' (' . self::cleanName($session['user_name']) . ') on device ' . $session['device_id']
            . " ended ($reason), reported by " . $this->who($who), $session['client_id'], $session['asset_id']);

        return ActionResult::ok('Session ended.', 200, 'ended', ['session_id' => $sessionId]);
    }

    private function checkDenied(RmmPrincipal $caller, int $deviceId, int $clientId, int $assetId, string $code, string $message, string $why): ActionResult
    {
        $this->audit->record('Background Session Denied', 'Session check refused for ' . $this->who($caller) . ' on device ' . $deviceId . ': ' . $why, $clientId, $assetId);

        return ActionResult::fail(403, $code, $message, ['allow' => false]);
    }

    private function assetOf(string $sessionId): int
    {
        return $this->store->findSession($sessionId)['asset_id'] ?? 0;
    }

    private function featureIsOn(string $feature): bool
    {
        try {
            return ($this->featureOn)($feature);
        } catch (\Throwable) {
            return false;
        }
    }

    /** "user 12 (Alice)": the id always, the name stripped of anything that could forge a second log line. */
    private function who(RmmPrincipal $p): string
    {
        return 'user ' . $p->userId . ' (' . self::cleanName($p->userName) . ')';
    }

    private static function cleanName(string $name): string
    {
        $n = trim((string) preg_replace('/[^\p{L}\p{N} ._@-]/u', '', $name));

        return $n === '' ? 'unnamed' : mb_substr($n, 0, 60);
    }
}
