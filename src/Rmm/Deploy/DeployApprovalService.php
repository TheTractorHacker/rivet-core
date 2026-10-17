<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Deploy;

use RivetCore\Rmm\Approvals\ApprovalService;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Support\Sql;

/**
 * Two-person approval for software deployment, on the same approvals endpoint and table as scripts and patches (kind `software`). A package marked `two_person` needs a
 * second person for (a) every REAL run a person starts on a device and (b) every assignment (which is saved switched off and goes live when someone else approves it).
 * The request is frozen when it is made; what is applied is exactly what was frozen, checked again against the catalog at that moment.
 *
 * @api
 */
final class DeployApprovalService
{
    public const KIND = 'software';

    public function __construct(
        private readonly Sql $sql,
        private readonly ApprovalService $approvals,
        private readonly DeployService $service,
        private readonly DeployExpectations $expectations,
        private readonly DeviceRepository $devices,
        private readonly PackageCatalog $catalog,
    ) {
    }

    /**
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $v
     * @return array<string,mixed> the approval
     */
    public function requestRun(array $dev, array $v, string $action, int $userId, int $maxMinutes): array
    {
        $pkg = $this->catalog->package((int) $v['package_id']);
        $summary = ucfirst($action) . ' ' . mb_substr((string) ($pkg['name'] ?? '?'), 0, 80) . ' ' . $v['version'] . ' on ' . mb_substr((string) ($dev['hostname'] ?? 'a device'), 0, 80);

        return $this->approvals->request(self::KIND, ['type' => 'software', 'op' => 'run', 'device_id' => (int) $dev['device_id'], 'version_id' => (int) $v['version_id'], 'action' => $action, 'max_minutes' => $maxMinutes],
            $summary, 1, null, null, $userId);
    }

    /**
     * @param array<string,mixed> $assignment an assignment row, saved switched off
     * @return array<string,mixed> the approval
     */
    public function requestAssignment(array $assignment, string $packageName, int $userId): array
    {
        $summary = ucfirst((string) $assignment['action']) . ' ' . mb_substr($packageName, 0, 80) . ' on ' . $assignment['scope_type'] . ((int) $assignment['scope_id'] > 0 ? ' #' . (int) $assignment['scope_id'] : '');

        return $this->approvals->request(self::KIND, ['type' => 'software', 'op' => 'assign', 'assignment_id' => (int) $assignment['assignment_id']], $summary, 0, null, null, $userId);
    }

    /**
     * Apply an approved request: exactly what was frozen.
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $approval the approval row
     * @return array<string,mixed>
     */
    public function applyApproved(array $request, int $approvalId, int $approverId, array $approval): array
    {
        $out = ['applied' => false];
        if (($request['op'] ?? '') === 'run') {
            $dev = $this->devices->find((int) ($request['device_id'] ?? 0));
            $v = $this->catalog->version((int) ($request['version_id'] ?? 0));
            $action = $request['action'] ?? '';
            if ($dev === null || $v === null || !is_string($action) || !in_array($action, DeployProtocol::ACTIONS, true)) {
                $out['reason'] = 'gone';
            } else {
                $mins = is_int($request['max_minutes'] ?? null) ? $request['max_minutes'] : DeployProtocol::MAX_MINUTES_DEFAULT;
                $r = $this->service->queueInstall($dev, $v, $action, 'run', ['source' => 'manual', 'user_id' => (int) $approval['requested_by'], 'max_minutes' => $mins]);
                $out = ['applied' => $r['ok'] && isset($r['job_id']), 'job_id' => $r['job_id'] ?? null, 'reason' => $r['skipped'] ?? ($r['error'] ?? null)];
            }
        } elseif (($request['op'] ?? '') === 'assign') {
            $id = (int) ($request['assignment_id'] ?? 0);
            $n = $this->sql->run('UPDATE rmm_package_assignments SET enabled = 1, activated_at = ?, approval_id = ? WHERE assignment_id = ? AND enabled = 0 AND activated_at IS NULL', [$this->sql->utcNow(), $approvalId, $id]);
            $this->expectations->invalidate();
            $out = ['applied' => $n === 1, 'assignment_id' => $id];
        }
        $this->approvals->recordResult($approvalId, $out);

        return $out;
    }

    /**
     * A request was rejected: a pending assignment that waited for it is dropped.
     *
     * @param array<string,mixed> $request
     */
    public function rejected(array $request): void
    {
        if (($request['op'] ?? '') === 'assign') {
            $this->sql->run('DELETE FROM rmm_package_assignments WHERE assignment_id = ? AND enabled = 0 AND activated_at IS NULL', [(int) ($request['assignment_id'] ?? 0)]);
        }
    }

    /**
     * The client ids a frozen request reaches; [0] when it reaches the fleet, the package of a global, group or tag assignment.
     *
     * @param array<string,mixed> $request
     * @return list<int>
     */
    public function clientsOf(array $request): array
    {
        if (($request['op'] ?? '') === 'run') {
            $d = $this->devices->find((int) ($request['device_id'] ?? 0));

            return $d === null ? [] : [(int) $d['client_id']];
        }
        $a = $this->sql->one('SELECT scope_type, scope_id FROM rmm_package_assignments WHERE assignment_id = ?', [(int) ($request['assignment_id'] ?? 0)]);
        if ($a === null) {
            return [];
        }
        if ($a['scope_type'] === 'client') {
            return [(int) $a['scope_id']];
        }
        if ($a['scope_type'] === 'device') {
            $d = $this->devices->find((int) $a['scope_id']);

            return $d === null ? [] : [(int) $d['client_id']];
        }

        return [0];
    }
}
