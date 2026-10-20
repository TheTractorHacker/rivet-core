<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Policy\StarterPack;

use RivetCore\Rmm\Alerting\MaintenanceService;
use RivetCore\Rmm\Change\ChangeSettings;
use RivetCore\Rmm\Patch\PatchPolicyStore;
use RivetCore\Rmm\Policy\PolicyStore;

/**
 * Lists, installs, updates, resets and removes the items of the {@see PolicyPack}. No table: a pack entity is recognised by the marker in its description
 * (note for a maintenance window), and an edit by an administrator by the hash in that marker.
 *
 * States: `not_installed`, `installed` (untouched, current), `update_available` (untouched, the pack has a newer revision), `edited` (an administrator
 * changed name, description or settings: never overwritten by install or update, `reset` takes the pack version back). Every entity is created switched off
 * and assigned to nothing; an update never reaches a live (enabled or assigned) entity unless `include_live` is passed.
 *
 * Outcomes: added, updated, reset, removed, unchanged, skipped (with a reason), blocked (with a reason), failed.
 *
 * @api
 */
final class PolicyPackInstaller
{
    public const OPS = ['install', 'update', 'reset', 'uninstall'];

    public function __construct(
        private readonly PolicyStore $policies,
        private readonly PatchPolicyStore $patch,
        private readonly MaintenanceService $windows,
        private readonly ChangeSettings $change,
        private readonly ?PolicyPack $pack = null,
    ) {
    }

    public function pack(): PolicyPack
    {
        return $this->pack ?? PolicyPack::bundled();
    }

    /**
     * The read model: every item with its state in this instance. Never contains the setting bodies of other entities, only the pack's own data.
     *
     * @return array<string,mixed>
     */
    public function catalog(): array
    {
        $found = $this->found();
        $items = [];
        $counts = ['not_installed' => 0, 'installed' => 0, 'update_available' => 0, 'edited' => 0];
        foreach ($this->pack()->items() as $it) {
            $s = $this->stateOf($it, $found[$it['id']] ?? null);
            ++$counts[$s['state']];
            $items[] = [
                'id' => $it['id'], 'kind' => $it['kind'], 'group' => $it['group'], 'name' => $it['name'], 'platform' => $it['platform'], 'rev' => $it['rev'],
                'summary' => $it['summary'], 'why' => $it['why'], 'why_settings' => $it['why_settings'],
                'params' => $it['params'] ?? new \stdClass(), 'instance_wide' => $it['kind'] === 'change_presets',
            ] + $s;
        }

        return ['pack_id' => $this->pack()->id(), 'name' => $this->pack()->name(), 'version' => $this->pack()->version(), 'counts' => $counts, 'items' => $items];
    }

    /**
     * @param array<string,mixed> $sel {ids?: list<string> (default all), params?: {itemId: {name: value}}, include_live?: bool, confirm_instance_wide?: bool}
     * @return array<string,mixed> {op, dry_run, counts, items: list<{id,kind,name,outcome,reason?,entity_id?}>}
     */
    public function run(string $op, array $sel, int $userId, bool $dryRun = false): array
    {
        if (!in_array($op, self::OPS, true)) {
            throw new \InvalidArgumentException('Unknown operation.');
        }
        $ids = $this->ids($sel['ids'] ?? null);
        $found = $this->found();
        $out = [];
        foreach ($ids as $id) {
            $it = $this->pack()->item($id);
            if ($it === null) {
                continue;
            }
            $params = $sel['params'][$id] ?? [];
            try {
                $r = $this->one($op, $it, $found[$id] ?? null, is_array($params) ? $params : [], $sel, $userId, $dryRun);
            } catch (\InvalidArgumentException $e) {
                $r = ['outcome' => 'blocked', 'reason' => $e->getMessage()];
            } catch (\Throwable) {
                $r = ['outcome' => 'failed', 'reason' => 'Could not be applied; see the server log.'];
            }
            $out[] = ['id' => $id, 'kind' => $it['kind'], 'name' => $it['name'], 'rev' => $it['rev']] + $r;
        }
        $counts = [];
        foreach ($out as $o) {
            $counts[$o['outcome']] = ($counts[$o['outcome']] ?? 0) + 1;
        }

        return ['op' => $op, 'dry_run' => $dryRun, 'pack_id' => $this->pack()->id(), 'pack_version' => $this->pack()->version(), 'counts' => $counts, 'items' => $out];
    }

    // ------------------------------------------------------------------ one item

