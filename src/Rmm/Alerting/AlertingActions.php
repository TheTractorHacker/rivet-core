<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Checks\CheckCatalog;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Technician\ActionResult;

/**
 * Technician and administrator actions of the Phase 3 alerting area, shared by the REST API ({@see \RivetCore\Rmm\Http\AlertingApi}) and any
 * edition page. Same decision order as the other action classes: no view access at all -> 403, a record outside the caller's clients is the
 * same 404 as a missing one, a missing grant -> 403.
 *
 * Grants. Reads need `rmm.device.view`. Acknowledging or resolving an alert, a maintenance window scoped to ONE client or ONE device, and a
 * device's parent need `rmm.alert.manage` for that client. A window scoped to all devices, a site, a group or a tag cuts across clients, so it
 * needs `rmm.admin`, like escalation policies and the alerting settings. A per-device threshold override needs `rmm.device.manage`.
 *
 * @api
 */
final class AlertingActions
{
    public function __construct(
        private readonly Sql $sql,
        private readonly DeviceRepository $devices,
        private readonly RmmAuthorizer $authz,
        private readonly AlertService $alerts,
        private readonly MaintenanceService $maintenance,
        private readonly EscalationService $escalation,
        private readonly DependencyService $dependencies,
        private readonly StormControl $storm,
        private readonly CheckEvalStore $eval,
        private readonly RmmSettings $settings,
        private readonly RmmAuditInterface $audit,
    ) {
    }

    private function view(int $uid): ?ActionResult
    {
        $why = $this->authz->check($uid, RmmAbility::DEVICE_VIEW, 0);

        return $why === null ? null : ActionResult::fail(403, 'forbidden', $why);
    }

    private function role(int $uid, string $ability): ?ActionResult
    {
        $why = $this->authz->check($uid, $ability, 0);

        return $why === null ? null : ActionResult::fail(403, 'forbidden', $why);
    }

    /**
     * May this caller know that a record scoped to ($type, $id) exists? A client or device scope outside the caller's clients (or a device that
     * is gone) is invisible: the answer is the same 404 as for a record that does not exist. Other scopes span clients and are visible to
     * everyone who may view devices.
     */
    private function scopeVisible(int $uid, string $type, int $id): bool
    {
        if ($type === 'client') {
            return $this->authz->clientOk($uid, $id);
        }
        if ($type === 'device') {
            $d = $this->devices->find($id);

            return $d !== null && $this->authz->clientOk($uid, (int) $d['client_id']);
        }

        return true;
    }

    // ------------------------------------------------------------------ alerts

    /**
     * @param array<string,mixed> $filters state, severity, device_id, client_id, check_key, group_key
     */
    public function listAlerts(RmmPrincipal $who, array $filters, int $limit, int $offset): ActionResult
    {
        if (($e = $this->view($who->userId)) !== null) {
            return $e;
        }
        $f = [];
        foreach (['state', 'severity', 'check_key', 'group_key'] as $k) {
            if (isset($filters[$k]) && is_string($filters[$k]) && $filters[$k] !== '') {
                $f[$k] = $filters[$k];
            }
        }
        foreach (['device_id', 'client_id'] as $k) {
            if (isset($filters[$k]) && (int) $filters[$k] > 0) {
                $f[$k] = (int) $filters[$k];
            }
        }
        $r = $this->alerts->list($f, $this->authz->visibleClientIds($who->userId), $limit, $offset);

        return ActionResult::ok('', 200, 'ok', $r);
    }

    public function alertGroups(RmmPrincipal $who): ActionResult
    {
        if (($e = $this->view($who->userId)) !== null) {
            return $e;
        }

        return ActionResult::ok('', 200, 'ok', ['groups' => $this->alerts->groups($this->authz->visibleClientIds($who->userId))]);
    }

