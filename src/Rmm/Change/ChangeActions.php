<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Change;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Technician\ActionResult;

/**
 * Reading recorded changes and managing change monitoring, shared by the REST API and an edition's pages.
 *
 * Reading needs `rmm.change.view` on top of `rmm.device.view`, and only the caller's own clients are searched. Presets, custom watches and rules need `rmm.change.manage`; a
 * rule for ALL clients needs unrestricted client access, a rule for one client needs access to that client. The switch itself and the storage caps need `rmm.admin`. A device or
 * rule outside the caller's clients is the same 404 as a missing one. Every change to the settings or the rules is written to the audit log with who did it.
 *
 * @api
 */
final class ChangeActions
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly RmmAuthorizer $authz,
        private readonly RmmSettings $settings,
        private readonly ChangeSettings $change,
        private readonly ChangeService $service,
        private readonly ChangeRuleService $rules,
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

    /** @param array<string,mixed> $f from, to, kind, watch, change, target, item, actor, q */
    public function deviceChanges(RmmPrincipal $who, int $deviceId, array $f, int $limit, int $offset): ActionResult
    {
        [$dev, $err] = $this->device($who, $deviceId, RmmAbility::CHANGE_VIEW);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }

        return ActionResult::ok('ok', 200, 'ok', $this->service->search(['device_id' => $deviceId] + $f, $this->authz->visibleClientIds($who->userId), $limit, $offset));
    }

    /** @param array<string,mixed> $f device_id, client_id, from, to, kind, watch, change, target, item, actor, q */
    public function fleetChanges(RmmPrincipal $who, array $f, int $limit, int $offset): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::CHANGE_VIEW)) !== null) {
            return $e;
        }
        $visible = $this->authz->visibleClientIds($who->userId);
        if ($visible !== null) {
            // a caller limited to some clients reads change text only where the change grant holds for that client
            $visible = array_values(array_filter($visible, fn (int $c): bool => $this->authz->check($who->userId, RmmAbility::CHANGE_VIEW, $c) === null));
        }
        if (isset($f['device_id']) && (int) $f['device_id'] > 0) {
            [$dev, $err] = $this->device($who, (int) $f['device_id'], RmmAbility::CHANGE_VIEW);
            if ($err !== null || $dev === null) {
                return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
            }
        }
        if (isset($f['client_id']) && (int) $f['client_id'] > 0 && !$this->authz->clientOk($who->userId, (int) $f['client_id'])) {
            return ActionResult::fail(404, 'not_found', 'Client not found.');
        }

        return ActionResult::ok('ok', 200, 'ok', $this->service->search($f, $visible, $limit, $offset));
    }

    /** @param array<string,mixed> $f from, to */
    public function timeline(RmmPrincipal $who, int $deviceId, array $f, int $limit): ActionResult
    {
        [$dev, $err] = $this->device($who, $deviceId, RmmAbility::CHANGE_VIEW);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }

        return ActionResult::ok('ok', 200, 'ok', ['device_id' => $deviceId, 'hostname' => (string) ($dev['hostname'] ?? '')] + $this->service->timeline($deviceId, $f, $limit));
    }

    /** What one device is told to watch and what its agent last reported about it. */
    public function deviceStatus(RmmPrincipal $who, int $deviceId): ActionResult
    {
        [$dev, $err] = $this->device($who, $deviceId, RmmAbility::CHANGE_VIEW);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $watches = array_map(static fn (array $w): string => (string) $w['id'], $this->change->watchesFor((string) ($dev['os'] ?? 'windows')));

        return ActionResult::ok('ok', 200, 'ok', ['enabled' => $this->settings->featureOn(ChangeProtocol::FEATURE), 'watches' => $watches] + $this->service->stats($deviceId));
    }

    public function stats(RmmPrincipal $who): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::CHANGE_VIEW)) !== null) {
            return $e;
        }

        return ActionResult::ok('ok', 200, 'ok', $this->service->stats(null));
    }

    // ------------------------------------------------------------------ settings

    /** @return array<string,mixed> */
    private function settingsView(): array
    {
        return ['enabled' => $this->settings->featureOn(ChangeProtocol::FEATURE)] + $this->change->summary();
    }

    public function settingsRead(RmmPrincipal $who): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::CHANGE_VIEW)) !== null) {
            return $e;
        }

        return ActionResult::ok('ok', 200, 'ok', $this->settingsView());
    }

    /**
     * Change the settings. `presets` and `custom` need `rmm.change.manage`; `features` (the `change_monitor` switch) and `limits` need `rmm.admin`. Turning the switch on needs
     * `accept_change_storage: true`: the request is refused with the plain-language text when the acknowledgement is missing, so no client can switch it on by accident.
     *
     * @param array<string,mixed> $in features {change_monitor}, presets, custom, limits, accept_change_storage
     */
    public function updateSettings(RmmPrincipal $who, array $in): ActionResult
    {
        foreach (array_keys($in) as $k) {
            if (!in_array($k, ['features', 'presets', 'custom', 'limits', 'accept_change_storage'], true)) {
                return ActionResult::fail(422, 'invalid', 'Unknown setting ' . mb_substr((string) $k, 0, 40) . '.');
            }
        }
        $needAdmin = array_key_exists('features', $in) || array_key_exists('limits', $in);
        $needManage = array_key_exists('presets', $in) || array_key_exists('custom', $in);
        if ($needAdmin && ($denied = $this->authz->check($who->userId, RmmAbility::ADMIN, 0, false)) !== null) {
            $this->audit->record('Change Monitor Settings Denied', "User {$who->userId} denied a change-monitoring settings change: $denied", 0, 0);

            return ActionResult::fail(403, 'forbidden', $denied);
        }
        if ($needManage) {
            if (($e = $this->role($who, RmmAbility::CHANGE_MANAGE)) !== null) {
                $this->audit->record('Change Monitor Settings Denied', "User {$who->userId} denied a change-monitoring settings change", 0, 0);

                return $e;
            }
            if ($this->authz->visibleClientIds($who->userId) !== null) {
                return ActionResult::fail(403, 'forbidden', 'Watches apply to every client, so changing them needs access to all clients.');
            }
        }
        $features = $this->settings->features();
        $changed = [];
        if (array_key_exists('features', $in)) {
            $f = $in['features'];
            if (!is_array($f) || array_diff(array_keys($f), [ChangeProtocol::FEATURE]) !== [] || !is_bool($f[ChangeProtocol::FEATURE] ?? null)) {
                return ActionResult::fail(422, 'invalid', 'features takes change_monitor, true or false.');
            }
            if ($features[ChangeProtocol::FEATURE] !== $f[ChangeProtocol::FEATURE]) {
                $changed[ChangeProtocol::FEATURE] = $f[ChangeProtocol::FEATURE];
            }
        }
        if (($changed[ChangeProtocol::FEATURE] ?? false) === true && empty($in['accept_change_storage'])) {
            return ActionResult::fail(422, 'confirmation_required', ChangeSettings::STORAGE_WARNING . ' Send accept_change_storage: true to turn change monitoring on.');
        }
        $errors = $this->change->update(array_intersect_key($in, array_flip(['presets', 'custom', 'limits'])), $who->userId);
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
        foreach (['presets', 'custom', 'limits'] as $k) {
            if (array_key_exists($k, $in)) {
                $what[] = "$k updated";
            }
        }
        $this->audit->record('Change Monitor Settings Changed', "{$who->userName}: " . ($what === [] ? 'no change' : implode(', ', $what)), 0, 0);
        $this->settings->refresh();
        $this->change->refresh();

        return ActionResult::ok('Saved.', 200, 'saved', $this->settingsView());
    }

    // ------------------------------------------------------------------ rules

    public function listRules(RmmPrincipal $who): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::CHANGE_VIEW)) !== null) {
            return $e;
        }

        return ActionResult::ok('ok', 200, 'ok', ['data' => $this->rules->all($this->authz->visibleClientIds($who->userId))]);
    }

    public function getRule(RmmPrincipal $who, int $id): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::CHANGE_VIEW)) !== null) {
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
        $this->audit->record('Change Rule Created', "{$who->userName} created change rule #$id: " . (is_string($in['name'] ?? null) ? mb_substr(ChangeProtocol::text($in['name'], 100), 0, 100) : ''), (int) ($in['client_id'] ?? 0), 0);

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
        $this->audit->record('Change Rule Changed', "{$who->userName} changed change rule #$id", (int) $cur['client_id'], 0);

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
        $this->audit->record('Change Rule Deleted', "{$who->userName} deleted change rule #$id", (int) $cur['client_id'], 0);

        return ActionResult::ok('Deleted. Alerts it had open close at the next housekeeping run.');
    }

    /**
     * Create the recommended rules that do not exist yet (see {@see ChangeRuleService::installRecommended()}).
     *
     * @param array<string,mixed> $in client_id
     */
    public function installRecommendedRules(RmmPrincipal $who, array $in): ActionResult
    {
        if (array_diff(array_keys($in), ['client_id']) !== []) {
            return ActionResult::fail(422, 'invalid', 'Only client_id is accepted.');
        }
        $client = $in['client_id'] ?? 0;
        if (!is_int($client) || $client < 0) {
            return ActionResult::fail(422, 'invalid', 'client_id must be 0 (all clients) or a client id.');
        }
        if (($e = $this->manage($who, $client)) !== null) {
            return $e;
        }
        $r = $this->rules->installRecommended($who->userId, $client);
        $this->audit->record('Change Rules Recommended', "{$who->userName} installed " . count($r['created']) . ' recommended change rules', $client, 0);

        return ActionResult::ok(count($r['created']) . ' rules created.', 201, 'created', $r + ['data' => $this->rules->all($this->authz->visibleClientIds($who->userId))]);
    }

    /** @return array<string,mixed>|null */
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
        if (($e = $this->role($who, RmmAbility::CHANGE_MANAGE)) !== null) {
            return $e;
        }
        if ($clientId === 0) {
            return $this->authz->visibleClientIds($who->userId) === null ? null : ActionResult::fail(403, 'forbidden', 'A rule for all clients needs access to all clients.');
        }
        if (!$this->authz->clientOk($who->userId, $clientId)) {
            return ActionResult::fail(404, 'not_found', 'Client not found.');
        }
        $denied = $this->authz->check($who->userId, RmmAbility::CHANGE_MANAGE, $clientId);

        return $denied === null ? null : ActionResult::fail(403, 'forbidden', $denied);
    }
}