    /**
     * @param array<string,mixed> $it
     * @param array<string,mixed>|null $ent the entity found for the item
     * @param array<string,mixed> $params
     * @param array<string,mixed> $sel
     * @return array<string,mixed>
     */
    private function one(string $op, array $it, ?array $ent, array $params, array $sel, int $userId, bool $dry): array
    {
        $st = $this->stateOf($it, $ent);
        $state = $st['state'];
        $live = $ent !== null && ($ent['enabled'] || $ent['assignments'] > 0);
        $isChange = $it['kind'] === 'change_presets';

        if ($op === 'install') {
            if ($state !== 'not_installed') {
                return ['outcome' => 'unchanged', 'entity_id' => $ent['id'] ?? null, 'state' => $state];
            }
            if ($isChange && empty($sel['confirm_instance_wide'])) {
                return ['outcome' => 'blocked', 'reason' => 'This changes an instance-wide setting for all devices; confirm it with confirm_instance_wide.'];
            }
            $r = PolicyPack::resolve($it, $params);
            if ($this->nameTaken($it, $r['name'])) {
                return ['outcome' => 'blocked', 'reason' => 'A policy with this name already exists that is not from the pack; rename it first.'];
            }
            $this->requireRings($it);
            if ($dry) {
                return ['outcome' => 'added'];
            }

            return ['outcome' => 'added', 'entity_id' => $this->create($it, $r, $userId)];
        }
        if ($ent === null && !$isChange) {
            return ['outcome' => 'skipped', 'reason' => 'Not installed.'];
        }
        if ($isChange) {
            return $op === 'uninstall'
                ? ['outcome' => 'skipped', 'reason' => 'An instance-wide setting: turn the presets off in the change monitoring settings.']
                : ['outcome' => 'skipped', 'reason' => $state === 'installed' ? 'Already on.' : 'Use install.'];
        }
        if ($op === 'update') {
            if ($state === 'edited') {
                return ['outcome' => 'skipped', 'reason' => 'Edited by an administrator; use reset to take the pack version.'];
            }
            if ($state !== 'update_available') {
                return ['outcome' => 'unchanged', 'entity_id' => $ent['id']];
            }
            if ($live && empty($sel['include_live'])) {
                return ['outcome' => 'skipped', 'reason' => 'In use (enabled or assigned): updating changes devices without a review. Pass include_live to update it anyway.'];
            }
        }
        if ($op === 'uninstall') {
            if ($state === 'edited') {
                return ['outcome' => 'skipped', 'reason' => 'Edited by an administrator; kept.'];
            }
            if ($ent['assignments'] > 0 || ($it['kind'] === 'maintenance_window' && $ent['enabled'])) {
                return ['outcome' => 'skipped', 'reason' => $it['kind'] === 'maintenance_window' ? 'Switched on; switch it off first.' : 'Assigned to devices; unassign it first.'];
            }
            if (!$dry) {
                $this->delete($it, (int) $ent['id']);
            }

            return ['outcome' => 'removed', 'entity_id' => $ent['id']];
        }
        // update and reset write the pack version over the entity (reset also over an edited one)
        $r = PolicyPack::resolve($it, $params);
        $this->requireRings($it);
        if (!$dry) {
            $this->rewrite($it, $r, (int) $ent['id'], $userId, $ent);
        }

        return ['outcome' => $op === 'reset' ? 'reset' : 'updated', 'entity_id' => $ent['id']];
    }

    // ------------------------------------------------------------------ state

    /**
     * @param array<string,mixed> $it
     * @param array<string,mixed>|null $ent
     * @return array<string,mixed>
     */
    private function stateOf(array $it, ?array $ent): array
    {
        if ($it['kind'] === 'change_presets') {
            $on = $this->change->summary()['presets'];
            $enabled = [];
            foreach ($on as $p) {
                if ($p['enabled']) {
                    $enabled[$p['id']] = true;
                }
            }
            $all = true;
            foreach ($it['body']['presets'] as $ids) {
                foreach ($ids as $pid) {
                    $all = $all && isset($enabled[$pid]);
                }
            }

            return ['state' => $all ? 'installed' : 'not_installed', 'entity_id' => null, 'enabled' => $all, 'assignments' => 0, 'edited' => false, 'update_available' => false];
        }
        if ($ent === null) {
            return ['state' => 'not_installed', 'entity_id' => null, 'enabled' => false, 'assignments' => 0, 'edited' => false, 'update_available' => false];
        }
        $edited = !hash_equals($ent['marker_hash'], PolicyPack::hash($ent['content']));
        $upd = $ent['marker_rev'] < $it['rev'];
        $state = $edited ? 'edited' : ($upd ? 'update_available' : 'installed');

        return ['state' => $state, 'entity_id' => $ent['id'], 'enabled' => $ent['enabled'], 'assignments' => $ent['assignments'], 'edited' => $edited, 'update_available' => $upd, 'installed_rev' => $ent['marker_rev']];
    }

