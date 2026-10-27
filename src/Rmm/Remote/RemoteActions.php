<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\Mesh\MeshService;
use RivetCore\Rmm\Presentation\RemoteHeader;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Technician\ActionResult;

/**
 * The remote tools a technician uses on one device (Phase 6): the service manager, the process manager, file transfer and the remote-tools settings. Shared by
 * the REST API ({@see \RivetCore\Rmm\Http\RemoteApi}) and by an edition's pages, like {@see \RivetCore\Rmm\Technician\TechnicianActions}.
 *
 * ORDER OF DECISIONS (a denial reveals nothing): the role cannot view devices -> 403; the device is missing OR outside the caller's client scope -> the same 404;
 * the caller lacks `rmm.remote.manage` (or `rmm.admin` for sending a file to a device and for the settings) -> 403 with the edition's generic text, audited;
 * the feature's sub-switch is off -> 409 `feature_disabled` with the plain-language reason; then the request is validated against strict allow-lists. Nothing
 * here ever builds a command string: it queues a signed job whose parameters are validated names and numbers ({@see RemoteJobTypes}) and the agent turns
 * them into an argument list.
 *
 * @api
 */
final class RemoteActions
{
    /** The agent's own service names: stopping, restarting or disabling them would cut the device off, so the server refuses before queuing (the agent refuses too). */
    public const PROTECTED_SERVICES = ['rivetit-agent', 'rivetit-agent.service', 'rivetitagent', 'rivetit endpoint agent'];

    public function __construct(
        private readonly Sql $sql,
        private readonly DeviceRepository $devices,
        private readonly RmmAuthorizer $authz,
        private readonly RmmSettings $settings,
        private readonly JobService $jobs,
        private readonly TransferService $transfers,
        private readonly RemoteSettings $remote,
        private readonly MeshService $mesh,
        private readonly RmmAuditInterface $audit,
        private readonly ?\RivetCore\Rmm\Mesh\MeshAgentSettings $meshSettings = null,
        private readonly ?\RivetCore\Rmm\Mesh\MeshAgentService $meshAgents = null,
        private readonly ?\RivetCore\Rmm\Scripts\TargetResolver $targets = null,
    ) {
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * @return array{0:?array<string,mixed>,1:?ActionResult} [device, error]
     */
    private function deviceFor(RmmPrincipal $who, int $deviceId): array
    {
        $role = $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, 0);
        if ($role !== null) {
            return [null, ActionResult::fail(403, 'forbidden', $role)];
        }
        $dev = $this->devices->find($deviceId);
        $client = $dev === null ? 0 : (int) $dev['client_id'];
        if ($dev === null || !$this->authz->clientOk($who->userId, $client) || $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, $client) !== null) {
            return [null, ActionResult::fail(404, 'not_found', 'Device not found.')];
        }

