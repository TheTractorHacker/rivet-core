<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Technician;

use RivetCore\Rmm\Approvals\ApprovalService;
use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Scripts\ApprovedRunExecutor;
use RivetCore\Rmm\Scripts\BulkRunner;
use RivetCore\Rmm\Scripts\ScheduleService;
use RivetCore\Rmm\Scripts\ScriptRunner;
use RivetCore\Rmm\Scripts\ScriptService;
use RivetCore\Rmm\Scripts\TargetResolver;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\RmmEventPublisher;

/**
 * Technician and administrator actions of the Phase 2 automation area: the script library, running a library script on a device or on a target,
 * approvals, and scheduled scripts. Same decision order as {@see TechnicianActions}: no view access at all -> 403; a device that is missing or outside
 * the caller's clients -> the same 404; a missing grant -> 403.
 *
 *  - read the library (without text): `rmm.device.view`; read a script's text: `rmm.job.run_saved`; write the library: `rmm.job.run_script`
 *    (whoever can run free-form text may publish it); re-sign after a key rotation, manage schedules: `rmm.admin`.
 *  - run a library script on a device or a target: `rmm.job.run_saved` for each client reached. A script flagged `requires_approval`, or a run on more
 *    devices than the `approval_bulk_threshold` limit, waits for someone else with `rmm.job.approve` for every client reached.
 *  - A destructive script still needs `confirm`.
 *
 * Script text never reaches the audit log: a line carries the script's name, version and the first 16 characters of the SHA-256 of its text.
 *
 * @api
 */
