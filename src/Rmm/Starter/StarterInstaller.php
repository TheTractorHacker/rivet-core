<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Starter;

use RivetCore\Rmm\Policy\PolicyStore;
use RivetCore\Rmm\Scripts\ScriptService;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Installs a {@see StarterPack} into the instance through the script library ({@see ScriptService}) and the policy store ({@see PolicyStore}). It adds
 * no table: what came from a pack is recognised by a marker the installer writes into data those services already keep.
 *
 *   script   the `note` of the version it published: `starter|<pack id>|<item id>|<pack version>|<content hash>` (an update the installer parked for
 *            an administrator to read is `starter-updated|...`), and the tags `starter` and `risk:<class>`
 *   policy   the end of the description: ` [starter|<pack id>|<item id>|<pack version>|<settings hash>]`
 *
 * Rules, all of which {@see plan()} shows before anything is written:
 *
 *  - Idempotent: an item is identified by (pack id, item id) and compared by content hash. Installing twice changes nothing the second time.
 *  - Scripts are signed with the instance key when they are published (the library does that). They go in active, with `requires_approval` and the
 *    `destructive` flag from their risk class; the library has no drafts, and a script is only ever run by a person.
 *  - A script whose current version is still exactly what the installer published is updated to the new version. A script an administrator changed (a
 *    new version, or a hash that no longer matches) is NEVER replaced: the new text is parked as a further version flagged `starter-updated` and the
 *    script keeps running what it ran. A script an administrator retired stays retired.
 *  - Policies are drafts: created switched off and unassigned, so they affect no device until an administrator reviews, enables and assigns them. A
 *    policy whose settings were edited, or that is enabled or assigned, is left alone when the pack has a newer version of it.
 *  - Uninstall retires scripts and deletes policies only while they are untouched (and, for a policy, off and unassigned).
 *
 * Every method throws \InvalidArgumentException with a message that is safe to show for a bad selection.
 *
 * @api
 */
final class StarterInstaller
{
    public const SCRIPT_MARK = 'starter';
    public const STAGED_MARK = 'starter-updated';
    private const POLICY_RE = '/ \[starter\|([a-z0-9-]+)\|([a-z0-9-]+)\|(\d+\.\d+\.\d+)\|([0-9a-f]{64})\]$/';
    private const NOTE_RE = '/^(starter|starter-updated)\|([a-z0-9-]+)\|([a-z0-9-]+)\|(\d+\.\d+\.\d+)\|([0-9a-f]{64})$/';
    private const SELECT_KEYS = ['pack', 'ids', 'tags', 'platform', 'risk', 'kinds', 'params', 'policy_params', 'q'];

    /** @var list<StarterPack> */
    private array $packs;

    /** @param list<StarterPack>|null $packs null = the bundled pack */
    public function __construct(
        private readonly Sql $sql,
        private readonly ScriptService $scripts,
        private readonly PolicyStore $policies,
        private readonly RmmSettings $settings,
        ?array $packs = null,
    ) {
        $this->packs = $packs ?? [StarterPack::bundled()];
    }

    /** @return list<StarterPack> */
    public function packs(): array
    {
        return $this->packs;
    }

    // ------------------------------------------------------------------ browse

    /**
     * What the packs offer, with each item's state in this instance (`not_installed`, `current`, `update_available`, `modified`, `update_staged`,
     * `retired`) and the script or policy it became. Filters: `pack`, `platform`, `risk`, `tags`, `q`.
     *
     * @param array<string,mixed> $filter
     * @return array<string,mixed>
     */
    public function catalog(array $filter = []): array
    {
        $sel = $this->selection($filter, false);
        $markers = $this->scriptMarkers();
        $pol = $this->policyMarkers();
        $packs = [];
        $totals = ['scripts' => 0, 'installed' => 0, 'policies' => 0];
        foreach ($this->packs as $pack) {
            if ($sel['pack'] !== null && $sel['pack'] !== $pack->id()) {
                continue;
            }
            $cat = $pack->catalog();
            $scripts = [];
            foreach ($cat['scripts'] as $s) {
                if (!$this->scriptMatches($s, $sel, false)) {
                    continue;
                }
                $st = $this->scriptState($pack, $s, $markers);
                $scripts[] = $s + ['state' => $st['state'], 'script_id' => $st['script_id'], 'installed_version' => $st['version']];
                ++$totals['scripts'];
                $totals['installed'] += $st['state'] === 'not_installed' ? 0 : 1;
            }
            $policies = [];
            foreach ($cat['policies'] as $p) {
                if (!$this->policyMatches($p, $sel, false)) {
                    continue;
                }
                $st = $this->policyState($pack, (array) $pack->policy((string) $p['id']), $pol, [], false);
                $policies[] = $p + ['state' => $st['state'], 'policy_id' => $st['policy_id'], 'enabled' => $st['enabled'], 'assignments' => $st['assignments']];
                ++$totals['policies'];
            }
            $packs[] = ['pack' => $cat['pack'], 'counts' => $cat['counts'], 'scripts' => $scripts, 'check_sets' => $cat['check_sets'], 'policies' => $policies];
        }

        return ['packs' => $packs, 'totals' => $totals, 'ready' => $this->blocker() === null, 'blocker' => $this->blocker()];
    }

