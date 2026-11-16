<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Scripts;

use RivetCore\Rmm\Approvals\ApprovalService;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Queues the jobs of an APPROVED run request, as the user who asked, inside the clients that user can see. Called right after the approval is granted
 * and again by housekeeping for an approval whose execution did not finish (a crash between "approved" and "queued"): the per-device idempotency key
 * (approval and device) makes the second execution queue only the devices that are missing.
 *
 * @api
 */
final class ApprovedRunExecutor
{
    /** Seconds an approved run may have no recorded result before housekeeping picks it up again. */
    public const RESUME_AFTER_S = 120;

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly ApprovalService $approvals,
        private readonly ScriptService $scripts,
        private readonly BulkRunner $bulk,
        private readonly ?RmmTenancyInterface $tenancy,
        private readonly RmmAuditInterface $audit,
        private readonly ?\RivetCore\Rmm\Authz\RmmAuthorizer $authz = null,
        private readonly ?TargetResolver $targets = null,
    ) {
    }

    /**
     * CORE-DS-44: the request froze the number of devices its target reached when it was asked for. A target that reaches MORE devices now (a group or tag that
     * gained members, a site that grew) is not what the second person saw: why not, or null when it is fine (or the request froze no count: made before this check).
     *
     * @param array<string,mixed> $request
     * @param array{script:array<string,mixed>,version:array<string,mixed>,schema:list<array<string,mixed>>} $loaded
     */
    public function targetChanged(array $request, int $requesterId, array $loaded): ?string
    {
        if ($this->targets === null || !is_int($request['device_count'] ?? null) || !is_array($request['target'] ?? null)) {
            return null;
        }
        $t = $request['target'];
        $visible = $this->tenancy === null ? null : $this->tenancy->visibleClientIds($requesterId);
        $now = $this->targets->count((string) ($t['type'] ?? 'device'), (int) ($t['id'] ?? 0), $visible, ScriptLanguage::platforms((string) $loaded['script']['language']));

        return $now > $request['device_count'] ? "The target now reaches $now devices; the request was made for {$request['device_count']}. Ask again so the approver sees the real number." : null;
    }

    /**
     * @param array<string,mixed> $approval
     * @param array<string,mixed> $request its frozen request
     * @param array{script:array<string,mixed>,version:array<string,mixed>,schema:list<array<string,mixed>>} $loaded
     * @param (\Closure(int):bool)|null $allowed whether the requester may run jobs on a client (null = already decided)
     * @return array<string,mixed>
     */
    public function execute(array $approval, array $request, array $loaded, ?\Closure $allowed = null): array
    {
        $id = (int) $approval['approval_id'];
        $requester = (int) $approval['requested_by'];
        /** @var array<string,mixed> $t */
        $t = is_array($request['target'] ?? null) ? $request['target'] : [];
        $target = ['type' => (string) ($t['type'] ?? 'device'), 'id' => (int) ($t['id'] ?? 0)];
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];
        $timeout = isset($request['timeout_s']) && is_int($request['timeout_s']) ? $request['timeout_s'] : null;
        $visible = $this->tenancy === null ? null : $this->tenancy->visibleClientIds($requester);
        if (($why = $this->targetChanged($request, $requester, $loaded)) !== null) {
            $res = ['created' => 0, 'device_count' => 0, 'skipped_denied' => 0, 'skipped_error' => 0, 'errors' => [$why], 'target_changed' => true];
            $this->approvals->recordResult($id, $res);
            $this->audit->record('Approved Run Refused', "Approved request #$id was not run: $why", 0, (int) $loaded['script']['script_id']);

            return $res;
        }
        $res = $this->bulk->run($loaded, $target, $params, $timeout, $requester, $visible, $allowed, 'approval', $id, $this->settings->limits()['bulk_run_max']);
        $this->approvals->recordResult($id, $res);
        $this->audit->record('Approved Run Queued', "Approved request #$id queued {$res['created']} job(s) of {$res['device_count']} device(s) for script \"{$loaded['script']['name']}\" version {$loaded['version']['version']}", 0, (int) $loaded['script']['script_id']);

        return $res;
    }

    /**
     * Finish approved runs that never recorded a result.
     *
     * @return int runs resumed
     */
    public function resume(): int
    {
        $n = 0;
        $rows = $this->sql->all("SELECT approval_id FROM rmm_approvals WHERE kind = 'run' AND state = ? AND result_json IS NULL AND decided_at <= ? ORDER BY approval_id LIMIT 10",
            [ApprovalService::APPROVED, $this->sql->utcAt(-self::RESUME_AFTER_S)]);
        foreach ($rows as $r) {
            $a = $this->approvals->get((int) $r['approval_id']);
            $req = $this->approvals->requestOf((int) $r['approval_id']);
            if ($a === null || $req === null) {
                continue;
            }
            [$loaded] = $this->scripts->load((int) ($req['script_id'] ?? 0), (int) ($req['script_version'] ?? 0));
            if ($loaded === null || !hash_equals((string) ($req['body_sha256'] ?? ''), (string) $loaded['version']['body_sha256'])) {
                $this->approvals->recordResult((int) $r['approval_id'], ['error' => 'The script changed or was retired before the approved run could be queued.']);
                continue;
            }
            // CORE-DS-41: the same per-client grant check decide() makes, for the requester as they are now (a grant lost or an account switched off since the approval stops the run).
            $requester = (int) $a['requested_by'];
            $allowed = $this->authz === null ? null : fn (int $client): bool => $this->authz->allowed($requester, \RivetCore\Rmm\Authz\RmmAbility::JOB_RUN_SAVED, $client);
            $this->execute($a, $req, $loaded, $allowed);
            ++$n;
        }

        return $n;
    }
}
