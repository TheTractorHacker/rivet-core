<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Logs;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Technician\ActionResult;

/**
 * Reading collected logs and managing log rules (Phase 8), shared by the REST API and an edition's pages.
 *
 * Reading entries needs the grant that reads job output (`rmm.job.run_saved`, log text is as free-form as script output) on top of `rmm.device.view`, and only
 * the caller's own clients are searched. Managing rules needs `rmm.alert.manage`; a rule for ALL clients needs unrestricted client access, a rule for one client
 * needs access to that client. A device or rule outside the caller's clients is the same 404 as a missing one.
 *
 * @api
 */
final class LogActions
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly RmmAuthorizer $authz,
        private readonly LogService $logs,
        private readonly LogRuleService $rules,
        private readonly RmmAuditInterface $audit,
    ) {
    }

    private function role(RmmPrincipal $who, string $ability): ?ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, 0);
        if ($denied === null && $ability !== RmmAbility::DEVICE_VIEW) {
            $denied = $this->authz->check($who->userId, $ability, 0);
        }

        return $denied === null ? null : ActionResult::fail(403, 'forbidden', $denied);
    }

    /**
     * @return array{0:?array<string,mixed>,1:?ActionResult}
     */
    private function device(RmmPrincipal $who, int $deviceId, string $ability): array
    {
        if (($e = $this->role($who, $ability)) !== null) {
            return [null, $e];
        }
        $dev = $this->devices->find($deviceId);
        $client = $dev === null ? 0 : (int) $dev['client_id'];
        if ($dev === null || !$this->authz->clientOk($who->userId, $client) || $this->authz->check($who->userId, $ability, $client) !== null) {
            return [null, ActionResult::fail(404, 'not_found', 'Device not found.')];
        }

        return [$dev, null];
    }

    /**
     * Search the entries of one device.
     *
     * @param array<string,mixed> $f from, to, level, source, code, q
     */
    public function deviceLogs(RmmPrincipal $who, int $deviceId, array $f, int $limit, int $offset): ActionResult
    {
        [$dev, $err] = $this->device($who, $deviceId, RmmAbility::JOB_RUN_SAVED);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }

        return ActionResult::ok('ok', 200, 'ok', $this->logs->search(['device_id' => $deviceId] + $f, $this->authz->visibleClientIds($who->userId), $limit, $offset));
    }

    /**
     * Search across the caller's clients (a window of at most 7 days).
     *
     * @param array<string,mixed> $f device_id, client_id, from, to, level, source, code, q
     */
    public function fleetLogs(RmmPrincipal $who, array $f, int $limit, int $offset): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::JOB_RUN_SAVED)) !== null) {
            return $e;
        }
        $visible = $this->authz->visibleClientIds($who->userId);
        if (isset($f['device_id']) && (int) $f['device_id'] > 0) {
            [$dev, $err] = $this->device($who, (int) $f['device_id'], RmmAbility::JOB_RUN_SAVED);
            if ($err !== null || $dev === null) {
                return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
            }
        }
        if (isset($f['client_id']) && (int) $f['client_id'] > 0 && !$this->authz->clientOk($who->userId, (int) $f['client_id'])) {
            return ActionResult::fail(404, 'not_found', 'Client not found.');
        }

        return ActionResult::ok('ok', 200, 'ok', $this->logs->search($f, $visible, $limit, $offset));
    }

    /** Storage figures: the fleet's, and one device's when a device is given. */
    public function stats(RmmPrincipal $who, ?int $deviceId): ActionResult
    {
        if ($deviceId !== null) {
            [$dev, $err] = $this->device($who, $deviceId, RmmAbility::JOB_RUN_SAVED);
            if ($err !== null || $dev === null) {
                return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
            }
        } elseif (($e = $this->role($who, RmmAbility::JOB_RUN_SAVED)) !== null) {
            return $e;
        }

        return ActionResult::ok('ok', 200, 'ok', $this->logs->stats($deviceId));
    }

    // ------------------------------------------------------------------ rules

    public function listRules(RmmPrincipal $who): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::DEVICE_VIEW)) !== null) {
            return $e;
        }

        return ActionResult::ok('ok', 200, 'ok', ['data' => $this->rules->all($this->authz->visibleClientIds($who->userId))]);
    }

    public function getRule(RmmPrincipal $who, int $id): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::DEVICE_VIEW)) !== null) {
            return $e;
        }
        $r = $this->visibleRule($who, $id);

        return $r === null ? ActionResult::fail(404, 'not_found', 'Rule not found.') : ActionResult::ok('ok', 200, 'ok', $r);
    }

    /** @param array<string,mixed> $in */
    public function createRule(RmmPrincipal $who, array $in): ActionResult
    {
        if (($e = $this->manage($who, (int) ($in['client_id'] ?? 0))) !== null) {
            return $e;
        }
        [$id, $errors] = $this->rules->create($in, $who->userId);
        if ($id === null) {
            return ActionResult::fail(422, 'invalid', implode(' ', $errors));
        }
        $this->audit->record('Log Rule Created', "{$who->userName} created log rule #$id: " . (is_string($in['name'] ?? null) ? mb_substr($in['name'], 0, 100) : ''), (int) ($in['client_id'] ?? 0), 0);

        return ActionResult::ok('Rule created.', 201, 'created', (array) $this->rules->get($id));
    }

    /** @param array<string,mixed> $in */
    public function updateRule(RmmPrincipal $who, int $id, array $in): ActionResult
    {
        $cur = $this->visibleRule($who, $id);
        if ($cur === null) {
            return ActionResult::fail(404, 'not_found', 'Rule not found.');
        }
        if (($e = $this->manage($who, (int) $cur['client_id'])) !== null) {
            return $e;
        }
        if (isset($in['client_id']) && (int) $in['client_id'] !== (int) $cur['client_id'] && ($e = $this->manage($who, (int) $in['client_id'])) !== null) {
            return $e;
        }
        $errors = $this->rules->update($id, $in);
        if ($errors !== []) {
            return ActionResult::fail(422, 'invalid', implode(' ', $errors));
        }
        $this->audit->record('Log Rule Changed', "{$who->userName} changed log rule #$id", (int) $cur['client_id'], 0);

        return ActionResult::ok('Saved.', 200, 'saved', (array) $this->rules->get($id));
    }

    public function deleteRule(RmmPrincipal $who, int $id): ActionResult
    {
        $cur = $this->visibleRule($who, $id);
        if ($cur === null) {
            return ActionResult::fail(404, 'not_found', 'Rule not found.');
        }
        if (($e = $this->manage($who, (int) $cur['client_id'])) !== null) {
            return $e;
        }
        $this->rules->delete($id);
        $this->audit->record('Log Rule Deleted', "{$who->userName} deleted log rule #$id", (int) $cur['client_id'], 0);

        return ActionResult::ok('Deleted. Alerts it had open close at the next housekeeping run.');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function visibleRule(RmmPrincipal $who, int $id): ?array
    {
        $r = $this->rules->get($id);
        if ($r === null) {
            return null;
        }
        $visible = $this->authz->visibleClientIds($who->userId);

        return $visible === null || ((int) $r['client_id'] !== 0 && in_array((int) $r['client_id'], $visible, true)) ? $r : null;
    }

    private function manage(RmmPrincipal $who, int $clientId): ?ActionResult
    {
        if (($e = $this->role($who, RmmAbility::ALERT_MANAGE)) !== null) {
            return $e;
        }
        if ($clientId === 0) {
            return $this->authz->visibleClientIds($who->userId) === null ? null : ActionResult::fail(403, 'forbidden', 'A rule for all clients needs access to all clients.');
        }
        if (!$this->authz->clientOk($who->userId, $clientId)) {
            return ActionResult::fail(404, 'not_found', 'Client not found.');
        }
        $denied = $this->authz->check($who->userId, RmmAbility::ALERT_MANAGE, $clientId);

        return $denied === null ? null : ActionResult::fail(403, 'forbidden', $denied);
    }
}
