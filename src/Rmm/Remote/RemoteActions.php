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
     */
    private function queue(RmmPrincipal $who, array $dev, string $type, array $params, bool $destructive, string $audit): ActionResult
    {
        $r = $this->jobs->create($dev, $type, null, $params, null, $destructive, $who->userId);
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
        if ($j === null || !in_array($j['type'], RemoteProtocol::JOB_TYPES, true)) {
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
     * Change the remote-tools settings. Turning `filetransfer` on needs `accept_filetransfer_risk: true`, turning `logs` on needs `accept_logs_storage: true`: the
     * request is refused with the plain-language text when the acknowledgement is missing, so no client can switch them on by accident.
     *
     * @param array<string,mixed> $in features {remote_tools, filetransfer, logs}, transfer_allow, logs_collect, limits, accept_filetransfer_risk, accept_logs_storage
     */
    public function updateSettings(RmmPrincipal $who, array $in): ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::ADMIN, 0, false);
        if ($denied !== null) {
            $this->audit->record('Remote Settings Denied', "User {$who->userId} denied remote-tools settings change: $denied", 0, 0);

            return ActionResult::fail(403, 'forbidden', $denied);
        }
        $known = ['features', 'transfer_allow', 'logs_collect', 'limits', 'accept_filetransfer_risk', 'accept_logs_storage'];
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
        $errors = $this->remote->update(array_intersect_key($in, array_flip(['transfer_allow', 'logs_collect', 'limits'])), $who->userId);
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
        $this->audit->record('Remote Settings Changed', "{$who->userName}: " . ($what === [] ? 'no change' : implode(', ', $what)), 0, 0);
        $this->settings->refresh();
        $this->remote->refresh();

        return ActionResult::ok('Saved.', 200, 'saved', $this->settingsView());
    }
}