        return [$dev, null];
    }

    /**
     * The device, after the ability and the feature switch.
     *
     * @return array{0:?array<string,mixed>,1:?ActionResult}
     */
    private function gate(RmmPrincipal $who, int $deviceId, string $ability, string $feature, string $what): array
    {
        [$dev, $err] = $this->deviceFor($who, $deviceId);
        if ($err !== null || $dev === null) {
            return [null, $err ?? ActionResult::fail(404, 'not_found', 'Device not found.')];
        }
        $client = (int) $dev['client_id'];
        $denied = $this->authz->check($who->userId, $ability, $client);
        if ($denied !== null) {
            $this->audit->record('Remote Tool Denied', "User {$who->userId} denied $what on device $deviceId: $denied", $client, (int) ($dev['asset_id'] ?? 0));

            return [null, ActionResult::fail(403, 'forbidden', $denied)];
        }
        if (!$this->settings->featureOn($feature)) {
            return [null, ActionResult::fail(409, 'feature_disabled', self::offText($feature))];
        }
        if ($dev['link_state'] !== 'linked') {
            return [null, ActionResult::fail(409, 'conflict', 'Only a device linked to an asset can be managed. Approve it first.')];
        }

        return [$dev, null];
    }

    private static function offText(string $feature): string
    {
        return match ($feature) {
            RemoteProtocol::FEATURE_FILE_TRANSFER => 'File transfer is switched off. An administrator can turn it on in the RMM remote-tools settings after reading what it allows.',
            RemoteProtocol::FEATURE_LOGS => 'Log collection is switched off. An administrator can turn it on in the RMM remote-tools settings after reading the storage warning.',
            default => 'The remote tools (service and process managers, Mesh terminal and files) are switched off. An administrator can turn them on in the RMM remote-tools settings.',
        };
    }

    /**
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $params
     * @param array<string,mixed> $opts {@see JobService::create()} options (the Mesh install keeps the device group id out of the job row with `extra.secret`)
     */
    private function queue(RmmPrincipal $who, array $dev, string $type, array $params, bool $destructive, string $audit, array $opts = []): ActionResult
    {
        $r = $this->jobs->create($dev, $type, null, $params, null, $destructive, $who->userId, $opts);
        $client = (int) $dev['client_id'];
        $asset = (int) ($dev['asset_id'] ?? 0);
        if (!$r['ok']) {
            $http = ($r['code'] ?? '') === 'unsupported' ? 409 : 422;

            return ActionResult::fail($http, $http === 409 ? 'unsupported' : 'invalid', (string) ($r['error'] ?? 'The job could not be created.'));
        }
        $jobId = (string) ($r['job_id'] ?? '');
        $this->audit->record('Remote Tool Queued', "{$who->userName} queued $type job $jobId on device {$dev['device_id']}: $audit", $client, $asset);

        return ActionResult::ok('Queued. The device picks it up at its next check-in.', 202, 'queued', ['job_id' => $jobId]);
    }

    // ------------------------------------------------------------------ service manager

    /** Ask the device for its list of services; read the answer with {@see snapshot()}. */
    public function listServices(RmmPrincipal $who, int $deviceId): ActionResult
    {
        [$dev, $err] = $this->gate($who, $deviceId, RmmAbility::REMOTE_MANAGE, RemoteProtocol::FEATURE_REMOTE_TOOLS, 'service list');
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }

        return $this->queue($who, $dev, RemoteProtocol::JOB_SERVICE_LIST, [], false, 'list services');
    }

    /**
     * Start, stop, restart, enable, disable or set manual a service. `stop`, `restart` and `disable` interrupt the device and need `$confirm`; such a job is offered at
     * most once and never retried.
     */
    public function controlService(RmmPrincipal $who, int $deviceId, mixed $name, mixed $action, bool $confirm): ActionResult
    {
        [$dev, $err] = $this->gate($who, $deviceId, RmmAbility::REMOTE_MANAGE, RemoteProtocol::FEATURE_REMOTE_TOOLS, 'service control');
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $os = (string) ($dev['os'] ?? 'windows');
        $clean = RemoteProtocol::serviceName($name, $os);
        if ($clean === null) {
            return ActionResult::fail(422, 'invalid', 'That is not a valid service name (letters, digits and _ . @ $ : - only, no spaces, not starting with a symbol).');
        }
        if (!is_string($action) || !in_array($action, RemoteProtocol::SERVICE_ACTIONS, true)) {
            return ActionResult::fail(422, 'invalid', 'The action must be one of: ' . implode(', ', RemoteProtocol::SERVICE_ACTIONS) . '.');
        }
        if ($os === 'linux' && $action === 'manual') {
            return ActionResult::fail(422, 'invalid', 'systemd has no separate manual startup type: use disable.');
        }
        $destructive = in_array($action, RemoteProtocol::SERVICE_DESTRUCTIVE, true);
        if ($destructive && in_array(strtolower($clean), self::PROTECTED_SERVICES, true)) {
            return ActionResult::fail(422, 'protected', 'That is the RMM agent\'s own service: stopping it would cut the device off from management.');
        }
        if ($destructive && !$confirm) {
            return ActionResult::fail(422, 'confirmation_required', "Choosing $action on a service interrupts whatever depends on it. Confirm it explicitly.");
        }

        return $this->queue($who, $dev, RemoteProtocol::JOB_SERVICE_CONTROL, ['name' => $clean, 'action' => $action], $destructive, "$action $clean");
    }

    // ------------------------------------------------------------------ process manager

    public function listProcesses(RmmPrincipal $who, int $deviceId, mixed $limit = null, mixed $sort = null): ActionResult
    {
        [$dev, $err] = $this->gate($who, $deviceId, RmmAbility::REMOTE_MANAGE, RemoteProtocol::FEATURE_REMOTE_TOOLS, 'process list');
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $params = [];
        if ($limit !== null) {
            $params['limit'] = is_string($limit) && ctype_digit($limit) ? (int) $limit : $limit;
        }
        if ($sort !== null) {
            $params['sort'] = $sort;
        }

        return $this->queue($who, $dev, RemoteProtocol::JOB_PROCESS_LIST, $params, false, 'list processes');
    }

    /**
     * End a process. The caller names the process id AND the process name as listed: the agent re-reads the name at kill time and refuses on a mismatch, so a recycled
     * process id cannot end the wrong program. System processes and the agent itself are refused by the agent. Always needs `$confirm`.
     */
    public function killProcess(RmmPrincipal $who, int $deviceId, mixed $pid, mixed $name, bool $force, bool $confirm): ActionResult
    {
        [$dev, $err] = $this->gate($who, $deviceId, RmmAbility::REMOTE_MANAGE, RemoteProtocol::FEATURE_REMOTE_TOOLS, 'process kill');
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $p = RemoteProtocol::pid($pid);
        if ($p === null) {
            return ActionResult::fail(422, 'invalid', 'The process id must be a whole number from ' . RemoteProtocol::PID_MIN . ' to ' . RemoteProtocol::PID_MAX . '.');
        }
        $n = RemoteProtocol::processName($name);
        if ($n === null) {
            return ActionResult::fail(422, 'invalid', 'Give the process name exactly as listed (letters, digits, spaces and . _ ( ) + @ # ~ [ ] - only).');
        }
        if (!$confirm) {
            return ActionResult::fail(422, 'confirmation_required', 'Ending a process can lose unsaved work and stop a service. Confirm it explicitly.');
        }

        return $this->queue($who, $dev, RemoteProtocol::JOB_PROCESS_KILL, ['pid' => $p, 'name' => $n, 'force' => $force], true, "end process $p ($n)" . ($force ? ' forced' : ''));
    }

    // ------------------------------------------------------------------ results of the list jobs

    /**
     * The latest result of a list job for the device: the structured document the agent reported, the job state, and when it finished. Needs `rmm.remote.manage`
     * (a service or process list is not job output a saved-script grant may read).
     *
     * @param string $kind services | processes
     */
    public function snapshot(RmmPrincipal $who, int $deviceId, string $kind): ActionResult
    {
        [$dev, $err] = $this->deviceFor($who, $deviceId);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $denied = $this->authz->check($who->userId, RmmAbility::REMOTE_MANAGE, (int) $dev['client_id']);
        if ($denied !== null) {
            return ActionResult::fail(403, 'forbidden', $denied);
        }
        $type = $kind === 'services' ? RemoteProtocol::JOB_SERVICE_LIST : RemoteProtocol::JOB_PROCESS_LIST;
        $j = $this->sql->one('SELECT job_id, state, reason, output, output_truncated, created_at, finished_at FROM endpoint_agent_jobs WHERE device_id = ? AND type = ? ORDER BY created_at DESC, job_id LIMIT 1', [$deviceId, $type]);
        if ($j === null) {
            return ActionResult::ok('No list has been requested yet.', 200, 'ok', ['job_id' => null, 'state' => 'none', 'data' => null]);
        }
        $data = null;
        $error = null;
        if ($j['state'] === 'succeeded') {
            $data = json_decode((string) $j['output'], true);
            if (!is_array($data)) {
                $data = null;
                $error = 'The device answered with something that is not a list.';
            }
        } elseif (in_array($j['state'], ['failed', 'timed_out', 'cancelled', 'expired'], true)) {
            $error = 'The job ended as ' . $j['state'] . ($j['reason'] ? ' (' . $j['reason'] . ')' : '') . '.';
        }

        return ActionResult::ok('ok', 200, 'ok', ['job_id' => $j['job_id'], 'state' => $j['state'], 'requested_at' => Sql::iso((string) $j['created_at']),
            'finished_at' => Sql::iso($j['finished_at'] === null ? null : (string) $j['finished_at']), 'data' => $data, 'error' => $error]);
    }

    /** The result of any remote-tool job (a control or a kill included): state, exit code and the structured detail the agent reported. */
    public function jobResult(RmmPrincipal $who, int $deviceId, string $jobId): ActionResult
    {
        [$dev, $err] = $this->deviceFor($who, $deviceId);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $denied = $this->authz->check($who->userId, RmmAbility::REMOTE_MANAGE, (int) $dev['client_id']);
        if ($denied !== null) {
            return ActionResult::fail(403, 'forbidden', $denied);
        }
        $j = $this->sql->one('SELECT job_id, type, state, reason, exit_code, output, created_at, finished_at FROM endpoint_agent_jobs WHERE job_id = ? AND device_id = ?', [strtolower($jobId), $deviceId]);
        if ($j === null || !RemoteProtocol::isToolJob($j['type'])) {
            return ActionResult::fail(404, 'not_found', 'Job not found.');
        }
        $data = json_decode((string) $j['output'], true);

        return ActionResult::ok('ok', 200, 'ok', ['job_id' => $j['job_id'], 'type' => $j['type'], 'state' => $j['state'], 'reason' => $j['reason'], 'exit_code' => $j['exit_code'] === null ? null : (int) $j['exit_code'],
            'finished_at' => Sql::iso($j['finished_at'] === null ? null : (string) $j['finished_at']), 'result' => is_array($data) ? $data : null]);
    }

    // ------------------------------------------------------------------ file transfer

    /** Ask the device to send one file to the server. */
    public function requestDownload(RmmPrincipal $who, int $deviceId, mixed $path): ActionResult
    {
        [$dev, $err] = $this->gate($who, $deviceId, RmmAbility::REMOTE_MANAGE, RemoteProtocol::FEATURE_FILE_TRANSFER, 'file download');
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        if (!is_string($path) || $path === '') {
            return ActionResult::fail(422, 'invalid', 'Give the full path of the file.');
        }
        $r = $this->transfers->requestDownload($dev, $path, $who->userId);
        $client = (int) $dev['client_id'];
        $asset = (int) ($dev['asset_id'] ?? 0);
        if (!$r['ok']) {
            $this->audit->record('File Download Refused', "{$who->userName} asked for a file on device $deviceId: " . ($r['reason'] ?? $r['code'] ?? 'refused'), $client, $asset);

            return ActionResult::fail((int) ($r['http'] ?? 422), (string) ($r['code'] ?? 'invalid'), (string) ($r['error'] ?? 'Refused.'), isset($r['reason']) ? ['reason' => $r['reason']] : []);
        }
        $this->audit->record('File Download Requested', "{$who->userName} asked device $deviceId to send " . self::logPath($path) . " (transfer {$r['transfer_id']}, job {$r['job_id']})", $client, $asset);

        return ActionResult::ok('Queued. The device sends the file at its next check-in.', 202, 'queued', ['transfer_id' => $r['transfer_id'], 'job_id' => $r['job_id']]);
    }

    /**
     * Declare a file to send to a device (administrators only). `$confirm` is required: the file replaces nothing unless `$overwrite`, but it is still written to the device.
     */
    public function createUpload(RmmPrincipal $who, int $deviceId, mixed $path, mixed $size, mixed $sha256, bool $overwrite, bool $confirm): ActionResult
    {
        [$dev, $err] = $this->gate($who, $deviceId, RmmAbility::ADMIN, RemoteProtocol::FEATURE_FILE_TRANSFER, 'file upload');
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        if (!is_string($path) || $path === '' || !is_int($size) || !is_string($sha256)) {
            return ActionResult::fail(422, 'invalid', 'path, size (bytes) and sha256 are required.');
        }
        if (!$confirm) {
            return ActionResult::fail(422, 'confirmation_required', 'Sending a file to a device writes to its disk' . ($overwrite ? ' and replaces any file at that path' : '') . '. Confirm it explicitly.');
        }
        $r = $this->transfers->createUpload($dev, $path, $size, strtolower($sha256), $overwrite, $who->userId);
        $client = (int) $dev['client_id'];
        $asset = (int) ($dev['asset_id'] ?? 0);
        if (!$r['ok']) {
            $this->audit->record('File Upload Refused', "{$who->userName} wanted to send a file to device $deviceId: " . ($r['reason'] ?? $r['code'] ?? 'refused'), $client, $asset);

            return ActionResult::fail((int) ($r['http'] ?? 422), (string) ($r['code'] ?? 'invalid'), (string) ($r['error'] ?? 'Refused.'), isset($r['reason']) ? ['reason' => $r['reason']] : []);
        }
        $this->audit->record('File Upload Declared', "{$who->userName} declared a file for device $deviceId at " . self::logPath($path) . ", $size bytes, sha256 " . strtolower($sha256) . " (transfer {$r['transfer_id']})", $client, $asset);

        return ActionResult::ok('Declared. Now send the file content.', 201, 'created', ['transfer_id' => $r['transfer_id'], 'state' => 'awaiting_content']);
    }

    /**
     * Receive the declared file's bytes and queue the delivery.
     *
     * @param resource $stream
     */
    public function uploadContent(RmmPrincipal $who, int $deviceId, string $transferId, mixed $stream): ActionResult
    {
        [$dev, $err] = $this->gate($who, $deviceId, RmmAbility::ADMIN, RemoteProtocol::FEATURE_FILE_TRANSFER, 'file upload');
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $r = $this->transfers->storeUploadContent($dev, strtolower($transferId), $stream, $who->userId);
        $client = (int) $dev['client_id'];
        $asset = (int) ($dev['asset_id'] ?? 0);
        if (!$r['ok']) {
            return ActionResult::fail((int) ($r['http'] ?? 422), (string) ($r['code'] ?? 'invalid'), (string) ($r['error'] ?? 'Refused.'));
        }
        $this->audit->record('File Upload Queued', "{$who->userName} queued delivery of transfer $transferId to device $deviceId (job {$r['job_id']})", $client, $asset);

        return ActionResult::ok('Queued. The device fetches the file at its next check-in, verifies it and puts it in place.', 202, 'queued', ['transfer_id' => $transferId, 'job_id' => $r['job_id']]);
    }

    /** The transfer ledger of a device. */
    public function listTransfers(RmmPrincipal $who, int $deviceId): ActionResult
    {
        [$dev, $err] = $this->deviceFor($who, $deviceId);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $denied = $this->authz->check($who->userId, RmmAbility::REMOTE_MANAGE, (int) $dev['client_id']);
        if ($denied !== null) {
            return ActionResult::fail(403, 'forbidden', $denied);
        }

        return ActionResult::ok('ok', 200, 'ok', ['data' => $this->transfers->listFor($deviceId)]);
    }

    public function transfer(RmmPrincipal $who, int $deviceId, string $transferId): ActionResult
    {
        [$dev, $t, $err] = $this->transferOf($who, $deviceId, $transferId);
        if ($err !== null || $t === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Transfer not found.');
        }

        return ActionResult::ok('ok', 200, 'ok', $this->transfers->present($t));
    }

    /**
     * The verified file of a finished download. `data` carries path, length, sha256 and name for the caller to stream; the fetch is audited.
     */
    public function downloadContent(RmmPrincipal $who, int $deviceId, string $transferId): ActionResult
    {
        [$dev, $t, $err] = $this->transferOf($who, $deviceId, $transferId);
        if ($err !== null || $t === null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Transfer not found.');
        }
        $c = $this->transfers->contentOf($t);
        if ($c === null) {
            return ActionResult::fail(409, 'conflict', 'This file is not available: it has not arrived, failed its checksum, was deleted or expired.');
        }
        $this->audit->record('File Downloaded', "{$who->userName} downloaded transfer $transferId (" . self::logPath((string) $t['remote_path']) . ", {$c['length']} bytes, sha256 {$c['sha256']}) from device $deviceId",
            (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

        return ActionResult::ok('ok', 200, 'ok', $c);
    }

    public function deleteTransfer(RmmPrincipal $who, int $deviceId, string $transferId): ActionResult
    {
        [$dev, $t, $err] = $this->transferOf($who, $deviceId, $transferId);
        if ($err !== null || $t === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Transfer not found.');
        }
        $this->transfers->discard($t, $who->userId);

        return ActionResult::ok('Deleted.');
    }

    /**
     * @return array{0:?array<string,mixed>,1:?array<string,mixed>,2:?ActionResult} [device, transfer row, error]
     */
    private function transferOf(RmmPrincipal $who, int $deviceId, string $transferId): array
    {
        [$dev, $err] = $this->deviceFor($who, $deviceId);
        if ($err !== null || $dev === null) {
            return [null, null, $err ?? ActionResult::fail(404, 'not_found', 'Device not found.')];
        }
        $denied = $this->authz->check($who->userId, RmmAbility::REMOTE_MANAGE, (int) $dev['client_id']);
        if ($denied !== null) {
            return [null, null, ActionResult::fail(403, 'forbidden', $denied)];
        }
        $t = $this->transfers->find(strtolower($transferId), $deviceId);
        if ($t === null) {
            return [null, null, ActionResult::fail(404, 'not_found', 'Transfer not found.')];
        }

        return [$dev, $t, null];
    }

    private static function logPath(string $p): string
    {
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '?', $p) ?? '', 0, 200);
    }

    // ------------------------------------------------------------------ Mesh terminal and files

    /**
     * What the device page needs to offer the MeshCentral views: whether Mesh is set up, whether this device is mapped to a node, and which views this caller may open.
     */
    public function meshInfo(RmmPrincipal $who, int $deviceId): ActionResult
    {
        [$dev, $err] = $this->deviceFor($who, $deviceId);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $client = (int) $dev['client_id'];
        $cfg = $this->settings->get();
        $configured = (int) $cfg['mesh_enabled'] === 1 && (string) $cfg['mesh_url'] !== '' && !empty($cfg['mesh_login_key_enc']);
        $node = $this->mesh->nodeFor($deviceId);
        $views = [];
        if ($this->authz->allowed($who->userId, RmmAbility::REMOTE_LAUNCH, $client)) {
            $views[] = 'desktop';
            if ($this->settings->featureOn(RemoteProtocol::FEATURE_REMOTE_TOOLS) && $this->authz->allowed($who->userId, RmmAbility::REMOTE_MANAGE, $client)) {
                array_push($views, 'terminal', 'files');
            }
        }

        return ActionResult::ok('ok', 200, 'ok', ['configured' => $configured, 'mapped' => $node !== null, 'node_id' => $views === [] ? null : $node, 'views' => $configured && $node !== null ? $views : [],
            'terminal_files_enabled' => $this->settings->featureOn(RemoteProtocol::FEATURE_REMOTE_TOOLS)]);
    }

    /**
     * The remote part of the shared device header ({@see \RivetCore\Rmm\Presentation\RemoteHeader}), for THIS caller: the views he may open, the one the button leads with and a sentence for the state.
     * Built on the same facts as {@see self::meshInfo()}, which stays as it was. `$hints` are optional facts the edition knows: `server` (a server chassis or virtual server).
     * Order of decisions: device scope (404) -> the pure decision table. A caller without remote abilities still gets the state, with every view unavailable and the reason.
     *
     * @param array<string,mixed> $hints
     */
    public function headerRemote(RmmPrincipal $who, int $deviceId, array $hints = []): ActionResult
    {
        [$dev, $err] = $this->deviceFor($who, $deviceId);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $client = (int) $dev['client_id'];
        $cfg = $this->settings->get();
        $inv = is_string($dev['inventory_json'] ?? null) ? json_decode($dev['inventory_json'], true) : null;
        $inv = is_array($inv) ? $inv : [];
        $state = $this->devices->status($dev, $cfg);

        return ActionResult::ok('ok', 200, 'ok', RemoteHeader::decide([
            'family' => \RivetCore\Rmm\Presentation\DeviceHeader::family($inv, (string) ($dev['os'] ?? '') . ' ' . (string) ($dev['os_version'] ?? '')),
            'has_desktop' => is_bool($inv['desktop_session'] ?? null) ? $inv['desktop_session'] : null,
            'server' => ($hints['server'] ?? false) === true,
            'enabled' => (int) $cfg['mesh_enabled'] === 1,
            'configured' => (string) $cfg['mesh_url'] !== '' && !empty($cfg['mesh_login_key_enc']),
            'node' => $this->mesh->nodeFor($deviceId),
            'agent_installed' => ($this->meshAgents?->stateFor($deviceId)['installed'] ?? false) === true,
            'offline' => in_array($state['state'], ['offline', 'stale', 'never'], true),
            'can_launch' => $this->authz->allowed($who->userId, RmmAbility::REMOTE_LAUNCH, $client),
            'can_manage' => $this->authz->allowed($who->userId, RmmAbility::REMOTE_MANAGE, $client),
            'tools_on' => $this->settings->featureOn(RemoteProtocol::FEATURE_REMOTE_TOOLS),
        ]));
    }

    // ------------------------------------------------------------------ the Mesh agent: push an install or a removal

    /** The reason a Mesh agent cannot be pushed right now, or null. */
    private function meshInstallRefusal(): ?ActionResult
    {
        if ($this->meshAgents === null) {
            return ActionResult::fail(409, 'not_configured', 'The MeshCentral agent install is not available.');
        }
        $why = $this->meshAgents->installRefusal();

        return $why === null ? null : ActionResult::fail(409, 'not_configured', $why);
    }

    /**
     * Install the MeshCentral agent on one enrolled device (a `mesh_install` job). The job carries NOTHING the caller typed: the address, the device group id,
     * the optional pin and the agent-id and argument tables come from the stored Mesh settings at this moment, and the group id is kept out of the job row (it is sealed
     * with the job's other secrets and put back only when the job is signed for the device). The device downloads the agent from the customer's own Mesh server over
     * https with the same pinned routine the installer uses; a failure never touches the RivetIT agent.
     *
     * Order of decisions: device scope (404) -> `rmm.remote.manage` (403) -> the remote-tools switch (409 `feature_disabled`) -> linked device -> Mesh enabled and configured
     * (409 `not_configured`) -> the device announced `job:mesh_install` (409 `unsupported`: update the agent). IDEMPOTENT: a device whose last report says the Mesh agent
     * is installed answers 200 `already_installed` and queues nothing unless `$force`; a second request while one is still queued or running answers 200 with the
     * same job (`already_queued`).
     */
    public function installMeshAgent(RmmPrincipal $who, int $deviceId, bool $force = false): ActionResult
    {
        [$dev, $err] = $this->gate($who, $deviceId, RmmAbility::REMOTE_MANAGE, RemoteProtocol::FEATURE_REMOTE_TOOLS, 'Mesh agent install');
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        if (($refused = $this->meshInstallRefusal()) !== null) {
            return $refused;
        }

        return $this->pushMeshInstall($who, $dev, $force);
    }

    /**
     * The Mesh agent install an enrollment token asked for ("install the Mesh agent once this device is approved"), queued by the SERVER for a device that is already
     * linked. There is no user: it acts as the system principal and needs no ability, because the administrator's tick-box on the installer was the decision and the
     * device only gets here after an approval or an automatic match. Everything else is the manual path: the remote-tools switch (409 `feature_disabled`), Mesh enabled and
     * configured (409 `not_configured`), a linked device, the announced `job:mesh_install` capability (409 `unsupported`), idempotent (`already_installed`, `already_queued`),
     * and the same sealed job (the group id comes from the stored settings and is kept out of the job row). Called by {@see \RivetCore\Rmm\Mesh\MeshAfterApproval}.
     *
     * @param array<string,mixed> $dev the device row
     */
    public function installMeshAgentAfterApproval(array $dev): ActionResult
    {
        if (!$this->settings->featureOn(RemoteProtocol::FEATURE_REMOTE_TOOLS)) {
            return ActionResult::fail(409, 'feature_disabled', self::offText(RemoteProtocol::FEATURE_REMOTE_TOOLS));
        }
        if (($dev['link_state'] ?? '') !== 'linked') {
            return ActionResult::fail(409, 'conflict', 'Only a device linked to an asset can be managed. Approve it first.');
        }
        if (($refused = $this->meshInstallRefusal()) !== null) {
            return $refused;
        }

        return $this->pushMeshInstall(new RmmPrincipal(0, 'System'), $dev, false);
    }

    /**
     * @param array<string,mixed> $dev
     */
    private function pushMeshInstall(RmmPrincipal $who, array $dev, bool $force): ActionResult
    {
        $deviceId = (int) $dev['device_id'];
        $state = $this->meshAgents?->stateFor($deviceId);
        if (!$force && $state !== null && $state['installed']) {
            return ActionResult::ok('The MeshCentral agent is already installed on this device. Nothing was queued.', 200, 'already_installed', ['skipped' => 'already_installed', 'node_id' => $state['node_id']]);
        }
        $open = $this->sql->one("SELECT job_id FROM endpoint_agent_jobs WHERE device_id = ? AND type = ? AND state IN ('queued','running') ORDER BY created_at DESC LIMIT 1", [$deviceId, RemoteProtocol::JOB_MESH_INSTALL]);
        if ($open !== null) {
            return ActionResult::ok('A Mesh agent install is already queued or running for this device.', 200, 'already_queued', ['skipped' => 'already_queued', 'job_id' => $open['job_id']]);
        }
        $params = $this->meshAgents?->jobParams($force);
        if ($params === null) {
            return ActionResult::fail(409, 'not_configured', (string) $this->meshAgents?->installRefusal());
        }
        // The stored settings are validated again by the job type's own validator before anything is queued.
        [, $bad] = RemoteJobTypes::meshInstall($params);
        if ($bad !== null) {
            return ActionResult::fail(409, 'not_configured', 'The stored MeshCentral settings cannot be sent: ' . $bad);
        }
        $secretId = $params['meshid'];
        $params['meshid'] = \RivetCore\Rmm\Job\JobExtras::SECRET_MARK;

        return $this->queue($who, $dev, RemoteProtocol::JOB_MESH_INSTALL, $params, false, 'install the MeshCentral agent' . ($force ? ' (forced)' : ''), ['extra' => ['secret' => ['meshid' => $secretId]]]);
    }

    /**
     * Ask the device to run the MeshCentral agent's own uninstaller (a `mesh_uninstall` job: destructive, offered at most once). Never touches the RivetIT agent. Needs `$confirm`.
     * Not tied to the Mesh settings: an agent can be removed after Mesh was switched off.
     */
    public function uninstallMeshAgent(RmmPrincipal $who, int $deviceId, bool $confirm): ActionResult
    {
        [$dev, $err] = $this->gate($who, $deviceId, RmmAbility::REMOTE_MANAGE, RemoteProtocol::FEATURE_REMOTE_TOOLS, 'Mesh agent removal');
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        if (!$confirm) {
            return ActionResult::fail(422, 'confirmation_required', 'Removing the MeshCentral agent ends remote control through MeshCentral on this device. Confirm it explicitly.');
        }

        return $this->queue($who, $dev, RemoteProtocol::JOB_MESH_UNINSTALL, [], true, 'remove the MeshCentral agent');
    }

    /**
     * Install the MeshCentral agent on every device of a target (`device`, `tag`, `group`, `client`, `site`, `policy` or `all`), inside the caller's clients. The same
     * rules as the single install apply to each device, which is skipped (and counted) when it already reports the agent, already has an install queued, the caller lacks
     * `rmm.remote.manage` for its client, or its agent is too old. The target may reach at most `bulk_run_max` devices; above `approval_bulk_threshold` (when it is set) the
     * request is REFUSED (409 `approval_required`): the two-person approval queue only knows script runs today, so a larger fleet is installed in batches by tag or client.
     * Needs `$confirm`.
     *
     * @param array<string,mixed> $in {target:{type,id}, force?, confirm?}
     */
    public function installMeshAgentBulk(RmmPrincipal $who, array $in): ActionResult
    {
        $role = $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, 0) ?? $this->authz->check($who->userId, RmmAbility::REMOTE_MANAGE, 0);
        if ($role !== null) {
            return ActionResult::fail(403, 'forbidden', $role);
        }
        if (array_diff(array_keys($in), ['target', 'force', 'confirm']) !== []) {
            return ActionResult::fail(422, 'invalid', 'Only target, force and confirm are accepted.');
        }
        foreach (['force', 'confirm'] as $k) {
            if (array_key_exists($k, $in) && !is_bool($in[$k])) {
                return ActionResult::fail(422, 'invalid', "$k must be true or false.");
            }
        }
        if ($this->targets === null) {
            return ActionResult::fail(409, 'not_configured', 'Bulk installs are not available.');
        }
        [$target, $terr] = $this->targets->validate($in['target'] ?? null);
        if ($target === null) {
            return ActionResult::fail(422, 'invalid', $terr ?? 'Invalid target.');
        }
        if (!$this->settings->featureOn(RemoteProtocol::FEATURE_REMOTE_TOOLS)) {
            return ActionResult::fail(409, 'feature_disabled', self::offText(RemoteProtocol::FEATURE_REMOTE_TOOLS));
        }
        if (($refused = $this->meshInstallRefusal()) !== null) {
            return $refused;
        }
        $force = !empty($in['force']);
        $visible = $this->authz->visibleClientIds($who->userId);
        $limits = $this->settings->limits();
        $max = $limits['bulk_run_max'];
        // Count first (bounded by the cap), so a refusal queues nothing.
        $count = 0;
        $cursor = 0;
        while ($count <= $max) {
            $page = $this->targets->devices($target['type'], $target['id'], $visible, ['windows', 'linux'], $cursor, 500);
            if ($page === []) {
                break;
            }
            $count += count($page);
            $cursor = (int) $page[count($page) - 1]['device_id'];
        }
        if ($count === 0) {
            return ActionResult::fail(422, 'invalid', 'No linked device of this target can receive the install.');
        }
        if ($count > $max) {
            return ActionResult::fail(422, 'too_many', "That reaches more than $max devices; one bulk install is limited to $max.");
        }
        $threshold = $limits['approval_bulk_threshold'];
        if ($threshold > 0 && $count > $threshold) {
            return ActionResult::fail(409, 'approval_required', "That reaches $count devices, above the approval threshold of $threshold. Bulk Mesh installs cannot be approved yet: install in batches of at most $threshold (by tag, group or client).");
        }
        if (empty($in['confirm'])) {
            return ActionResult::fail(422, 'confirmation_required', "This installs the MeshCentral agent on up to $count device(s). Confirm it explicitly.", ['device_count' => $count]);
        }
        $out = ['device_count' => 0, 'created' => 0, 'skipped_denied' => 0, 'skipped_installed' => 0, 'skipped_queued' => 0, 'skipped_unsupported' => 0, 'skipped_error' => 0, 'errors' => []];
        $allowed = [];
        $cursor = 0;
        while (true) {
            $page = $this->targets->devices($target['type'], $target['id'], $visible, ['windows', 'linux'], $cursor, 500);
            if ($page === []) {
                break;
            }
            foreach ($page as $d) {
                $cursor = (int) $d['device_id'];
                ++$out['device_count'];
                $client = (int) $d['client_id'];
                $allowed[$client] ??= $this->authz->allowed($who->userId, RmmAbility::REMOTE_MANAGE, $client);
                if (!$allowed[$client]) {
                    ++$out['skipped_denied'];
                    continue;
                }
                $r = $this->pushMeshInstall($who, $d, $force);
                if ($r->ok && $r->code === 'queued') {
                    ++$out['created'];
                } elseif ($r->ok && $r->code === 'already_installed') {
                    ++$out['skipped_installed'];
                } elseif ($r->ok) {
                    ++$out['skipped_queued'];
                } elseif ($r->code === 'unsupported') {
                    ++$out['skipped_unsupported'];
                } else {
                    ++$out['skipped_error'];
                    if (count($out['errors']) < 5) {
                        $out['errors'][] = ((string) ($d['hostname'] ?? 'device ' . $d['device_id'])) . ': ' . $r->message;
                    }
                }
            }
            if (count($page) < 500) {
                break;
            }
        }
        $this->audit->record('Mesh Agent Bulk Install', "{$who->userName} queued the MeshCentral agent install on target {$target['type']} {$target['id']}: {$out['created']} job(s) of {$out['device_count']} device(s)"
            . ($force ? ' (forced)' : ''), 0, 0);

        return ActionResult::ok("{$out['created']} job(s) queued.", 201, 'queued', $out);
    }

    // ------------------------------------------------------------------ settings (administrators)

    /** @return array<string,mixed> */
    private function settingsView(): array
    {
        $f = $this->settings->features();

        return ['features' => [RemoteProtocol::FEATURE_REMOTE_TOOLS => $f[RemoteProtocol::FEATURE_REMOTE_TOOLS], RemoteProtocol::FEATURE_FILE_TRANSFER => $f[RemoteProtocol::FEATURE_FILE_TRANSFER],
            RemoteProtocol::FEATURE_LOGS => $f[RemoteProtocol::FEATURE_LOGS]], 'transfer_storage_error' => $this->transfers->unavailable()] + $this->remote->summary();
    }

    public function settings(RmmPrincipal $who): ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::ADMIN, 0, false);
        if ($denied !== null) {
            return ActionResult::fail(403, 'forbidden', $denied);
        }

        return ActionResult::ok('ok', 200, 'ok', $this->settingsView());
    }

    /**
     * The "apply compliance baseline" button: stores the recommended Windows log presets next to the ones already chosen (administrators; audited by
     * {@see RemoteSettings::applyComplianceBaseline()}). It does not turn log collection on and does not touch any source.
     */
    public function applyLogPresetBaseline(RmmPrincipal $who): ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::ADMIN, 0, false);
        if ($denied !== null) {
            $this->audit->record('Remote Settings Denied', "User {$who->userId} denied applying the log preset baseline: $denied", 0, 0);

            return ActionResult::fail(403, 'forbidden', $denied);
        }
        $this->remote->applyComplianceBaseline($who->userId, $who->userName);
        $this->remote->refresh();

        return ActionResult::ok('Saved.', 200, 'saved', $this->settingsView());
    }

    /**
     * Change the remote-tools settings. Turning `filetransfer` on needs `accept_filetransfer_risk: true`, turning `logs` on needs `accept_logs_storage: true`: the
     * request is refused with the plain-language text when the acknowledgement is missing, so no client can switch them on by accident.
     *
     * @param array<string,mixed> $in features {remote_tools, filetransfer, logs}, transfer_allow, logs_collect, limits, accept_filetransfer_risk, accept_logs_storage,
     *        compliance_baseline_if_unset (true with the first enable: store the compliance log-preset baseline if no preset choice was ever saved)
     */
    public function updateSettings(RmmPrincipal $who, array $in): ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::ADMIN, 0, false);
        if ($denied !== null) {
            $this->audit->record('Remote Settings Denied', "User {$who->userId} denied remote-tools settings change: $denied", 0, 0);

            return ActionResult::fail(403, 'forbidden', $denied);
        }
        $known = ['features', 'transfer_allow', 'logs_collect', 'limits', 'accept_filetransfer_risk', 'accept_logs_storage', 'compliance_baseline_if_unset'];
        foreach (array_keys($in) as $k) {
            if (!in_array($k, $known, true)) {
                return ActionResult::fail(422, 'invalid', 'Unknown setting ' . mb_substr((string) $k, 0, 40) . '.');
            }
        }
        $features = $this->settings->features();
        $wanted = $in['features'] ?? [];
        if (!is_array($wanted)) {
            return ActionResult::fail(422, 'invalid', 'features must be an object of switch name to true or false.');
        }
        $changed = [];
        foreach ($wanted as $k => $v) {
            if (!in_array($k, [RemoteProtocol::FEATURE_REMOTE_TOOLS, RemoteProtocol::FEATURE_FILE_TRANSFER, RemoteProtocol::FEATURE_LOGS], true) || !is_bool($v)) {
                return ActionResult::fail(422, 'invalid', 'features takes remote_tools, filetransfer and logs, each true or false.');
            }
            if ($features[$k] !== $v) {
                $changed[$k] = $v;
            }
        }
        if (($changed[RemoteProtocol::FEATURE_FILE_TRANSFER] ?? false) === true && empty($in['accept_filetransfer_risk'])) {
            return ActionResult::fail(422, 'confirmation_required', RemoteSettings::FILE_TRANSFER_RISK . ' Send accept_filetransfer_risk: true to turn file transfer on.');
        }
        if (($changed[RemoteProtocol::FEATURE_LOGS] ?? false) === true && empty($in['accept_logs_storage'])) {
            return ActionResult::fail(422, 'confirmation_required', RemoteSettings::LOGS_STORAGE_WARNING . ' Send accept_logs_storage: true to turn log collection on.');
        }
        $errors = $this->remote->update(array_intersect_key($in, array_flip(['transfer_allow', 'logs_collect', 'limits', 'compliance_baseline_if_unset'])), $who->userId);
        if ($errors !== []) {
            return ActionResult::fail(422, 'invalid', implode(' ', $errors));
        }
        if ($changed !== []) {
            $errors = $this->settings->update(['features_json' => array_merge($features, $changed)]);
            if ($errors !== []) {
                return ActionResult::fail(422, 'invalid', implode(' ', $errors));
            }
        }
        $what = [];
        foreach ($changed as $k => $v) {
            $what[] = "$k " . ($v ? 'ON' : 'off');
        }
        foreach (['transfer_allow', 'logs_collect', 'limits'] as $k) {
            if (array_key_exists($k, $in)) {
                $what[] = "$k updated";
            }
        }
        if (($in['compliance_baseline_if_unset'] ?? false) === true && !array_key_exists('logs_collect', $in)) {
            $what[] = 'log presets: compliance baseline stored if none was chosen';
        }
        $this->audit->record('Remote Settings Changed', "{$who->userName}: " . ($what === [] ? 'no change' : implode(', ', $what)), 0, 0);
        $this->settings->refresh();
        $this->remote->refresh();

        return ActionResult::ok('Saved.', 200, 'saved', $this->settingsView());
    }

    // ------------------------------------------------------------------ Mesh agent install settings (administrators)

    /** @return array<string,mixed> */
    private function meshSettingsView(): array
    {
        $cfg = $this->settings->get();
        $m = $this->meshSettings?->get() ?? ['group_id' => '', 'group_id_url' => '', 'server_cert_pin' => '', 'auto_map_overwrite' => false, 'agent_ids' => [], 'install_args' => [], 'hide' => \RivetCore\Rmm\Mesh\MeshCookie::HIDE_DEFAULT, 'hide_tools' => null];
        $t = $this->meshSettings?->effectiveTables() ?? ['agent_ids' => [], 'install_args' => []];
        $refusal = $this->meshAgents?->installRefusal();

        return ['mesh_enabled' => (int) $cfg['mesh_enabled'] === 1, 'mesh_url' => (string) $cfg['mesh_url'], 'mesh_group_id' => $m['group_id'], 'mesh_group_id_url' => $m['group_id_url'], 'mesh_server_cert_pin' => $m['server_cert_pin'],
            'mesh_auto_map_overwrite' => $m['auto_map_overwrite'], 'mesh_agent_ids' => $m['agent_ids'], 'mesh_install_args' => $m['install_args'],
            'mesh_hide' => $m['hide'], 'mesh_hide_tools' => $m['hide_tools'],
            'effective_agent_ids' => $t['agent_ids'], 'effective_install_args' => $t['install_args'], 'default_agent_ids' => \RivetCore\Rmm\Mesh\MeshAgentCatalog::DEFAULT_AGENT_IDS,
            'install_ready' => $refusal === null && $this->meshAgents !== null, 'install_refusal' => $this->meshAgents === null ? 'Not available.' : $refusal,
            'note' => 'The download address shape (agent id 4 for Windows x64, installflags=0, meshid in MeshCentral\'s 64-character URL form) was checked against a real MeshCentral. The Linux agent ids, the install arguments and the node-id locations were not: check them against your Mesh version.'];
    }

    public function meshSettings(RmmPrincipal $who): ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::ADMIN, 0, false);
        if ($denied !== null) {
            return ActionResult::fail(403, 'forbidden', $denied);
        }

        return ActionResult::ok('ok', 200, 'ok', $this->meshSettingsView());
    }

    /**
     * Change the Mesh agent install settings: `mesh_group_id`, `mesh_server_cert_pin`, `mesh_auto_map_overwrite`, `mesh_agent_ids`, `mesh_install_args`, `mesh_hide`, `mesh_hide_tools` (MeshCentral view parameters of a launch). Nothing is written when
     * any value is invalid. `probe_pin: true` reads the Mesh server's certificate and answers with a proposed pin (`pin_probe`) WITHOUT saving it.
     *
     * @param array<string,mixed> $in
     */
    public function updateMeshSettings(RmmPrincipal $who, array $in): ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::ADMIN, 0, false);
        if ($denied !== null) {
            $this->audit->record('Remote Settings Denied', "User {$who->userId} denied Mesh agent settings change: $denied", 0, 0);

            return ActionResult::fail(403, 'forbidden', $denied);
        }
        if ($this->meshSettings === null) {
            return ActionResult::fail(409, 'unsupported', 'The Mesh agent install settings are not available.');
        }
        $probe = !empty($in['probe_pin']);
        unset($in['probe_pin']);
        $errors = $this->meshSettings->update($in, $who->userId);
        if ($errors !== []) {
            return ActionResult::fail(422, 'invalid', implode(' ', $errors));
        }
        $warnings = $this->meshSettings->lastWarnings();
        if ($in !== []) {
            $this->audit->record('Settings', "{$who->userName} changed the Mesh agent install settings (" . implode(', ', array_keys($in)) . ')', 0, 0);
        }
        $data = $this->meshSettingsView();
        if ($warnings !== []) {
            $data['warnings'] = $warnings;
        }
        if ($probe) {
            $url = (string) $this->settings->get()['mesh_url'];
            $data['pin_probe'] = $url === '' ? ['ok' => false, 'error' => 'Save the MeshCentral address first.'] : $this->mesh->probePin($url);
        }

        return ActionResult::ok('Saved.', 200, 'saved', $data);
    }
}
