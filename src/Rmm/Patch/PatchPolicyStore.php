<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Patch;

use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Policy\PolicyLayer;
use RivetCore\Rmm\Policy\PolicyResolver;
use RivetCore\Rmm\Support\Sql;

/**
 * Patch policies, their assignments and the rings (tables rmm_patch_policies, rmm_patch_assignments, rmm_patch_rings). The assignment scopes and the
 * way they combine are those of the Phase 2 policies: global, client, site, group, tag and device, most specific wins, `enforce` locks a key
 * ({@see PatchPolicyResolver}). Authorization is the caller's ({@see PatchActions}).
 *
 * @api
 */
final class PatchPolicyStore
{
    public const MAX_POLICIES = 200;
    public const MAX_RINGS = 20;

    public function __construct(private readonly Sql $sql, private readonly ?RmmTenancyInterface $tenancy = null)
    {
    }

    // ------------------------------------------------------------------ rings

    /** @return list<array{ring_id:int,name:string,delay_days:int,sort_order:int,description:string}> */
    public function rings(): array
    {
        $out = [];
        foreach ($this->sql->all('SELECT * FROM rmm_patch_rings ORDER BY sort_order, delay_days, ring_id') as $r) {
            $out[] = ['ring_id' => (int) $r['ring_id'], 'name' => (string) $r['name'], 'delay_days' => (int) $r['delay_days'], 'sort_order' => (int) $r['sort_order'], 'description' => (string) $r['description']];
        }

        return $out;
    }

    /** @return array<string,int> ring name => delay days */
    public function ringDelays(): array
    {
        $out = [];
        foreach ($this->rings() as $r) {
            $out[$r['name']] = $r['delay_days'];
        }

        return $out;
    }

    /** @return list<string> */
    public function ringNames(): array
    {
        return array_keys($this->ringDelays());
    }

    /**
     * @param array<string,mixed> $in {name, delay_days, sort_order?, description?}
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public function saveRing(?int $ringId, array $in): array
    {
        $name = $in['name'] ?? null;
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9_-]{0,38}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('A ring name is 1 to 39 lower-case letters, digits, - or _, starting with a letter.');
        }
        $delay = $in['delay_days'] ?? 0;
        if (!is_int($delay) || $delay < 0 || $delay > 365) {
            throw new \InvalidArgumentException('delay_days must be 0 to 365.');
        }
        $order = $in['sort_order'] ?? 100;
        if (!is_int($order) || $order < 0 || $order > 100000) {
            throw new \InvalidArgumentException('sort_order must be 0 to 100000.');
        }
        $desc = mb_substr(trim(is_string($in['description'] ?? null) ? $in['description'] : ''), 0, 200);
        $dup = $this->sql->one('SELECT ring_id FROM rmm_patch_rings WHERE name = ?', [$name]);
        if ($dup !== null && (int) $dup['ring_id'] !== $ringId) {
            throw new \InvalidArgumentException('A ring with that name exists.');
        }
        if ($ringId === null) {
            if ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_patch_rings') >= self::MAX_RINGS) {
                throw new \InvalidArgumentException('At most ' . self::MAX_RINGS . ' rings.');
            }
            $ringId = $this->sql->insert('INSERT INTO rmm_patch_rings (name, delay_days, sort_order, description) VALUES (?, ?, ?, ?)', [$name, $delay, $order, $desc]);
        } else {
            $cur = $this->sql->one('SELECT name FROM rmm_patch_rings WHERE ring_id = ?', [$ringId]);
            if ($cur === null) {
                throw new \InvalidArgumentException('Ring not found.');
            }
            $this->sql->run('UPDATE rmm_patch_rings SET name = ?, delay_days = ?, sort_order = ?, description = ? WHERE ring_id = ?', [$name, $delay, $order, $desc, $ringId]);
            if ($cur['name'] !== $name) {
                $this->renameRing((string) $cur['name'], $name);
            }
        }

        return $this->rings()[array_search($ringId, array_column($this->rings(), 'ring_id'), true)] ?? [];
    }

    /** Policies keep a ring by name: follow a rename. */
    private function renameRing(string $old, string $new): void
    {
        foreach ($this->sql->all('SELECT policy_id, settings_json FROM rmm_patch_policies') as $p) {
            $s = json_decode((string) $p['settings_json'], true);
            if (is_array($s) && ($s['ring']['value'] ?? null) === $old) {
                $s['ring']['value'] = $new;
                $this->sql->run('UPDATE rmm_patch_policies SET settings_json = ? WHERE policy_id = ?', [(string) json_encode($s), $p['policy_id']]);
            }
        }
    }