    public function getAlert(RmmPrincipal $who, int $alertId): ActionResult
    {
        if (($e = $this->view($who->userId)) !== null) {
            return $e;
        }
        $a = $this->alerts->find($alertId, $this->authz->visibleClientIds($who->userId));

        return $a === null ? ActionResult::fail(404, 'not_found', 'Alert not found.') : ActionResult::ok('', 200, 'ok', ['alert' => $a]);
    }

    /** @return array{0:?array<string,mixed>,1:?ActionResult} */
    private function manageable(RmmPrincipal $who, int $alertId): array
    {
        if (($e = $this->view($who->userId)) !== null) {
            return [null, $e];
        }
        $a = $this->alerts->find($alertId, $this->authz->visibleClientIds($who->userId));
        if ($a === null) {
            return [null, ActionResult::fail(404, 'not_found', 'Alert not found.')];
        }
        $why = $this->authz->check($who->userId, RmmAbility::ALERT_MANAGE, (int) $a['client_id']);
        if ($why !== null) {
            return [null, ActionResult::fail(403, 'forbidden', $why)];
        }

        return [$a, null];
    }

    public function acknowledge(RmmPrincipal $who, int $alertId): ActionResult
    {
        [$a, $err] = $this->manageable($who, $alertId);
        if ($a === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Alert not found.');
        }
        $r = $this->alerts->acknowledge($alertId, $who->userId);
        if ($r === 'resolved') {
            return ActionResult::fail(409, 'conflict', 'That alert is already resolved.');
        }
        if ($r === 'acknowledged') {
            $this->audit->record('Alert Acknowledged', "User {$who->userId} acknowledged alert $alertId ({$a['check_key']} on device {$a['device_id']})", (int) $a['client_id'], (int) ($a['asset_id'] ?? 0));
        }

        return ActionResult::ok($r === 'already' ? 'Already acknowledged.' : 'Acknowledged.', 200, 'ok', ['alert' => $this->alerts->find($alertId)]);
    }

    public function resolve(RmmPrincipal $who, int $alertId): ActionResult
    {
        [$a, $err] = $this->manageable($who, $alertId);
        if ($a === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Alert not found.');
        }
        $r = $this->alerts->resolveManually($alertId, $who->userId);
        if ($r === 'resolved') {
            $this->audit->record('Alert Resolved', "User {$who->userId} resolved alert $alertId ({$a['check_key']} on device {$a['device_id']})", (int) $a['client_id'], (int) ($a['asset_id'] ?? 0));
        }

        return ActionResult::ok($r === 'already' ? 'Already resolved.' : 'Resolved.', 200, 'ok', ['alert' => $this->alerts->find($alertId)]);
    }

    // ------------------------------------------------------------------ maintenance windows

    /** @param array<string,mixed> $filters */
    public function listWindows(RmmPrincipal $who, array $filters = []): ActionResult
    {
        if (($e = $this->view($who->userId)) !== null) {
            return $e;
        }
        $f = [];
        if (isset($filters['scope_type']) && is_string($filters['scope_type'])) {
            $f['scope_type'] = $filters['scope_type'];
        }
        if (isset($filters['scope_id'])) {
            $f['scope_id'] = (int) $filters['scope_id'];
        }
        if (!empty($filters['active'])) {
            $f['active'] = true;
        }
        $items = [];
        foreach ($this->maintenance->all($f) as $w) {
            if ($this->scopeVisible($who->userId, $w['scope_type'], $w['scope_id'])) {
                $items[] = $w;
            }
        }

        return ActionResult::ok('', 200, 'ok', ['data' => $items]);
    }

    public function getWindow(RmmPrincipal $who, int $windowId): ActionResult
    {
        if (($e = $this->view($who->userId)) !== null) {
            return $e;
        }
        $w = $this->maintenance->find($windowId);
        if ($w !== null && !$this->scopeVisible($who->userId, $w['scope_type'], $w['scope_id'])) {
            $w = null;
        }

        return $w === null ? ActionResult::fail(404, 'not_found', 'Window not found.') : ActionResult::ok('', 200, 'ok', ['window' => $w]);
    }

