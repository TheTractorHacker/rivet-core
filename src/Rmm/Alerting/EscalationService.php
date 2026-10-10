<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

use RivetCore\Rmm\Contracts\RmmEscalationInterface;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * Escalation policies and the escalation clock.
 *
 * A policy has a scope (the most specific enabled policy that contains the device is used: device, group, tag, site, client, all), a
 * minimum severity ("warn" = every alert, "crit" = only critical ones), ordered steps and an optional repeat. A step says: this many
 * minutes after the alert opened, if it is still open and not acknowledged, notify these targets. Step 1 with `after_min` 0 notifies as
 * soon as housekeeping next runs. After the last step the last step's targets are re-notified every `repeat_every_min` minutes,
 * `repeat_max` times (0 = until the alert is acknowledged or resolved). Acknowledging or resolving an alert stops the clock; an alert held in a
 * `mute` maintenance window is re-examined every 5 minutes and notifies only after the window.
 *
 * Core only decides when a notice is due and who it is for; {@see RmmEscalationInterface::notify()} delivers it. Nothing here runs inside
 * a check-in: {@see tick()} is called by housekeeping.
 *
 * @api
 */
final class EscalationService
{
    public const MAX_POLICIES = 200;
    public const MAX_STEPS = 10;
    public const MAX_TARGETS = 10;
    public const MAX_AFTER_MIN = 10080;
    public const TARGET_TYPES = ['user', 'group', 'email', 'chat', 'webhook'];
    public const MAX_ATTEMPTS = 5;
    public const RETRY_S = 60;
    public const MUTED_RECHECK_S = 300;
    public const TICK_LIMIT = 200;

    /** How long a long-lived process (a queue worker) keeps the policy list before it reads it again. */
    public const CACHE_TTL_S = 30;

    /** @var list<array<string,mixed>>|null enabled policies with their steps */
    private ?array $cache = null;
    private int $cachedAt = 0;

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly ScopeMatcher $scopes,
        private readonly MaintenanceService $maintenance,
        private readonly RmmEscalationInterface $notifier,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    public function refresh(): void
    {
        $this->cache = null;
    }

    // ------------------------------------------------------------------ policies

    /** @return list<array<string,mixed>> */
    private function enabled(): array
    {
        if ($this->cache === null || $this->sql->time() - $this->cachedAt > self::CACHE_TTL_S) {
            $this->cachedAt = $this->sql->time();
            $this->cache = [];
            $steps = [];
            foreach ($this->sql->all('SELECT * FROM rmm_escalation_steps ORDER BY policy_id, step_no') as $s) {
                $steps[(int) $s['policy_id']][] = $this->presentStep($s);
            }
            foreach ($this->sql->all('SELECT * FROM rmm_escalation_policies WHERE enabled = 1 ORDER BY policy_id') as $p) {
                $row = $this->presentPolicy($p);
                $row['steps'] = $steps[$row['policy_id']] ?? [];
                $this->cache[] = $row;
            }
        }

        return $this->cache;
    }

    /**
     * The policy that governs an alert of this severity on this device, or null.
     *
     * @param array<string,mixed> $dev
     * @return array<string,mixed>|null
     */
    public function policyFor(array $dev, string $severity): ?array
    {
        $best = null;
        foreach ($this->enabled() as $p) {
            if ($p['steps'] === [] || !$this->scopes->matches($p['scope_type'], $p['scope_id'], $dev)) {
                continue;
            }
            if ($p['min_severity'] === 'crit' && $severity !== 'crit') {
                continue;
            }
            if ($best === null || ScopeMatcher::SPECIFICITY[$p['scope_type']] > ScopeMatcher::SPECIFICITY[$best['scope_type']]) {
                $best = $p;
            }
        }

        return $best;
    }