    /** @return bool false when a policy still uses the ring or it does not exist */
    public function deleteRing(int $ringId): bool
    {
        $r = $this->sql->one('SELECT name FROM rmm_patch_rings WHERE ring_id = ?', [$ringId]);
        if ($r === null) {
            return false;
        }
        foreach ($this->sql->all('SELECT settings_json FROM rmm_patch_policies') as $p) {
            $s = json_decode((string) $p['settings_json'], true);
            if (is_array($s) && ($s['ring']['value'] ?? null) === $r['name']) {
                return false;
            }
        }
        if ($this->sql->one('SELECT rule_id FROM rmm_patch_rules WHERE ring_id = ?', [$ringId]) !== null) {
            return false;
        }

        return $this->sql->run('DELETE FROM rmm_patch_rings WHERE ring_id = ?', [$ringId]) === 1;
    }

    // ------------------------------------------------------------------ policies

    /**
     * @param array<string,mixed> $in {name, description?, enabled?, settings?}
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public function save(?int $policyId, array $in, int $userId, ?int $confirmedBy = null): array
    {
        $name = trim(is_string($in['name'] ?? null) ? $in['name'] : '');
        if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f]/', $name) === 1) {
            throw new \InvalidArgumentException('A policy needs a name of 1 to 100 characters.');
        }
        $desc = mb_substr(trim(is_string($in['description'] ?? null) ? $in['description'] : ''), 0, 300);
        $cur = $policyId === null ? null : $this->sql->one('SELECT * FROM rmm_patch_policies WHERE policy_id = ?', [$policyId]);
        if ($policyId !== null && $cur === null) {
            throw new \InvalidArgumentException('Policy not found.');
        }
        $enabled = array_key_exists('enabled', $in) ? (!empty($in['enabled']) ? 1 : 0) : (int) ($cur['enabled'] ?? 1);
        if (array_key_exists('settings', $in)) {
            [$settings, $err] = PatchPolicySettings::normalize($in['settings'], $this->ringNames());
            if ($err !== null || $settings === null) {
                throw new \InvalidArgumentException($err ?? 'Invalid settings.');
            }
        } else {
            $settings = $cur === null ? [] : (json_decode((string) $cur['settings_json'], true) ?: []);
        }
        $dup = $this->sql->one('SELECT policy_id FROM rmm_patch_policies WHERE name = ?', [$name]);
        if ($dup !== null && (int) $dup['policy_id'] !== $policyId) {
            throw new \InvalidArgumentException('A patch policy with that name exists.');
        }
        $now = $this->sql->utcNow();
        $sj = (string) json_encode($settings === [] ? new \stdClass() : $settings);
        if ($policyId === null) {
            if ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_patch_policies') >= self::MAX_POLICIES) {
                throw new \InvalidArgumentException('At most ' . self::MAX_POLICIES . ' patch policies.');
            }
            $policyId = $this->sql->insert('INSERT INTO rmm_patch_policies (name, description, enabled, settings_json, reboot_confirmed_by, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$name, $desc, $enabled, $sj, $confirmedBy, $userId, $now, $now]);
        } else {
            $this->sql->run('UPDATE rmm_patch_policies SET name = ?, description = ?, enabled = ?, settings_json = ?, reboot_confirmed_by = ?, updated_at = ? WHERE policy_id = ?',
                [$name, $desc, $enabled, $sj, $confirmedBy ?? $cur['reboot_confirmed_by'] ?? null, $now, $policyId]);
        }

        return $this->find($policyId) ?? [];
    }

    public function delete(int $policyId): bool
    {
        return (bool) $this->sql->transaction(function () use ($policyId): bool {
            $this->sql->run('DELETE FROM rmm_patch_assignments WHERE policy_id = ?', [$policyId]);

            return $this->sql->run('DELETE FROM rmm_patch_policies WHERE policy_id = ?', [$policyId]) === 1;
        });
    }

    /** @return array<string,mixed>|null */
    public function find(int $policyId): ?array
    {
        $r = $this->sql->one('SELECT * FROM rmm_patch_policies WHERE policy_id = ?', [$policyId]);

        return $r === null ? null : self::row($r);
    }

