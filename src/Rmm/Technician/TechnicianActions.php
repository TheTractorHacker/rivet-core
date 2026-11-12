<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Technician;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmBridgeInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Device\DeviceService;
use RivetCore\Rmm\Enrollment\EnrollmentService;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\Mesh\MeshService;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Update\UpdateService;

/**
 * Technician-initiated and administrator-initiated actions on devices, shared by the edition's web handlers and the REST API
 * ({@see \RivetCore\Rmm\Http\TechnicianApi}) so the two cannot drift apart. Authorization is decided here, server-side, on every call;
 * hiding a button is only cosmetic.
 *
 * ORDER OF DECISIONS (so a denial reveals nothing): the role cannot view devices at all -> 403 (it reveals nothing about any device);
 * the device is missing OR outside the caller's client scope -> the same 404 (no existence oracle); the caller lacks the specific
 * ability -> 403 with a generic reason, audited for jobs and remote sessions.
 *
 * Every method returns an {@see ActionResult}.
 *
 * @api
 */
final class TechnicianActions
{
    public function __construct(
        private readonly Sql $sql,
        private readonly DeviceRepository $devices,
        private readonly DeviceService $deviceService,
        private readonly EnrollmentService $enrollment,
        private readonly JobService $jobs,
        private readonly UpdateService $updates,
        private readonly MeshService $mesh,
        private readonly RmmAuthorizer $authz,
        private readonly RmmBridgeInterface $bridge,
        private readonly RmmAuditInterface $audit,
        private readonly string $clientLabel = 'client',
        private readonly ?ScriptActions $library = null,
        private readonly ?\RivetCore\Rmm\Settings\RmmSettings $settings = null,
        private readonly ?\RivetCore\Rmm\Mesh\MeshMapping $meshMapping = null,
        private readonly ?\RivetCore\Rmm\Mesh\MeshAgentService $meshAgents = null,
    ) {
    }

    /**
     * Role first (no view access at all -> 403), then client scope (outside it -> the same 404 as a missing device).
     *
     * @param bool $administrative the administration side: needs the administrative ability instead of `device.view`, and works while the module is off
     * @return array{0:?array<string,mixed>,1:?ActionResult} [device, error]
     */
    private function deviceFor(int $userId, int $deviceId, bool $administrative = false): array
    {
        $role = $this->authz->check($userId, $administrative ? RmmAbility::DEVICE_MANAGE : RmmAbility::DEVICE_VIEW, 0, !$administrative);
        if ($role !== null) {
            return [null, ActionResult::fail(403, 'forbidden', $role)];
        }
        $dev = $this->devices->find($deviceId);
        $client = $dev === null ? 0 : (int) $dev['client_id'];
        if ($dev === null || !$this->authz->clientOk($userId, $client)
            || (!$administrative && $this->authz->check($userId, RmmAbility::DEVICE_VIEW, $client) !== null)) {
            return [null, ActionResult::fail(404, 'not_found', 'Device not found.')];
        }

        return [$dev, null];
    }

    /**
     * The device row when this user may view it, or null: the same answer for "missing" and "not your client".
     *
     * @return array<string,mixed>|null
     */
    public function visibleDevice(int $userId, int $deviceId): ?array
    {
        $dev = $this->devices->find($deviceId);
        if ($dev === null || $this->authz->check($userId, RmmAbility::DEVICE_VIEW, (int) $dev['client_id']) !== null) {
            return null;
        }

        return $dev;
    }

    // ------------------------------------------------------------------ jobs

