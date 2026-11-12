<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Contracts\BackgroundModeConfigInterface;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Mesh\MeshService;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\RmmEventPublisher;

/**
 * Redeems the single-use code of a Rivet Console launch (`POST /api/v1/endpoint_console/redeem`, see {@see \RivetCore\Rmm\Http\ConsoleApi}) for the MeshCentral login the console
 * needs.
 *
 * ORDER. The per-address rate limit (every request) -> the minimum console version (426, which says nothing about the code) -> the code's shape -> a look at the code's row (an
 * unknown, expired or used code ends here) -> the per-technician rate limit -> ONE atomic conditional UPDATE that consumes the code (of any number of concurrent calls exactly
 * one gets past it, and a second redeem within the 60 seconds fails the same way) -> THE GATE, re-run for the technician the code was issued to -> the cookie, the report
 * token and the session row -> the audit row, which is written BEFORE anything is returned (no row, no cookie). A code that is consumed stays consumed whatever the gate says.
 *
 * ANSWERS. Every failure that could tell a guesser something (unknown, expired, already used, malformed, technician revoked since the launch, ability, switch, scope or device
 * lost) is the same 400 `{"ok":false,"error":"invalid_or_expired"}`. Only a valid code can reach 403 `approval_required` (the client's policy is `approve`, which Core cannot
 * arrange yet), so that answer reveals nothing to someone without a code.
 *
 * THE GATE at redeem time (the whole existing launch gate, re-run): the `remote_console` switch; `rmm.device.view`, `rmm.remote.launch` (and `rmm.remote.manage` plus the
 * `remote_tools` switch for a terminal or the files) for the device's CURRENT client, which includes the module switch, the user being active and the client being inside
 * their scope; the device still existing, not retired or revoked, and still mapped to the node the code names; then the client's policy when Background Mode's policy mode is
 * `per_client`: `approve` refuses (403), `notify` is passed on as the `notice`. Whether the device is online is checked at launch only.
 * Background Mode's own switch and ability do not apply: a console session is a standard session and Background is never issued.
 *
 * SECRETS. The code, its hash, the cookie and the report token never reach a log, an audit row, an event payload or an exception message. The cookie is minted here, returned
 * once and not stored; the report token is stored only as its SHA-256.
 *
 * @api
 */
final class ConsoleRedeemService
{
    public const IP_LIMIT = 20;
    public const TECH_LIMIT = 10;
    public const WINDOW_S = 60;
    /** Seconds the console has to use the response (covers first-use trust dialogs). */
    public const EXPIRES_IN_S = 180;
    /** Denied rows written per address and minute: the rest are dropped so an attacker cannot flood the audit log. */
    public const DENIED_LOG_PER_MIN = 10;

