<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Enrollment;

use RivetCore\Rmm\Contracts\RmmAssetsInterface;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\Link\RmmLinker;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * Enrollment tokens, device enrollment and the asset-identity rules.
 *
 * IDENTITY POLICY:
 *  - A device is identified by its server-assigned device_id, which survives reconnects, agent updates, re-enrollment and reinstall.
 *  - Same install_id enrolling again                      -> same device, credential rotated.
 *  - New install_id but same machine_guid or same serial  -> reinstall of the same machine: same device, credential rotated.
 *  - machine_guid and serial both present and both different from the stored device while install_id matches -> 409 conflict.
 *  - Asset linking uses stable identifiers only: serial, then MAC. A hostname match alone is only ever a suggestion, never a link.
 *    Several strong matches, disagreeing matches, a match outside the token's client, or an asset that another live device
 *    already owns are NOT merged: the device waits in pending_approval for an administrator.
 *
 * @api
 */
final class EnrollmentService
{
    private const EXHAUSTED = 'This enrollment token has no uses left.';

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly RmmAssetsInterface $assets,
        private readonly RmmTenancyInterface $tenancy,
        private readonly RmmLinker $linker,
        private readonly RmmAuditInterface $audit,
        private readonly AttemptLog $attempts,
        private readonly bool $allowLinux = false,
        private readonly string $clientLabel = 'client',
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    // ------------------------------------------------------------------ enrollment tokens