    /** @return ActionResult|null a refusal for this window scope */
    private function windowScopeAllowed(RmmPrincipal $who, string $scope, int $scopeId): ?ActionResult
    {
        if ($scope === 'client') {
            $why = $this->authz->check($who->userId, RmmAbility::ALERT_MANAGE, $scopeId);

            return $why === null ? null : ActionResult::fail(403, 'forbidden', $why);
        }
        if ($scope === 'device') {
            $d = $this->devices->find($scopeId);
            if ($d === null || $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, (int) $d['client_id']) !== null) {
                return ActionResult::fail(404, 'not_found', 'Device not found.');
            }
            $why = $this->authz->check($who->userId, RmmAbility::ALERT_MANAGE, (int) $d['client_id']);

            return $why === null ? null : ActionResult::fail(403, 'forbidden', $why);
        }

        return $this->role($who->userId, RmmAbility::ADMIN);
    }

    /** @param array<string,mixed> $in */
    public function createWindow(RmmPrincipal $who, array $in): ActionResult
    {
        if (($e = $this->view($who->userId)) !== null) {
            return $e;
        }
        $scope = is_string($in['scope_type'] ?? null) ? $in['scope_type'] : 'all';
        $scopeId = is_int($in['scope_id'] ?? null) ? $in['scope_id'] : 0;
        if (($e = $this->windowScopeAllowed($who, $scope, $scopeId)) !== null) {
            return $e;
        }
        try {
            $w = $this->maintenance->create($in, $who->userId);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        $this->audit->record('Maintenance Window Created', "User {$who->userId} created maintenance window \"{$w['name']}\" ({$w['mode']}, {$w['scope_type']} {$w['scope_id']})", 0, 0);

        return ActionResult::ok('Window saved.', 201, 'ok', ['window' => $w]);
    }

    /** @param array<string,mixed> $in */
    public function updateWindow(RmmPrincipal $who, int $windowId, array $in): ActionResult
    {
        if (($e = $this->view($who->userId)) !== null) {
            return $e;
        }
        $cur = $this->maintenance->find($windowId);
        if ($cur === null || !$this->scopeVisible($who->userId, $cur['scope_type'], $cur['scope_id'])) {
            return ActionResult::fail(404, 'not_found', 'Window not found.');
        }
        if (($e = $this->windowScopeAllowed($who, $cur['scope_type'], $cur['scope_id'])) !== null) {
            return $e;
        }
        $scope = is_string($in['scope_type'] ?? null) ? $in['scope_type'] : $cur['scope_type'];
        $scopeId = is_int($in['scope_id'] ?? null) ? $in['scope_id'] : ($scope === 'all' ? 0 : $cur['scope_id']);
        if (($scope !== $cur['scope_type'] || $scopeId !== $cur['scope_id']) && ($e = $this->windowScopeAllowed($who, $scope, $scopeId)) !== null) {
            return $e;
        }
        try {
            $w = $this->maintenance->update($windowId, $in);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        if ($w === null) {
            return ActionResult::fail(404, 'not_found', 'Window not found.');
        }
        $this->audit->record('Maintenance Window Updated', "User {$who->userId} changed maintenance window $windowId", 0, 0);

        return ActionResult::ok('Window saved.', 200, 'ok', ['window' => $w]);
    }

    public function deleteWindow(RmmPrincipal $who, int $windowId): ActionResult
    {
        if (($e = $this->view($who->userId)) !== null) {
            return $e;
        }
        $cur = $this->maintenance->find($windowId);
        if ($cur === null || !$this->scopeVisible($who->userId, $cur['scope_type'], $cur['scope_id'])) {
            return ActionResult::fail(404, 'not_found', 'Window not found.');
        }
        if (($e = $this->windowScopeAllowed($who, $cur['scope_type'], $cur['scope_id'])) !== null) {
            return $e;
        }
        $this->maintenance->delete($windowId);
        $this->audit->record('Maintenance Window Deleted', "User {$who->userId} deleted maintenance window $windowId (\"{$cur['name']}\")", 0, 0);

        return ActionResult::ok('Window deleted.');
    }

    /** The windows open for one device now. */
    public function deviceMaintenance(RmmPrincipal $who, int $deviceId): ActionResult
    {
        [$dev, $err] = $this->device($who->userId, $deviceId, null);
        if ($dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $rows = array_map(fn (array $w): array => $this->maintenance->present($w), $this->maintenance->activeFor($dev));

        return ActionResult::ok('', 200, 'ok', ['data' => $rows]);
    }

    /**
     * Everything the device page needs to explain why a bad check has (or has no) alert: the maintenance windows open for the device, its
     * parent (and whether an ancestor is down), and per check the threshold tier, last reading, flap state and why an alert is held back.
     */
    public function deviceAlerting(RmmPrincipal $who, int $deviceId): ActionResult
    {
        [$dev, $err] = $this->device($who->userId, $deviceId, null);
        if ($dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $on = $this->settings->featureOn('alerting');
        $windows = array_map(fn (array $w): array => $this->maintenance->present($w), $this->maintenance->activeFor($dev));
        $state = $this->maintenance->stateFor($dev);
        $down = $this->dependencies->downAncestor($deviceId);
        $failN = max(1, (int) $this->settings->get()['failure_debounce']);
        $eval = [];
        foreach ($this->sql->all('SELECT * FROM rmm_check_eval WHERE device_id = ?', [$deviceId]) as $r) {
            $eval[(string) $r['check_key']] = $r;
        }
        $checks = [];
        foreach ($this->sql->all('SELECT check_key, status, consecutive_failures, alert_id FROM endpoint_agent_checks WHERE device_id = ? ORDER BY check_key', [$deviceId]) as $c) {
            $e = $eval[(string) $c['check_key']] ?? null;
            $bad = in_array($c['status'], ['warn', 'fail'], true);
            $held = null;
            if ($on && $bad && $c['alert_id'] === null) {
                $held = $state['suppress'] ? 'maintenance_suppress' : ($state['mute'] ? 'maintenance' : ($down !== null ? 'dependency' : null));
            }
            $checks[] = ['check_key' => (string) $c['check_key'], 'status' => (string) $c['status'], 'alert_id' => $c['alert_id'] === null ? null : (int) $c['alert_id'],
                'tier' => $e === null ? null : (string) $e['tier'], 'pending_tier' => $e === null ? null : (string) $e['cand_tier'], 'last_value' => $e === null || $e['last_reading'] === null ? null : (float) $e['last_reading'],
                'flapping' => $e !== null && (int) $e['flapping'] === 1, 'has_override' => $e !== null && $e['override_json'] !== null,
                'held_by' => $held, 'waiting_for_debounce' => $bad && $c['alert_id'] === null && (int) $c['consecutive_failures'] < $failN];
        }

        return ActionResult::ok('', 200, 'ok', ['alerting_enabled' => $on, 'maintenance' => $windows, 'muted' => $state['mute'], 'suppressed' => $state['suppress'],
            'parent' => $this->dependencies->parentOf($deviceId), 'down_ancestor' => $down, 'checks' => $checks]);
    }

    // ------------------------------------------------------------------ escalation policies and settings

    public function listPolicies(RmmPrincipal $who): ActionResult
    {
        if (($e = $this->view($who->userId)) !== null) {
            return $e;
        }

        $items = array_values(array_filter($this->escalation->allPolicies(), fn (array $p): bool => $this->scopeVisible($who->userId, $p['scope_type'], $p['scope_id'])));

        return ActionResult::ok('', 200, 'ok', ['data' => $items]);
    }

    public function getPolicy(RmmPrincipal $who, int $policyId): ActionResult
    {
        if (($e = $this->view($who->userId)) !== null) {
            return $e;
        }
        $p = $this->escalation->findPolicy($policyId);
        if ($p !== null && !$this->scopeVisible($who->userId, $p['scope_type'], $p['scope_id'])) {
            $p = null;
        }

        return $p === null ? ActionResult::fail(404, 'not_found', 'Policy not found.') : ActionResult::ok('', 200, 'ok', ['policy' => $p]);
    }

    /** @param array<string,mixed> $in */
    public function createPolicy(RmmPrincipal $who, array $in): ActionResult
    {
        if (($e = $this->role($who->userId, RmmAbility::ADMIN)) !== null) {
            return $e;
        }
        try {
            $p = $this->escalation->createPolicy($in, $who->userId);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        $this->audit->record('Escalation Policy Created', "User {$who->userId} created escalation policy \"{$p['name']}\"", 0, 0);

        return ActionResult::ok('Policy saved.', 201, 'ok', ['policy' => $p]);
    }

    /** @param array<string,mixed> $in */
    public function updatePolicy(RmmPrincipal $who, int $policyId, array $in): ActionResult
    {
        if (($e = $this->role($who->userId, RmmAbility::ADMIN)) !== null) {
            return $e;
        }
        $cur = $this->escalation->findPolicy($policyId);
        if ($cur === null || !$this->scopeVisible($who->userId, $cur['scope_type'], $cur['scope_id'])) {
            return ActionResult::fail(404, 'not_found', 'Policy not found.');
        }
        try {
            $p = $this->escalation->updatePolicy($policyId, $in);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        if ($p === null) {
            return ActionResult::fail(404, 'not_found', 'Policy not found.');
        }
        $this->audit->record('Escalation Policy Updated', "User {$who->userId} changed escalation policy $policyId", 0, 0);

        return ActionResult::ok('Policy saved.', 200, 'ok', ['policy' => $p]);
    }

    public function deletePolicy(RmmPrincipal $who, int $policyId): ActionResult
    {
        if (($e = $this->role($who->userId, RmmAbility::ADMIN)) !== null) {
            return $e;
        }
        $cur = $this->escalation->findPolicy($policyId);
        if ($cur === null || !$this->scopeVisible($who->userId, $cur['scope_type'], $cur['scope_id'])) {
            return ActionResult::fail(404, 'not_found', 'Policy not found.');
        }
        if (!$this->escalation->deletePolicy($policyId)) {
            return ActionResult::fail(404, 'not_found', 'Policy not found.');
        }
        $this->audit->record('Escalation Policy Deleted', "User {$who->userId} deleted escalation policy $policyId", 0, 0);

        return ActionResult::ok('Policy deleted.');
    }

    public function settings(RmmPrincipal $who): ActionResult
    {
        if (($e = $this->view($who->userId)) !== null) {
            return $e;
        }

        return ActionResult::ok('', 200, 'ok', ['alerting_enabled' => $this->settings->featureOn('alerting'), 'storm' => $this->storm->config()]);
    }

    /** @param array<string,mixed> $in storm_global_max, storm_global_window_s, storm_client_max, storm_client_window_s */
    public function updateSettings(RmmPrincipal $who, array $in): ActionResult
    {
        if (($e = $this->role($who->userId, RmmAbility::ADMIN)) !== null) {
            return $e;
        }
        try {
            $c = $this->storm->update($in);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        $this->audit->record('Alerting Settings Changed', "User {$who->userId} changed the alert storm control limits", 0, 0);

        return ActionResult::ok('Saved.', 200, 'ok', ['alerting_enabled' => $this->settings->featureOn('alerting'), 'storm' => $c]);
    }

    // ------------------------------------------------------------------ dependencies

    /** @return array{0:?array<string,mixed>,1:?ActionResult} */
    private function device(int $uid, int $deviceId, ?string $ability): array
    {
        if (($e = $this->view($uid)) !== null) {
            return [null, $e];
        }
        $dev = $this->devices->find($deviceId);
        if ($dev === null || $this->authz->check($uid, RmmAbility::DEVICE_VIEW, (int) $dev['client_id']) !== null) {
            return [null, ActionResult::fail(404, 'not_found', 'Device not found.')];
        }
        if ($ability !== null && ($why = $this->authz->check($uid, $ability, (int) $dev['client_id'])) !== null) {
            return [null, ActionResult::fail(403, 'forbidden', $why)];
        }

        return [$dev, null];
    }

    public function getParent(RmmPrincipal $who, int $deviceId): ActionResult
    {
        [$dev, $err] = $this->device($who->userId, $deviceId, null);
        if ($dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }

        return ActionResult::ok('', 200, 'ok', ['parent' => $this->dependencies->parentOf($deviceId), 'children' => $this->dependencies->childrenOf($deviceId)]);
    }

    public function setParent(RmmPrincipal $who, int $deviceId, int $parentId): ActionResult
    {
        [$dev, $err] = $this->device($who->userId, $deviceId, RmmAbility::ALERT_MANAGE);
        if ($dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $par = $this->devices->find($parentId);
        if ($par === null || $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, (int) $par['client_id']) !== null) {
            return ActionResult::fail(404, 'not_found', 'Parent device not found.');
        }
        try {
            $this->dependencies->setParent($deviceId, $parentId, $who->userId);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        $this->audit->record('Device Parent Set', "User {$who->userId} set device $parentId as the parent of device $deviceId", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

        return ActionResult::ok('Saved.', 200, 'ok', ['parent' => $this->dependencies->parentOf($deviceId)]);
    }

    public function clearParent(RmmPrincipal $who, int $deviceId): ActionResult
    {
        [$dev, $err] = $this->device($who->userId, $deviceId, RmmAbility::ALERT_MANAGE);
        if ($dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        if (!$this->dependencies->clearParent($deviceId)) {
            return ActionResult::fail(404, 'not_found', 'That device has no parent.');
        }
        $this->audit->record('Device Parent Cleared', "User {$who->userId} cleared the parent of device $deviceId", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

        return ActionResult::ok('Cleared.');
    }

    // ------------------------------------------------------------------ thresholds

    /** The check types and their params (the catalog the UI builds its forms from). */
    public function checkTypes(RmmPrincipal $who): ActionResult
    {
        if (($e = $this->view($who->userId)) !== null) {
            return $e;
        }
        $out = [];
        foreach (CheckCatalog::LEGACY as $t) {
            $out[] = ['type' => $t, 'legacy' => true, 'platforms' => $t === 'script' ? ['windows', 'linux'] : ['windows', 'linux'], 'reports_value' => false, 'unit' => null, 'fields' => new \stdClass()];
        }
        foreach (CheckCatalog::types() as $t => $spec) {
            $fields = [];
            foreach ($spec['fields'] as $name => $f) {
                $fields[$name] = ['kind' => $f[0], 'required' => !empty($f['req'])] + ($f[0] === 'int' ? ['min' => $f[1], 'max' => $f[2], 'default' => $f[3]]
                    : ($f[0] === 'enum' ? ['values' => $f[1], 'default' => $f[2]] : ($f[0] === 'bool' ? ['default' => $f[1]] : ($f[0] === 'str' ? ['max_length' => $f[1]] : ['max_items' => $f[1], 'item_max_length' => $f[2]]))));
            }
            $out[] = ['type' => $t, 'legacy' => false, 'platforms' => $spec['platforms'], 'reports_value' => $spec['unit'] !== null, 'unit' => $spec['unit'], 'fields' => $fields];
        }

        return ActionResult::ok('', 200, 'ok', ['data' => $out]);
    }

    /** The thresholds of one check on one device: the definition's, the device override, the effective result and the live state. */
    public function getThresholds(RmmPrincipal $who, int $deviceId, string $checkKey): ActionResult
    {
        [$dev, $err] = $this->device($who->userId, $deviceId, null);
        if ($dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $def = $this->definition($checkKey);
        if ($def === null) {
            return ActionResult::fail(404, 'not_found', 'No such check is defined.');
        }
        $row = $this->eval->load($deviceId, [$checkKey])[$checkKey] ?? null;
        $override = $row === null || $row['override_json'] === null ? null : json_decode((string) $row['override_json'], true);
        $defined = isset($def['params']['thresholds']) && is_array($def['params']['thresholds']) ? $def['params']['thresholds'] : null;
        $merged = $this->merge($defined, is_array($override) ? $override : null);
        [$eff] = $merged === null ? [null] : Thresholds::normalize($merged);

        return ActionResult::ok('', 200, 'ok', [
            'check_key' => $checkKey, 'type' => (string) $def['type'], 'reports_value' => CheckCatalog::reportsValue((string) $def['type']), 'defined' => $defined,
            'override' => is_array($override) ? $override : null, 'effective' => $eff,
            'state' => $row === null ? null : ['tier' => (string) $row['tier'], 'pending_tier' => (string) $row['cand_tier'], 'pending_samples' => (int) $row['cand_count'],
                'last_value' => $row['last_reading'] === null ? null : (float) $row['last_reading'], 'flapping' => (int) $row['flapping'] === 1],
        ]);
    }

    /**
     * @param array<string,mixed> $in thresholds fields to replace the definition's; a field set to null is removed. An empty object clears the override.
     */
    public function setThresholds(RmmPrincipal $who, int $deviceId, string $checkKey, array $in): ActionResult
    {
        [$dev, $err] = $this->device($who->userId, $deviceId, RmmAbility::DEVICE_MANAGE);
        if ($dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $def = $this->definition($checkKey);
        if ($def === null) {
            return ActionResult::fail(404, 'not_found', 'No such check is defined.');
        }
        if (!isset($def['params']['thresholds']) || !is_array($def['params']['thresholds'])) {
            return ActionResult::fail(409, 'conflict', 'This check declares no thresholds, so it has nothing to override. Add thresholds to its definition first.');
        }
        if ($in === []) {
            $this->eval->setOverride($deviceId, $checkKey, null, $who->userId);
            $this->audit->record('Threshold Override Cleared', "User {$who->userId} cleared the threshold override of $checkKey on device $deviceId", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

            return ActionResult::ok('Override removed.');
        }
        $merged = $this->merge($def['params']['thresholds'], $in);
        [$eff, $why] = $merged === null ? [null, 'Nothing left.'] : Thresholds::normalize($merged);
        if ($eff === null) {
            return ActionResult::fail(422, 'invalid', (string) $why);
        }
        $this->eval->setOverride($deviceId, $checkKey, $in, $who->userId);
        $this->audit->record('Threshold Override Set', "User {$who->userId} overrode the thresholds of $checkKey on device $deviceId", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

        return ActionResult::ok('Override saved.', 200, 'ok', ['override' => $in, 'effective' => $eff]);
    }

    /**
     * @param array<string,mixed>|null $base
     * @param array<string,mixed>|null $over
     * @return array<string,mixed>|null
     */
    private function merge(?array $base, ?array $over): ?array
    {
        $m = $base ?? [];
        foreach ($over ?? [] as $k => $v) {
            if ($v === null) {
                unset($m[$k]);
            } else {
                $m[$k] = $v;
            }
        }

        return $m === [] ? null : $m;
    }

    /** @return array<string,mixed>|null */
    private function definition(string $key): ?array
    {
        foreach ($this->settings->checks() as $c) {
            if (($c['key'] ?? null) === $key) {
                return $c;
            }
        }

        return null;
    }
}