    /**
     * @param \Closure(string,int,int):bool $rateLimit (bucket, limit, windowSeconds): true when within budget (the edition's limiter, as for DeviceApi)
     */
    public function __construct(
        private readonly ConsoleStore $store,
        private readonly ConsoleSettings $settings,
        private readonly RmmAuthorizer $authz,
        private readonly RmmSettings $rmmSettings,
        private readonly DeviceRepository $devices,
        private readonly MeshService $mesh,
        private readonly BackgroundModeConfigInterface $policy,
        private readonly RmmAuditInterface $audit,
        private readonly \Closure $rateLimit,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    /**
     * @param array<string,mixed>|null $client the console's own description of itself (untrusted: only the version is used for the minimum, the rest is audit text)
     * @return array{http:int,body:array<string,mixed>,headers:array<string,string>}
     */
    public function redeem(string $code, ?array $client, string $ip): array
    {
        $ipKey = substr(ConsoleStore::ipHash($ip), 0, 32);   // short: the database limiter's bucket key is 80 characters
        if (!($this->rateLimit)('console_redeem_ip:' . $ipKey, self::IP_LIMIT, self::WINDOW_S)) {
            return self::limited();
        }
        $min = $this->settings->get()['min_version'];
        if ($min !== '') {
            $v = is_array($client) && is_string($client['version'] ?? null) ? $client['version'] : null;
            if (ConsoleSettings::tooOld($min, $v)) {
                return ['http' => 426, 'body' => ['ok' => false, 'error' => 'client_too_old', 'min_version' => $min], 'headers' => []];
            }
        }
        if (!ConsoleLink::validCode($code)) {
            return $this->invalid($ipKey, $ip, 'malformed');
        }
        $hash = ConsoleLink::hashCode($code);
        $row = $this->store->peekValid($hash);
        if ($row === null) {
            return $this->invalid($ipKey, $ip, 'unknown_expired_or_used');
        }
        if (!($this->rateLimit)('console_redeem_user:' . $row['user_id'], self::TECH_LIMIT, self::WINDOW_S)) {
            return self::limited();
        }
        if (!$this->store->consume($hash)) {
            return $this->invalid($ipKey, $ip, 'unknown_expired_or_used');   // lost the race: the same answer as a replay
        }
        $gate = $this->gate($row);
        if ($gate !== 'ok') {
            return $this->refuse($ipKey, $ip, $row, $gate);
        }
        $dev = $this->devices->find($row['device_id']);
        $clientId = $dev === null ? $row['client_id'] : (int) $dev['client_id'];
        $creds = $this->mesh->consoleCredentials($row['user_id'], $row['user_name'], $this->settings->get()['cookie_once']);
        if (!$creds['ok']) {
            $this->audit->record('Console Link Denied', "A Rivet Console link for user {$row['user_id']} (" . ConsoleText::name($row['user_name']) . ") on device {$row['device_id']} could not be redeemed from " . ConsoleText::clean($ip, 64) . ': Mesh not configured', $clientId, $row['asset_id']);

            return ['http' => 503, 'body' => ['ok' => false, 'error' => 'unavailable'], 'headers' => []];
        }
        $report = $this->settings->get()['public_url'];
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->store->startSession($row['session_id'], hash('sha256', $token), $row['user_id'], $row['user_name'], $row['device_id'], $clientId, $row['asset_id'], $row['node_id'], $row['kind']);
        $app = is_array($client) ? ConsoleText::clean((string) ($client['app'] ?? ''), 40) : '';
        $ver = is_array($client) ? ConsoleText::clean((string) ($client['version'] ?? ''), 20) : '';
        $plat = is_array($client) ? ConsoleText::clean((string) ($client['platform'] ?? ''), 20) : '';
        // The audit row first: if it cannot be written nothing is returned.
        $name = ConsoleText::name($row['user_name']);
        $this->audit->record('Console Link Redeemed', "user {$row['user_id']} ($name) redeemed a Rivet Console link ({$row['kind']}) for session {$row['session_id']} on device {$row['device_id']} from " . ConsoleText::clean($ip, 64)
            . ($app === '' && $ver === '' && $plat === '' ? '' : " (client reported: $app $ver $plat)"), $clientId, $row['asset_id']);
        $this->emit(RmmEvent::CONSOLE_LINK_REDEEMED, $row['device_id'], $clientId, $row['asset_id'], ['session_id' => $row['session_id'], 'user_id' => $row['user_id'], 'user_name' => $name, 'kind' => $row['kind']]);

        return ['http' => 200, 'headers' => [], 'body' => [
            'ok' => true,
            'session_id' => $row['session_id'],
            'node' => $row['node_id'],
            'kind' => $row['kind'],
            'technician' => ['username' => $creds['account'], 'display' => $name],
            'mesh' => ['server' => $creds['server'], 'domain' => $creds['domain'], 'cookie' => $creds['cookie'], 'userid' => $creds['userid']],
            'notice' => $this->notice($clientId),
            'expires_in' => self::EXPIRES_IN_S,
            'report' => ['url' => $report . '/api/v1/endpoint_console/events', 'token' => $token, 'paths' => $this->settings->get()['log_file_paths']],
            'bg_authorization' => null,
        ]];
    }

    /**
     * The gate for a consumed code. `ok`, or why not: `forbidden` (everything that is a plain refusal), `approval_required`.
     *
     * @param array{user_id:int,user_name:string,device_id:int,client_id:int,asset_id:int,node_id:string,kind:string,mode:string,session_id:string} $row
     */
    private function gate(array $row): string
    {
        try {
            if ($row['mode'] !== 'standard' || !ConsoleLink::validKind($row['kind'])) {
                return 'forbidden';   // Background and chat are never issued, whatever a row says
            }
            if (!$this->rmmSettings->featureOn(RemoteProtocol::FEATURE_REMOTE_CONSOLE)) {
                return 'forbidden';
            }
            $dev = $this->devices->find($row['device_id']);
            if ($dev === null || $dev['revoked_at'] !== null || $dev['retired_at'] !== null) {
                return 'forbidden';
            }
            $client = (int) $dev['client_id'];
            $abilities = [RmmAbility::DEVICE_VIEW, RmmAbility::REMOTE_LAUNCH];
            if ($row['kind'] !== 'desktop') {
                $abilities[] = RmmAbility::REMOTE_MANAGE;
                if (!$this->rmmSettings->featureOn(RemoteProtocol::FEATURE_REMOTE_TOOLS)) {
                    return 'forbidden';
                }
            }
            foreach ($abilities as $ability) {
                if ($this->authz->check($row['user_id'], $ability, $client) !== null) {
                    return 'forbidden';
                }
            }
            if (ConsoleSessionService::consoleNode((string) $this->mesh->nodeFor($row['device_id']), $this->mesh->domain()) !== $row['node_id']) {
                return 'forbidden';   // the device was re-mapped, or the Mesh domain changed, since the launch
            }
            if ($this->policy->policyMode() === BackgroundModePolicy::MODE_PER_CLIENT && $this->policy->clientPolicy($client) === BackgroundModePolicy::CLIENT_APPROVE) {
                return 'approval_required';
            }
        } catch (\Throwable) {
            return 'forbidden';   // a lookup that fails denies
        }

        return 'ok';
    }

    /** `notify` when the client's policy says the person at the device must be told (a banner for the technician), else null. */
    private function notice(int $clientId): ?string
    {
        try {
            return $this->policy->policyMode() === BackgroundModePolicy::MODE_PER_CLIENT && $this->policy->clientPolicy($clientId) === BackgroundModePolicy::CLIENT_NOTIFY ? 'notify' : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{http:int,body:array<string,mixed>,headers:array<string,string>} */
    private static function limited(): array
    {
        return ['http' => 429, 'body' => ['ok' => false, 'error' => 'rate_limited'], 'headers' => ['Retry-After' => (string) self::WINDOW_S]];
    }

    /** @return array{http:int,body:array<string,mixed>,headers:array<string,string>} */
    private static function invalidAnswer(): array
    {
        return ['http' => 400, 'body' => ['ok' => false, 'error' => 'invalid_or_expired'], 'headers' => []];
    }

    /**
     * The identical 400 for a code that is malformed, unknown, expired or used (no technician is known, so none is named).
     *
     * @return array{http:int,body:array<string,mixed>,headers:array<string,string>}
     */
    private function invalid(string $ipKey, string $ip, string $reason): array
    {
        $this->denied($ipKey, $ip, $reason, 0, 0, null);

        return self::invalidAnswer();
    }

    /**
     * @param array{user_id:int,user_name:string,device_id:int,client_id:int,asset_id:int,node_id:string,kind:string,mode:string,session_id:string} $row
     * @return array{http:int,body:array<string,mixed>,headers:array<string,string>}
     */
    private function refuse(string $ipKey, string $ip, array $row, string $gate): array
    {
        $this->denied($ipKey, $ip, $gate, $row['client_id'], $row['asset_id'], $row);
        if ($gate === 'approval_required') {
            return ['http' => 403, 'body' => ['ok' => false, 'error' => 'approval_required', 'message' => 'This client requires approval before a remote session can start.'], 'headers' => []];
        }

        return self::invalidAnswer();
    }

    /**
     * One `Console Link Denied` row (and event) per refusal, throttled per address so a flood of bad codes cannot flood the audit log. No technician for an unknown code.
     *
     * @param array{user_id:int,user_name:string,device_id:int,kind:string}|null $row
     */
    private function denied(string $ipKey, string $ip, string $reason, int $clientId, int $assetId, ?array $row): void
    {
        if (!($this->rateLimit)('console_denied_log:' . $ipKey, self::DENIED_LOG_PER_MIN, self::WINDOW_S)) {
            return;
        }
        $who = $row === null ? '' : " for user {$row['user_id']} (" . ConsoleText::name($row['user_name']) . ") on device {$row['device_id']} ({$row['kind']})";
        $this->audit->record('Console Link Denied', 'A Rivet Console link was refused from ' . ConsoleText::clean($ip, 64) . $who . ": $reason", $clientId, $assetId);
        $this->emit(RmmEvent::CONSOLE_LINK_DENIED, $row['device_id'] ?? 0, $clientId, $assetId, ['user_id' => $row['user_id'] ?? null, 'kind' => $row['kind'] ?? null, 'reason' => $reason]);
    }

    /** @param array<string,mixed> $fields */
    private function emit(string $event, int $deviceId, int $clientId, int $assetId, array $fields): void
    {
        $this->events?->emit($event, ['device_id' => $deviceId, 'asset_id' => $assetId, 'client_id' => $clientId, 'hostname' => ''], $fields);
    }
}