    /**
     * @return array{token_id:int,token:string} the plaintext token is shown once and never stored
     * @throws \InvalidArgumentException when the client does not exist
     */
    public function createToken(int $clientId, int $locationId, string $ring, int $ttlHours, int $maxUses, string $label, int $userId): array
    {
        if ($this->tenancy->clientName($clientId) === null) {
            throw new \InvalidArgumentException('Choose the ' . $this->clientLabel . ' the devices belong to.');
        }
        if ($locationId > 0 && !$this->tenancy->locationInClient($locationId, $clientId)) {
            $locationId = 0;
        }
        $cfg = $this->settings->get();
        $ttlHours = max(1, min($ttlHours, (int) $cfg['enroll_max_ttl_h']));
        $maxUses = max(1, min($maxUses, RmmProtocol::ENROLL_MAX_USES));
        $ring = in_array($ring, RmmProtocol::RINGS, true) ? $ring : 'stable';
        $selector = bin2hex(random_bytes(6));
        $secret = bin2hex(random_bytes(20));
        $id = $this->sql->insert('INSERT INTO endpoint_agent_enrollment_tokens (token_selector, token_hash, label, client_id, location_id, ring, expires_at, max_uses, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$selector, hash('sha256', $secret), mb_substr($label, 0, 100), $clientId, $locationId, $ring, $this->sql->utcAt($ttlHours * 3600), $maxUses, $userId, $this->sql->utcNow()]);

        return ['token_id' => $id, 'token' => RmmProtocol::ENROLL_TOKEN_PREFIX . '.' . $selector . '.' . $secret];
    }

    public function revokeToken(int $tokenId, int $userId): bool
    {
        return $this->sql->run('UPDATE endpoint_agent_enrollment_tokens SET revoked_at = ?, revoked_by = ? WHERE token_id = ? AND revoked_at IS NULL',
            [$this->sql->utcNow(), $userId, $tokenId]) > 0;
    }

    // ------------------------------------------------------------------ rate limiting (DB backed, so it works without Redis)

    private function checkRate(string $ip): void
    {
        $r = $this->attempts->byAddress(RmmProtocol::ENROLL_RATE_SALT, $ip, RmmProtocol::ENROLL_RATE_WINDOW_S);
        if ($r['failures'] >= RmmProtocol::ENROLL_RATE_MAX_FAILURES || $r['total'] >= RmmProtocol::ENROLL_RATE_MAX_ATTEMPTS) {
            throw new ApiError(429, 'rate_limited', 'Too many enrollment attempts. Try again later.', ['Retry-After' => (string) RmmProtocol::ENROLL_RATE_WINDOW_S]);
        }
    }

    private function recordAttempt(string $ip, bool $ok, string $reason, string $selector): void
    {
        $this->attempts->record(RmmProtocol::ENROLL_RATE_SALT, $ip, $ok, $reason, $selector);
        if (!$ok) {
            $this->audit->record('Enrollment Failed', 'Enrollment attempt rejected (' . $reason . ') from ' . $ip . ($selector !== '' ? ' using token ' . substr($selector, 0, 12) : ''), 0, 0);
        }
    }

    // ------------------------------------------------------------------ the endpoint

    /**
     * @param array<string,mixed> $body the decoded request body
     * @return array<string,mixed> the 201 body
     * @throws ApiError
     */
    public function enroll(array $body, string $ip): array
    {
        $this->checkRate($ip);

        $tokenStr = $body['enrollment_token'] ?? null;
        $dev = $body['device'] ?? null;
        if (!is_string($tokenStr) || !is_array($dev)) {
            $this->recordAttempt($ip, false, 'malformed', '');
            throw new ApiError(422, 'invalid', 'enrollment_token and device are required');
        }
        $token = $this->authenticateToken($tokenStr, $ip);
        try {
            $d = DeviceValidator::validateDevice($dev, $this->allowLinux);
        } catch (ApiError $e) {
            $this->recordAttempt($ip, false, 'invalid_device', (string) $token['token_selector']);
            throw $e;
        }
        $os = ($dev['os'] ?? '') === 'linux' ? 'linux' : 'windows';

        try {
            $result = $this->sql->transaction(function () use ($d, $token, $ip, $os): array {
                $now = $this->sql->utcNow();
                $used = $this->sql->run('UPDATE endpoint_agent_enrollment_tokens SET use_count = use_count + 1, last_used_at = ?
                    WHERE token_id = ? AND use_count < max_uses AND revoked_at IS NULL AND expires_at > ?', [$now, $token['token_id'], $now]);
                if ($used !== 1) {
                    throw new ApiError(403, 'forbidden', self::EXHAUSTED);
                }

                return $this->placeDevice($d, $os, $token, $ip);
            });
        } catch (ApiError $e) {
            $this->recordAttempt($ip, false, $e->errCode === 'forbidden' && $e->getMessage() === self::EXHAUSTED ? 'exhausted' : $e->errCode, (string) $token['token_selector']);
            throw $e;
        }

        $this->recordAttempt($ip, true, $result['event'], (string) $token['token_selector']);
        $this->audit->record('Enrolled', 'Device ' . $result['device_id'] . ' (' . $d['hostname'] . ') ' . $result['event'] . ' with token ' . $token['token_selector'] . ', state ' . $result['status'],
            (int) $token['client_id'], (int) ($result['matched_asset_id'] ?? 0));
        $this->events?->emit(RmmEvent::DEVICE_ENROLLED, ['device_id' => $result['device_id'], 'asset_id' => $result['matched_asset_id'], 'client_id' => (int) $token['client_id'], 'hostname' => $d['hostname']],
            ['link_state' => $result['status'], 'os' => $os, 'outcome' => $result['event']]);

        [, $pub, $kid] = $this->settings->signingKey();
        $cfg = $this->settings->get();

        return [
            'device_id' => $result['device_id'],
            'device_token' => $result['device_token'],
            'check_in_interval_s' => (int) $cfg['check_in_interval_s'],
            'server_time' => $this->sql->isoNow(),
            'status' => $result['status'],
            'matched_asset_id' => $result['matched_asset_id'],
            'signing_public_key' => $pub,
            'signing_key_id' => $kid,
            'config' => ['checks' => $this->settings->signedChecks(), 'collect_interval_s' => (int) $cfg['collect_interval_s']],
        ];
    }

    /**
     * @return array<string,mixed> the token row; every failure is a 401 and is recorded
     */
    private function authenticateToken(string $tokenStr, string $ip): array
    {
        $parts = explode('.', $tokenStr);
        $selector = '';
        $row = null;
        if (count($parts) === 3 && $parts[0] === RmmProtocol::ENROLL_TOKEN_PREFIX && preg_match(RmmProtocol::ENROLL_TOKEN_SELECTOR_RE, $parts[1]) === 1 && preg_match(RmmProtocol::ENROLL_TOKEN_SECRET_RE, $parts[2]) === 1) {
            $selector = $parts[1];
            $row = $this->sql->one('SELECT * FROM endpoint_agent_enrollment_tokens WHERE token_selector = ?', [$selector]);
        }
        // Always compare, even when nothing matched, so a miss costs the same as a near miss.
        $stored = (string) ($row['token_hash'] ?? str_repeat('0', 64));
        $given = hash('sha256', $parts[2] ?? '');
        $secretOk = hash_equals($stored, $given);
        if ($row === null || !$secretOk) {
            $this->recordAttempt($ip, false, 'invalid_token', $selector);
            throw new ApiError(401, 'invalid_token', 'Invalid enrollment token.');
        }
        if ($row['revoked_at'] !== null) {
            $this->recordAttempt($ip, false, 'revoked', $selector);
            throw new ApiError(401, 'revoked', 'This enrollment token was revoked.');
        }
        if (Sql::ts((string) $row['expires_at']) <= $this->sql->time()) {
            $this->recordAttempt($ip, false, 'expired', $selector);
            throw new ApiError(401, 'expired', 'This enrollment token has expired.');
        }

        return $row;
    }

    // ------------------------------------------------------------------ placement

    /**
     * @param array<string,mixed> $d validated device
     * @param array<string,mixed> $token
     * @return array{device_id:int,device_token:string,status:string,matched_asset_id:?int,event:string}
     */
    private function placeDevice(array $d, string $os, array $token, string $ip): array
    {
        $now = $this->sql->utcNow();
        $newToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $newToken);
        $expires = $this->sql->utcAt(RmmProtocol::DEVICE_TOKEN_VALID_DAYS * 86400);
        $macsJson = json_encode($d['macs']);

        // 1. Same install_id: re-enrollment.
        $dev = $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE install_id = ? FOR UPDATE', [$d['install_id']]);
        $event = 're-enrolled';
        $crossClient = false;
        if ($dev !== null) {
            if (!self::sameClient($dev, $token)) {
                // install_id is unique, so another client's row cannot be duplicated; and it must not be taken over either.
                throw new ApiError(409, 'conflict', 'This install id is already bound to a different machine.');
            }
            $this->refuseIfBlocked($dev);
            if (self::identityConflict($dev, $d)) {
                throw new ApiError(409, 'conflict', 'This install id is already bound to a different machine.');
            }
        } else {
            // 2. Reinstall of a known machine: same machine_guid, else same serial.
            $dev = $this->findReinstall($d, (int) $token['client_id']);
            if ($dev !== null) {
                $this->refuseIfBlocked($dev);
                $event = 'reinstalled';
            } else {
                // The same machine identity under ANOTHER client (a cloned image, or an attempted takeover) never reuses that
                // device row: it becomes a new device of the token's client and waits for an administrator.
                $crossClient = $this->identityExistsElsewhere($d, (int) $token['client_id']);
            }
        }

        if ($dev !== null) {
            $this->sql->run('UPDATE endpoint_agent_devices SET install_id = ?, machine_guid = COALESCE(?, machine_guid), hostname = ?, os_version = ?, arch = ?,
                serial = COALESCE(?, serial), manufacturer = COALESCE(?, manufacturer), model = COALESCE(?, model), mac_addresses = ?, agent_version = ?,
                token_hash = ?, token_issued_at = ?, token_expires_at = ?, enroll_count = enroll_count + 1, enrolled_via_token_id = ?, last_ip = ?, last_seq = 0
                WHERE device_id = ?',
                [$d['install_id'], $d['machine_guid'], $d['hostname'], $d['os_version'], $d['arch'], $d['serial'], $d['manufacturer'], $d['model'], $macsJson,
                    $d['agent_version'], $hash, $now, $expires, $token['token_id'], substr($ip, 0, 64), $dev['device_id']]);
            // A reinstalled agent restarts its sequence numbers; the idempotency window restarts with it.
            $this->sql->run('DELETE FROM endpoint_agent_checkins WHERE device_id = ?', [$dev['device_id']]);
            $deviceId = (int) $dev['device_id'];
            $dev = $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE device_id = ?', [$deviceId]) ?? $dev;
            if ($dev['link_state'] === 'linked' && !empty($dev['asset_id'])) {
                $asset = $this->assets->find((int) $dev['asset_id']);
                if ($asset === null || $asset['archived']) {
                    // The asset was retired or deleted while the device was away: needs a human decision, not a silent relink.
                    $this->sql->run("UPDATE endpoint_agent_devices SET link_state = 'pending_approval', match_reason = 'asset_retired' WHERE device_id = ?", [$deviceId]);
                    $dev['link_state'] = 'pending_approval';
                }
            }
            $status = $dev['link_state'] === 'linked' ? 'linked' : 'pending_approval';

            return ['device_id' => $deviceId, 'device_token' => $newToken, 'status' => $status,
                'matched_asset_id' => $dev['link_state'] === 'linked' ? (int) $dev['asset_id'] : null, 'event' => $event];
        }

        // 3. A brand-new device: capacity first, then decide its asset.
        $limit = (int) ($this->settings->get()['max_devices'] ?? 0);
        if ($limit > 0 && (int) $this->sql->val('SELECT COUNT(*) FROM endpoint_agent_devices WHERE revoked_at IS NULL AND retired_at IS NULL') >= $limit) {
            throw new ApiError(403, 'device_limit', 'The device limit of this installation has been reached.');
        }
        $match = $crossClient
            ? ['asset_id' => null, 'status' => 'pending_approval', 'reason' => 'cross_client_identity', 'candidates' => []]
            : $this->matchAsset($d, (int) $token['client_id']);
        $assetId = null;
        $state = 'pending_approval';
        $status = $match['status'];
        $reason = $match['reason'];
        if ($match['asset_id'] !== null) {
            $assetId = $match['asset_id'];
            $state = 'linked';
            $status = 'linked';
        } elseif ($match['status'] === 'pending_approval' && $match['reason'] === 'no_match' && $this->settings->get()['unmatched_policy'] === 'auto_create') {
            $assetId = $this->createAsset($d, (int) $token['client_id'], (int) $token['location_id']);
            $state = 'linked';
            $status = 'linked';
            $reason = 'auto_created';
        }
        $deviceId = $this->sql->insert('INSERT INTO endpoint_agent_devices (install_id, machine_guid, hostname, os, os_version, arch, serial, manufacturer, model, mac_addresses,
            agent_version, asset_id, client_id, location_id, ring, link_state, match_reason, match_candidates_json, token_hash, token_issued_at, token_expires_at,
            enrolled_via_token_id, first_seen_at, last_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$d['install_id'], $d['machine_guid'], $d['hostname'], $os, $d['os_version'], $d['arch'], $d['serial'], $d['manufacturer'], $d['model'], $macsJson,
                $d['agent_version'], $assetId, (int) $token['client_id'], (int) $token['location_id'], $token['ring'], $state, $reason,
                $match['candidates'] !== [] ? json_encode($match['candidates']) : null, $hash, $now, $expires, $token['token_id'], $now, substr($ip, 0, 64), $now]);
        if ($assetId !== null) {
            $this->linker->ensure($deviceId, $assetId);
        }

        return ['device_id' => $deviceId, 'device_token' => $newToken, 'status' => $status, 'matched_asset_id' => $assetId, 'event' => 'enrolled'];
    }