    // ------------------------------------------------------------------ plan and install

    /**
     * A dry run: what an install of this selection would add, update, park for review or skip, and why. Writes nothing.
     *
     * @param array<string,mixed> $selection {pack?, ids?, tags?, platform?, risk?, kinds?, params?, policy_params?}
     * @return array<string,mixed> {ready, blocker, items: [...], counts: {...}}
     */
    public function plan(array $selection = []): array
    {
        $items = $this->planItems($this->selection($selection, true));

        return ['ready' => $this->blocker() === null, 'blocker' => $this->blocker(), 'items' => array_map(static fn (array $i): array => self::publicItem($i), $items), 'counts' => self::count($items)];
    }

    /**
     * Do what {@see plan()} shows. An item that fails does not stop the others; its outcome is `failed` with a safe message.
     *
     * @param array<string,mixed> $selection
     * @return array<string,mixed> {items: [{..., outcome, message}], counts}
     */
    public function install(array $selection, int $userId): array
    {
        $items = $this->planItems($this->selection($selection, true));
        $done = [];
        foreach ($items as $i) {
            $done[] = self::publicItem($this->apply($i, $userId));
        }
        $counts = [];
        foreach ($done as $d) {
            $counts[$d['outcome']] = ($counts[$d['outcome']] ?? 0) + 1;
        }
        ksort($counts);

        return ['items' => $done, 'counts' => $counts];
    }

    /**
     * Retire the untouched starter scripts and delete the untouched, switched-off, unassigned starter policies of a selection.
     *
     * @param array<string,mixed> $selection
     * @return array<string,mixed>
     */
    public function uninstall(array $selection, int $userId, bool $dryRun = false): array
    {
        $sel = $this->selection($selection, true);
        $markers = $this->scriptMarkers();
        $pol = $this->policyMarkers();
        $out = [];
        foreach ($this->selected($sel) as [$pack, $kind, $item]) {
            if ($kind === 'script') {
                $st = $this->scriptState($pack, $item, $markers);
                $row = ['kind' => 'script', 'pack' => $pack->id(), 'id' => $item['id'], 'name' => $item['name'], 'script_id' => $st['script_id']];
                if ($st['script_id'] === null) {
                    $out[] = $row + ['outcome' => 'skipped', 'message' => 'Not installed.'];
                } elseif ($st['state'] === 'retired') {
                    $out[] = $row + ['outcome' => 'skipped', 'message' => 'Already retired.'];
                } elseif (!$st['untouched']) {
                    $out[] = $row + ['outcome' => 'skipped', 'message' => 'An administrator changed this script, so it was left alone.'];
                } elseif ($dryRun) {
                    $out[] = $row + ['outcome' => 'would_retire', 'message' => 'Would be retired.'];
                } else {
                    $this->scripts->setRetired((int) $st['script_id'], true);
                    $out[] = $row + ['outcome' => 'retired', 'message' => 'Retired (its history stays).'];
                }
            } else {
                $st = $this->policyState($pack, $item, $pol, [], false);
                $row = ['kind' => 'policy', 'pack' => $pack->id(), 'id' => $item['id'], 'name' => $item['name'], 'policy_id' => $st['policy_id']];
                if ($st['policy_id'] === null) {
                    $out[] = $row + ['outcome' => 'skipped', 'message' => 'Not installed.'];
                } elseif (!$st['untouched'] || $st['enabled'] || $st['assignments'] > 0) {
                    $out[] = $row + ['outcome' => 'skipped', 'message' => 'The policy was edited, is switched on or is assigned, so it was left alone.'];
                } elseif ($dryRun) {
                    $out[] = $row + ['outcome' => 'would_delete', 'message' => 'Would be deleted.'];
                } else {
                    $this->policies->delete((int) $st['policy_id']);
                    $out[] = $row + ['outcome' => 'deleted', 'message' => 'Deleted.'];
                }
            }
        }
        $counts = [];
        foreach ($out as $d) {
            $counts[$d['outcome']] = ($counts[$d['outcome']] ?? 0) + 1;
        }
        ksort($counts);

        return ['items' => $out, 'counts' => $counts];
    }