    /**
     * Every pack entity in the instance, by item id (the one with the lowest id when an administrator copied one with its marker).
     *
     * @return array<string,array<string,mixed>>
     */
    private function found(): array
    {
        $out = [];
        $add = function (string $itemId, array $e) use (&$out): void {
            if (!isset($out[$itemId]) || $e['id'] < $out[$itemId]['id']) {
                $out[$itemId] = $e;
            }
        };
        foreach ($this->policies->all() as $p) {
            $m = PolicyPack::parseMarker((string) $p['description']);
            if ($m === null) {
                continue;
            }
            $s = $p['settings'] instanceof \stdClass ? [] : (array) $p['settings'];
            $add($m[0], ['id' => (int) $p['policy_id'], 'enabled' => (bool) $p['enabled'], 'assignments' => (int) $p['assignments'], 'marker_rev' => $m[1], 'marker_hash' => $m[2],
                'content' => ['name' => (string) $p['name'], 'description' => PolicyPack::stripMarker((string) $p['description']), 'settings' => $s]]);
        }
        foreach ($this->patch->all() as $p) {
            $m = PolicyPack::parseMarker((string) $p['description']);
            if ($m === null) {
                continue;
            }
            $s = $p['settings'] instanceof \stdClass ? [] : (array) $p['settings'];
            $add($m[0], ['id' => (int) $p['policy_id'], 'enabled' => (bool) $p['enabled'], 'assignments' => count($this->patch->assignments((int) $p['policy_id'])), 'marker_rev' => $m[1], 'marker_hash' => $m[2],
                'content' => ['name' => (string) $p['name'], 'description' => PolicyPack::stripMarker((string) $p['description']), 'settings' => $s]]);
        }
        foreach ($this->windows->all() as $w) {
            $m = PolicyPack::parseMarker((string) $w['note']);
            if ($m === null) {
                continue;
            }
            $add($m[0], ['id' => (int) $w['window_id'], 'enabled' => (bool) $w['enabled'], 'assignments' => 0, 'marker_rev' => $m[1], 'marker_hash' => $m[2],
                'content' => ['name' => (string) $w['name'], 'description' => PolicyPack::stripMarker((string) $w['note']), 'window' => self::windowContent($w)]]);
        }

        return $out;
    }

    /**
     * The part of a window the pack owns, from a stored (presented) window or a validated row. The time zone and the on/off switch belong to the administrator.
     *
     * @param array<string,mixed> $w
     * @return array<string,mixed>
     */
    private static function windowContent(array $w): array
    {
        $o = [];
        foreach (['name', 'mode', 'scope_type', 'kind', 'recur_freq', 'recur_days', 'local_start'] as $k) {
            $o[$k] = (string) ($w[$k] ?? '');
        }
        foreach (['scope_id', 'recur_interval', 'duration_min'] as $k) {
            $o[$k] = (int) ($w[$k] ?? 0);
        }

        return $o;
    }

    // ------------------------------------------------------------------ writes

    /**
     * @param array<string,mixed> $it
     * @param array{name:string,description:string,content:array<string,mixed>} $r
     */
    private function create(array $it, array $r, int $userId): int
    {
        $note = $r['description'] . PolicyPack::marker($it['id'], (int) $it['rev'], PolicyPack::hash($this->comparable($it, $r)));
        switch ($it['kind']) {
            case 'check_policy':
            case 'alert_preset':
                $p = $this->policies->create(['name' => $r['name'], 'description' => $note, 'settings' => $r['content']['settings']], $userId);
                $this->policies->update((int) $p['policy_id'], ['enabled' => false], $userId);

                return (int) $p['policy_id'];
            case 'patch_policy':
                $p = $this->patch->save(null, ['name' => $r['name'], 'description' => $note, 'enabled' => false, 'settings' => $r['content']['settings']], $userId);

                return (int) $p['policy_id'];
            case 'maintenance_window':
                $w = $this->windows->create($this->windowInput($r, $note, null), $userId);

                return (int) $w['window_id'];
        }
        // change presets: add to what is on (never removes anything an administrator turned on)
        $this->applyChange($it, $userId);

        return 0;
    }