    /**
     * Queue a job. `type`: a registered job type (powershell, shell, python, reboot, collect); `script` (free-form text) or `script_id` (the edition's saved
     * script) or `library_script_id` (a script of Core's library, with `script_version` and `params`: see {@see ScriptActions::runOnDevice()}); `params`;
     * `timeout_s`; `destructive`; `confirm` (required for reboot and any destructive job).
     *
     * @param array<string,mixed> $in
     */
    public function submitJob(RmmPrincipal $who, int $deviceId, array $in): ActionResult
    {
        if (isset($in['library_script_id']) && $this->library !== null) {
            return $this->library->runOnDevice($who, $deviceId, $in);   // a script from the Core library: see ScriptActions
        }
        [$dev, $err] = $this->deviceFor($who->userId, $deviceId);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $type = is_string($in['type'] ?? null) ? $in['type'] : '';
        $script = isset($in['script']) && is_string($in['script']) ? $in['script'] : null;
        $savedId = isset($in['script_id']) && is_numeric($in['script_id']) ? (int) $in['script_id'] : 0;
        $destructive = !empty($in['destructive']);
        $client = (int) $dev['client_id'];
        $asset = (int) ($dev['asset_id'] ?? 0);

        $def = $this->jobs->registry()->get($type);
        if ($def === null) {
            return ActionResult::fail(422, 'invalid', 'Unknown job type.');
        }
        if (\RivetCore\Rmm\Remote\RemoteProtocol::isToolJob($type)) {
            // The remote tools carry validated parameters, per-action confirmation, the feature switches and the file-transfer ledger: only their own endpoints may queue them.
            return ActionResult::fail(422, 'invalid', 'That job type is queued through the remote-tools endpoints (services, processes, files, Mesh agent).');
        }
        if (\RivetCore\Rmm\Patch\PatchProtocol::isPatchJob($type)) {
            return ActionResult::fail(422, 'invalid', 'That job type is queued through the patch endpoints (patches/scan, patches/install).');
        }
        if (\RivetCore\Rmm\Deploy\DeployProtocol::isDeployJob($type)) {
            return ActionResult::fail(422, 'invalid', 'That job type is queued through the software endpoints (deployments/detect, deployments/run).');
        }
        // A script from the saved library needs the lower grant than free-form script text.
        $ability = $def->requiresScript && $savedId > 0 ? RmmAbility::JOB_RUN_SAVED : $def->ability;
        $denied = $this->authz->check($who->userId, $ability, $client);
        if ($denied !== null) {
            $this->audit->record('Job Denied', "User {$who->userId} denied $type job on device $deviceId: $denied", $client, $asset);

            return ActionResult::fail(403, 'forbidden', $denied);
        }
        if ($dev['link_state'] !== 'linked') {
            return ActionResult::fail(409, 'conflict', 'Only a device linked to an asset can receive jobs. Approve it first.');
        }
        if ($def->requiresScript && $savedId > 0) {
            $body = $this->bridge->savedPowerShellScript($savedId);
            if ($body === null || trim($body) === '') {
                return ActionResult::fail(422, 'invalid', 'That saved script is not a usable PowerShell script.');
            }
            $script = $body;
        }
        $isDestructive = $def->destructive || $destructive;
        if ($isDestructive && empty($in['confirm'])) {
            return ActionResult::fail(422, 'confirmation_required', 'This job is destructive. Confirm it explicitly.');
        }
        $params = isset($in['params']) && is_array($in['params']) ? $in['params'] : [];
        $timeout = isset($in['timeout_s']) && is_numeric($in['timeout_s']) ? (int) $in['timeout_s'] : null;
        $r = $this->jobs->create($dev, $type, $script, $params, $timeout, $destructive, $who->userId);
        if (!$r['ok']) {
            return ActionResult::fail(422, 'invalid', (string) ($r['error'] ?? 'The job could not be created.'));
        }
        $jobId = (string) ($r['job_id'] ?? '');
        $this->audit->record('Job Submitted', "{$who->userName} submitted $type job $jobId on device $deviceId" . ($isDestructive ? ' (destructive)' : '')
            . ($def->requiresScript ? ', script sha256 ' . substr(hash('sha256', (string) $script), 0, 16) : ''), $client, $asset);

        return ActionResult::ok('Job queued.', 201, 'queued', ['job_id' => $jobId]);
    }

