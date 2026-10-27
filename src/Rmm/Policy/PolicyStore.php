<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Policy;

use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Support\Sql;

/**
 * Policies, their versions and their assignments (tables rmm_policies, rmm_policy_versions, rmm_policy_assignments). Every method
 * validates and either returns the stored row or throws \InvalidArgumentException with a message that is safe to show. Authorization and
 * audit are the caller's ({@see \RivetCore\Rmm\Technician\PolicyActions}).
 *
 * A policy edit that changes the settings makes a new version (the old body is kept); renaming it or switching it off does not. A disabled
 * policy keeps its assignments and reaches no device until it is switched on again.
 *
 * @api
 */
final class PolicyStore
{
    public const NAME_MAX = 100;
    public const MAX_POLICIES = 500;

    public function __construct(private readonly Sql $sql, private readonly ?RmmTenancyInterface $tenancy = null)
    {
    }

    /**
     * @param array<string,mixed> $in {name, description?, settings}
     * @return array<string,mixed>
     */
    public function create(array $in, int $userId): array
    {
        $name = self::cleanName($in['name'] ?? null);
        [$body, $err] = PolicySettings::normalizeBody(['settings' => $in['settings'] ?? []]);
        if ($err !== null || $body === null) {
            throw new \InvalidArgumentException($err ?? 'Invalid policy.');
        }
        $desc = self::cleanText($in['description'] ?? '', 300);
        if ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_policies') >= self::MAX_POLICIES) {
            throw new \InvalidArgumentException('At most ' . self::MAX_POLICIES . ' policies.');
        }
        if ($this->sql->one('SELECT policy_id FROM rmm_policies WHERE name = ?', [$name]) !== null) {
            throw new \InvalidArgumentException('A policy with that name exists.');
        }
        $json = self::encode($body);
        $now = $this->sql->utcNow();