    // ------------------------------------------------------------------ planning

    /**
     * @param array<string,mixed> $sel a normalised selection
     * @return list<array<string,mixed>>
     */
    private function planItems(array $sel): array
    {
        $markers = $this->scriptMarkers();
        $pol = $this->policyMarkers();
        $blocker = $this->blocker();
        $taken = [];
        $out = [];
        foreach ($this->selected($sel) as [$pack, $kind, $item]) {
            $base = ['kind' => $kind, 'pack' => $pack->id(), 'pack_version' => $pack->version(), 'id' => $item['id'], 'name' => $item['name'], 'platform' => $item['platform']];
            if ($kind === 'script') {
                $base += ['risk' => $item['risk'], '_item' => $item, '_pack' => $pack];
                $st = $this->scriptState($pack, $item, $markers);
                $base += ['script_id' => $st['script_id'], 'current_version' => $st['version']];
                switch ($st['state']) {
                    case 'current':
                        $out[] = $base + ['action' => 'unchanged', 'reason' => 'Already installed at this content.'];
                        break;
                    case 'retired':
                        $out[] = $base + ['action' => 'skip', 'reason' => 'An administrator retired this script; it stays retired.'];
                        break;
                    case 'update_staged':
                        $out[] = $base + ['action' => 'skip', 'reason' => 'The update is already parked as version ' . $st['staged'] . ' for review.'];
                        break;
                    case 'update_available':
                        $out[] = $blocker !== null ? $base + ['action' => 'blocked', 'reason' => $blocker] : $base + ['action' => 'update', 'reason' => 'The pack has a newer version and this script is untouched.'];
                        break;
                    case 'modified':
                        $out[] = $blocker !== null ? $base + ['action' => 'blocked', 'reason' => $blocker]
                            : $base + ['action' => 'stage', 'reason' => 'An administrator changed this script. The new text is added as a further version flagged starter-updated and the script keeps running what it ran.'];
                        break;
                    default:
                        $name = (string) $item['name'];
                        $use = $this->freeName($name, $taken);
                        if ($blocker !== null) {
                            $out[] = $base + ['action' => 'blocked', 'reason' => $blocker];
                        } elseif ($use === null) {
                            $out[] = $base + ['action' => 'blocked', 'reason' => 'A script with this name exists and so does "' . $name . ' (starter)".'];
                        } else {
                            $taken[mb_strtolower($use)] = 1;
                            $out[] = $base + ['action' => 'add', 'reason' => $use === $name ? 'Not installed.' : 'Not installed (a script with this name exists, so it is added as "' . $use . '").', 'use_name' => $use];
                        }
                }
                continue;
            }
            $base += ['_item' => $item, '_pack' => $pack, 'risk' => null];
            $params = $this->policyParams($sel, (string) $item['id']);
            try {
                $resolved = $pack->resolvePolicy($item, $params);
            } catch (\InvalidArgumentException $e) {
                $out[] = $base + ['action' => 'blocked', 'reason' => $e->getMessage()];
                continue;
            }
            $st = $this->policyState($pack, $item, $pol, $resolved['settings'], true);
            $base += ['policy_id' => $st['policy_id'], '_resolved' => $resolved, 'omitted' => $resolved['omitted']];
            switch ($st['state']) {
                case 'current':
                    $out[] = $base + ['action' => 'unchanged', 'reason' => 'Already installed at this content.'];
                    break;
                case 'modified':
                    $out[] = $base + ['action' => 'skip', 'reason' => 'An administrator edited this policy; it is left alone.'];
                    break;
                case 'in_use':
                    $out[] = $base + ['action' => 'skip', 'reason' => 'The pack has a newer version, but the policy is switched on or assigned, so it was not changed. Switch it off and unassign it (or delete it) and install again.'];
                    break;
                case 'update_available':
                    $out[] = $base + ['action' => 'update', 'reason' => 'The pack has a newer version (or other parameter values) and the draft is untouched, off and unassigned.'];
                    break;
                default:
                    $exists = $this->sql->one('SELECT policy_id FROM rmm_policies WHERE name = ?', [$item['name']]);
                    $out[] = $exists !== null ? $base + ['action' => 'blocked', 'reason' => 'A policy with this name exists.']
                        : $base + ['action' => 'add', 'reason' => 'Not installed. Created switched off and unassigned.'];
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $i a planned item
     * @return array<string,mixed> the item with outcome and message
     */
    private function apply(array $i, int $userId): array
    {
        $action = (string) $i['action'];
        $map = ['unchanged' => 'unchanged', 'skip' => 'skipped', 'blocked' => 'blocked'];
        if (isset($map[$action])) {
            return array_merge($i, ['outcome' => $map[$action], 'message' => (string) $i['reason']]);
        }
        try {
            return $i['kind'] === 'script' ? $this->applyScript($i, $userId) : $this->applyPolicy($i, $userId);
        } catch (\InvalidArgumentException $e) {
            return array_merge($i, ['outcome' => 'failed', 'message' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,mixed> $i
     * @return array<string,mixed>
     */
    private function applyScript(array $i, int $userId): array
    {
        /** @var array<string,mixed> $s */
        $s = $i['_item'];
        /** @var StarterPack $pack */
        $pack = $i['_pack'];
        $note = self::note(self::SCRIPT_MARK, $pack->id(), (string) $s['id'], $pack->version(), (string) $s['content_hash']);
        if ($i['action'] === 'add') {
            $r = $this->scripts->create(['name' => $i['use_name'], 'description' => $s['description'], 'language' => $s['language'], 'body' => $s['body'], 'params' => $s['params'],
                'tags' => array_merge(['starter', 'risk:' . $s['risk']], $s['tags']), 'requires_approval' => $s['requires_approval'], 'destructive' => $s['destructive'],
                'timeout_s' => $s['timeout_s'], 'note' => $note], $userId);

            return array_merge($i, ['outcome' => 'added', 'message' => 'Installed.', 'script_id' => (int) $r['script_id'], 'version' => (int) $r['current_version']]);
        }
        $id = (int) $i['script_id'];
        if ($i['action'] === 'update') {
            $r = $this->scripts->update($id, ['body' => $s['body'], 'params' => $s['params'], 'note' => $note], $userId);

            return array_merge($i, ['outcome' => 'updated', 'message' => 'Updated to pack version ' . $pack->version() . '.', 'version' => (int) $r['current_version']]);
        }
        $v = $this->scripts->stage($id, ['body' => $s['body'], 'params' => $s['params'], 'note' => self::note(self::STAGED_MARK, $pack->id(), (string) $s['id'], $pack->version(), (string) $s['content_hash'])], $userId);

        return array_merge($i, ['outcome' => 'staged', 'message' => "Added as version $v, flagged starter-updated, not current.", 'version' => $v]);
    }

    /**
     * @param array<string,mixed> $i
     * @return array<string,mixed>
     */
    private function applyPolicy(array $i, int $userId): array
    {
        /** @var array<string,mixed> $p */
        $p = $i['_item'];
        /** @var StarterPack $pack */
        $pack = $i['_pack'];
        /** @var array{settings:array<string,array{mode:string,value:mixed}>,omitted:list<string>,params:array<string,mixed>} $res */
        $res = $i['_resolved'];
        $desc = $p['description'] . self::policyMarker($pack->id(), (string) $p['id'], $pack->version(), StarterPack::policyHash($res['settings']));
        if ($i['action'] === 'add') {
            $row = $this->policies->create(['name' => $p['name'], 'description' => $desc, 'settings' => $res['settings']], $userId);
            try {
                $this->policies->update((int) $row['policy_id'], ['enabled' => false], $userId);
            } catch (\Throwable $e) {
                $this->policies->delete((int) $row['policy_id']);
                throw new \InvalidArgumentException('The policy draft could not be switched off, so it was not created.');
            }

            return array_merge($i, ['outcome' => 'added', 'message' => 'Created as a draft: switched off, not assigned.', 'policy_id' => (int) $row['policy_id']]);
        }
        $this->policies->update((int) $i['policy_id'], ['description' => $desc, 'settings' => $res['settings']], $userId);

        return array_merge($i, ['outcome' => 'updated', 'message' => 'Draft updated to pack version ' . $pack->version() . '.']);
    }

    // ------------------------------------------------------------------ state

    /**
     * @return array<string,array<string,list<array{script_id:int,version:int,kind:string,hash:string}>>> pack id => item id => marker versions
     */
    private function scriptMarkers(): array
    {
        $out = [];
        foreach ($this->sql->all("SELECT script_id, version, note FROM rmm_script_versions WHERE note LIKE 'starter%' ORDER BY script_id, version") as $r) {
            if (preg_match(self::NOTE_RE, (string) $r['note'], $m) === 1) {
                $out[$m[2]][$m[3]][] = ['script_id' => (int) $r['script_id'], 'version' => (int) $r['version'], 'kind' => $m[1], 'hash' => $m[5]];
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,array<string,list<array{script_id:int,version:int,kind:string,hash:string}>>> $markers
     * @return array{state:string,script_id:?int,version:?int,untouched:bool,staged:?int}
     */
    private function scriptState(StarterPack $pack, array $item, array $markers): array
    {
        $none = ['state' => 'not_installed', 'script_id' => null, 'version' => null, 'untouched' => false, 'staged' => null];
        $entries = $markers[$pack->id()][(string) $item['id']] ?? [];
        if ($entries === []) {
            return $none;
        }
        $scriptId = $entries[0]['script_id'];
        $mine = array_values(array_filter($entries, static fn (array $e): bool => $e['script_id'] === $scriptId));
        $s = $this->sql->one('SELECT script_id, language, platform, current_version, retired_at FROM rmm_scripts_v2 WHERE script_id = ?', [$scriptId]);
        if ($s === null) {
            return $none;
        }
        $cur = (int) $s['current_version'];
        $v = $this->sql->one('SELECT body, params_schema_json FROM rmm_script_versions WHERE script_id = ? AND version = ?', [$scriptId, $cur]);
        $marker = null;
        foreach ($mine as $e) {
            if ($e['version'] === $cur && $e['kind'] === self::SCRIPT_MARK) {
                $marker = $e;
            }
        }
        $markerHash = $marker['hash'] ?? '';
        $untouched = $marker !== null && $v !== null
            && hash_equals($markerHash, StarterPack::scriptHash((string) $s['language'], (string) $s['platform'], (string) $v['body'], ScriptService::decodeSchema($v['params_schema_json'])));
        $r = ['script_id' => $scriptId, 'version' => $cur, 'untouched' => $untouched, 'staged' => null];
        if ($s['retired_at'] !== null) {
            return ['state' => 'retired'] + $r;
        }
        $target = (string) $item['content_hash'];
        if ($untouched && hash_equals($markerHash, $target)) {
            return ['state' => 'current'] + $r;
        }
        foreach ($mine as $e) {
            if ($e['kind'] === self::STAGED_MARK && hash_equals($e['hash'], $target)) {
                return ['state' => 'update_staged', 'staged' => $e['version']] + $r;
            }
        }

        return ['state' => $untouched ? 'update_available' : 'modified'] + $r;
    }

    /**
     * @return array<string,array<string,array{policy_id:int,hash:string,enabled:bool,assignments:int,settings_hash:string}>> pack id => item id => marker
     */
    private function policyMarkers(): array
    {
        $out = [];
        foreach ($this->policies->all() as $p) {
            if (preg_match(self::POLICY_RE, (string) $p['description'], $m) !== 1) {
                continue;
            }
            $settings = $p['settings'] instanceof \stdClass ? [] : (array) $p['settings'];
            $out[$m[1]][$m[2]] = ['policy_id' => (int) $p['policy_id'], 'hash' => $m[4], 'enabled' => (bool) $p['enabled'], 'assignments' => (int) $p['assignments'],
                'settings_hash' => StarterPack::policyHash($settings)];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,array<string,array{policy_id:int,hash:string,enabled:bool,assignments:int,settings_hash:string}>> $markers
     * @param array<string,array{mode:string,value:mixed}> $settings what the pack would write ([] = unknown, only the stored state is reported)
     * @return array{state:string,policy_id:?int,untouched:bool,enabled:bool,assignments:int}
     */
    private function policyState(StarterPack $pack, array $item, array $markers, array $settings, bool $withTarget): array
    {
        $m = $markers[$pack->id()][(string) ($item['id'] ?? '')] ?? null;
        if ($m === null) {
            return ['state' => 'not_installed', 'policy_id' => null, 'untouched' => false, 'enabled' => false, 'assignments' => 0];
        }
        $untouched = hash_equals($m['hash'], $m['settings_hash']);
        $r = ['policy_id' => $m['policy_id'], 'untouched' => $untouched, 'enabled' => $m['enabled'], 'assignments' => $m['assignments']];
        if (!$untouched) {
            return ['state' => 'modified'] + $r;
        }
        $target = $withTarget ? StarterPack::policyHash($settings) : StarterPack::policyHash($pack->resolvePolicy($item)['settings']);
        if (hash_equals($m['hash'], $target)) {
            return ['state' => 'current'] + $r;
        }
        if ($m['enabled'] || $m['assignments'] > 0) {
            return ['state' => $withTarget ? 'in_use' : 'update_available'] + $r;
        }

        return ['state' => 'update_available'] + $r;
    }

    private function blocker(): ?string
    {
        try {
            $this->settings->signingKey();
        } catch (\RuntimeException) {
            return 'The instance signing key is not available: generate it in the module settings before installing scripts.';
        }

        return null;
    }

    /** @param array<string,int> $taken lower case names this run has already claimed */
    private function freeName(string $name, array $taken): ?string
    {
        foreach ([$name, $name . ' (starter)'] as $n) {
            if (!isset($taken[mb_strtolower($n)]) && $this->sql->one('SELECT script_id FROM rmm_scripts_v2 WHERE name = ?', [$n]) === null) {
                return $n;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ selection

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function selection(array $in, bool $forInstall): array
    {
        foreach (array_keys($in) as $k) {
            if (!in_array($k, self::SELECT_KEYS, true)) {
                throw new \InvalidArgumentException('Unknown selection member ' . (preg_match('/^[A-Za-z0-9_]{1,40}$/', (string) $k) === 1 ? $k : '(invalid)') . '.');
            }
        }
        $pack = $in['pack'] ?? null;
        if ($pack !== null && $pack !== '') {
            if (!is_string($pack) || !in_array($pack, array_map(static fn (StarterPack $p): string => $p->id(), $this->packs), true)) {
                throw new \InvalidArgumentException('Unknown starter pack.');
            }
        } else {
            $pack = null;
        }
        $list = static function (mixed $v, string $what, int $max = 200): array {
            if ($v === null || $v === '' || $v === []) {
                return [];
            }
            $v = is_string($v) ? [$v] : $v;
            if (!is_array($v) || !array_is_list($v) || count($v) > $max) {
                throw new \InvalidArgumentException("$what is a list of at most $max values.");
            }
            foreach ($v as $x) {
                if (!is_string($x) || $x === '' || strlen($x) > 100) {
                    throw new \InvalidArgumentException("$what holds only short text values.");
                }
            }

            return array_values(array_unique(array_map('strtolower', $v)));
        };
        $platform = $in['platform'] ?? null;
        if ($platform !== null && $platform !== '' && (!is_string($platform) || !in_array($platform, StarterPack::PLATFORMS, true))) {
            throw new \InvalidArgumentException('platform is windows or linux.');
        }
        $risk = $list($in['risk'] ?? null, 'risk');
        foreach ($risk as $r) {
            if (!in_array($r, StarterPack::RISKS, true)) {
                throw new \InvalidArgumentException('risk is read_only, changes_system or disruptive.');
            }
        }
        $tags = $list($in['tags'] ?? null, 'tags');
        $ids = $list($in['ids'] ?? null, 'ids', 500);
        $kinds = $list($in['kinds'] ?? null, 'kinds');
        foreach ($kinds as $k) {
            if (!in_array($k, ['scripts', 'policies'], true)) {
                throw new \InvalidArgumentException('kinds is scripts, policies or both.');
            }
        }
        if ($kinds === []) {
            // A risk or tag filter is about scripts; policies are picked by id, platform or by default.
            $kinds = $risk !== [] || $tags !== [] ? ['scripts'] : ['scripts', 'policies'];
            if ($ids !== []) {
                $kinds = ['scripts', 'policies'];
            }
        }
        $params = $in['params'] ?? [];
        $pp = $in['policy_params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params)) || !is_array($pp) || ($pp !== [] && array_is_list($pp))) {
            throw new \InvalidArgumentException('params and policy_params are objects.');
        }
        $q = $in['q'] ?? '';

        return ['pack' => $pack, 'ids' => $ids, 'tags' => $tags, 'platform' => $platform === '' ? null : $platform, 'risk' => $risk, 'kinds' => $kinds, 'params' => $params,
            'policy_params' => $pp, 'q' => is_string($q) ? mb_strtolower(trim($q)) : '', 'install' => $forInstall];
    }

    /**
     * @param array<string,mixed> $sel
     * @return list<array{0:StarterPack,1:string,2:array<string,mixed>}>
     */
    private function selected(array $sel): array
    {
        $out = [];
        $known = [];
        foreach ($this->packs as $pack) {
            if ($sel['pack'] !== null && $sel['pack'] !== $pack->id()) {
                continue;
            }
            foreach ($pack->scripts() as $s) {
                $known[(string) $s['id']] = 1;
                if (in_array('scripts', $sel['kinds'], true) && $this->scriptMatches($s, $sel, true)) {
                    $out[] = [$pack, 'script', $s];
                }
            }
            foreach ($pack->policies() as $p) {
                $known[(string) $p['id']] = 1;
                if (in_array('policies', $sel['kinds'], true) && $this->policyMatches($p, $sel, true)) {
                    $out[] = [$pack, 'policy', $p];
                }
            }
        }
        foreach ($sel['ids'] as $id) {
            if (!isset($known[$id])) {
                throw new \InvalidArgumentException('Unknown starter item ' . (preg_match('/^[a-z0-9-]{1,60}$/', $id) === 1 ? $id : '(invalid)') . '.');
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,mixed> $sel
     */
    private function scriptMatches(array $s, array $sel, bool $byIds): bool
    {
        if ($byIds && $sel['ids'] !== [] && !in_array((string) $s['id'], $sel['ids'], true)) {
            return false;
        }
        if ($sel['platform'] !== null && $s['platform'] !== $sel['platform']) {
            return false;
        }
        if ($sel['risk'] !== [] && !in_array($s['risk'], $sel['risk'], true)) {
            return false;
        }
        if ($sel['tags'] !== [] && array_intersect($sel['tags'], array_map('strtolower', (array) $s['tags'])) === []) {
            return false;
        }

        return $sel['q'] === '' || str_contains(mb_strtolower($s['name'] . ' ' . $s['description']), $sel['q']);
    }

    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $sel
     */
    private function policyMatches(array $p, array $sel, bool $byIds): bool
    {
        if ($byIds && $sel['ids'] !== [] && !in_array((string) $p['id'], $sel['ids'], true)) {
            return false;
        }
        if ($sel['platform'] !== null && $p['platform'] !== $sel['platform']) {
            return false;
        }

        return $sel['q'] === '' || str_contains(mb_strtolower($p['name'] . ' ' . $p['description']), $sel['q']);
    }

    /**
     * @param array<string,mixed> $sel
     * @return array<string,mixed>
     */
    private function policyParams(array $sel, string $policyId): array
    {
        $own = $sel['policy_params'][$policyId] ?? [];

        return (is_array($own) ? $own : []) + (array) $sel['params'];
    }

    // ------------------------------------------------------------------ helpers

    public static function note(string $mark, string $pack, string $item, string $version, string $hash): string
    {
        return "$mark|$pack|$item|$version|$hash";
    }

    public static function policyMarker(string $pack, string $item, string $version, string $hash): string
    {
        return " [starter|$pack|$item|$version|$hash]";
    }

    /**
     * @param array<string,mixed> $i
     * @return array<string,mixed> the item without internals
     */
    private static function publicItem(array $i): array
    {
        foreach (array_keys($i) as $k) {
            if (str_starts_with((string) $k, '_')) {
                unset($i[$k]);
            }
        }

        return $i;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,int>
     */
    private static function count(array $items): array
    {
        $c = [];
        foreach ($items as $i) {
            $c[(string) $i['action']] = ($c[(string) $i['action']] ?? 0) + 1;
        }
        ksort($c);

        return $c;
    }
}