    /** @return list<array<string,mixed>> */
    public function allPolicies(): array
    {
        $steps = [];
        foreach ($this->sql->all('SELECT * FROM rmm_escalation_steps ORDER BY policy_id, step_no') as $s) {
            $steps[(int) $s['policy_id']][] = $this->presentStep($s);
        }
        $out = [];
        foreach ($this->sql->all('SELECT * FROM rmm_escalation_policies ORDER BY name LIMIT ' . self::MAX_POLICIES) as $p) {
            $row = $this->presentPolicy($p);
            $row['steps'] = $steps[$row['policy_id']] ?? [];
            $out[] = $row;
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function findPolicy(int $policyId): ?array
    {
        foreach ($this->allPolicies() as $p) {
            if ($p['policy_id'] === $policyId) {
                return $p;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $in {name, enabled?, scope_type?, scope_id?, min_severity?, repeat_every_min?, repeat_max?, steps: [{after_min, targets: [{type, ref}]}]}
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public function createPolicy(array $in, int $userId): array
    {
        $v = $this->validate($in, null);
        if ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_escalation_policies') >= self::MAX_POLICIES) {
            throw new \InvalidArgumentException('There are already ' . self::MAX_POLICIES . ' escalation policies.');
        }

        return $this->sql->transaction(function () use ($v, $userId): array {
            $id = $this->sql->insert('INSERT INTO rmm_escalation_policies (name, enabled, scope_type, scope_id, min_severity, repeat_every_min, repeat_max, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$v['name'], $v['enabled'], $v['scope_type'], $v['scope_id'], $v['min_severity'], $v['repeat_every_min'], $v['repeat_max'], $userId, $this->sql->utcNow()]);
            $this->writeSteps($id, $v['steps']);
            $this->refresh();

            return $this->findPolicy($id) ?? [];
        });
    }

    /**
     * @param array<string,mixed> $in any of the create fields; `steps`, when present, replaces all steps
     * @return array<string,mixed>|null null when the policy does not exist
     * @throws \InvalidArgumentException
     */
    public function updatePolicy(int $policyId, array $in): ?array
    {
        $cur = $this->findPolicy($policyId);
        if ($cur === null) {
            return null;
        }
        $merged = $in + ['name' => $cur['name'], 'enabled' => $cur['enabled'], 'scope_type' => $cur['scope_type'], 'scope_id' => $cur['scope_id'], 'min_severity' => $cur['min_severity'],
            'repeat_every_min' => $cur['repeat_every_min'], 'repeat_max' => $cur['repeat_max'], 'steps' => $cur['steps']];
        $v = $this->validate($merged, $policyId);

        return $this->sql->transaction(function () use ($v, $policyId): array {
            $this->sql->run('UPDATE rmm_escalation_policies SET name = ?, enabled = ?, scope_type = ?, scope_id = ?, min_severity = ?, repeat_every_min = ?, repeat_max = ?, updated_at = ? WHERE policy_id = ?',
                [$v['name'], $v['enabled'], $v['scope_type'], $v['scope_id'], $v['min_severity'], $v['repeat_every_min'], $v['repeat_max'], $this->sql->utcNow(), $policyId]);
            $this->writeSteps($policyId, $v['steps']);
            $this->refresh();

            return $this->findPolicy($policyId) ?? [];
        });
    }

    public function deletePolicy(int $policyId): bool
    {
        $n = $this->sql->transaction(function () use ($policyId): int {
            $this->sql->run('DELETE FROM rmm_escalation_steps WHERE policy_id = ?', [$policyId]);
            $this->sql->run('UPDATE rmm_alert_meta SET esc_next_at = NULL WHERE policy_id = ? AND state = ?', [$policyId, 'open']);

            return $this->sql->run('DELETE FROM rmm_escalation_policies WHERE policy_id = ?', [$policyId]);
        });
        $this->refresh();

        return $n > 0;
    }

    /** @param list<array{after_min:int,targets:list<array{type:string,ref:string}>}> $steps */
    private function writeSteps(int $policyId, array $steps): void
    {
        $this->sql->run('DELETE FROM rmm_escalation_steps WHERE policy_id = ?', [$policyId]);
        foreach ($steps as $i => $s) {
            $this->sql->run('INSERT INTO rmm_escalation_steps (policy_id, step_no, after_min, targets_json) VALUES (?, ?, ?, ?)', [$policyId, $i + 1, $s['after_min'], json_encode($s['targets'])]);
        }
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    private function validate(array $in, ?int $selfId): array
    {
        $name = is_string($in['name'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $in['name'])) : '';
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('A policy needs a name of 1 to 100 characters.');
        }
        $dup = $this->sql->val('SELECT policy_id FROM rmm_escalation_policies WHERE name = ?', [$name]);
        if ($dup !== null && (int) $dup !== $selfId) {
            throw new \InvalidArgumentException('A policy with that name exists.');
        }
        $scope = $in['scope_type'] ?? 'all';
        if (!is_string($scope) || !in_array($scope, ScopeMatcher::SCOPES, true)) {
            throw new \InvalidArgumentException('scope_type must be one of ' . implode(', ', ScopeMatcher::SCOPES) . '.');
        }
        $scopeId = $scope === 'all' ? 0 : ($in['scope_id'] ?? 0);
        if (!is_int($scopeId) || ($scope !== 'all' && $scopeId < 1)) {
            throw new \InvalidArgumentException('scope_id must be the id of the ' . $scope . '.');
        }
        $this->scopes->assertExists($scope, $scopeId);
        $min = $in['min_severity'] ?? 'warn';
        if (!is_string($min) || !in_array($min, ['warn', 'crit'], true)) {
            throw new \InvalidArgumentException('min_severity must be warn or crit.');
        }
        $rep = $in['repeat_every_min'] ?? 0;
        $repMax = $in['repeat_max'] ?? 0;
        if (!is_int($rep) || $rep < 0 || $rep > self::MAX_AFTER_MIN || ($rep > 0 && $rep < 5) || !is_int($repMax) || $repMax < 0 || $repMax > 1000) {
            throw new \InvalidArgumentException('repeat_every_min is 0 (no repeat) or 5 to ' . self::MAX_AFTER_MIN . '; repeat_max is 0 to 1000.');
        }
        $steps = $in['steps'] ?? null;
        if (!is_array($steps) || !array_is_list($steps) || $steps === [] || count($steps) > self::MAX_STEPS) {
            throw new \InvalidArgumentException('A policy needs 1 to ' . self::MAX_STEPS . ' steps.');
        }
        $out = [];
        $prev = 0;
        foreach ($steps as $i => $s) {
            $n = $i + 1;
            if (!is_array($s)) {
                throw new \InvalidArgumentException("Step $n: must be an object with after_min and targets.");
            }
            $after = $s['after_min'] ?? null;
            if (!is_int($after) || $after < 0 || $after > self::MAX_AFTER_MIN) {
                throw new \InvalidArgumentException("Step $n: after_min must be a whole number from 0 to " . self::MAX_AFTER_MIN . '.');
            }
            if ($after < $prev) {
                throw new \InvalidArgumentException("Step $n: after_min must not be earlier than the step before.");
            }
            $prev = $after;
            $targets = is_array($s['targets'] ?? null) && array_is_list($s['targets']) ? $s['targets'] : null;
            if ($targets === null || $targets === [] || count($targets) > self::MAX_TARGETS) {
                throw new \InvalidArgumentException("Step $n: needs 1 to " . self::MAX_TARGETS . ' targets.');
            }
            $clean = [];
            foreach ($targets as $t) {
                $type = is_array($t) ? ($t['type'] ?? null) : null;
                $ref = is_array($t) ? ($t['ref'] ?? null) : null;
                if (is_int($ref)) {
                    $ref = (string) $ref;
                }
                if (!is_string($type) || !in_array($type, self::TARGET_TYPES, true) || !is_string($ref) || $ref === '' || mb_strlen($ref) > 200 || preg_match('/[\x00-\x1f]/', $ref) === 1) {
                    throw new \InvalidArgumentException("Step $n: a target is {type: " . implode('|', self::TARGET_TYPES) . ', ref: id, address or channel (at most 200 characters)}.');
                }
                $clean[$type . "\0" . $ref] = ['type' => $type, 'ref' => $ref];
            }
            $out[] = ['after_min' => $after, 'targets' => array_values($clean)];
        }

        return ['name' => $name, 'enabled' => array_key_exists('enabled', $in) ? ((bool) $in['enabled'] ? 1 : 0) : 1, 'scope_type' => $scope, 'scope_id' => $scopeId, 'min_severity' => $min,
            'repeat_every_min' => $rep, 'repeat_max' => $repMax, 'steps' => $out];
    }

    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private function presentPolicy(array $p): array
    {
        return ['policy_id' => (int) $p['policy_id'], 'name' => (string) $p['name'], 'enabled' => (int) $p['enabled'] === 1, 'scope_type' => (string) $p['scope_type'], 'scope_id' => (int) $p['scope_id'],
            'min_severity' => (string) $p['min_severity'], 'repeat_every_min' => (int) $p['repeat_every_min'], 'repeat_max' => (int) $p['repeat_max'], 'created_at' => Sql::iso((string) $p['created_at'])];
    }

    /**
     * @param array<string,mixed> $s
     * @return array<string,mixed>
     */
    private function presentStep(array $s): array
    {
        $t = json_decode((string) $s['targets_json'], true);

        return ['step' => (int) $s['step_no'], 'after_min' => (int) $s['after_min'], 'targets' => is_array($t) ? $t : []];
    }

    // ------------------------------------------------------------------ clock

    /**
     * When the next notice of an alert is due. $fired is how many notices were sent already (steps first, then repeats).
     *
     * @param array<string,mixed> $policy
     * @return int|null Unix time, or null when nothing more is due
     */
    public static function nextDue(array $policy, int $openedAt, int $fired, int $now): ?int
    {
        $steps = $policy['steps'];
        $n = count($steps);
        if ($fired < $n) {
            return $openedAt + $steps[$fired]['after_min'] * 60;
        }
        $every = (int) $policy['repeat_every_min'];
        $repeats = $fired - $n;
        if ($every <= 0 || ((int) $policy['repeat_max'] > 0 && $repeats >= (int) $policy['repeat_max'])) {
            return null;
        }

        return $now + $every * 60;
    }

    /**
     * Fire every notice that is due. Returns the counts.
     *
     * @return array{due:int,sent:int,failed:int,held:int}
     */
    public function tick(): array
    {
        $out = ['due' => 0, 'sent' => 0, 'failed' => 0, 'held' => 0];
        $now = $this->sql->time();
        $rows = $this->sql->all("SELECT m.*, d.hostname, d.asset_id, d.location_id, d.client_id AS dev_client FROM rmm_alert_meta m JOIN endpoint_agent_devices d ON d.device_id = m.device_id
            WHERE m.state = 'open' AND m.esc_next_at IS NOT NULL AND m.esc_next_at <= ? ORDER BY m.esc_next_at LIMIT " . self::TICK_LIMIT, [$this->sql->utcNow()]);
        $integration = $this->settings->integrationId();
        foreach ($rows as $m) {
            ++$out['due'];
            $policy = $m['policy_id'] === null ? null : $this->policyById((int) $m['policy_id']);
            if ($policy === null) {
                $this->sql->run('UPDATE rmm_alert_meta SET esc_next_at = NULL WHERE meta_id = ?', [$m['meta_id']]);
                continue;
            }
            $dev = ['device_id' => (int) $m['device_id'], 'client_id' => (int) $m['client_id'], 'location_id' => (int) $m['location_id'], 'hostname' => (string) $m['hostname'], 'asset_id' => $m['asset_id']];
            if ($this->maintenance->stateFor($dev)['mute']) {
                $this->sql->run('UPDATE rmm_alert_meta SET esc_next_at = ? WHERE meta_id = ?', [$this->sql->utcAt(self::MUTED_RECHECK_S), $m['meta_id']]);
                ++$out['held'];
                continue;
            }
            $fired = (int) $m['esc_count'];
            $steps = $policy['steps'];
            $n = count($steps);
            $repeat = $fired >= $n;
            $step = $steps[min($fired, $n - 1)];
            $note = [
                'alert_id' => (int) $m['alert_id'], 'device_id' => (int) $m['device_id'], 'asset_id' => $m['asset_id'] === null ? null : (int) $m['asset_id'], 'client_id' => (int) $m['client_id'],
                'hostname' => (string) $m['hostname'], 'check_key' => (string) $m['check_key'], 'severity' => $m['severity'] === 'crit' ? 'error' : 'warning', 'message' => (string) $m['message'],
                'state' => 'open', 'step' => $step['step'], 'repeat' => $repeat, 'policy_id' => $policy['policy_id'], 'policy_name' => $policy['name'], 'targets' => $step['targets'],
                'opened_at' => Sql::iso((string) $m['opened_at']), 'notified_at' => $this->sql->isoNow(),
            ];
            $ok = false;
            try {
                $ok = $this->notifier->notify($integration, $note);
            } catch (\Throwable $e) {
                error_log('rmm escalation notify failed: ' . get_class($e) . ': ' . $e->getMessage());
            }
            $attempts = (int) $m['esc_attempts'] + 1;
            if (!$ok && $attempts < self::MAX_ATTEMPTS) {
                $this->sql->run('UPDATE rmm_alert_meta SET esc_attempts = ?, esc_next_at = ? WHERE meta_id = ?', [$attempts, $this->sql->utcAt(self::RETRY_S), $m['meta_id']]);
                ++$out['failed'];
                continue;
            }
            // Delivered, or given up after MAX_ATTEMPTS: move on so one dead channel cannot hold the whole chain.
            ++$fired;
            $next = self::nextDue($policy, Sql::ts((string) $m['opened_at']), $fired, $now);
            $this->sql->run('UPDATE rmm_alert_meta SET esc_step = ?, esc_count = ?, esc_attempts = 0, esc_next_at = ?, last_notified_at = ? WHERE meta_id = ?',
                [min($fired, $n), $fired, $next === null ? null : gmdate('Y-m-d H:i:s', max($next, $now)), $ok ? $this->sql->utcNow() : $m['last_notified_at'], $m['meta_id']]);
            if ($ok) {
                ++$out['sent'];
                $this->events?->emit(RmmEvent::ALERT_ESCALATED, $dev, ['alert_id' => (int) $m['alert_id'], 'check_key' => (string) $m['check_key'], 'severity' => $note['severity'],
                    'reason' => $repeat ? 'repeat' : 'step', 'step' => $step['step'], 'policy_id' => $policy['policy_id']]);
            } else {
                ++$out['failed'];
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function policyById(int $id): ?array
    {
        foreach ($this->enabled() as $p) {
            if ($p['policy_id'] === $id) {
                return $p;
            }
        }

        return null;
    }
}
