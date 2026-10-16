<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Patch;

use RivetCore\Rmm\Approvals\ApprovalService;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * Approve and decline updates, the approval rules, and the automatic approvals of policies and rules. This class holds the rules of the data (what a row may
 * become, what a rule matches); WHO may do it is {@see PatchActions}, which also makes the client-scope decisions before any id reaches this class.
 *
 * A row moves available -> approved | declined (a person), available -> approved (a policy or a rule, after its days), declined -> approved (a person changes their mind),
 * approved -> declined, and failed -> approved | declined. An installed row never goes back by approval, and an automatic approval never touches a declined update.
 *
 * TWO PEOPLE. A policy with `two_person` makes a manual approval wait for a second user: {@see requestSecond()} freezes the exact (device, update) pairs in an approval of
 * kind `patch` (Phase 2's {@see ApprovalService}); the second person decides it with the ordinary approvals endpoint and {@see applyApproved()} applies exactly those pairs.
 *
 * @api
 */
final class PatchApprovalService
{
    public const KIND = 'patch';
    public const MAX_PAIRS = 2000;
    public const MAX_RULES = 100;

    public function __construct(
        private readonly Sql $sql,
        private readonly PatchPolicyStore $policies,
        private readonly ?ApprovalService $approvals = null,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    // ------------------------------------------------------------------ selection

    /**
     * The rows a request names, inside the caller's clients.
     *
     * @param array{update_ids?:list<string>,device_id?:?int,client_id?:?int,kinds?:list<string>,status?:list<string>} $f
     * @param list<int>|null $visible client ids the caller may reach (null = all)
     * @return list<array{id:int,device_id:int,client_id:int,update_id:string,kind:string,status:string,title:string}>
     */
    public function select(array $f, ?array $visible, int $limit = self::MAX_PAIRS + 1): array
    {
        $where = [];
        $p = [];
        if ($visible !== null) {
            if ($visible === []) {
                return [];
            }
            $where[] = 'client_id IN (' . implode(',', array_fill(0, count($visible), '?')) . ')';
            array_push($p, ...$visible);
        }
        if (!empty($f['update_ids'])) {
            $where[] = 'update_id IN (' . implode(',', array_fill(0, count($f['update_ids']), '?')) . ')';
            array_push($p, ...$f['update_ids']);
        }
        if (!empty($f['device_id'])) {
            $where[] = 'device_id = ?';
            $p[] = (int) $f['device_id'];
        }
        if (!empty($f['client_id'])) {
            $where[] = 'client_id = ?';
            $p[] = (int) $f['client_id'];
        }
        if (!empty($f['kinds'])) {
            $where[] = 'kind IN (' . implode(',', array_fill(0, count($f['kinds']), '?')) . ')';
            array_push($p, ...$f['kinds']);
        }
        $status = $f['status'] ?? ['available', 'declined', 'failed'];
        $where[] = 'status IN (' . implode(',', array_fill(0, count($status), '?')) . ')';
        array_push($p, ...$status);
        $out = [];
        foreach ($this->sql->all('SELECT id, device_id, client_id, update_id, kind, status, title FROM rmm_patch_available WHERE ' . implode(' AND ', $where) . ' ORDER BY id LIMIT ' . max(1, $limit), $p) as $r) {
            $out[] = ['id' => (int) $r['id'], 'device_id' => (int) $r['device_id'], 'client_id' => (int) $r['client_id'], 'update_id' => (string) $r['update_id'], 'kind' => (string) $r['kind'],
                'status' => (string) $r['status'], 'title' => (string) $r['title']];
        }

        return $out;
    }

    /**
     * Whether any of these rows belongs to a device whose effective policy asks for a second person.
     *
     * @param list<array{device_id:int}> $rows
     */
    public function needsSecondPerson(array $rows): bool
    {
        $seen = [];
        foreach ($rows as $r) {
            if (isset($seen[$r['device_id']])) {
                continue;
            }
            $seen[$r['device_id']] = true;
            $dev = $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE device_id = ?', [$r['device_id']]);
            if ($dev !== null && $this->policies->effectiveFor($dev)['two_person']) {
                return true;
            }
            if (count($seen) >= 500) {
                return true;   // too many devices to prove none needs it: be strict
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ approve and decline

    /**
     * @param list<array{id:int,device_id:int,client_id:int,update_id:string,kind:string,status:string,title:string}> $rows
     * @return int rows changed
     */
    public function approve(array $rows, string $source, ?int $userId, ?int $approvalId = null): int
    {
        return $this->set($rows, 'approved', $source, $userId, $approvalId, '');
    }

    /**
     * @param list<array{id:int,device_id:int,client_id:int,update_id:string,kind:string,status:string,title:string}> $rows
     */
    public function decline(array $rows, ?int $userId, string $reason): int
    {
        return $this->set($rows, 'declined', 'manual', $userId, null, mb_substr($reason, 0, 200));
    }

    /**
     * @param list<array{id:int,device_id:int,client_id:int,update_id:string,kind:string,status:string,title:string}> $rows
     */
    private function set(array $rows, string $to, string $source, ?int $userId, ?int $approvalId, string $reason): int
    {
        if ($rows === []) {
            return 0;
        }
        $from = $to === 'approved' ? ['available', 'declined', 'failed'] : ['available', 'approved', 'failed'];
        $now = $this->sql->utcNow();
        $changed = 0;
        $byUpdate = [];
        foreach (array_chunk(array_column($rows, 'id'), 200) as $ids) {
            $n = $this->sql->run('UPDATE rmm_patch_available SET status = ?, status_source = ?, status_by = ?, status_at = ?, approval_id = ?, decline_reason = ?' . ($to === 'approved' ? ', fail_count = 0' : '')
                . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND status IN (\'' . implode("','", $from) . '\')', [$to, $source, $userId, $now, $approvalId, $reason, ...$ids]);
            $changed += $n;
        }
        if ($to === 'approved' && $changed > 0) {
            foreach ($rows as $r) {
                $byUpdate[$r['update_id']] = ($byUpdate[$r['update_id']] ?? ['title' => $r['title'], 'kind' => $r['kind'], 'devices' => 0]);
                ++$byUpdate[$r['update_id']]['devices'];
            }
            foreach (array_slice($byUpdate, 0, 25, true) as $id => $u) {
                $this->events?->emitScoped(RmmEvent::PATCH_APPROVED, 0, ['update_id' => (string) $id, 'title' => $u['title'], 'kind' => $u['kind'], 'source' => $source, 'devices' => $u['devices'], 'approved_by' => $userId]);
            }
        }

        return $changed;
    }

    // ------------------------------------------------------------------ two people

    /**
     * @param list<array{id:int,device_id:int,client_id:int,update_id:string,kind:string,status:string,title:string}> $rows
     * @return array<string,mixed> the approval
     */
    public function requestSecond(array $rows, int $userId): array
    {
        if ($this->approvals === null) {
            throw new \LogicException('Two-person approval is not available.');
        }
        $pairs = array_map(static fn (array $r): array => [$r['device_id'], $r['update_id']], $rows);
        $devices = count(array_unique(array_column($rows, 'device_id')));
        $updates = count(array_unique(array_column($rows, 'update_id')));
        $summary = "Approve $updates update" . ($updates === 1 ? '' : 's') . " on $devices device" . ($devices === 1 ? '' : 's');

        return $this->approvals->request(self::KIND, ['type' => 'patch', 'action' => 'approve', 'pairs' => $pairs], $summary, $devices, null, null, $userId);
    }

    /**
     * Apply an approved two-person request: exactly the pairs frozen in it.
     *
     * @param array<string,mixed> $request the frozen request
     * @return array{approved:int}
     */
    public function applyApproved(array $request, int $approvalId, int $approverId): array
    {
        $pairs = is_array($request['pairs'] ?? null) ? $request['pairs'] : [];
        $rows = [];
        foreach (array_slice($pairs, 0, self::MAX_PAIRS) as $pair) {
            if (!is_array($pair) || count($pair) !== 2 || !is_int($pair[0]) || !is_string($pair[1])) {
                continue;
            }
            $r = $this->sql->one('SELECT id, device_id, client_id, update_id, kind, status, title FROM rmm_patch_available WHERE device_id = ? AND update_id = ?', [$pair[0], $pair[1]]);
            if ($r !== null) {
                $rows[] = ['id' => (int) $r['id'], 'device_id' => (int) $r['device_id'], 'client_id' => (int) $r['client_id'], 'update_id' => (string) $r['update_id'], 'kind' => (string) $r['kind'],
                    'status' => (string) $r['status'], 'title' => (string) $r['title']];
            }
        }
        $n = $this->approve($rows, 'manual', $approverId, $approvalId);
        $this->approvals?->recordResult($approvalId, ['approved' => $n]);

        return ['approved' => $n];
    }

    /**
     * The distinct client ids a frozen request reaches.
     *
     * @param array<string,mixed> $request
     * @return list<int>
     */
    public function clientsOf(array $request): array
    {
        $ids = [];
        foreach (array_slice(is_array($request['pairs'] ?? null) ? $request['pairs'] : [], 0, self::MAX_PAIRS) as $pair) {
            if (is_array($pair) && isset($pair[0]) && is_int($pair[0])) {
                $ids[$pair[0]] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->sql->all('SELECT DISTINCT client_id FROM endpoint_agent_devices WHERE device_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_keys($ids)) as $r) {
            $out[] = (int) $r['client_id'];
        }

        return $out;
    }

    // ------------------------------------------------------------------ rules

    /**
     * @param array<string,mixed> $in {name, enabled?, ring?, kinds?, severities?, after_days, client_id?}
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public function saveRule(?int $ruleId, array $in, int $userId): array
    {
        $name = trim(is_string($in['name'] ?? null) ? $in['name'] : '');
        if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f]/', $name) === 1) {
            throw new \InvalidArgumentException('A rule needs a name of 1 to 100 characters.');
        }
        $ringId = null;
        if (isset($in['ring']) && $in['ring'] !== '') {
            $ringId = $this->sql->val('SELECT ring_id FROM rmm_patch_rings WHERE name = ?', [is_string($in['ring']) ? $in['ring'] : '']);
            if ($ringId === null) {
                throw new \InvalidArgumentException('ring must be the name of an existing ring.');
            }
            $ringId = (int) $ringId;
        }
        $kinds = self::listOf($in['kinds'] ?? [], PatchProtocol::KINDS, 'kinds');
        $sev = self::listOf($in['severities'] ?? [], PatchProtocol::SEVERITIES, 'severities');
        $after = $in['after_days'] ?? 0;
        if (!is_int($after) || $after < 0 || $after > 365) {
            throw new \InvalidArgumentException('after_days must be 0 to 365.');
        }
        $client = $in['client_id'] ?? 0;
        if (!is_int($client) || $client < 0) {
            throw new \InvalidArgumentException('client_id must be a client id or 0 for all clients.');
        }
        $cur = $ruleId === null ? null : $this->sql->one('SELECT * FROM rmm_patch_rules WHERE rule_id = ?', [$ruleId]);
        if ($ruleId !== null && $cur === null) {
            throw new \InvalidArgumentException('Rule not found.');
        }
        $enabled = array_key_exists('enabled', $in) ? (!empty($in['enabled']) ? 1 : 0) : (int) ($cur['enabled'] ?? 1);
        if ($ruleId === null) {
            if ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_patch_rules') >= self::MAX_RULES) {
                throw new \InvalidArgumentException('At most ' . self::MAX_RULES . ' rules.');
            }
            $ruleId = $this->sql->insert('INSERT INTO rmm_patch_rules (name, enabled, ring_id, kinds, severities, after_days, client_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$name, $enabled, $ringId, implode(',', $kinds), implode(',', $sev), $after, $client, $userId, $this->sql->utcNow()]);
        } else {
            $this->sql->run('UPDATE rmm_patch_rules SET name = ?, enabled = ?, ring_id = ?, kinds = ?, severities = ?, after_days = ?, client_id = ? WHERE rule_id = ?',
                [$name, $enabled, $ringId, implode(',', $kinds), implode(',', $sev), $after, $client, $ruleId]);
        }

        return $this->rule($ruleId) ?? [];
    }

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function listOf(mixed $v, array $allowed, string $field): array
    {
        if (is_string($v)) {
            $v = $v === '' ? [] : explode(',', $v);
        }
        if (!is_array($v) || !array_is_list($v)) {
            throw new \InvalidArgumentException("$field must be a list of: " . implode(', ', $allowed) . '.');
        }
        $out = [];
        foreach ($v as $x) {
            if (!is_string($x) || !in_array($x, $allowed, true)) {
                throw new \InvalidArgumentException("$field must be a list of: " . implode(', ', $allowed) . '.');
            }
            $out[$x] = $x;
        }

        return array_values($out);
    }

    public function deleteRule(int $ruleId): bool
    {
        return $this->sql->run('DELETE FROM rmm_patch_rules WHERE rule_id = ?', [$ruleId]) === 1;
    }

    /** @return array<string,mixed>|null */
    public function rule(int $ruleId): ?array
    {
        $r = $this->sql->one('SELECT r.*, g.name AS ring_name FROM rmm_patch_rules r LEFT JOIN rmm_patch_rings g ON g.ring_id = r.ring_id WHERE r.rule_id = ?', [$ruleId]);

        return $r === null ? null : self::ruleRow($r);
    }

    /** @return list<array<string,mixed>> */
    public function rules(): array
    {
        return array_map(self::ruleRow(...), $this->sql->all('SELECT r.*, g.name AS ring_name FROM rmm_patch_rules r LEFT JOIN rmm_patch_rings g ON g.ring_id = r.ring_id ORDER BY r.rule_id'));
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function ruleRow(array $r): array
    {
        return ['rule_id' => (int) $r['rule_id'], 'name' => (string) $r['name'], 'enabled' => (int) $r['enabled'] === 1, 'ring' => $r['ring_name'] === null ? null : (string) $r['ring_name'],
            'kinds' => $r['kinds'] === '' ? [] : explode(',', (string) $r['kinds']), 'severities' => $r['severities'] === '' ? [] : explode(',', (string) $r['severities']),
            'after_days' => (int) $r['after_days'], 'client_id' => (int) $r['client_id'], 'created_by' => (int) $r['created_by'], 'created_at' => Sql::iso((string) $r['created_at'])];
    }

    // ------------------------------------------------------------------ automatic approval

    /**
     * Approve what the device's policy and the approval rules allow by now. Safe to call as often as you like.
     *
     * @param array<string,mixed> $dev
     * @return int rows approved
     */
    public function autoApprove(array $dev): int
    {
        $deviceId = (int) $dev['device_id'];
        $rows = $this->sql->all("SELECT id, device_id, client_id, update_id, kind, severity, status, title, first_seen_at FROM rmm_patch_available WHERE device_id = ? AND status = 'available'", [$deviceId]);
        if ($rows === []) {
            return 0;
        }
        $eff = $this->policies->effectiveFor($dev);
        $rules = array_values(array_filter($this->rules(), static fn (array $r): bool => $r['enabled'] && ($r['client_id'] === 0 || $r['client_id'] === (int) $dev['client_id']) && ($r['ring'] === null || $r['ring'] === $eff['ring'])));
        $now = $this->sql->time();
        $bySource = [];
        foreach ($rows as $r) {
            $seen = Sql::ts((string) $r['first_seen_at']);
            $source = null;
            $after = PatchPolicyResolver::autoApproveAfterDays($eff, (string) $r['kind']);
            if ($after !== null && $seen + $after * 86400 <= $now) {
                $source = 'policy';
            }
            foreach ($rules as $rule) {
                if (($rule['kinds'] === [] || in_array($r['kind'], $rule['kinds'], true)) && ($rule['severities'] === [] || in_array($r['severity'], $rule['severities'], true))
                    && $seen + $rule['after_days'] * 86400 <= $now) {
                    $source = 'rule:' . $rule['rule_id'];
                    break;
                }
            }
            if ($source !== null) {
                $bySource[$source][] = ['id' => (int) $r['id'], 'device_id' => $deviceId, 'client_id' => (int) $r['client_id'], 'update_id' => (string) $r['update_id'], 'kind' => (string) $r['kind'],
                    'status' => 'available', 'title' => (string) $r['title']];
            }
        }
        $n = 0;
        foreach ($bySource as $source => $set) {
            $n += $this->approve($set, $source, null);
        }

        return $n;
    }
}