    public function cancelJob(RmmPrincipal $who, int $deviceId, string $jobId): ActionResult
    {
        [$dev, $err] = $this->deviceFor($who->userId, $deviceId);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $denied = $this->authz->check($who->userId, RmmAbility::JOB_RUN_SAVED, (int) $dev['client_id']);
        if ($denied !== null) {
            return ActionResult::fail(403, 'forbidden', $denied);
        }
        if (!$this->jobs->cancel($jobId, $deviceId, $who->userId)) {
            return ActionResult::fail(409, 'conflict', 'Only a queued job can be cancelled.');
        }
        $this->audit->record('Job Cancelled', "{$who->userName} cancelled job $jobId on device $deviceId", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

        return ActionResult::ok('Job cancelled.', 200, 'cancelled');
    }

    // ------------------------------------------------------------------ remote access

    /**
     * Launch a MeshCentral session. The session id is the only identifier stored (through the edition's remote-session log): the login
     * token and URL are never written anywhere. `$view` is desktop (the default), terminal or files; the last two (Phase 6) need `rmm.remote.manage` as well
     * and the `remote_tools` sub-switch, because a terminal is a shell on the device.
     */
    public function launchRemote(RmmPrincipal $who, int $deviceId, bool $force = false, ?string $ip = null, ?string $userAgent = null, string $view = 'desktop'): ActionResult
    {
        if (!isset(MeshService::VIEWMODES[$view])) {
            return ActionResult::fail(422, 'invalid', 'The view must be desktop, terminal or files.');
        }
        [$dev, $err] = $this->deviceFor($who->userId, $deviceId);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $client = (int) $dev['client_id'];
        $asset = (int) ($dev['asset_id'] ?? 0);
        $denied = $this->authz->check($who->userId, RmmAbility::REMOTE_LAUNCH, $client);
        if ($denied !== null) {
            $this->audit->record('Remote Denied', "User {$who->userId} denied remote session on device $deviceId: $denied", $client, $asset);

            return ActionResult::fail(403, 'forbidden', $denied);
        }
        if ($view !== 'desktop') {
            $why = $this->authz->check($who->userId, RmmAbility::REMOTE_MANAGE, $client);
            if ($why !== null) {
                $this->audit->record('Remote Denied', "User {$who->userId} denied Mesh $view on device $deviceId: $why", $client, $asset);

                return ActionResult::fail(403, 'forbidden', $why);
            }
            if ($this->settings === null || !$this->settings->featureOn('remote_tools')) {
                return ActionResult::fail(409, 'feature_disabled', 'The Mesh terminal and file views are switched off. An administrator can turn the remote tools on in the RMM remote-tools settings.');
            }
        }
        $r = $this->mesh->launch($dev, $who->userName, $who->userId, $force, $view);
        if (!$r['ok']) {
            $this->audit->record('Remote Failed', "{$who->userName} remote session on device $deviceId did not start: {$r['code']}", $client, $asset);
            $http = ['unmapped' => 404, 'device_offline' => 409, 'not_configured' => 409, 'device_retired' => 409, 'mesh_unavailable' => 503][$r['code']] ?? 409;

            return ActionResult::fail($http, $r['code'], $r['message']);
        }
        $sessionId = (string) ($r['session_id'] ?? '');
        $this->bridge->recordRemoteSession($asset, $client, $who->userId, 'meshcentral', 'meshcentral:session:' . $sessionId,
            $ip === null ? null : substr($ip, 0, 100), $userAgent === null ? null : substr($userAgent, 0, 300));
        $this->audit->record('Remote Session', "{$who->userName} opened MeshCentral " . ($view === 'desktop' ? '' : "$view ") . "session $sessionId on device $deviceId" . ($force ? ' (forced while offline)' : ''), $client, $asset);

        return ActionResult::ok('Opening the remote session.', 200, 'ok', ['url' => (string) ($r['url'] ?? ''), 'session_id' => $sessionId]);
    }

    /** Map (or, with an empty id, clear) the MeshCentral node of a device. Administrators only. */
    public function setMeshNode(RmmPrincipal $who, int $deviceId, string $nodeId): ActionResult
    {
        return $this->manage($who, $deviceId, function (array $dev) use ($who, $deviceId, $nodeId): ActionResult {
            $node = trim($nodeId);
            if (!$this->devices->setMeshNode($deviceId, $node, $who->userId)) {
                return ActionResult::fail(422, 'invalid', 'That does not look like a MeshCentral node id. In MeshCentral open the device and copy the address bar, or paste the node id (node//... or the 64 characters after gotonode=).');
            }
            $this->audit->record('Mesh Node Mapped', "{$who->userName} " . ($node === '' ? 'cleared' : 'set') . " the MeshCentral node for device $deviceId", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

            return ActionResult::ok('Saved.');
        });
    }

    /** The most devices one {@see approveMeshNodes()} call takes. */
    public const MESH_APPROVE_MAX = 200;

    /**
     * Why each linked device is (not) mapped to a MeshCentral node, for the fleet "Mesh mapping" page. Scope: the caller's clients only, and only those where it may
     * view devices; a device of another client is simply absent (and a `client_id` filter outside the scope answers as an empty list). `can_approve` is true only
     * when the caller may also manage devices of that client.
     *
     * @param array{state?:string,client_id?:int,q?:string} $filters
     */
    public function meshMappings(RmmPrincipal $who, array $filters = [], int $limit = 100, int $offset = 0): ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, 0);
        if ($denied !== null) {
            return ActionResult::fail(403, 'forbidden', $denied);
        }
        if ($this->meshMapping === null) {
            return ActionResult::fail(409, 'not_configured', 'The Mesh mapping view is not available.');
        }
        if (isset($filters['state']) && $filters['state'] !== '' && !in_array($filters['state'], \RivetCore\Rmm\Mesh\MeshMapping::STATES, true)) {
            return ActionResult::fail(422, 'invalid', 'state must be one of ' . implode(', ', \RivetCore\Rmm\Mesh\MeshMapping::STATES) . '.');
        }
        $visible = $this->authz->visibleClientIds($who->userId);
        $candidates = $visible ?? array_map('intval', array_column($this->sql->all("SELECT DISTINCT client_id FROM endpoint_agent_devices WHERE link_state = 'linked'"), 'client_id'));
        $clients = array_values(array_filter($candidates, fn (int $c): bool => $this->authz->allowed($who->userId, RmmAbility::DEVICE_VIEW, $c)));
        $r = $this->meshMapping->fleet($clients, $filters, max(1, min(500, $limit)), max(0, $offset));
        $manage = [];
        foreach ($r['items'] as &$item) {
            $c = (int) $item['client_id'];
            $manage[$c] ??= $this->authz->allowed($who->userId, RmmAbility::DEVICE_MANAGE, $c);
            $item['can_approve'] = $item['can_approve'] && $manage[$c];
        }
        unset($item);

        return ActionResult::ok('ok', 200, 'ok', $r + ['limit' => max(1, min(500, $limit)), 'offset' => max(0, $offset)]);
    }