        return $this->sql->transaction(function () use ($name, $desc, $json, $userId, $now): array {
            $id = $this->sql->insert('INSERT INTO rmm_policies (name, description, kind, body_json, version, enabled, created_by, created_at, updated_by, updated_at) VALUES (?, ?, ?, ?, 1, 1, ?, ?, ?, ?)',
                [$name, $desc, 'agent', $json, $userId, $now, $userId, $now]);
            $this->sql->run('INSERT INTO rmm_policy_versions (policy_id, version, body_json, changed_by, changed_at) VALUES (?, 1, ?, ?, ?)', [$id, $json, $userId, $now]);

            return $this->find($id) ?? [];
        });
    }

    /**
     * @param array<string,mixed> $in any of name, description, settings, enabled
     * @return array<string,mixed>
     */
    public function update(int $policyId, array $in, int $userId): array
    {
        return $this->sql->transaction(function () use ($policyId, $in, $userId): array {
            $cur = $this->sql->one('SELECT * FROM rmm_policies WHERE policy_id = ? FOR UPDATE', [$policyId]);
            if ($cur === null) {
                throw new \InvalidArgumentException('Policy not found.');
            }
            $name = array_key_exists('name', $in) ? self::cleanName($in['name']) : (string) $cur['name'];
            if ($name !== $cur['name'] && $this->sql->one('SELECT policy_id FROM rmm_policies WHERE name = ? AND policy_id <> ?', [$name, $policyId]) !== null) {
                throw new \InvalidArgumentException('A policy with that name exists.');
            }
            $desc = array_key_exists('description', $in) ? self::cleanText($in['description'], 300) : (string) $cur['description'];
            $enabled = array_key_exists('enabled', $in) ? ((bool) $in['enabled'] ? 1 : 0) : (int) $cur['enabled'];
            $json = (string) $cur['body_json'];
            $version = (int) $cur['version'];
            $now = $this->sql->utcNow();
            if (array_key_exists('settings', $in)) {
                [$body, $err] = PolicySettings::normalizeBody(['settings' => $in['settings']]);
                if ($err !== null || $body === null) {
                    throw new \InvalidArgumentException($err ?? 'Invalid policy.');
                }
                $new = self::encode($body);
                if ($new !== $json) {
                    $json = $new;
                    ++$version;
                    $this->sql->run('INSERT INTO rmm_policy_versions (policy_id, version, body_json, changed_by, changed_at) VALUES (?, ?, ?, ?, ?)', [$policyId, $version, $json, $userId, $now]);
                }
            }
            $this->sql->run('UPDATE rmm_policies SET name = ?, description = ?, enabled = ?, body_json = ?, version = ?, updated_by = ?, updated_at = ? WHERE policy_id = ?',
                [$name, $desc, $enabled, $json, $version, $userId, $now, $policyId]);

            return $this->find($policyId) ?? [];
        });
    }

    /** Delete a policy with its versions and assignments (the devices it reached fall back to the next layer at their next check-in). */
    public function delete(int $policyId): bool
    {
        return $this->sql->transaction(function () use ($policyId): bool {
            $this->sql->run('DELETE FROM rmm_policy_assignments WHERE policy_id = ?', [$policyId]);
            $this->sql->run('DELETE FROM rmm_policy_versions WHERE policy_id = ?', [$policyId]);

            return $this->sql->run('DELETE FROM rmm_policies WHERE policy_id = ?', [$policyId]) === 1;
        });
    }

    /** @return array<string,mixed>|null */
    public function find(int $policyId): ?array
    {
        $r = $this->sql->one('SELECT * FROM rmm_policies WHERE policy_id = ?', [$policyId]);

        return $r === null ? null : self::row($r);
    }

    /** @return list<array<string,mixed>> with the number of assignments each */
    public function all(): array
    {
        $out = [];
        foreach ($this->sql->all('SELECT p.*, (SELECT COUNT(*) FROM rmm_policy_assignments a WHERE a.policy_id = p.policy_id) AS assignments FROM rmm_policies p ORDER BY p.name') as $r) {
            $out[] = self::row($r) + ['assignments' => (int) $r['assignments']];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> newest first, without bodies unless $withBody */
    public function versions(int $policyId, bool $withBody = false): array
    {
        $out = [];
        foreach ($this->sql->all('SELECT * FROM rmm_policy_versions WHERE policy_id = ? ORDER BY version DESC', [$policyId]) as $r) {
            $row = ['version' => (int) $r['version'], 'changed_by' => (int) $r['changed_by'], 'changed_at' => Sql::iso((string) $r['changed_at'])];
            if ($withBody) {
                $row['settings'] = self::settingsOf((string) $r['body_json']) ?: new \stdClass();
            }
            $out[] = $row;
        }

        return $out;
    }

    // ------------------------------------------------------------------ assignments

    /**
     * Assign a policy to a scope (or change the priority, enforce flag or overrides of an existing assignment).
     *
     * @param array<string,mixed> $in {scope_type, scope_id?, priority?, enforce?, overrides?}
     * @return array<string,mixed>
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
        $scopeId = $type === 'global' ? 0 : (is_int($in['scope_id'] ?? null) ? $in['scope_id'] : (is_string($in['scope_id'] ?? null) && ctype_digit($in['scope_id']) ? (int) $in['scope_id'] : 0));
        if ($type !== 'global' && $scopeId <= 0) {
            throw new \InvalidArgumentException('scope_id is required for a ' . $type . ' assignment.');
        }
        $this->checkScope($type, $scopeId);
        $priority = $in['priority'] ?? 100;
        if (!is_int($priority) || $priority < 0 || $priority > 1000000) {
            throw new \InvalidArgumentException('priority must be a whole number from 0 to 1000000.');
        }
        $enforce = !empty($in['enforce']) ? 1 : 0;
        [$overrides, $err] = PolicySettings::normalize($in['overrides'] ?? null);
        if ($err !== null || $overrides === null) {
            throw new \InvalidArgumentException($err ?? 'Invalid overrides.');
        }
        $oj = $overrides === [] ? null : (string) json_encode($overrides);
        $now = $this->sql->utcNow();
        $this->sql->transaction(function () use ($policyId, $type, $scopeId, $priority, $enforce, $oj, $userId, $now): void {
            $cur = $this->sql->one('SELECT assignment_id FROM rmm_policy_assignments WHERE policy_id = ? AND scope_type = ? AND scope_id = ? FOR UPDATE', [$policyId, $type, $scopeId]);
            if ($cur === null) {
                $this->sql->run('INSERT INTO rmm_policy_assignments (policy_id, scope_type, scope_id, priority, enforce, overrides_json, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$policyId, $type, $scopeId, $priority, $enforce, $oj, $userId, $now]);
            } else {
                $this->sql->run('UPDATE rmm_policy_assignments SET priority = ?, enforce = ?, overrides_json = ? WHERE assignment_id = ?', [$priority, $enforce, $oj, $cur['assignment_id']]);
            }
        });
        $row = $this->sql->one('SELECT * FROM rmm_policy_assignments WHERE policy_id = ? AND scope_type = ? AND scope_id = ?', [$policyId, $type, $scopeId]);

        return self::assignmentRow($row ?? []);
    }

    /** @return array<string,mixed>|null the removed assignment */
    public function unassign(int $assignmentId): ?array
    {
        $row = $this->sql->one('SELECT * FROM rmm_policy_assignments WHERE assignment_id = ?', [$assignmentId]);
        if ($row === null) {
            return null;
        }
        $this->sql->run('DELETE FROM rmm_policy_assignments WHERE assignment_id = ?', [$assignmentId]);

        return self::assignmentRow($row);
    }

    /** @return list<array<string,mixed>> */
    public function assignments(?int $policyId = null): array
    {
        $out = [];
        $rows = $policyId === null
            ? $this->sql->all('SELECT * FROM rmm_policy_assignments ORDER BY policy_id, assignment_id')
            : $this->sql->all('SELECT * FROM rmm_policy_assignments WHERE policy_id = ? ORDER BY assignment_id', [$policyId]);
        foreach ($rows as $r) {
            $out[] = self::assignmentRow($r);
        }

        return $out;
    }

    /**
     * One assignment, or null.
     *
     * @return array<string,mixed>|null
     */
    public function assignment(int $assignmentId): ?array
    {
        $r = $this->sql->one('SELECT * FROM rmm_policy_assignments WHERE assignment_id = ?', [$assignmentId]);

        return $r === null ? null : self::assignmentRow($r);
    }

    /** Remove the assignments that point at something that no longer exists (a deleted tag, group or device). */
    public function dropOrphanAssignments(): int
    {
        $n = 0;
        $n += $this->sql->run("DELETE a FROM rmm_policy_assignments a WHERE a.scope_type = 'tag' AND NOT EXISTS (SELECT 1 FROM rmm_tags t WHERE t.tag_id = a.scope_id)");
        $n += $this->sql->run("DELETE a FROM rmm_policy_assignments a WHERE a.scope_type = 'group' AND NOT EXISTS (SELECT 1 FROM rmm_groups g WHERE g.group_id = a.scope_id)");
        $n += $this->sql->run("DELETE a FROM rmm_policy_assignments a WHERE a.scope_type = 'device' AND NOT EXISTS (SELECT 1 FROM endpoint_agent_devices d WHERE d.device_id = a.scope_id)");

        return $n;
    }

    private function checkScope(string $type, int $scopeId): void
    {
        $ok = match ($type) {
            'global', 'site' => true,
            'client' => $this->tenancy === null || $this->tenancy->clientName($scopeId) !== null,
            'group' => $this->sql->one('SELECT group_id FROM rmm_groups WHERE group_id = ?', [$scopeId]) !== null,
            'tag' => $this->sql->one('SELECT tag_id FROM rmm_tags WHERE tag_id = ?', [$scopeId]) !== null,
            'device' => $this->sql->one('SELECT device_id FROM endpoint_agent_devices WHERE device_id = ?', [$scopeId]) !== null,
            default => false,
        };
        if (!$ok) {
            throw new \InvalidArgumentException('That ' . $type . ' does not exist.');
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Layers for one device: its matching assignments of enabled policies, ready for {@see PolicyResolver}.
     *
     * @param array<string,mixed> $dev the device row
     * @return list<PolicyLayer>
     */
    public function layersFor(array $dev): array
    {
        $id = (int) $dev['device_id'];
        $rows = $this->sql->all("SELECT a.assignment_id, a.policy_id, a.scope_type, a.priority, a.enforce, a.overrides_json, p.body_json
            FROM rmm_policy_assignments a JOIN rmm_policies p ON p.policy_id = a.policy_id AND p.enabled = 1
            WHERE a.scope_type = 'global'
               OR (a.scope_type = 'client' AND a.scope_id = ?)
               OR (a.scope_type = 'site' AND a.scope_id = ? AND ? > 0)
               OR (a.scope_type = 'device' AND a.scope_id = ?)
               OR (a.scope_type = 'tag' AND a.scope_id IN (SELECT dt.tag_id FROM rmm_device_tags dt WHERE dt.device_id = ?))
               OR (a.scope_type = 'group' AND (EXISTS (SELECT 1 FROM rmm_group_devices gd WHERE gd.group_id = a.scope_id AND gd.device_id = ?)
                    OR EXISTS (SELECT 1 FROM rmm_group_tags gt JOIN rmm_device_tags dt2 ON dt2.tag_id = gt.tag_id WHERE gt.group_id = a.scope_id AND dt2.device_id = ?)))
            ORDER BY a.assignment_id",
            [(int) $dev['client_id'], (int) $dev['location_id'], (int) $dev['location_id'], $id, $id, $id, $id]);
        $out = [];
        foreach ($rows as $r) {
            $settings = self::settingsOf((string) $r['body_json']);
            $overrides = is_string($r['overrides_json']) && $r['overrides_json'] !== '' ? json_decode($r['overrides_json'], true) : [];
            /** @var array<string,array{mode:string,value:mixed}> $ov */
            $ov = is_array($overrides) ? $overrides : [];
            $out[] = new PolicyLayer((int) $r['assignment_id'], (int) $r['policy_id'], (string) $r['scope_type'], (int) $r['priority'], (int) $r['enforce'] === 1, PolicyLayer::merge($settings, $ov));
        }

        return $out;
    }

    /** @return array<string,array{mode:string,value:mixed}> */
    public static function settingsOf(string $bodyJson): array
    {
        $b = json_decode($bodyJson, true);
        $s = is_array($b) && is_array($b['settings'] ?? null) ? $b['settings'] : [];

        /** @var array<string,array{mode:string,value:mixed}> $s */
        return $s;
    }

    /** @param array{settings:array<string,array{mode:string,value:mixed}>} $body */
    private static function encode(array $body): string
    {
        return (string) json_encode($body === ['settings' => []] ? ['settings' => new \stdClass()] : $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function cleanName(mixed $v): string
    {
        $s = is_string($v) ? trim($v) : '';
        if ($s === '' || mb_strlen($s) > self::NAME_MAX || preg_match('/[\x00-\x1f]/', $s) === 1) {
            throw new \InvalidArgumentException('A policy needs a name of 1 to ' . self::NAME_MAX . ' characters.');
        }

        return $s;
    }

    private static function cleanText(mixed $v, int $max): string
    {
        $s = is_string($v) ? trim($v) : '';
        if (mb_strlen($s) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $s) === 1) {
            throw new \InvalidArgumentException("Text is limited to $max characters.");
        }

        return $s;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function row(array $r): array
    {
        return ['policy_id' => (int) $r['policy_id'], 'name' => (string) $r['name'], 'description' => (string) $r['description'], 'version' => (int) $r['version'],
            'enabled' => (int) $r['enabled'] === 1, 'settings' => self::settingsOf((string) $r['body_json']) ?: new \stdClass(), 'updated_at' => Sql::iso((string) $r['updated_at']), 'updated_by' => (int) $r['updated_by']];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function assignmentRow(array $r): array
    {
        $ov = is_string($r['overrides_json'] ?? null) && $r['overrides_json'] !== '' ? json_decode($r['overrides_json'], true) : null;

        return ['assignment_id' => (int) $r['assignment_id'], 'policy_id' => (int) $r['policy_id'], 'scope_type' => (string) $r['scope_type'], 'scope_id' => (int) $r['scope_id'],
            'priority' => (int) $r['priority'], 'enforce' => (int) $r['enforce'] === 1, 'overrides' => is_array($ov) ? $ov : (object) []];
    }
}