final class ScriptActions
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly RmmAuthorizer $authz,
        private readonly RmmSettings $settings,
        private readonly ScriptService $scripts,
        private readonly ScriptRunner $runner,
        private readonly BulkRunner $bulk,
        private readonly TargetResolver $targets,
        private readonly ApprovalService $approvals,
        private readonly ScheduleService $schedules,
        private readonly ApprovedRunExecutor $executor,
        private readonly RmmAuditInterface $audit,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    private function role(int $userId, string $ability): ?ActionResult
    {
        $why = $this->authz->check($userId, $ability, 0);

        return $why === null ? null : ActionResult::fail(403, 'forbidden', $why);
    }

    // ------------------------------------------------------------------ the library

    /**
     * @param array<string,mixed> $in see {@see ScriptService::create()}
     */
    public function createScript(RmmPrincipal $who, array $in): ActionResult
    {
        if (($e = $this->role($who->userId, RmmAbility::JOB_RUN_SCRIPT)) !== null) {
            return $e;
        }
        try {
            $s = $this->scripts->create($in, $who->userId);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        $this->audit->record('Script Created', "{$who->userName} created library script \"{$s['name']}\" (#{$s['script_id']}) version {$s['current_version']}, sha256 " . substr((string) ($s['current']['body_sha256'] ?? ''), 0, 16), 0, (int) $s['script_id']);

        return ActionResult::ok('Script saved.', 201, 'ok', ['script' => $s]);
    }

    /**
     * @param array<string,mixed> $in
     */
    public function updateScript(RmmPrincipal $who, int $scriptId, array $in): ActionResult
    {
        if (($e = $this->role($who->userId, RmmAbility::JOB_RUN_SCRIPT)) !== null) {
            return $e;
        }
        $before = $this->scripts->get($scriptId);
        if ($before === null) {
            return ActionResult::fail(404, 'not_found', 'Script not found.');
        }
        try {
            $s = $this->scripts->update($scriptId, $in, $who->userId);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        $this->audit->record('Script Updated', "{$who->userName} changed library script \"{$s['name']}\" (#$scriptId)" . ($s['current_version'] !== $before['current_version'] ? " to version {$s['current_version']}, sha256 " . substr((string) ($s['current']['body_sha256'] ?? ''), 0, 16) : ' (settings only)'), 0, $scriptId);

        return ActionResult::ok('Script saved.', 200, 'ok', ['script' => $s]);
    }

    public function retireScript(RmmPrincipal $who, int $scriptId, bool $retire = true): ActionResult
    {
        if (($e = $this->role($who->userId, RmmAbility::JOB_RUN_SCRIPT)) !== null) {
            return $e;
        }
        if (!$this->scripts->setRetired($scriptId, $retire)) {
            return $this->scripts->get($scriptId) === null ? ActionResult::fail(404, 'not_found', 'Script not found.') : ActionResult::fail(409, 'conflict', 'Nothing changed.');
        }
        $this->audit->record($retire ? 'Script Retired' : 'Script Restored', "{$who->userName} " . ($retire ? 'retired' : 'restored') . " library script #$scriptId", 0, $scriptId);

        return ActionResult::ok($retire ? 'Script retired.' : 'Script restored.');
    }

    /** After the signing key was rotated: sign the stored text again with the current key. */
    public function resignScript(RmmPrincipal $who, int $scriptId): ActionResult
    {
        if (($e = $this->role($who->userId, RmmAbility::ADMIN)) !== null) {
            return $e;
        }
        try {
            $n = $this->scripts->resign($scriptId);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(404, 'not_found', $ex->getMessage());
        }
        $this->audit->record('Script Resigned', "{$who->userName} re-signed $n version(s) of library script #$scriptId", 0, $scriptId);

        return ActionResult::ok("Re-signed $n version(s).", 200, 'ok', ['versions' => $n]);
    }

    // ------------------------------------------------------------------ running

    /**
     * Run a library script on one device.
     *
     * @param array<string,mixed> $in {library_script_id, script_version?, params?, timeout_s?, confirm?}
     */
    public function runOnDevice(RmmPrincipal $who, int $deviceId, array $in): ActionResult
    {
        $view = $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, 0);
        if ($view !== null) {
            return ActionResult::fail(403, 'forbidden', $view);
        }
        $dev = $this->devices->find($deviceId);
        if ($dev === null || $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, (int) $dev['client_id']) !== null) {
            return ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $client = (int) $dev['client_id'];
        $asset = (int) ($dev['asset_id'] ?? 0);
        $denied = $this->authz->check($who->userId, RmmAbility::JOB_RUN_SAVED, $client);
        if ($denied !== null) {
            $this->audit->record('Job Denied', "User {$who->userId} denied library script job on device $deviceId: $denied", $client, $asset);

            return ActionResult::fail(403, 'forbidden', $denied);
        }
        if ($dev['link_state'] !== 'linked') {
            return ActionResult::fail(409, 'conflict', 'Only a device linked to an asset can receive jobs. Approve it first.');
        }
        [$loaded, $res] = $this->loadFor($in);
        if ($loaded === null) {
            return $res ?? ActionResult::fail(422, 'invalid', 'Script not found.');
        }
        $script = $loaded['script'];
        if ((int) $script['destructive'] === 1 && empty($in['confirm'])) {
            return ActionResult::fail(422, 'confirmation_required', 'This script is destructive. Confirm it explicitly.');
        }
        $params = $this->paramsOf($in);
        if (is_string($params)) {
            return ActionResult::fail(422, 'invalid', $params);
        }
        $timeout = isset($in['timeout_s']) && is_numeric($in['timeout_s']) ? (int) $in['timeout_s'] : null;
        if ((int) $script['requires_approval'] === 1) {
            return $this->requestRun($who, $loaded, ['type' => 'device', 'id' => $deviceId], $params, $timeout, 1, $client, $asset);
        }
        $prep = $this->runner->prepare($loaded, $dev, $params, $timeout);
        if (!$prep['ok']) {
            return ActionResult::fail(422, 'invalid', $prep['error']);
        }
        $r = $this->runner->dispatch($loaded, $dev, $prep, $who->userId, 'manual');
        if (!$r['ok']) {
            return ActionResult::fail(422, 'invalid', (string) ($r['error'] ?? 'The job could not be created.'));
        }
        $this->audit->record('Script Run', "{$who->userName} ran library script \"{$script['name']}\" version {$loaded['version']['version']} (sha256 " . substr((string) $loaded['version']['body_sha256'], 0, 16) . ") on device $deviceId, job " . ($r['job_id'] ?? ''), $client, $asset);

        return ActionResult::ok('Job queued.', 201, 'queued', ['job_id' => $r['job_id'] ?? '']);
    }

    /**
     * Run a library script on a target (device, tag, group, client, site, policy or all). The devices reached are the live linked ones inside the
     * caller's clients.
     *
     * @param array<string,mixed> $in {library_script_id, script_version?, target:{type,id}, params?, timeout_s?, confirm?}
     */
    public function runOnTarget(RmmPrincipal $who, array $in): ActionResult
    {
        if (($e = $this->role($who->userId, RmmAbility::JOB_RUN_SAVED)) !== null) {
            return $e;
        }
        [$loaded, $res] = $this->loadFor($in);
        if ($loaded === null) {
            return $res ?? ActionResult::fail(422, 'invalid', 'Script not found.');
        }
        $script = $loaded['script'];
        [$target, $terr] = $this->targets->validate($in['target'] ?? null);
        if ($target === null) {
            return ActionResult::fail(422, 'invalid', $terr ?? 'Invalid target.');
        }
        if ((int) $script['destructive'] === 1 && empty($in['confirm'])) {
            return ActionResult::fail(422, 'confirmation_required', 'This script is destructive. Confirm it explicitly.');
        }
        $params = $this->paramsOf($in);
        if (is_string($params)) {
            return ActionResult::fail(422, 'invalid', $params);
        }
        $timeout = isset($in['timeout_s']) && is_numeric($in['timeout_s']) ? (int) $in['timeout_s'] : null;
        $visible = $this->authz->visibleClientIds($who->userId);
        $platforms = \RivetCore\Rmm\Scripts\ScriptLanguage::platforms((string) $script['language']);
        $count = $this->targets->count($target['type'], $target['id'], $visible, $platforms);
        $limits = $this->settings->limits();
        if ($count === 0) {
            return ActionResult::fail(422, 'invalid', 'No device of this target can run that script.');
        }
        if ($count > $limits['bulk_run_max']) {
            return ActionResult::fail(422, 'too_many', "That reaches $count devices; one run is limited to {$limits['bulk_run_max']}.");
        }
        $threshold = $limits['approval_bulk_threshold'];
        if ((int) $script['requires_approval'] === 1 || ($threshold > 0 && $count > $threshold)) {
            return $this->requestRun($who, $loaded, $target, $params, $timeout, $count, 0, 0);
        }
        $res = $this->bulk->run($loaded, $target, $params, $timeout, $who->userId, $visible, fn (int $client): bool => $this->authz->allowed($who->userId, RmmAbility::JOB_RUN_SAVED, $client), 'manual', null, $limits['bulk_run_max']);
        $this->audit->record('Script Run', "{$who->userName} ran library script \"{$script['name']}\" version {$loaded['version']['version']} (sha256 " . substr((string) $loaded['version']['body_sha256'], 0, 16)
            . ") on target {$target['type']} {$target['id']}: {$res['created']} job(s) of {$res['device_count']} device(s)", 0, (int) $script['script_id']);

        return ActionResult::ok("{$res['created']} job(s) queued.", 201, 'queued', $res);
    }

    /**
     * @param array{script:array<string,mixed>,version:array<string,mixed>,schema:list<array<string,mixed>>} $loaded
     * @param array{type:string,id:int} $target
     * @param array<array-key,mixed> $params
     */
    private function requestRun(RmmPrincipal $who, array $loaded, array $target, array $params, ?int $timeout, int $count, int $client, int $asset): ActionResult
    {
        $err = $this->runner->checkStatic($loaded['schema'], $params, true);
        if ($err !== null) {
            return ActionResult::fail(422, 'invalid', $err);
        }
        $script = $loaded['script'];
        $req = ['type' => 'run', 'script_id' => (int) $script['script_id'], 'script_version' => (int) $loaded['version']['version'], 'body_sha256' => (string) $loaded['version']['body_sha256'],
            'params' => $params === [] ? new \stdClass() : $params, 'timeout_s' => $timeout, 'target' => $target, 'confirmed' => (int) $script['destructive'] === 1];
        $summary = "Run \"{$script['name']}\" v{$loaded['version']['version']} on " . ($target['type'] === 'all' ? 'all devices' : "{$target['type']} {$target['id']}") . " ($count device" . ($count === 1 ? '' : 's') . ')';
        $a = $this->approvals->request('run', $req, $summary, $count, (int) $script['script_id'], (int) $loaded['version']['version'], $who->userId);
        $this->audit->record('Approval Requested', "{$who->userName} requested approval #{$a['approval_id']}: $summary (script sha256 " . substr((string) $loaded['version']['body_sha256'], 0, 16) . ')', $client, $asset);
        $this->events?->emitScoped(RmmEvent::APPROVAL_REQUESTED, $client, ['approval_id' => $a['approval_id'], 'kind' => 'run', 'summary' => $summary, 'device_count' => $count, 'requested_by' => $who->userId]);

        return ActionResult::ok('This run needs a second person to approve it.', 202, 'pending_approval', ['approval_id' => $a['approval_id'], 'state' => 'pending_approval', 'device_count' => $count]);
    }

    // ------------------------------------------------------------------ approvals

    /**
     * Approve or reject a pending request. Approving a run queues its jobs at once; approving a schedule lets it start.
     */
    public function decide(RmmPrincipal $who, int $approvalId, bool $approve, string $note = ''): ActionResult
    {
        if (($e = $this->role($who->userId, RmmAbility::JOB_APPROVE)) !== null) {
            return $e;
        }
        $a = $this->approvals->get($approvalId);
        if ($a === null) {
            return ActionResult::fail(404, 'not_found', 'Approval not found.');
        }
        $req = $this->approvals->requestOf($approvalId);
        if ($req === null) {
            return ActionResult::fail(409, 'conflict', 'That request no longer matches its recorded hash and cannot be decided.');
        }
        if ((int) $a['requested_by'] === $who->userId) {
            return ActionResult::fail(403, 'own_request', 'A request must be approved by someone other than the person who made it.');
        }
        $target = is_array($req['target'] ?? null) ? $req['target'] : null;
        $loaded = null;
        if ($approve) {
            // The approver must be allowed to approve for every client the request reaches.
            if ($a['kind'] === 'run') {
                [$loaded, $res] = $this->pinned($req);
                if ($loaded === null) {
                    return $res ?? ActionResult::fail(409, 'conflict', 'The script changed since this was requested. Ask again.');
                }
                $denied = $this->approverScope($who->userId, $target, $loaded);
                if ($denied !== null) {
                    return $denied;
                }
            } else {
                $sched = $this->schedules->get((int) ($req['schedule_id'] ?? 0));
                if ($sched === null || (int) $sched['approval_id'] !== $approvalId) {
                    return ActionResult::fail(409, 'conflict', 'The schedule was changed or deleted since this was requested.');
                }
                [$loaded, $res] = $this->pinned($req);
                if ($loaded === null) {
                    return $res ?? ActionResult::fail(409, 'conflict', 'The script changed since this was requested. Ask again.');
                }
                $denied = $this->approverScope($who->userId, $target, $loaded);
                if ($denied !== null) {
                    return $denied;
                }
            }
        }
        $d = $this->approvals->decide($approvalId, $who->userId, $approve, $note);
        if (!$d['ok']) {
            $http = ['not_found' => 404, 'conflict' => 409, 'expired' => 409, 'own_request' => 403][$d['code'] ?? ''] ?? 409;

            return ActionResult::fail($http, (string) ($d['code'] ?? 'conflict'), (string) ($d['error'] ?? 'Could not decide.'));
        }
        $state = $approve ? ApprovalService::APPROVED : ApprovalService::REJECTED;
        $this->audit->record($approve ? 'Approval Granted' : 'Approval Rejected', "{$who->userName} " . ($approve ? 'approved' : 'rejected') . " request #$approvalId ({$a['summary']}) made by user {$a['requested_by']}", 0, 0);
        $this->events?->emitScoped(RmmEvent::APPROVAL_DECIDED, 0, ['approval_id' => $approvalId, 'kind' => $a['kind'], 'state' => $state, 'decided_by' => $who->userId]);
        $data = ['approval_id' => $approvalId, 'state' => $state];
        if ($approve) {
            if ($a['kind'] === 'run') {
                $data['result'] = $this->executor->execute($a, $req, $loaded, fn (int $client): bool => $this->authz->allowed((int) $a['requested_by'], RmmAbility::JOB_RUN_SAVED, $client));
            } else {
                $this->schedules->approve((int) $req['schedule_id'], $approvalId);
                $data['result'] = ['schedule_id' => (int) $req['schedule_id']];
                $this->approvals->recordResult($approvalId, $data['result']);
            }
        }

        return ActionResult::ok($approve ? 'Approved.' : 'Rejected.', 200, $state, $data);
    }

    /** The requester (or an administrator) withdraws a pending request. */
    public function cancel(RmmPrincipal $who, int $approvalId): ActionResult
    {
        $a = $this->approvals->get($approvalId);
        if ($a === null) {
            return ActionResult::fail(404, 'not_found', 'Approval not found.');
        }
        if ((int) $a['requested_by'] !== $who->userId && $this->authz->check($who->userId, RmmAbility::ADMIN, 0) !== null) {
            return ActionResult::fail(404, 'not_found', 'Approval not found.');
        }
        if (!$this->approvals->cancel($approvalId, $who->userId)) {
            return ActionResult::fail(409, 'conflict', 'Only a pending request can be cancelled.');
        }
        $this->audit->record('Approval Cancelled', "{$who->userName} cancelled request #$approvalId", 0, 0);
        $this->events?->emitScoped(RmmEvent::APPROVAL_DECIDED, 0, ['approval_id' => $approvalId, 'kind' => $a['kind'], 'state' => ApprovalService::CANCELLED, 'decided_by' => $who->userId]);

        return ActionResult::ok('Cancelled.', 200, ApprovalService::CANCELLED);
    }

    /**
     * The script version a request froze, as long as it is still intact and unchanged.
     *
     * @param array<string,mixed> $req
     * @return array{0:?array{script:array<string,mixed>,version:array<string,mixed>,schema:list<array<string,mixed>>},1:?ActionResult}
     */
    public function pinned(array $req): array
    {
        [$loaded, $err] = $this->scripts->load((int) ($req['script_id'] ?? 0), (int) ($req['script_version'] ?? 0));
        if ($loaded === null) {
            return [null, ActionResult::fail(409, 'conflict', $err ?? 'The script is no longer available.')];
        }
        if (!hash_equals((string) ($req['body_sha256'] ?? ''), (string) $loaded['version']['body_sha256'])) {
            return [null, ActionResult::fail(409, 'conflict', 'The script text no longer matches what was requested.')];
        }

        return [$loaded, null];
    }

    /**
     * The approver may only approve what they could see and are allowed to approve: every device of the request inside their clients, and the
     * approve grant for each of those clients.
     *
     * @param array<string,mixed>|null $target
     * @param array{script:array<string,mixed>,version:array<string,mixed>,schema:list<array<string,mixed>>} $loaded
     */
    private function approverScope(int $userId, ?array $target, array $loaded): ?ActionResult
    {
        if ($target === null) {
            return ActionResult::fail(409, 'conflict', 'That request has no target.');
        }
        $platforms = \RivetCore\Rmm\Scripts\ScriptLanguage::platforms((string) $loaded['script']['language']);
        $t = ['type' => (string) $target['type'], 'id' => (int) $target['id']];
        $visible = $this->authz->visibleClientIds($userId);
        $all = $this->targets->count($t['type'], $t['id'], null, $platforms);
        if ($visible !== null && $this->targets->count($t['type'], $t['id'], $visible, $platforms) !== $all) {
            return ActionResult::fail(403, 'forbidden', 'This request reaches devices outside the ' . 'clients you can access.');
        }
        $cursor = 0;
        $seen = [];
        while (true) {
            $page = $this->targets->devices($t['type'], $t['id'], $visible, $platforms, $cursor, 500);
            if ($page === []) {
                break;
            }
            foreach ($page as $d) {
                $cursor = (int) $d['device_id'];
                $c = (int) $d['client_id'];
                if (!isset($seen[$c])) {
                    $seen[$c] = true;
                    $why = $this->authz->check($userId, RmmAbility::JOB_APPROVE, $c);
                    if ($why !== null) {
                        return ActionResult::fail(403, 'forbidden', $why);
                    }
                }
            }
            if (count($page) < 500) {
                break;
            }
        }

        return null;
    }

    /**
     * May this user see the approval request? Its summary names a target and a device count, so a caller restricted to some clients sees only their own
     * requests and those whose target lies entirely inside their clients (the same answer, a 404, as for a missing one).
     *
     * @param array<string,mixed> $approval
     */
    public function approvalVisible(int $userId, array $approval): bool
    {
        $visible = $this->authz->visibleClientIds($userId);
        if ($visible === null || (int) $approval['requested_by'] === $userId) {
            return true;
        }
        $req = $this->approvals->requestOf((int) $approval['approval_id']);
        $t = is_array($req['target'] ?? null) ? $req['target'] : null;
        if ($t === null) {
            return false;
        }
        $platforms = [];
        if (isset($approval['script_id']) && ($s = $this->scripts->get((int) $approval['script_id'])) !== null) {
            $platforms = \RivetCore\Rmm\Scripts\ScriptLanguage::platforms((string) $s['language']);
        }
        $type = (string) ($t['type'] ?? '');
        $id = (int) ($t['id'] ?? 0);

        return $this->targets->count($type, $id, null, $platforms) === $this->targets->count($type, $id, $visible, $platforms);
    }

    // ------------------------------------------------------------------ schedules

    /**
     * Create (`$scheduleId` null) or change a schedule. Administrators only.
     *
     * @param array<string,mixed> $in see {@see ScheduleService::save()}
     */
    public function saveSchedule(RmmPrincipal $who, ?int $scheduleId, array $in): ActionResult
    {
        if (($e = $this->role($who->userId, RmmAbility::ADMIN)) !== null) {
            return $e;
        }
        try {
            $r = $this->schedules->save($scheduleId, $in, $who->userId);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail($ex->getMessage() === 'Schedule not found.' ? 404 : 422, $ex->getMessage() === 'Schedule not found.' ? 'not_found' : 'invalid', $ex->getMessage());
        }
        $s = $r['schedule'];
        $this->audit->record($scheduleId === null ? 'Schedule Created' : 'Schedule Updated', "{$who->userName} " . ($scheduleId === null ? 'created' : 'changed') . " schedule \"{$s['name']}\" (#{$s['schedule_id']}): script #{$s['script_id']} v{$s['script_version']} sha256 "
            . substr((string) $s['body_sha256'], 0, 16) . " on {$s['target']['type']} {$s['target']['id']} ({$r['device_count']} device(s))", 0, (int) $s['script_id']);
        $data = ['schedule' => $s, 'device_count' => $r['device_count']];
        if ($r['needs_approval']) {
            $req = ['type' => 'schedule', 'schedule_id' => (int) $s['schedule_id'], 'script_id' => (int) $s['script_id'], 'script_version' => (int) $s['script_version'], 'body_sha256' => (string) $s['body_sha256'],
                'params' => $s['params'], 'target' => $s['target'], 'kind' => $s['kind'], 'interval_s' => $s['interval_s'], 'cron' => $s['cron']];
            $summary = "Schedule \"{$s['name']}\" runs script #{$s['script_id']} v{$s['script_version']} on {$s['target']['type']} {$s['target']['id']} ({$r['device_count']} devices)";
            $a = $this->approvals->request('schedule', $req, $summary, $r['device_count'], (int) $s['script_id'], (int) $s['script_version'], $who->userId);
            $this->schedules->attachApproval((int) $s['schedule_id'], (int) $a['approval_id']);
            $this->audit->record('Approval Requested', "{$who->userName} requested approval #{$a['approval_id']}: $summary", 0, (int) $s['script_id']);
            $this->events?->emitScoped(RmmEvent::APPROVAL_REQUESTED, 0, ['approval_id' => $a['approval_id'], 'kind' => 'schedule', 'summary' => $summary, 'device_count' => $r['device_count'], 'requested_by' => $who->userId]);
            $data['approval_id'] = $a['approval_id'];
            $data['state'] = 'pending_approval';
            $data['schedule'] = $this->schedules->get((int) $s['schedule_id']);

            return ActionResult::ok('Saved. The schedule starts once a second person approves it.', $scheduleId === null ? 201 : 200, 'pending_approval', $data);
        }

        return ActionResult::ok('Saved.', $scheduleId === null ? 201 : 200, 'ok', $data);
    }

    public function deleteSchedule(RmmPrincipal $who, int $scheduleId): ActionResult
    {
        if (($e = $this->role($who->userId, RmmAbility::ADMIN)) !== null) {
            return $e;
        }
        $s = $this->schedules->get($scheduleId);
        if ($s === null || !$this->schedules->delete($scheduleId)) {
            return ActionResult::fail(404, 'not_found', 'Schedule not found.');
        }
        $this->audit->record('Schedule Deleted', "{$who->userName} deleted schedule \"{$s['name']}\" (#$scheduleId)", 0, (int) $s['script_id']);

        return ActionResult::ok('Deleted.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string,mixed> $in
     * @return array{0:?array{script:array<string,mixed>,version:array<string,mixed>,schema:list<array<string,mixed>>},1:?ActionResult}
     */
    private function loadFor(array $in): array
    {
        $id = isset($in['library_script_id']) && is_numeric($in['library_script_id']) ? (int) $in['library_script_id'] : 0;
        $ver = isset($in['script_version']) && is_numeric($in['script_version']) ? (int) $in['script_version'] : null;
        if ($id <= 0) {
            return [null, ActionResult::fail(422, 'invalid', 'library_script_id is required.')];
        }
        [$loaded, $err] = $this->scripts->load($id, $ver);
        if ($loaded === null) {
            return [null, ActionResult::fail($err === 'Script not found.' ? 404 : 422, $err === 'Script not found.' ? 'not_found' : 'invalid', $err ?? 'Script not found.')];
        }

        return [$loaded, null];
    }

    /**
     * @param array<string,mixed> $in
     * @return array<array-key,mixed>|string the params, or an error message
     */
    private function paramsOf(array $in): array|string
    {
        $p = $in['params'] ?? [];
        if (!is_array($p) || ($p !== [] && array_is_list($p))) {
            return 'params is an object of parameter name to value.';
        }
        foreach ($p as $v) {
            if (is_float($v) || is_array($v)) {
                return 'A parameter is text, a whole number or true/false.';
            }
        }

        return $p;
    }
}
