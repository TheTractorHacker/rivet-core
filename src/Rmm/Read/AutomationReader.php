<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Read;

use RivetCore\Rmm\Approvals\ApprovalService;
use RivetCore\Rmm\Device\DeviceState;
use RivetCore\Rmm\Fields\CustomFieldService;
use RivetCore\Rmm\Policy\EffectivePolicy;
use RivetCore\Rmm\Policy\PolicyStore;
use RivetCore\Rmm\Scripts\ScheduleService;
use RivetCore\Rmm\Scripts\ScriptService;
use RivetCore\Rmm\Support\Sql;

/**
 * The Phase 2 reads: policies, a device's effective policy, the script library, approvals, schedules and custom fields. Reached through
 * {@see RmmReadModel}; no read here ever returns a secret value, and script text only when asked for.
 *
 * @api
 */
final class AutomationReader
{
    public function __construct(
        private readonly Sql $sql,
        private readonly PolicyStore $policies,
        private readonly EffectivePolicy $effective,
        private readonly ScriptService $scripts,
        private readonly ApprovalService $approvals,
        private readonly ScheduleService $schedules,
        private readonly CustomFieldService $fields,
        private readonly DeviceState $state,
    ) {
    }

    public function policyStore(): PolicyStore
    {
        return $this->policies;
    }

    public function scriptService(): ScriptService
    {
        return $this->scripts;
    }

    public function approvalService(): ApprovalService
    {
        return $this->approvals;
    }

    public function scheduleService(): ScheduleService
    {
        return $this->schedules;
    }

    public function fieldService(): CustomFieldService
    {
        return $this->fields;
    }

    /**
     * Why a device behaves as it does: the policies that reach it, what each setting resolved to and where it came from.
     *
     * @param array<string,mixed> $dev the device row
     * @return array<string,mixed>
     */
    public function effectivePolicy(array $dev): array
    {
        return $this->effective->explain($dev, DeviceState::capabilitiesOf($this->state->get((int) $dev['device_id'])));
    }

    /**
     * The custom fields that apply to a device with their current values (secrets masked): its own, its location's and its client's.
     *
     * @param array<string,mixed> $dev
     * @return list<array<string,mixed>>
     */
    public function deviceFields(array $dev): array
    {
        $out = [];
        foreach (['client' => (int) $dev['client_id'], 'site' => (int) $dev['location_id'], 'device' => (int) $dev['device_id']] as $scope => $id) {
            if ($id <= 0 && $scope !== 'client') {
                continue;
            }
            foreach ($this->fields->valuesFor($scope, $id) as $v) {
                $out[] = ['scope' => $scope, 'scope_id' => $id] + $v;
            }
        }

        return $out;
    }

    /**
     * Devices a policy reaches, with the resolved check count, for the policy page.
     *
     * @return array{policy:array<string,mixed>|null,assignments:list<array<string,mixed>>,versions:list<array<string,mixed>>}
     */
    public function policyDetail(int $policyId): array
    {
        return ['policy' => $this->policies->find($policyId), 'assignments' => $this->policies->assignments($policyId), 'versions' => $this->policies->versions($policyId)];
    }

    /**
     * Recent library jobs of a script (any device), for the script page.
     *
     * @param list<int>|null $visibleClientIds only devices of these clients (null = all); applied in SQL so the limit counts what the caller may see
     * @return list<array<string,mixed>>
     */
    public function scriptRuns(int $scriptId, int $limit = 50, ?array $visibleClientIds = null, int $offset = 0): array
    {
        $out = [];
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        [$scope, $sp] = \RivetCore\Rmm\Tags\TagService::scope($visibleClientIds, 'd');
        foreach ($this->sql->all("SELECT e.job_id, e.device_id, d.client_id, e.script_version, e.schedule_id, e.approval_id, e.created_at, j.state, j.exit_code, j.finished_at, d.hostname
            FROM rmm_job_extra e JOIN endpoint_agent_jobs j ON j.job_id = e.job_id LEFT JOIN endpoint_agent_devices d ON d.device_id = e.device_id
            WHERE e.script_id = ?$scope ORDER BY e.created_at DESC, e.job_id LIMIT $limit OFFSET $offset", [$scriptId, ...$sp]) as $r) {
            $out[] = ['job_id' => (string) $r['job_id'], 'device_id' => (int) $r['device_id'], 'hostname' => (string) ($r['hostname'] ?? ''), 'client_id' => (int) $r['client_id'], 'script_version' => $r['script_version'] === null ? null : (int) $r['script_version'],
                'schedule_id' => $r['schedule_id'] === null ? null : (int) $r['schedule_id'], 'approval_id' => $r['approval_id'] === null ? null : (int) $r['approval_id'],
                'state' => (string) $r['state'], 'exit_code' => $r['exit_code'] === null ? null : (int) $r['exit_code'], 'queued_at' => Sql::iso((string) $r['created_at']),
                'finished_at' => Sql::iso($r['finished_at'] === null ? null : (string) $r['finished_at'])];
        }

        return $out;
    }
}