    /** @param array<string,mixed> $dev */
    private function refuseIfBlocked(array $dev): void
    {
        if ($dev['revoked_at'] !== null || $dev['retired_at'] !== null) {
            throw new ApiError(403, 'forbidden', 'This device was ' . ($dev['revoked_at'] !== null ? 'revoked' : 'retired')
                . '. An administrator must allow re-enrollment first.');
        }
    }

    /**
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $d
     */
    private static function identityConflict(array $stored, array $d): bool
    {
        if ($stored['machine_guid'] !== null && $d['machine_guid'] !== null && $stored['machine_guid'] !== $d['machine_guid']) {
            return true;
        }
        if ($stored['serial'] !== null && $d['serial'] !== null && strcasecmp((string) $stored['serial'], (string) $d['serial']) !== 0
            && ($stored['machine_guid'] === null || $d['machine_guid'] === null)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,mixed>|null
     */
    private function findReinstall(array $d, int $clientId): ?array
    {
        if ($d['machine_guid'] !== null) {
            $row = $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE machine_guid = ? AND client_id = ? ORDER BY device_id LIMIT 1 FOR UPDATE', [$d['machine_guid'], $clientId]);
            if ($row !== null) {
                return $row;
            }
        }
        if ($d['serial'] !== null) {
            // Windows reinstalls generate a new MachineGuid but keep the BIOS serial; a different non-null guid on the stored row
            // is still the same box, since the serial matches.
            $row = $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE serial = ? AND client_id = ? ORDER BY device_id LIMIT 1 FOR UPDATE', [$d['serial'], $clientId]);
            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    /**
     * True when a device of ANOTHER client already carries this machine_guid or serial.
     *
     * @param array<string,mixed> $d
     */
    private function identityExistsElsewhere(array $d, int $clientId): bool
    {
        if ($d['machine_guid'] !== null
            && $this->sql->one('SELECT device_id FROM endpoint_agent_devices WHERE machine_guid = ? AND client_id <> ? LIMIT 1', [$d['machine_guid'], $clientId]) !== null) {
            return true;
        }

        return $d['serial'] !== null
            && $this->sql->one('SELECT device_id FROM endpoint_agent_devices WHERE serial = ? AND client_id <> ? LIMIT 1', [$d['serial'], $clientId]) !== null;
    }

    /**
     * @param array<string,mixed> $dev stored device row
     * @param array<string,mixed> $token enrollment token row
     */
    private static function sameClient(array $dev, array $token): bool
    {
        return (int) ($dev['client_id'] ?? 0) === (int) ($token['client_id'] ?? -1);
    }

    // ------------------------------------------------------------------ asset matching

    /**
     * @param array<string,mixed> $d validated device
     * @return array{asset_id:?int,status:string,reason:string,candidates:list<array<string,mixed>>}
     *         asset_id is set only for ONE unambiguous, in-scope, unowned strong match.
     */
    public function matchAsset(array $d, int $clientId): array
    {
        /** @var array<int,array{asset:array{asset_id:int,asset_name:string,client_id:int,serial:?string},by:list<string>}> $found */
        $found = [];
        if ($d['serial'] !== null) {
            foreach ($this->assets->findBySerial((string) $d['serial'], 10) as $a) {
                $found[$a['asset_id']]['asset'] = $a;
                $found[$a['asset_id']]['by'][] = 'serial';
            }
        }
        if ($d['macs'] !== []) {
            foreach ($this->assets->findByMacs($d['macs'], 20) as $a) {
                $found[$a['asset_id']]['asset'] = $a;
                $found[$a['asset_id']]['by'][] = 'mac';
            }
        }
        $cands = [];
        foreach ($found as $aid => $f) {
            $owner = $this->sql->one("SELECT device_id FROM endpoint_agent_devices WHERE asset_id = ? AND link_state = 'linked' AND revoked_at IS NULL AND retired_at IS NULL LIMIT 1", [$aid]);
            $cands[] = ['asset_id' => $aid, 'asset_name' => $f['asset']['asset_name'], 'client_id' => $f['asset']['client_id'],
                'serial' => $f['asset']['serial'], 'matched_by' => array_values(array_unique($f['by'])),
                'in_scope' => $f['asset']['client_id'] === $clientId, 'owned_by_device_id' => $owner !== null ? (int) $owner['device_id'] : null];
        }
        if ($cands !== []) {
            $inScope = array_values(array_filter($cands, static fn (array $c): bool => $c['in_scope']));
            if (count($cands) === 1 && $inScope !== [] && $inScope[0]['owned_by_device_id'] === null) {
                return ['asset_id' => $inScope[0]['asset_id'], 'status' => 'linked', 'reason' => 'matched_' . implode('_', $inScope[0]['matched_by']), 'candidates' => $cands];
            }
            if (count($cands) > 1) {
                return ['asset_id' => null, 'status' => 'ambiguous', 'reason' => 'ambiguous', 'candidates' => $cands];
            }
            if ($inScope === []) {
                return ['asset_id' => null, 'status' => 'pending_approval', 'reason' => 'scope_mismatch', 'candidates' => $cands];
            }

            return ['asset_id' => null, 'status' => 'ambiguous', 'reason' => 'asset_already_linked', 'candidates' => $cands];
        }
        // Hostname alone is a hint, never a link.
        $hints = [];
        foreach ($this->assets->findByHostname((string) $d['hostname'], 10) as $a) {
            $hints[] = ['asset_id' => $a['asset_id'], 'asset_name' => $a['asset_name'], 'client_id' => $a['client_id'], 'serial' => $a['serial'],
                'matched_by' => ['hostname'], 'in_scope' => $a['client_id'] === $clientId, 'owned_by_device_id' => null];
        }
        if ($hints !== []) {
            return ['asset_id' => null, 'status' => 'pending_approval', 'reason' => 'hostname_only', 'candidates' => $hints];
        }

        return ['asset_id' => null, 'status' => 'pending_approval', 'reason' => 'no_match', 'candidates' => []];
    }

    /** @param array<string,mixed> $d */
    private function createAsset(array $d, int $clientId, int $locationId): int
    {
        return $this->assets->createForDevice([
            'hostname' => (string) $d['hostname'],
            'os_version' => (string) $d['os_version'],
            'manufacturer' => $d['manufacturer'] === null ? null : (string) $d['manufacturer'],
            'model' => $d['model'] === null ? null : (string) $d['model'],
            'serial' => $d['serial'] === null ? null : (string) $d['serial'],
        ], $clientId, $locationId);
    }

    // ------------------------------------------------------------------ admin decisions on pending devices

    /**
     * @param string $action link | create_asset | reject
     * @return array{ok:bool,message:string}
     */
    public function resolvePending(int $deviceId, string $action, ?int $assetId, int $userId): array
    {
        $dev = $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE device_id = ?', [$deviceId]);
        if ($dev === null || $dev['link_state'] !== 'pending_approval') {
            return ['ok' => false, 'message' => 'That device is not waiting for approval.'];
        }
        if ($action === 'reject') {
            $this->sql->run("UPDATE endpoint_agent_devices SET revoked_at = ?, revoked_reason = 'rejected at approval', link_state = 'rejected', token_hash = '' WHERE device_id = ?", [$this->sql->utcNow(), $deviceId]);
            $this->audit->record('Rejected', "Pending device $deviceId rejected by user $userId", (int) $dev['client_id'], 0);

            return ['ok' => true, 'message' => 'Device rejected and its credential revoked.'];
        }
        if ($action === 'create_asset') {
            $asset = $this->createAsset([
                'hostname' => $dev['hostname'], 'os_version' => $dev['os_version'], 'manufacturer' => $dev['manufacturer'], 'model' => $dev['model'], 'serial' => $dev['serial'],
            ], (int) $dev['client_id'], (int) $dev['location_id']);
        } elseif ($action === 'link' && $assetId !== null && $assetId > 0) {
            $row = $this->assets->find($assetId);
            if ($row === null || $row['archived']) {
                return ['ok' => false, 'message' => 'That asset does not exist or is archived.'];
            }
            $owner = $this->sql->one("SELECT device_id FROM endpoint_agent_devices WHERE asset_id = ? AND link_state = 'linked' AND device_id <> ? AND revoked_at IS NULL AND retired_at IS NULL", [$assetId, $deviceId]);
            if ($owner !== null) {
                return ['ok' => false, 'message' => 'That asset already belongs to device ' . (int) $owner['device_id'] . '. Retire that device first.'];
            }
            $asset = $assetId;
            if ($row['client_id'] !== (int) $dev['client_id']) {
                // An explicit administrator choice may cross clients; the device follows the asset's client.
                $this->sql->run('UPDATE endpoint_agent_devices SET client_id = ? WHERE device_id = ?', [$row['client_id'], $deviceId]);
            }
        } else {
            return ['ok' => false, 'message' => 'Unknown action.'];
        }
        $this->sql->transaction(function () use ($asset, $deviceId, $userId): void {
            $this->sql->run("UPDATE endpoint_agent_devices SET asset_id = ?, link_state = 'linked', match_reason = ? WHERE device_id = ?", [$asset, 'approved_by_' . $userId, $deviceId]);
            $this->linker->ensure($deviceId, $asset);
        });
        $this->audit->record('Approved', "Device $deviceId linked to asset $asset by user $userId ($action)", (int) $dev['client_id'], $asset);

        return ['ok' => true, 'message' => 'Device linked to asset #' . $asset . '.'];
    }
}
