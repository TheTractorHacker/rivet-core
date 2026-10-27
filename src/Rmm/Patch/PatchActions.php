<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Patch;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Technician\ActionResult;

/**
 * What a technician or administrator does with patching, shared by the REST API ({@see \RivetCore\Rmm\Http\PatchApi}) and an edition's pages.
 *
 * ORDER OF DECISIONS (a denial reveals nothing): the role cannot view devices or patches -> 403; the device is missing OR outside the caller's clients -> the same 404;
 * the caller lacks the ability for that client -> 403 with the edition's generic text, audited; the `patching` sub-switch is off -> 409 `feature_disabled` (reads still
 * answer, with `enabled` false); then strict validation. Fleet-wide objects (rings, patch policies, rules without a client) are changed only by a caller whose scope is
 * not restricted to some clients; a restricted caller may manage rules of their own clients.
 *
 * ABILITIES: `rmm.patch.view` reads, `rmm.patch.manage` scans, approves, declines, dry-runs and manages rings, rules and policies, `rmm.patch.install` runs a REAL installation
 * (an install that asks to restart the device afterwards also needs `rmm.job.reboot`).
 *
 * @api
 */
final class PatchActions
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly RmmAuthorizer $authz,
        private readonly RmmSettings $settings,
        private readonly PatchScanService $scans,
        private readonly PatchInstallService $installs,
        private readonly PatchApprovalService $approvals,
        private readonly PatchPolicyStore $policies,
        private readonly PatchReader $reader,
        private readonly RmmAuditInterface $audit,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    // ------------------------------------------------------------------ plumbing

    /** @return array{0:?array<string,mixed>,1:?ActionResult} */
    private function deviceFor(RmmPrincipal $who, int $deviceId, string $ability): array
    {
        $role = $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, 0);
        if ($role !== null) {
            return [null, ActionResult::fail(403, 'forbidden', $role)];
        }
        $role = $this->authz->check($who->userId, RmmAbility::PATCH_VIEW, 0);
        if ($role !== null) {
            return [null, ActionResult::fail(403, 'forbidden', $role)];
        }
        $dev = $this->devices->find($deviceId);
        $client = $dev === null ? 0 : (int) $dev['client_id'];
        if ($dev === null || !$this->authz->clientOk($who->userId, $client) || $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, $client) !== null
            || $this->authz->check($who->userId, RmmAbility::PATCH_VIEW, $client) !== null) {
            return [null, ActionResult::fail(404, 'not_found', 'Device not found.')];
        }
        if ($ability !== RmmAbility::PATCH_VIEW) {
            $denied = $this->authz->check($who->userId, $ability, $client);
            if ($denied !== null) {
                $this->audit->record('Patch Denied', "User {$who->userId} denied $ability on device $deviceId: $denied", $client, (int) ($dev['asset_id'] ?? 0));

                return [null, ActionResult::fail(403, 'forbidden', $denied)];
            }
        }

        return [$dev, null];
    }

    private function featureOff(): ?ActionResult
    {
        return $this->settings->featureOn(PatchProtocol::FEATURE) ? null : ActionResult::fail(409, 'feature_disabled',
            'Patch management is switched off. An administrator can turn it on in the RMM settings: it asks every device for its list of missing updates about once a day and keeps that list.');
    }

    /**
     * The viewer's scope for a fleet read: [client ids or null for all, error].
     *
     * @return array{0:?list<int>,1:?ActionResult}
     */
    private function scope(RmmPrincipal $who, string $ability): array
    {
        foreach ([RmmAbility::DEVICE_VIEW, $ability] as $a) {
            $d = $this->authz->check($who->userId, $a, 0);
            if ($d !== null) {
                return [null, ActionResult::fail(403, 'forbidden', $d)];
            }
        }

        return [$this->authz->visibleClientIds($who->userId), null];
    }

    /** A fleet-wide object may only be changed by a caller who sees every client. */
    private function unrestricted(RmmPrincipal $who): ?ActionResult
    {
        return $this->authz->visibleClientIds($who->userId) === null ? null : ActionResult::fail(403, 'forbidden', 'Patch rings and policies apply to every client; ask an administrator whose access is not limited to some clients.');
    }

    // ------------------------------------------------------------------ one device

    public function summary(RmmPrincipal $who, int $deviceId): ActionResult
    {
        [$dev, $err] = $this->deviceFor($who, $deviceId, RmmAbility::PATCH_VIEW);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }

        return ActionResult::ok('ok', 200, 'ok', $this->reader->patchSummary($dev));
    }

    /**
     * @param array{status?:string,kind?:string,q?:string} $filters
     */
    public function updates(RmmPrincipal $who, int $deviceId, array $filters, int $limit, int $offset): ActionResult
    {
        [$dev, $err] = $this->deviceFor($who, $deviceId, RmmAbility::PATCH_VIEW);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }

        return ActionResult::ok('ok', 200, 'ok', $this->reader->deviceUpdates($deviceId, $filters, $limit, $offset) + ['summary' => $this->reader->patchSummary($dev)]);
    }

    public function history(RmmPrincipal $who, int $deviceId, int $limit, int $offset): ActionResult
    {
        [$dev, $err] = $this->deviceFor($who, $deviceId, RmmAbility::PATCH_VIEW);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }

        return ActionResult::ok('ok', 200, 'ok', $this->reader->deviceHistory($deviceId, $limit, $offset));
    }

    /** Ask the device for a fresh scan (read only on the device). */
    public function scan(RmmPrincipal $who, int $deviceId): ActionResult
    {
        [$dev, $err] = $this->deviceFor($who, $deviceId, RmmAbility::PATCH_MANAGE);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        if (($off = $this->featureOff()) !== null) {
            return $off;
        }
        if ($dev['link_state'] !== 'linked') {
            return ActionResult::fail(409, 'conflict', 'Only a device linked to an asset can be scanned. Approve it first.');
        }
        $r = $this->scans->request($dev, $who->userId);
        if (!$r['ok']) {
            $http = ($r['code'] ?? '') === 'unsupported' ? 409 : 422;

            return ActionResult::fail($http, $http === 409 ? 'unsupported' : 'invalid', (string) ($r['error'] ?? 'The scan could not be queued.'));
        }
        if (isset($r['skipped'])) {
            return ActionResult::ok('A scan is already waiting for this device.', 200, 'already_queued', ['job_id' => $r['job_id'] ?? null, 'skipped' => $r['skipped']]);
        }
        $this->audit->record('Patch Scan Queued', "{$who->userName} asked device $deviceId for a patch scan (job {$r['job_id']})", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

        return ActionResult::ok('Queued. The device picks it up at its next check-in.', 202, 'queued', ['job_id' => $r['job_id'] ?? null]);
    }

    /**
     * Install approved updates (or list what would be installed).
     *
     * @param array<string,mixed> $in {ids?: list<string>, mode?: dry_run|install (default dry_run), confirm?, reboot_policy?: never|if_required_defer|if_required_now, confirm_reboot?, max_minutes?}
     */
    public function install(RmmPrincipal $who, int $deviceId, array $in): ActionResult
    {
        $mode = $in['mode'] ?? 'dry_run';
        if (!is_string($mode) || !in_array($mode, PatchProtocol::MODES, true)) {
            [$d] = $this->deviceFor($who, $deviceId, RmmAbility::PATCH_VIEW);

            return $d === null ? ActionResult::fail(404, 'not_found', 'Device not found.') : ActionResult::fail(422, 'invalid', 'mode must be dry_run or install.');
        }
        [$dev, $err] = $this->deviceFor($who, $deviceId, $mode === 'install' ? RmmAbility::PATCH_INSTALL : RmmAbility::PATCH_MANAGE);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        if (($off = $this->featureOff()) !== null) {
            return $off;
        }
        if ($dev['link_state'] !== 'linked') {
            return ActionResult::fail(409, 'conflict', 'Only a device linked to an asset can receive jobs. Approve it first.');
        }
        $client = (int) $dev['client_id'];
        $ids = null;
        if (array_key_exists('ids', $in) && $in['ids'] !== null) {
            if (!is_array($in['ids']) || !array_is_list($in['ids']) || $in['ids'] === [] || count($in['ids']) > PatchProtocol::IDS_MAX) {
                return ActionResult::fail(422, 'invalid', 'ids must be a list of 1 to ' . PatchProtocol::IDS_MAX . ' update ids.');
            }
            foreach ($in['ids'] as $id) {
                if (!PatchProtocol::validId($id, (string) $dev['os'])) {
                    return ActionResult::fail(422, 'invalid', 'An update id is not valid for this device.');
                }
            }
            $ids = array_values(array_unique($in['ids']));
        }
        $reboot = $in['reboot_policy'] ?? 'never';
        if (!is_string($reboot) || !in_array($reboot, PatchProtocol::REBOOT_POLICIES, true)) {
            return ActionResult::fail(422, 'invalid', 'reboot_policy must be never, if_required_defer or if_required_now.');
        }
        $mins = $in['max_minutes'] ?? PatchProtocol::MAX_MINUTES_DEFAULT;
        if (!is_int($mins) || $mins < 1 || $mins > PatchProtocol::MAX_MINUTES_MAX) {
            return ActionResult::fail(422, 'invalid', 'max_minutes must be 1 to ' . PatchProtocol::MAX_MINUTES_MAX . '.');
        }
        if ($mode === 'install') {
            if (empty($in['confirm'])) {
                return ActionResult::fail(422, 'confirmation_required', 'This installs updates on the device. Confirm it explicitly.');
            }
            if ($reboot === 'if_required_now') {
                if (empty($in['confirm_reboot'])) {
                    return ActionResult::fail(422, 'confirmation_required', 'You asked to restart the device if the updates need it. Confirm the restart explicitly.');
                }
                $why = $this->authz->check($who->userId, RmmAbility::JOB_REBOOT, $client);
                if ($why !== null) {
                    $this->audit->record('Patch Denied', "User {$who->userId} denied restart after patching on device $deviceId: $why", $client, (int) ($dev['asset_id'] ?? 0));

                    return ActionResult::fail(403, 'forbidden', $why);
                }
            }
        } else {
            $reboot = 'never';
        }
        $r = $this->installs->queue($dev, $ids, $mode, ['source' => 'manual', 'user_id' => $who->userId, 'reboot_policy' => $reboot, 'max_minutes' => $mins]);
        if (!$r['ok']) {
            $code = (string) ($r['code'] ?? 'invalid');
            $http = ['unsupported' => 409, 'not_found' => 404][$code] ?? 422;

            return ActionResult::fail($http, $http === 404 ? 'not_found' : ($code === 'unsupported' ? 'unsupported' : 'invalid'), (string) ($r['error'] ?? 'The install could not be queued.'));
        }
        if (isset($r['skipped'])) {
            $msg = $r['skipped'] === 'already_queued' ? 'An install is already waiting or running on this device.' : 'There is nothing to install: no approved update on this device.';

            return ActionResult::ok($msg, 200, (string) $r['skipped'], ['skipped' => $r['skipped'], 'queued' => 0]);
        }
        $this->audit->record($mode === 'install' ? 'Patch Install Queued' : 'Patch Dry Run Queued', "{$who->userName} queued a patch " . ($mode === 'install' ? 'INSTALL' : 'dry run') . ' of ' . ($r['queued'] ?? 0)
            . " update(s) on device $deviceId (job {$r['job_id']})", $client, (int) ($dev['asset_id'] ?? 0));

        return ActionResult::ok('Queued. The device picks it up at its next check-in.', 202, 'queued', ['job_id' => $r['job_id'] ?? null, 'mode' => $mode, 'queued' => $r['queued'] ?? 0, 'remaining' => $r['remaining'] ?? 0, 'ids' => $r['ids'] ?? []]);
    }

    // ------------------------------------------------------------------ approvals

    /**
     * Approve updates. `update_ids` names them; `device_id` or `client_id` narrows to one device or client.
     *
     * @param array<string,mixed> $in
     */
    public function approve(RmmPrincipal $who, array $in): ActionResult
    {
        return $this->decide($who, $in, true);
    }

    /**
     * Decline updates (they are not approved by a rule or policy afterwards, and are not installed).
     *
     * @param array<string,mixed> $in
     */
    public function decline(RmmPrincipal $who, array $in): ActionResult
    {
        return $this->decide($who, $in, false);
    }

    /**
     * @param array<string,mixed> $in
     */
    private function decide(RmmPrincipal $who, array $in, bool $approve): ActionResult
    {
        [$visible, $err] = $this->scope($who, RmmAbility::PATCH_VIEW);
        if ($err !== null) {
            return $err;
        }
        $f = [];
        $ids = $in['update_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > 200) {
            return ActionResult::fail(422, 'invalid', 'update_ids must be a list of 1 to 200 update ids.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || (preg_match(PatchProtocol::WINDOWS_ID_RE, $id) !== 1 && preg_match(PatchProtocol::PACKAGE_ID_RE, $id) !== 1)) {
                return ActionResult::fail(422, 'invalid', 'An update id is not valid.');
            }
        }
        $f['update_ids'] = array_values(array_unique($ids));
        foreach (['device_id', 'client_id'] as $k) {
            if (array_key_exists($k, $in) && $in[$k] !== null) {
                if (!is_int($in[$k]) || $in[$k] <= 0) {
                    return ActionResult::fail(422, 'invalid', "$k must be a whole number.");
                }
                $f[$k] = $in[$k];
            }
        }
        $reason = $in['reason'] ?? '';
        if (!is_string($reason) || mb_strlen($reason) > 200) {
            return ActionResult::fail(422, 'invalid', 'reason is at most 200 characters.');
        }
        if (($f['device_id'] ?? 0) > 0) {
            // A named device: out of scope is the same 404 as a missing one.
            [$dev, $e] = $this->deviceFor($who, $f['device_id'], RmmAbility::PATCH_MANAGE);
            if ($e !== null || $dev === null) {
                return $e ?? ActionResult::fail(404, 'not_found', 'Device not found.');
            }
        } elseif (($f['client_id'] ?? 0) > 0 && $visible !== null && !in_array($f['client_id'], $visible, true)) {
            return ActionResult::fail(404, 'not_found', 'No such update.');
        }
        if ($approve === false) {
            $f['status'] = ['available', 'approved', 'failed'];
        }
        $rows = $this->approvals->select($f, $visible);
        // Only clients where the caller may manage patches; the rest does not exist for them.
        $allowedClient = [];
        $rows = array_values(array_filter($rows, function (array $r) use ($who, &$allowedClient): bool {
            return $allowedClient[$r['client_id']] ??= $this->authz->check($who->userId, RmmAbility::PATCH_MANAGE, $r['client_id']) === null;
        }));
        if ($rows === []) {
            $denied = $this->authz->check($who->userId, RmmAbility::PATCH_MANAGE, 0);
            if ($denied !== null) {
                $this->audit->record('Patch Denied', "User {$who->userId} denied " . ($approve ? 'approving' : 'declining') . " updates: $denied", 0, 0);

                return ActionResult::fail(403, 'forbidden', $denied);
            }

            return ActionResult::fail(404, 'not_found', 'No such update.');
        }
        if (($off = $this->featureOff()) !== null) {
            return $off;
        }
        if (count($rows) > PatchApprovalService::MAX_PAIRS) {
            return ActionResult::fail(422, 'invalid', 'That reaches more than ' . PatchApprovalService::MAX_PAIRS . ' updates on devices. Narrow it to one client or device.');
        }
        $clientForAudit = (int) $rows[0]['client_id'];
        if ($approve && $this->approvals->needsSecondPerson($rows)) {
            $a = $this->approvals->requestSecond($rows, $who->userId);
            $this->audit->record('Approval Requested', "{$who->userName} requested approval #{$a['approval_id']}: {$a['summary']}", $clientForAudit, 0);
            $this->events?->emitScoped(RmmEvent::APPROVAL_REQUESTED, $clientForAudit, ['approval_id' => $a['approval_id'], 'kind' => PatchApprovalService::KIND, 'summary' => $a['summary'], 'device_count' => $a['device_count'], 'requested_by' => $who->userId]);

            return ActionResult::ok('A second person has to approve this.', 202, 'pending_approval', ['approval_id' => $a['approval_id'], 'state' => 'pending_approval', 'matched' => count($rows)]);
        }
        $n = $approve ? $this->approvals->approve($rows, 'manual', $who->userId) : $this->approvals->decline($rows, $who->userId, $reason);
        $this->audit->record($approve ? 'Patch Approved' : 'Patch Declined', "{$who->userName} " . ($approve ? 'approved' : 'declined') . " $n update row(s) of " . count($f['update_ids']) . ' update id(s)', $clientForAudit, 0);

        return ActionResult::ok($approve ? 'Approved.' : 'Declined.', 200, $approve ? 'approved' : 'declined', ['matched' => count($rows), 'changed' => $n]);
    }

    /**
     * The second person's decision on a `patch` approval (called by the ordinary approvals endpoint).
     *
     * @param array<string,mixed> $approval the approval row ({@see \RivetCore\Rmm\Approvals\ApprovalService::get()})
     * @param array<string,mixed> $request the frozen request
     */
    public function decideApproval(RmmPrincipal $who, array $approval, array $request, bool $approve, \Closure $decide): ActionResult
    {
        $approvalId = (int) $approval['approval_id'];
        $clients = $this->approvals->clientsOf($request);
        foreach ($clients ?: [0] as $c) {
            $denied = $this->authz->check($who->userId, RmmAbility::PATCH_MANAGE, $c);
            if ($denied !== null || !$this->authz->clientOk($who->userId, $c)) {
                return ActionResult::fail($denied !== null ? 403 : 404, $denied !== null ? 'forbidden' : 'not_found', $denied ?? 'Approval not found.');
            }
        }
        /** @var array{ok:bool,code?:string,error?:string} $d */
        $d = $decide();
        if (!$d['ok']) {
            $http = ['not_found' => 404, 'conflict' => 409, 'expired' => 409, 'own_request' => 403][$d['code'] ?? ''] ?? 409;

            return ActionResult::fail($http, (string) ($d['code'] ?? 'conflict'), (string) ($d['error'] ?? 'Could not decide.'));
        }
        $data = ['approval_id' => $approvalId, 'state' => $approve ? 'approved' : 'rejected'];
        if ($approve) {
            $data['result'] = $this->approvals->applyApproved($request, $approvalId, $who->userId);
        }
        $this->audit->record($approve ? 'Approval Granted' : 'Approval Rejected', "{$who->userName} " . ($approve ? 'approved' : 'rejected') . " patch request #$approvalId ({$approval['summary']}) made by user {$approval['requested_by']}", 0, 0);

        return ActionResult::ok($approve ? 'Approved.' : 'Rejected.', 200, $data['state'], $data);
    }

    // ------------------------------------------------------------------ fleet reads

    /**
     * @param array<string,mixed> $filters {client_id?, site_id?, group_id?, group_by?, overdue_days?, limit?, offset?, os?}
     */
    public function compliance(RmmPrincipal $who, array $filters): ActionResult
    {
        [$visible, $err] = $this->scope($who, RmmAbility::PATCH_VIEW);
        if ($err !== null) {
            return $err;
        }
        $client = (int) ($filters['client_id'] ?? 0);
        if ($client > 0 && $visible !== null && !in_array($client, $visible, true)) {
            return ActionResult::fail(404, 'not_found', 'No such client.');
        }

        return ActionResult::ok('ok', 200, 'ok', ['enabled' => $this->settings->featureOn(PatchProtocol::FEATURE)] + $this->reader->patchCompliance($visible, $filters));
    }

    /** Missing updates across the caller's devices, one row per update id (the approvals page). */
    public function fleetUpdates(RmmPrincipal $who, string $status, ?int $clientId, int $limit, int $offset): ActionResult
    {
        [$visible, $err] = $this->scope($who, RmmAbility::PATCH_VIEW);
        if ($err !== null) {
            return $err;
        }
        if ($clientId !== null && $clientId > 0 && $visible !== null && !in_array($clientId, $visible, true)) {
            return ActionResult::fail(404, 'not_found', 'No such client.');
        }

        return ActionResult::ok('ok', 200, 'ok', ['enabled' => $this->settings->featureOn(PatchProtocol::FEATURE)] + $this->reader->fleetUpdates($visible, $clientId, $status, $limit, $offset));
    }

    // ------------------------------------------------------------------ rings, rules, policies

    public function listPolicies(RmmPrincipal $who): ActionResult
    {
        [$visible, $err] = $this->scope($who, RmmAbility::PATCH_VIEW);
        if ($err !== null) {
            return $err;
        }
        $rules = array_values(array_filter($this->approvals->rules(), static fn (array $r): bool => $visible === null || in_array($r['client_id'], $visible, true)));
        $policies = $this->policies->all();
        $assign = $this->policies->assignments();
        foreach ($policies as &$p) {
            $p['assignments'] = array_values(array_filter($assign, static fn (array $a): bool => $a['policy_id'] === $p['policy_id']));
        }
        unset($p);

        return ActionResult::ok('ok', 200, 'ok', ['policies' => $policies, 'rings' => $this->policies->rings(), 'rules' => $rules, 'settings' => PatchPolicySettings::keys()]);
    }

    public function getPolicy(RmmPrincipal $who, int $policyId): ActionResult
    {
        [, $err] = $this->scope($who, RmmAbility::PATCH_VIEW);
        if ($err !== null) {
            return $err;
        }
        $p = $this->policies->find($policyId);
        if ($p === null) {
            return ActionResult::fail(404, 'not_found', 'Policy not found.');
        }
        $p['assignments'] = $this->policies->assignments($policyId);

        return ActionResult::ok('ok', 200, 'ok', ['policy' => $p]);
    }

    /**
     * Create (policyId null) or change a patch policy.
     *
     * @param array<string,mixed> $in {name, description?, enabled?, settings?}
     */
    public function savePolicy(RmmPrincipal $who, ?int $policyId, array $in): ActionResult
    {
        if (($e = $this->writeGate($who)) !== null) {
            return $e;
        }
        $confirmedBy = null;
        $wantsConfirm = is_array($in['settings'] ?? null) && !empty($in['settings']['reboot_confirm']);
        if ($wantsConfirm) {
            $already = $policyId !== null && ($this->policies->values($policyId)['reboot_confirm'] ?? false) === true;
            if (!$already) {
                $why = $this->authz->check($who->userId, RmmAbility::JOB_REBOOT, 0);
                if ($why !== null) {
                    return ActionResult::fail(403, 'forbidden', 'Letting a policy restart devices by itself needs the grant to restart devices. ' . $why);
                }
                $confirmedBy = $who->userId;
            }
        }
        try {
            $p = $this->policies->save($policyId, $in, $who->userId, $confirmedBy);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail($ex->getMessage() === 'Policy not found.' ? 404 : 422, $ex->getMessage() === 'Policy not found.' ? 'not_found' : 'invalid', $ex->getMessage());
        }
        $this->audit->record($policyId === null ? 'Patch Policy Created' : 'Patch Policy Changed', "{$who->userName} " . ($policyId === null ? 'created' : 'changed') . " patch policy #{$p['policy_id']} ({$p['name']})", 0, 0);

        return ActionResult::ok('Saved.', $policyId === null ? 201 : 200, 'saved', ['policy' => $p]);
    }

    public function deletePolicy(RmmPrincipal $who, int $policyId): ActionResult
    {
        if (($e = $this->writeGate($who)) !== null) {
            return $e;
        }
        $p = $this->policies->find($policyId);
        if ($p === null || !$this->policies->delete($policyId)) {
            return ActionResult::fail(404, 'not_found', 'Policy not found.');
        }
        $this->audit->record('Patch Policy Deleted', "{$who->userName} deleted patch policy #$policyId ({$p['name']})", 0, 0);

        return ActionResult::ok('Deleted.');
    }

    /**
     * @param array<string,mixed> $in {scope_type, scope_id?, priority?, enforce?}
     */
    public function assignPolicy(RmmPrincipal $who, int $policyId, array $in): ActionResult
    {
        if (($e = $this->writeGate($who)) !== null) {
            return $e;
        }
        try {
            $a = $this->policies->assign($policyId, $in, $who->userId);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail($ex->getMessage() === 'Policy not found.' ? 404 : 422, $ex->getMessage() === 'Policy not found.' ? 'not_found' : 'invalid', $ex->getMessage());
        }
        $this->audit->record('Patch Policy Assigned', "{$who->userName} assigned patch policy #$policyId to {$a['scope_type']} {$a['scope_id']}", 0, 0);

        return ActionResult::ok('Assigned.', 200, 'assigned', ['assignment' => $a]);
    }

    public function unassignPolicy(RmmPrincipal $who, int $policyId, int $assignmentId): ActionResult
    {
        if (($e = $this->writeGate($who)) !== null) {
            return $e;
        }
        $a = $this->policies->unassign($policyId, $assignmentId);
        if ($a === null) {
            return ActionResult::fail(404, 'not_found', 'Assignment not found.');
        }
        $this->audit->record('Patch Policy Unassigned', "{$who->userName} removed the assignment of patch policy #$policyId from {$a['scope_type']} {$a['scope_id']}", 0, 0);

        return ActionResult::ok('Removed.');
    }

    /**
     * @param array<string,mixed> $in {name, delay_days, sort_order?, description?}
     */
    public function saveRing(RmmPrincipal $who, ?int $ringId, array $in): ActionResult
    {
        if (($e = $this->writeGate($who)) !== null) {
            return $e;
        }
        try {
            $r = $this->policies->saveRing($ringId, $in);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail($ex->getMessage() === 'Ring not found.' ? 404 : 422, $ex->getMessage() === 'Ring not found.' ? 'not_found' : 'invalid', $ex->getMessage());
        }
        $this->audit->record('Patch Ring Saved', "{$who->userName} saved patch ring {$r['name']} ({$r['delay_days']} days)", 0, 0);

        return ActionResult::ok('Saved.', $ringId === null ? 201 : 200, 'saved', ['ring' => $r]);
    }

    public function deleteRing(RmmPrincipal $who, int $ringId): ActionResult
    {
        if (($e = $this->writeGate($who)) !== null) {
            return $e;
        }
        if (!$this->policies->deleteRing($ringId)) {
            return ActionResult::fail(409, 'conflict', 'The ring does not exist or a policy or rule still uses it.');
        }
        $this->audit->record('Patch Ring Deleted', "{$who->userName} deleted patch ring #$ringId", 0, 0);

        return ActionResult::ok('Deleted.');
    }

    /**
     * @param array<string,mixed> $in see {@see PatchApprovalService::saveRule()}
     */
    public function saveRule(RmmPrincipal $who, ?int $ruleId, array $in): ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::PATCH_MANAGE, 0);
        if ($denied !== null) {
            return ActionResult::fail(403, 'forbidden', $denied);
        }
        $visible = $this->authz->visibleClientIds($who->userId);
        if ($visible !== null) {
            $client = $in['client_id'] ?? 0;
            if (!is_int($client) || !in_array($client, $visible, true)) {
                return ActionResult::fail(403, 'forbidden', 'A rule for every client needs access to every client. Set client_id to one of yours.');
            }
            if ($ruleId !== null && !in_array((int) ($this->approvals->rule($ruleId)['client_id'] ?? 0), $visible, true)) {
                return ActionResult::fail(404, 'not_found', 'Rule not found.');
            }
        }
        try {
            $r = $this->approvals->saveRule($ruleId, $in, $who->userId);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail($ex->getMessage() === 'Rule not found.' ? 404 : 422, $ex->getMessage() === 'Rule not found.' ? 'not_found' : 'invalid', $ex->getMessage());
        }
        $this->audit->record('Patch Rule Saved', "{$who->userName} saved patch approval rule #{$r['rule_id']} ({$r['name']})", (int) $r['client_id'], 0);

        return ActionResult::ok('Saved.', $ruleId === null ? 201 : 200, 'saved', ['rule' => $r]);
    }

    public function deleteRule(RmmPrincipal $who, int $ruleId): ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::PATCH_MANAGE, 0);
        if ($denied !== null) {
            return ActionResult::fail(403, 'forbidden', $denied);
        }
        $rule = $this->approvals->rule($ruleId);
        $visible = $this->authz->visibleClientIds($who->userId);
        if ($rule === null || ($visible !== null && !in_array($rule['client_id'], $visible, true)) || !$this->approvals->deleteRule($ruleId)) {
            return ActionResult::fail(404, 'not_found', 'Rule not found.');
        }
        $this->audit->record('Patch Rule Deleted', "{$who->userName} deleted patch approval rule #$ruleId", (int) $rule['client_id'], 0);

        return ActionResult::ok('Deleted.');
    }

    private function writeGate(RmmPrincipal $who): ?ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::PATCH_MANAGE, 0);
        if ($denied !== null) {
            return ActionResult::fail(403, 'forbidden', $denied);
        }

        return $this->unrestricted($who);
    }
}