    /**
     * @param array<string,mixed> $it
     * @param array{name:string,description:string,content:array<string,mixed>} $r
     * @param array<string,mixed> $ent
     */
    private function rewrite(array $it, array $r, int $id, int $userId, array $ent): void
    {
        $note = $r['description'] . PolicyPack::marker($it['id'], (int) $it['rev'], PolicyPack::hash($this->comparable($it, $r)));
        switch ($it['kind']) {
            case 'check_policy':
            case 'alert_preset':
                $this->policies->update($id, ['name' => $r['name'], 'description' => $note, 'settings' => $r['content']['settings']], $userId);
                break;
            case 'patch_policy':
                $this->patch->save($id, ['name' => $r['name'], 'description' => $note, 'settings' => $r['content']['settings']], $userId);
                break;
            case 'maintenance_window':
                $cur = null;
                foreach ($this->windows->all() as $w) {
                    if ((int) $w['window_id'] === $id) {
                        $cur = $w;
                    }
                }
                $this->windows->update($id, $this->windowInput($r, $note, $cur === null ? null : (string) $cur['timezone']));
                break;
        }
    }

    /**
     * @param array{name:string,description:string,content:array<string,mixed>} $r
     * @return array<string,mixed>
     */
    private function windowInput(array $r, string $note, ?string $keepTimezone): array
    {
        $w = $r['content']['window'];
        $in = ['name' => $w['name'], 'mode' => $w['mode'], 'scope_type' => $w['scope_type'], 'scope_id' => $w['scope_id'], 'kind' => $w['kind'], 'timezone' => $keepTimezone ?? $w['timezone'], 'note' => $note,
            'recur_freq' => $w['recur_freq'], 'recur_interval' => $w['recur_interval'], 'recur_days' => $w['recur_days'], 'local_start' => $w['local_start'], 'duration_min' => $w['duration_min']];
        if ($keepTimezone === null) {
            $in['enabled'] = false;   // new windows are created switched off; an update keeps the switch
        }

        return $in;
    }

    /**
     * What the hash covers: the stored form of the entity (settings as the store returns them, window as the stored fields).
     *
     * @param array<string,mixed> $it
     * @param array{name:string,description:string,content:array<string,mixed>} $r
     * @return array<string,mixed>
     */
    private function comparable(array $it, array $r): array
    {
        $c = ['name' => $r['name'], 'description' => $r['description']];
        if (isset($r['content']['settings'])) {
            $c['settings'] = $r['content']['settings'];
        }
        if (isset($r['content']['window'])) {
            $c['window'] = self::windowContent($r['content']['window']);
        }

        return $c;
    }

    /** @param array<string,mixed> $it */
    private function delete(array $it, int $id): void
    {
        match ($it['kind']) {
            'patch_policy' => $this->patch->delete($id),
            'maintenance_window' => $this->windows->delete($id),
            default => $this->policies->delete($id),
        };
    }

    /** @param array<string,mixed> $it */
    private function applyChange(array $it, int $userId): void
    {
        $presets = [];
        foreach (['windows', 'linux'] as $os) {
            $presets[$os] = array_values(array_unique(array_merge($this->change->enabledPresets($os), $it['body']['presets'][$os] ?? [])));
        }
        $err = $this->change->update(['presets' => $presets], $userId);
        if ($err !== []) {
            throw new \InvalidArgumentException($err[0]);
        }
    }

    /** @param array<string,mixed> $it */
    private function requireRings(array $it): void
    {
        if ($it['kind'] !== 'patch_policy') {
            return;
        }
        $ring = $it['body']['settings']['ring'] ?? null;
        if (is_string($ring) && !in_array($ring, $this->patch->ringNames(), true)) {
            throw new \InvalidArgumentException("The patch ring \"$ring\" does not exist; create it first (Patching > Rings).");
        }
    }

    /** @param array<string,mixed> $it */
    private function nameTaken(array $it, string $name): bool
    {
        if ($it['kind'] === 'change_presets') {
            return false;
        }
        $list = match ($it['kind']) {
            'patch_policy' => $this->patch->all(),
            'maintenance_window' => $this->windows->all(),
            default => $this->policies->all(),
        };
        foreach ($list as $p) {
            if (mb_strtolower((string) $p['name']) === mb_strtolower($name)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function ids(mixed $ids): array
    {
        $all = array_map(static fn (array $i): string => (string) $i['id'], $this->pack()->items());
        if ($ids === null) {
            return $all;
        }
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 100) {
            throw new \InvalidArgumentException('ids must be a list of item ids.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !in_array($id, $all, true)) {
                throw new \InvalidArgumentException('Unknown item id ' . (is_string($id) ? mb_substr($id, 0, 60) : '') . '.');
            }
        }

        return array_values(array_unique($ids));
    }
}