    /**
     * The values a policy holds, key => value.
     *
     * @return array<string,mixed>
     */
    public function values(int $policyId): array
    {
        $r = $this->sql->one('SELECT settings_json FROM rmm_patch_policies WHERE policy_id = ?', [$policyId]);
        $out = [];
        $s = $r === null ? null : json_decode((string) $r['settings_json'], true);
        foreach (is_array($s) ? $s : [] as $k => $e) {
            if (is_array($e) && array_key_exists('value', $e)) {
                $out[(string) $k] = $e['value'];
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_map(self::row(...), $this->sql->all('SELECT * FROM rmm_patch_policies ORDER BY name, policy_id'));
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function row(array $r): array
    {
        $s = json_decode((string) $r['settings_json'], true);
        $settings = [];
        if (is_array($s)) {
            foreach ($s as $k => $e) {
                if (is_array($e) && array_key_exists('value', $e)) {
                    $settings[(string) $k] = $e['value'];
                }
            }
        }

        return ['policy_id' => (int) $r['policy_id'], 'name' => (string) $r['name'], 'description' => (string) $r['description'], 'enabled' => (int) $r['enabled'] === 1,
            'settings' => $settings === [] ? new \stdClass() : $settings, 'reboot_confirmed_by' => $r['reboot_confirmed_by'] === null ? null : (int) $r['reboot_confirmed_by'],
            'created_by' => (int) $r['created_by'], 'created_at' => Sql::iso((string) $r['created_at']), 'updated_at' => Sql::iso((string) $r['updated_at'])];
    }

    // ------------------------------------------------------------------ assignments

    /**
     * @param array<string,mixed> $in {scope_type, scope_id?, priority?, enforce?}
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public function assign(int $policyId, array $in, int $userId): array
    {
        if ($this->find($policyId) === null) {
            throw new \InvalidArgumentException('Policy not found.');
        }
        $type = $in['scope_type'] ?? null;
        if (!is_string($type) || !isset(PolicyResolver::SCOPES[$type])) {
            throw new \InvalidArgumentException('scope_type must be global, client, site, group, tag or device.');
        }
        $sid = $in['scope_id'] ?? 0;
        $scopeId = $type === 'global' ? 0 : (is_int($sid) ? $sid : (is_string($sid) && ctype_digit($sid) ? (int) $sid : 0));
        if ($type !== 'global' && $scopeId <= 0) {
            throw new \InvalidArgumentException('scope_id is required for a ' . $type . ' assignment.');
        }
        $ok = match ($type) {
            'global', 'site' => true,
            'client' => $this->tenancy === null || $this->tenancy->clientName($scopeId) !== null,
            'group' => $this->sql->one('SELECT group_id FROM rmm_groups WHERE group_id = ?', [$scopeId]) !== null,
            'tag' => $this->sql->one('SELECT tag_id FROM rmm_tags WHERE tag_id = ?', [$scopeId]) !== null,
            default => $this->sql->one('SELECT device_id FROM endpoint_agent_devices WHERE device_id = ?', [$scopeId]) !== null,
        };
        if (!$ok) {
            throw new \InvalidArgumentException('That ' . $type . ' does not exist.');
        }
        $priority = $in['priority'] ?? 100;
        if (!is_int($priority) || $priority < 0 || $priority > 1000000) {
            throw new \InvalidArgumentException('priority must be a whole number from 0 to 1000000.');
        }
        $enforce = !empty($in['enforce']) ? 1 : 0;
        $now = $this->sql->utcNow();
        $this->sql->transaction(function () use ($policyId, $type, $scopeId, $priority, $enforce, $userId, $now): void {
            $cur = $this->sql->one('SELECT assignment_id FROM rmm_patch_assignments WHERE policy_id = ? AND scope_type = ? AND scope_id = ? FOR UPDATE', [$policyId, $type, $scopeId]);
            if ($cur === null) {
                $this->sql->run('INSERT INTO rmm_patch_assignments (policy_id, scope_type, scope_id, priority, enforce, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$policyId, $type, $scopeId, $priority, $enforce, $userId, $now]);
            } else {
                $this->sql->run('UPDATE rmm_patch_assignments SET priority = ?, enforce = ? WHERE assignment_id = ?', [$priority, $enforce, $cur['assignment_id']]);
            }
        });

        return self::assignmentRow($this->sql->one('SELECT * FROM rmm_patch_assignments WHERE policy_id = ? AND scope_type = ? AND scope_id = ?', [$policyId, $type, $scopeId]) ?? []);
    }

    /** @return array<string,mixed>|null the removed assignment */
    public function unassign(int $policyId, int $assignmentId): ?array
    {
        $row = $this->sql->one('SELECT * FROM rmm_patch_assignments WHERE assignment_id = ? AND policy_id = ?', [$assignmentId, $policyId]);
        if ($row === null) {
            return null;
        }
        $this->sql->run('DELETE FROM rmm_patch_assignments WHERE assignment_id = ?', [$assignmentId]);

        return self::assignmentRow($row);
    }

    /** @return list<array<string,mixed>> */
    public function assignments(?int $policyId = null): array
    {
        $rows = $policyId === null ? $this->sql->all('SELECT * FROM rmm_patch_assignments ORDER BY policy_id, assignment_id')
            : $this->sql->all('SELECT * FROM rmm_patch_assignments WHERE policy_id = ? ORDER BY assignment_id', [$policyId]);

        return array_map(self::assignmentRow(...), $rows);
    }

    /** Remove the assignments that point at a tag, group or device that no longer exists. */
    public function dropOrphanAssignments(): int
    {
        $n = $this->sql->run("DELETE a FROM rmm_patch_assignments a WHERE a.scope_type = 'tag' AND NOT EXISTS (SELECT 1 FROM rmm_tags t WHERE t.tag_id = a.scope_id)");
        $n += $this->sql->run("DELETE a FROM rmm_patch_assignments a WHERE a.scope_type = 'group' AND NOT EXISTS (SELECT 1 FROM rmm_groups g WHERE g.group_id = a.scope_id)");
        $n += $this->sql->run("DELETE a FROM rmm_patch_assignments a WHERE a.scope_type = 'device' AND NOT EXISTS (SELECT 1 FROM endpoint_agent_devices d WHERE d.device_id = a.scope_id)");

        return $n;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function assignmentRow(array $r): array
    {
        return ['assignment_id' => (int) ($r['assignment_id'] ?? 0), 'policy_id' => (int) ($r['policy_id'] ?? 0), 'scope_type' => (string) ($r['scope_type'] ?? ''), 'scope_id' => (int) ($r['scope_id'] ?? 0),
            'priority' => (int) ($r['priority'] ?? 0), 'enforce' => (int) ($r['enforce'] ?? 0) === 1, 'created_at' => Sql::iso((string) ($r['created_at'] ?? ''))];
    }

    // ------------------------------------------------------------------ resolution

    /**
     * @param array<string,mixed> $dev the device row
     * @return list<PolicyLayer>
     */
    public function layersFor(array $dev): array
    {
        $id = (int) $dev['device_id'];
        $loc = (int) ($dev['location_id'] ?? 0);
        $rows = $this->sql->all("SELECT a.assignment_id, a.policy_id, a.scope_type, a.priority, a.enforce, p.settings_json
            FROM rmm_patch_assignments a JOIN rmm_patch_policies p ON p.policy_id = a.policy_id AND p.enabled = 1
            WHERE a.scope_type = 'global'
               OR (a.scope_type = 'client' AND a.scope_id = ?)
               OR (a.scope_type = 'site' AND a.scope_id = ? AND ? > 0)
               OR (a.scope_type = 'device' AND a.scope_id = ?)
               OR (a.scope_type = 'tag' AND a.scope_id IN (SELECT dt.tag_id FROM rmm_device_tags dt WHERE dt.device_id = ?))
               OR (a.scope_type = 'group' AND (EXISTS (SELECT 1 FROM rmm_group_devices gd WHERE gd.group_id = a.scope_id AND gd.device_id = ?)
                    OR EXISTS (SELECT 1 FROM rmm_group_tags gt JOIN rmm_device_tags dt2 ON dt2.tag_id = gt.tag_id WHERE gt.group_id = a.scope_id AND dt2.device_id = ?)))
            ORDER BY a.assignment_id",
            [(int) $dev['client_id'], $loc, $loc, $id, $id, $id, $id]);
        $out = [];
        foreach ($rows as $r) {
            $s = json_decode((string) $r['settings_json'], true);
            /** @var array<string,array{mode:string,value:mixed}> $settings */
            $settings = is_array($s) ? $s : [];
            $out[] = new PolicyLayer((int) $r['assignment_id'], (int) $r['policy_id'], (string) $r['scope_type'], (int) $r['priority'], (int) $r['enforce'] === 1, $settings);
        }

        return $out;
    }

    /**
     * What the patch policies decide for a device.
     *
     * @param array<string,mixed> $dev
     * @return array<string,mixed> see {@see PatchPolicyResolver::resolve()}
     */
    public function effectiveFor(array $dev): array
    {
        return PatchPolicyResolver::resolve($this->layersFor($dev), $this->ringDelays());
    }
}