    /**
     * The Mesh mapping state of ONE device (the opt-in the device page uses; the default device detail does not carry it). A device outside the caller's clients is the
     * same 404 as a missing one; `can_approve` is false without `rmm.device.manage` for its client.
     */
    public function meshMapping(RmmPrincipal $who, int $deviceId): ActionResult
    {
        [$dev, $err] = $this->deviceFor($who->userId, $deviceId);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $m = $this->meshMapping?->forDevice($deviceId);
        if ($m === null) {
            return ActionResult::fail($this->meshMapping === null ? 409 : 404, $this->meshMapping === null ? 'not_configured' : 'not_found', $this->meshMapping === null ? 'The Mesh mapping view is not available.' : 'Device not found.');
        }
        $m['can_approve'] = $m['can_approve'] && $this->authz->allowed($who->userId, RmmAbility::DEVICE_MANAGE, (int) $dev['client_id']);

        return ActionResult::ok('ok', 200, 'ok', $m);
    }

    /**
     * Bulk "approve and map": for each device id, map the node THE DEVICE ITSELF REPORTED (the one stored in its Mesh agent state, never a typed one) through the same
     * audited action as a single approval ({@see setMeshNode()}: `rmm.device.manage` for the device's client). Per device the result is `changed`, `skipped` (nothing
     * to approve: not waiting for approval, already mapped, a different node kept by hand) or `refused` (not found or not yours, not permitted, or a CONFLICT: a node
     * another live device holds or also reports is never moved or overridden). At most {@see MESH_APPROVE_MAX} ids per call.
     *
     * @param array<mixed> $deviceIds
     */
    public function approveMeshNodes(RmmPrincipal $who, array $deviceIds): ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::DEVICE_MANAGE, 0, false);
        if ($denied !== null) {
            return ActionResult::fail(403, 'forbidden', $denied);
        }
        if ($this->meshAgents === null) {
            return ActionResult::fail(409, 'not_configured', 'The Mesh mapping is not available.');
        }
        if ($deviceIds === [] || !array_is_list($deviceIds) || count($deviceIds) > self::MESH_APPROVE_MAX) {
            return ActionResult::fail(422, 'invalid', 'Send a list of 1 to ' . self::MESH_APPROVE_MAX . ' device ids.');
        }
        $ids = [];
        foreach ($deviceIds as $id) {
            if (!is_int($id) || $id < 1) {
                return ActionResult::fail(422, 'invalid', 'Every device id must be a positive whole number.');
            }
            $ids[$id] = $id;
        }
        $results = [];
        $n = ['changed' => 0, 'skipped' => 0, 'refused' => 0];
        foreach ($ids as $id) {
            $r = $this->approveOneMeshNode($who, $id);
            ++$n[$r['result']];
            $results[] = ['device_id' => $id] + $r;
        }
        $this->audit->record('Mesh Nodes Bulk Approved', "{$who->userName} approved the reported MeshCentral node of {$n['changed']} of " . count($ids) . " device(s) ({$n['skipped']} skipped, {$n['refused']} refused)", 0, 0);

        return ActionResult::ok("{$n['changed']} device(s) mapped.", 200, 'ok', ['requested' => count($ids)] + $n + ['results' => $results]);
    }

    /** @return array{result:string,code:string,message:string} */
    private function approveOneMeshNode(RmmPrincipal $who, int $deviceId): array
    {
        $out = ['result' => 'refused', 'code' => 'not_found', 'message' => 'Device not found.'];
        $ran = false;
        $res = $this->manage($who, $deviceId, function (array $dev) use ($who, $deviceId, &$out, &$ran): ActionResult {
            $ran = true;
            $state = $this->meshAgents?->stateFor($deviceId);
            $node = $state['node_id'] ?? null;
            if ($state === null || !$state['installed'] || $node === null || $state['map_state'] !== \RivetCore\Rmm\Mesh\MeshAgentService::MAP_NEEDS_APPROVAL) {
                $out = ['result' => 'skipped', 'code' => 'not_awaiting_approval', 'message' => 'This device has no reported Mesh node waiting for approval.'];

                return ActionResult::ok('skipped');
            }
            $d = $this->meshAgents->claimDecision($dev, $node);   // re-checked now: the world may have changed since the page was drawn
            if ($d['action'] === 'conflict') {
                $out = ['result' => 'refused', 'code' => 'conflict', 'message' => 'Another device holds or also reports this node. It was not mapped.'];
                $this->audit->record('Mesh Node Approval Refused', "Approving MeshCentral node $node for device $deviceId was refused: it is {$d['why']}", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

                return ActionResult::fail(409, 'conflict', 'conflict');
            }
            if ($d['action'] !== 'needs_approval' && $d['action'] !== 'move') {
                $out = ['result' => 'skipped', 'code' => $d['action'] === 'same' ? 'already_mapped' : 'kept_manual', 'message' => $d['action'] === 'same' ? 'This device is already mapped to that node.' : 'A node mapped by hand differs from the reported one and was kept.'];

                return ActionResult::ok('skipped');
            }
            if ($d['action'] === 'move' && $d['other'] !== null) {
                $this->devices->setMeshNode((int) $d['other']['device_id'], null, $who->userId);   // the retired device of the same client and asset releases it
            }
            $r = $this->setMeshNode($who, $deviceId, $node);
            if (!$r->ok) {
                $out = ['result' => 'refused', 'code' => $r->code, 'message' => $r->message];

                return $r;
            }
            $this->meshAgents->markMapped($deviceId);
            $this->audit->record('Mesh Node Approved', "{$who->userName} approved MeshCentral node $node, which device $deviceId reported itself, and mapped it", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));
            $out = ['result' => 'changed', 'code' => 'mapped', 'message' => 'Mapped.'];

            return $r;
        });
        if (!$ran) {
            $out = ['result' => 'refused', 'code' => $res->code, 'message' => $res->message];   // 404 (missing or not yours: the same answer) or 403
        }

        return $out;
    }

    // ------------------------------------------------------------------ administration of one device

    /**
     * Resolve a device that is waiting for approval: link it to an existing asset (`$assetId`), create an asset for it, or reject it.
     *
     * @param string $action link | create_asset | reject
     */
    public function resolvePending(RmmPrincipal $who, int $deviceId, string $action, ?int $assetId = null): ActionResult
    {
        return $this->manage($who, $deviceId, function (array $dev) use ($deviceId, $action, $assetId, $who): ActionResult {
            if ($action === 'link' && $assetId !== null && $assetId > 0) {
                // Linking moves the device to the asset's client: the caller must be allowed to manage devices there too (CORE-02).
                $assetClient = $this->enrollment->assetClientId($assetId);
                if ($assetClient !== null && $assetClient !== (int) $dev['client_id']
                    && ($this->authz->check($who->userId, RmmAbility::DEVICE_MANAGE, $assetClient, false) !== null || !$this->authz->clientOk($who->userId, $assetClient))) {
                    return ActionResult::fail(404, 'not_found', 'That asset does not exist or is archived.');
                }
            }
            $r = $this->enrollment->resolvePending($deviceId, $action, $assetId, $who->userId);

            return $r['ok'] ? ActionResult::ok($r['message']) : ActionResult::fail(409, 'conflict', $r['message']);
        });
    }

    public function revoke(RmmPrincipal $who, int $deviceId, string $reason = ''): ActionResult
    {
        return $this->manage($who, $deviceId, fn (): ActionResult => $this->deviceService->revoke($deviceId, $reason !== '' ? $reason : 'revoked by ' . $who->userName, $who->userId)
            ? ActionResult::ok('Device revoked. It can no longer check in or fetch jobs.') : ActionResult::fail(409, 'conflict', 'Nothing changed.'));
    }

    public function rotateCredential(RmmPrincipal $who, int $deviceId): ActionResult
    {
        return $this->manage($who, $deviceId, fn (): ActionResult => $this->deviceService->rotate($deviceId, $who->userId)
            ? ActionResult::ok('Credential invalidated. The agent must re-enroll with a new enrollment token.') : ActionResult::fail(409, 'conflict', 'Nothing changed.'));
    }

    public function retire(RmmPrincipal $who, int $deviceId): ActionResult
    {
        return $this->manage($who, $deviceId, fn (): ActionResult => $this->deviceService->retire($deviceId, $who->userId)
            ? ActionResult::ok('Device retired: credential revoked, queued jobs cancelled, monitoring stopped. The asset was kept.') : ActionResult::fail(409, 'conflict', 'Nothing changed.'));
    }

    public function allowReenroll(RmmPrincipal $who, int $deviceId): ActionResult
    {
        return $this->manage($who, $deviceId, fn (): ActionResult => $this->deviceService->allowReenroll($deviceId, $who->userId)
            ? ActionResult::ok('The device may enroll again with a fresh enrollment token.') : ActionResult::fail(409, 'conflict', 'Nothing changed.'));
    }

    public function setRing(RmmPrincipal $who, int $deviceId, string $ring): ActionResult
    {
        return $this->manage($who, $deviceId, fn (): ActionResult => $this->deviceService->setRing($deviceId, $ring)
            ? ActionResult::ok('Ring updated.') : ActionResult::fail(422, 'invalid', 'Unknown ring.'));
    }

    /** The "another tool already manages this device" switch (see DeviceService::setAutomationDisabled()). */
    public function setAutomationDisabled(RmmPrincipal $who, int $deviceId, bool $disabled): ActionResult
    {
        return $this->manage($who, $deviceId, fn (): ActionResult => $this->deviceService->setAutomationDisabled($deviceId, $disabled, $who->userId)
            ? ActionResult::ok($disabled ? 'Automation disabled for this device.' : 'Automation re-enabled for this device.')
            : ActionResult::fail(404, 'not_found', 'Device not found.'));
    }

    /**
     * The "managed by another RMM" switch on many devices (1.0.0-rc.59), for a selection on the device list or for every device of one client.
     * Needs `rmm.device.manage` for EACH device's client: a device the caller may not manage, one that does not exist and one outside the caller's clients are all reported
     * the same way (`skipped`) and never changed, so the answer says nothing about other clients. With `$clientId` the selection is every device of that client (the caller must
     * manage it, else 403) and `$deviceIds` is ignored. At most {@see DeviceService::BULK_MAX} devices (422 `too_many` above, nothing changed).
     * Data: `changed`, `unchanged`, `skipped` (lists of the ids asked for) and the three counts.
     *
     * @param list<int> $deviceIds
     */
    public function setAutomationDisabledBulk(RmmPrincipal $who, array $deviceIds, bool $disabled, ?int $clientId = null): ActionResult
    {
        if ($clientId !== null) {
            if (!$this->authz->clientOk($who->userId, $clientId) || $this->authz->check($who->userId, RmmAbility::DEVICE_MANAGE, $clientId, false) !== null) {
                return ActionResult::fail(403, 'forbidden', 'You do not have access to that ' . $this->clientLabel . '.');
            }
            $deviceIds = array_map(static fn (array $r): int => (int) $r['device_id'], $this->sql->all('SELECT device_id FROM endpoint_agent_devices WHERE client_id = ? AND link_state <> \'panel\' ORDER BY device_id LIMIT ' . (DeviceService::BULK_MAX + 1), [$clientId]));
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $deviceIds), static fn (int $i): bool => $i > 0)));
        if (count($ids) > DeviceService::BULK_MAX) {
            return ActionResult::fail(422, 'too_many', 'That reaches more than ' . DeviceService::BULK_MAX . ' devices; change at most ' . DeviceService::BULK_MAX . ' at a time.');
        }
        $allowed = [];
        $skipped = [];
        foreach ($ids as $id) {
            $dev = $this->devices->find($id);
            if ($dev === null || !$this->authz->clientOk($who->userId, (int) $dev['client_id']) || $this->authz->check($who->userId, RmmAbility::DEVICE_MANAGE, (int) $dev['client_id'], false) !== null) {
                $skipped[] = $id;
            } else {
                $allowed[] = $id;
            }
        }
        $r = $this->deviceService->setAutomationDisabledBulk($allowed, $disabled, $who->userId);
        $skipped = array_merge($skipped, $r['not_found']);
        sort($skipped);
        $msg = count($r['changed']) . ' changed, ' . count($r['unchanged']) . ' already ' . ($disabled ? 'managed by another RMM' : 'under RivetCore automation') . ', ' . count($skipped) . ' skipped.';

        return ActionResult::ok($msg, 200, 'ok', ['changed' => $r['changed'], 'unchanged' => $r['unchanged'], 'skipped' => $skipped,
            'counts' => ['changed' => count($r['changed']), 'unchanged' => count($r['unchanged']), 'skipped' => count($skipped)]]);
    }

    public function clearUpdateFailures(RmmPrincipal $who, int $deviceId): ActionResult
    {
        return $this->manage($who, $deviceId, function () use ($deviceId): ActionResult {
            $this->updates->clearFailures($deviceId);

            return ActionResult::ok('Update failures cleared for this device.');
        });
    }

    /** Move a device (and its asset) to another client. The caller must be allowed to see the target client too. */
    public function transfer(RmmPrincipal $who, int $deviceId, int $clientId, int $locationId = 0): ActionResult
    {
        return $this->manage($who, $deviceId, function () use ($who, $deviceId, $clientId, $locationId): ActionResult {
            if (!$this->authz->clientOk($who->userId, $clientId) || $this->authz->check($who->userId, RmmAbility::DEVICE_MANAGE, $clientId, false) !== null) {
                return ActionResult::fail(403, 'forbidden', 'You do not have access to that ' . $this->clientLabel . '.');
            }

            return $this->deviceService->transfer($deviceId, $clientId, $locationId, $who->userId)
                ? ActionResult::ok('Device and asset moved to the new ' . $this->clientLabel . '.') : ActionResult::fail(422, 'invalid', 'Choose an existing ' . $this->clientLabel . '.');
        });
    }

    /**
     * @param \Closure(array<string,mixed>):ActionResult $do receives the device row
     */
    private function manage(RmmPrincipal $who, int $deviceId, \Closure $do): ActionResult
    {
        [$dev, $err] = $this->deviceFor($who->userId, $deviceId, true);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $denied = $this->authz->check($who->userId, RmmAbility::DEVICE_MANAGE, (int) $dev['client_id'], false);
        if ($denied !== null) {
            return ActionResult::fail(403, 'forbidden', $denied);
        }

        return $do($dev);
    }

    // ------------------------------------------------------------------ enrollment tokens

    /**
     * Create an enrollment token for a client. The plaintext token is in `data['token']` and is shown once.
     *
     * @param bool $managedExternally the machines enrolled with this token are already managed by another RMM or patch tool: they start with Core automation disabled
     *        ({@see \RivetCore\Rmm\Enrollment\EnrollmentService::createToken()}). An edition's REST body may accept it as the optional boolean `managed_externally`.
     */
    public function createToken(RmmPrincipal $who, int $clientId, int $locationId, string $ring, int $ttlHours, int $maxUses, string $label, bool $managedExternally = false): ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::TOKEN_MANAGE, $clientId, false);
        if ($denied !== null) {
            return ActionResult::fail(403, 'forbidden', $denied);
        }
        try {
            $t = $this->enrollment->createToken($clientId, $locationId, $ring, $ttlHours, $maxUses, trim($label), $who->userId, $managedExternally);
        } catch (\InvalidArgumentException $e) {
            return ActionResult::fail(422, 'invalid', $e->getMessage());
        }
        $this->audit->record('Enrollment Token Created', "{$who->userName} created enrollment token #{$t['token_id']} for client $clientId" . ($managedExternally ? ' (devices enrolled with it are managed by another RMM: Core automation disabled on them)' : ''), $clientId, 0);

        return ActionResult::ok('Enrollment token created. Copy it now: it is shown only once.', 201, 'created', ['token_id' => $t['token_id'], 'token' => $t['token']]);
    }

    public function revokeToken(RmmPrincipal $who, int $tokenId): ActionResult
    {
        // No existence oracle: a caller who may not manage tokens at all gets 403 (it reveals nothing about any token); beyond that,
        // a missing token and a token of a client outside the caller's scope or abilities answer the same 404.
        $denied = $this->authz->check($who->userId, RmmAbility::TOKEN_MANAGE, 0, false);
        if ($denied !== null) {
            return ActionResult::fail(403, 'forbidden', $denied);
        }
        $row = $this->sql->one('SELECT client_id FROM endpoint_agent_enrollment_tokens WHERE token_id = ?', [$tokenId]);
        if ($row !== null && $this->authz->check($who->userId, RmmAbility::TOKEN_MANAGE, (int) $row['client_id'], false) !== null) {
            $row = null;
        }
        if ($row === null || !$this->enrollment->revokeToken($tokenId, $who->userId)) {
            return ActionResult::fail(404, 'not_found', 'No such active enrollment token.');
        }
        $this->audit->record('Enrollment Token Revoked', "{$who->userName} revoked enrollment token #$tokenId", (int) $row['client_id'], 0);

        return ActionResult::ok('Enrollment token revoked.');
    }
}
