<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Technician;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Policy\PolicyStore;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Support\RmmEventPublisher;

/**
 * Policy administration: create, change, delete, assign and unassign. A policy decides what every device in its scope is told (its checks,
 * intervals, features and update ring, and script checks execute code on the endpoint), so all of it needs `rmm.admin`. Reading policies and a
 * device's effective policy needs only `rmm.device.view` (see {@see \RivetCore\Rmm\Read\RmmReadModel}).
 *
 * @api
 */
final class PolicyActions
{
    public function __construct(
        private readonly RmmAuthorizer $authz,
        private readonly PolicyStore $store,
        private readonly RmmAuditInterface $audit,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    private function admin(RmmPrincipal $who): ?ActionResult
    {
        $why = $this->authz->check($who->userId, RmmAbility::ADMIN, 0);

        return $why === null ? null : ActionResult::fail(403, 'forbidden', $why);
    }

    /** TEN-3: a policy is one object for every client (what its devices are told, and script checks run code): changing it is not for a caller limited to some clients. */
    private function fleetWide(RmmPrincipal $who): ?ActionResult
    {
        return $this->authz->visibleClientIds($who->userId) === null ? null
            : ActionResult::fail(403, 'forbidden', 'Policies apply to every client; ask an administrator whose access is not limited to some clients.');
    }

    /**
     * Where a caller may assign a policy: a client or device inside his clients (a client or device out of scope is the same 404 as one that does not exist); the other
     * scopes (global, site, group, tag) reach every client.
     */
    private function scopeRefusal(RmmPrincipal $who, string $type, int $id): ?ActionResult
    {
        $visible = $this->authz->visibleClientIds($who->userId);
        if ($visible === null) {
            return null;
        }
        if ($type === 'client' || $type === 'device') {
            $client = $this->store->scopeClientId($type, $id);

            return $client !== null && in_array($client, $visible, true) ? null : ActionResult::fail(404, 'not_found', $type === 'client' ? 'Client not found.' : 'Device not found.');
        }

        return $this->fleetWide($who);
    }

    /** @param array<string,mixed> $in {name, description?, settings} */
    public function create(RmmPrincipal $who, array $in): ActionResult
    {
        if (($e = $this->admin($who) ?? $this->fleetWide($who)) !== null) {
            return $e;
        }
        try {
            $p = $this->store->create($in, $who->userId);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        $this->audit->record('Policy Created', "{$who->userName} created policy \"{$p['name']}\" (#{$p['policy_id']})", 0, (int) $p['policy_id']);

        return ActionResult::ok('Policy saved.', 201, 'ok', ['policy' => $p]);
    }

    /** @param array<string,mixed> $in any of name, description, settings, enabled */
    public function update(RmmPrincipal $who, int $policyId, array $in): ActionResult
    {
        if (($e = $this->admin($who) ?? $this->fleetWide($who)) !== null) {
            return $e;
        }
        $before = $this->store->find($policyId);
        if ($before === null) {
            return ActionResult::fail(404, 'not_found', 'Policy not found.');
        }
        try {
            $p = $this->store->update($policyId, $in, $who->userId);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        $this->audit->record('Policy Updated', "{$who->userName} changed policy \"{$p['name']}\" (#$policyId)" . ($p['version'] !== $before['version'] ? " to version {$p['version']}" : '')
            . ($p['enabled'] !== $before['enabled'] ? ($p['enabled'] ? ', switched on' : ', switched off') : ''), 0, $policyId);

        return ActionResult::ok('Policy saved.', 200, 'ok', ['policy' => $p]);
    }

    public function delete(RmmPrincipal $who, int $policyId): ActionResult
    {
        if (($e = $this->admin($who) ?? $this->fleetWide($who)) !== null) {
            return $e;
        }
        $p = $this->store->find($policyId);
        if ($p === null || !$this->store->delete($policyId)) {
            return ActionResult::fail(404, 'not_found', 'Policy not found.');
        }
        $this->audit->record('Policy Deleted', "{$who->userName} deleted policy \"{$p['name']}\" (#$policyId)", 0, $policyId);

        return ActionResult::ok('Policy deleted.');
    }

    /** @param array<string,mixed> $in {scope_type, scope_id?, priority?, enforce?, overrides?} */
    public function assign(RmmPrincipal $who, int $policyId, array $in): ActionResult
    {
        if (($e = $this->admin($who)) !== null) {
            return $e;
        }
        $p = $this->store->find($policyId);
        if ($p === null) {
            return ActionResult::fail(404, 'not_found', 'Policy not found.');
        }
        $type = is_string($in['scope_type'] ?? null) ? $in['scope_type'] : '';
        $sid = is_int($in['scope_id'] ?? null) ? $in['scope_id'] : (is_string($in['scope_id'] ?? null) && ctype_digit($in['scope_id']) ? (int) $in['scope_id'] : 0);
        if ($type !== '' && ($e = $this->scopeRefusal($who, $type, $sid)) !== null) {
            return $e;
        }
        try {
            $a = $this->store->assign($policyId, $in, $who->userId);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        $this->audit->record('Policy Assigned', "{$who->userName} assigned policy \"{$p['name']}\" (#$policyId) to {$a['scope_type']} {$a['scope_id']}" . ($a['enforce'] ? ' (enforced)' : ''), $a['scope_type'] === 'client' ? (int) $a['scope_id'] : 0, $policyId);
        $this->events?->emitScoped(RmmEvent::POLICY_ASSIGNED, $a['scope_type'] === 'client' ? (int) $a['scope_id'] : 0,
            ['policy_id' => $policyId, 'policy_name' => $p['name'], 'scope_type' => $a['scope_type'], 'scope_id' => $a['scope_id'], 'action' => 'assigned']);

        return ActionResult::ok('Assigned.', 200, 'ok', ['assignment' => $a]);
    }

    public function unassign(RmmPrincipal $who, int $assignmentId): ActionResult
    {
        if (($e = $this->admin($who)) !== null) {
            return $e;
        }
        $cur = $this->store->assignment($assignmentId);
        if ($cur === null || $this->scopeRefusal($who, (string) $cur['scope_type'], (int) $cur['scope_id']) !== null) {
            return ActionResult::fail(404, 'not_found', 'Assignment not found.');   // out of scope looks exactly like missing
        }
        $a = $this->store->unassign($assignmentId);
        if ($a === null) {
            return ActionResult::fail(404, 'not_found', 'Assignment not found.');
        }
        $p = $this->store->find((int) $a['policy_id']);
        $this->audit->record('Policy Unassigned', "{$who->userName} removed policy \"" . ($p['name'] ?? '?') . "\" (#{$a['policy_id']}) from {$a['scope_type']} {$a['scope_id']}", $a['scope_type'] === 'client' ? (int) $a['scope_id'] : 0, (int) $a['policy_id']);
        $this->events?->emitScoped(RmmEvent::POLICY_ASSIGNED, $a['scope_type'] === 'client' ? (int) $a['scope_id'] : 0,
            ['policy_id' => (int) $a['policy_id'], 'policy_name' => $p['name'] ?? '', 'scope_type' => $a['scope_type'], 'scope_id' => $a['scope_id'], 'action' => 'unassigned']);

        return ActionResult::ok('Removed.');
    }
}
