<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

use RivetCore\Rmm\Contracts\RmmBridgeInterface;
use RivetCore\Rmm\Contracts\RmmEscalationInterface;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * The alert lifecycle on Core's side (table rmm_alert_meta). The alert itself, and its ticket, stay in the edition (RmmBridgeInterface);
 * Core keeps what the edition has no column for: severity tier, state (open, acknowledged, resolved), who acknowledged it, the grouping key,
 * which escalation policy governs it and when the next notice is due. A row is written when an alert opens, when its severity changes
 * and when it is acknowledged or resolved, never per sample. Identity is (device, check, episode), enforced by a unique key, so a
 * re-delivered check-in cannot record an episode twice.
 *
 * Editions that let users acknowledge or resolve alerts in their own screens call {@see acknowledge()} / {@see resolveExternal()} from that
 * path so Core's view and its escalation clock agree.
 *
 * @api
 */
final class AlertService
{
    public const STATES = ['open', 'acknowledged', 'resolved'];
    public const RETENTION_DAYS = 180;

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly RmmBridgeInterface $bridge,
        private readonly EscalationService $escalation,
        private readonly RmmEscalationInterface $notifier,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    /** The grouping key: alerts of one check kind in one client are one group. */
    public static function groupKey(int $clientId, string $checkKey): string
    {
        return mb_substr('c' . $clientId . ':' . $checkKey, 0, 120);
    }

    public static function tierOfStatus(string $status): string
    {
        return $status === 'fail' ? 'crit' : 'warn';
    }

    /** @param array<string,mixed> $dev */
    public function opened(array $dev, string $key, int $episode, int $alertId, string $status, string $message): void
    {
        $sev = self::tierOfStatus($status);
        $policy = $this->escalation->policyFor($dev, $sev);
        $next = $policy === null ? null : EscalationService::nextDue($policy, $this->sql->time(), 0, $this->sql->time());
        $this->sql->run('INSERT IGNORE INTO rmm_alert_meta (alert_id, device_id, client_id, check_key, episode, group_key, severity, state, message, opened_at, policy_id, esc_next_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$alertId, $dev['device_id'], (int) $dev['client_id'], $key, $episode, self::groupKey((int) $dev['client_id'], $key), $sev, 'open', mb_substr($message, 0, 500), $this->sql->utcNow(),
                $policy === null ? null : $policy['policy_id'], $next === null ? null : gmdate('Y-m-d H:i:s', $next)]);
        $this->events?->emit(RmmEvent::ALERT_OPENED, $dev, ['alert_id' => $alertId, 'check_key' => $key, 'severity' => $sev === 'crit' ? 'error' : 'warning', 'episode' => $episode,
            'message' => mb_substr($message, 0, 500), 'group_key' => self::groupKey((int) $dev['client_id'], $key)]);
    }

    /**
     * The open alert's severity changed because the check moved between warn and fail.
     * @param array<string,mixed> $dev
     */
    public function severityChanged(array $dev, string $key, int $alertId, string $toStatus): void
    {
        $to = self::tierOfStatus($toStatus);
        $row = $this->sql->one('SELECT * FROM rmm_alert_meta WHERE device_id = ? AND check_key = ? AND alert_id = ?', [$dev['device_id'], $key, $alertId]);
        if ($row === null || $row['severity'] === $to || $row['state'] === 'resolved') {
            return;
        }
        $policy = (int) ($row['esc_count'] ?? 0) === 0 ? $this->escalation->policyFor($dev, $to) : null;
        $next = $policy !== null && $row['esc_next_at'] === null ? EscalationService::nextDue($policy, Sql::ts((string) $row['opened_at']), 0, $this->sql->time()) : null;
        $this->sql->run('UPDATE rmm_alert_meta SET severity = ?, policy_id = COALESCE(?, policy_id), esc_next_at = COALESCE(?, esc_next_at) WHERE meta_id = ?',
            [$to, $policy === null ? null : $policy['policy_id'], $next === null ? null : gmdate('Y-m-d H:i:s', max($next, $this->sql->time())), $row['meta_id']]);
        $api = $to === 'crit' ? 'error' : 'warning';
        $this->safely(fn () => $this->notifier->raiseAlertSeverity($this->settings->integrationId(), $alertId, $api));
        $this->events?->emit(RmmEvent::ALERT_ESCALATED, $dev, ['alert_id' => $alertId, 'check_key' => $key, 'severity' => $api, 'reason' => 'severity', 'step' => 0,
            'policy_id' => $row['policy_id'] === null ? null : (int) $row['policy_id']]);
    }

    /**
     * Record that the module resolved an alert (recovery, retirement). Idempotent.
     * @param array<string,mixed> $dev
     */
    public function resolved(array $dev, string $key, int $alertId, string $reason = 'recovered', ?int $userId = null): void
    {
        $n = $this->sql->run("UPDATE rmm_alert_meta SET state = 'resolved', resolved_at = ?, resolved_by = ?, resolve_reason = ?, esc_next_at = NULL WHERE device_id = ? AND check_key = ? AND alert_id = ? AND state <> 'resolved'",
            [$this->sql->utcNow(), $userId, $reason, $dev['device_id'], $key, $alertId]);
        if ($n > 0) {
            $this->events?->emit(RmmEvent::ALERT_RESOLVED, $dev, ['alert_id' => $alertId, 'check_key' => $key, 'reason' => $reason, 'user_id' => $userId]);
        }
    }

    /** Every still-open alert of a device ended (retired or removed). */
    public function resolvedAllFor(int $deviceId, string $reason): void
    {
        $dev = $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE device_id = ?', [$deviceId]);
        foreach ($this->sql->all("SELECT alert_id, check_key FROM rmm_alert_meta WHERE device_id = ? AND state <> 'resolved'", [$deviceId]) as $m) {
            if ($dev !== null) {
                $this->resolved($dev, (string) $m['check_key'], (int) $m['alert_id'], $reason);
            }
        }
    }

    /**
     * Acknowledge an open alert: stops its escalation and tells the edition. Idempotent for an already acknowledged alert.
     *
     * @return string 'acknowledged' (done now), 'already' or 'not_found' or 'resolved' (a resolved alert cannot be acknowledged)
     */
    public function acknowledge(int $alertId, int $userId): string
    {
        $m = $this->sql->one('SELECT m.*, d.hostname, d.asset_id FROM rmm_alert_meta m JOIN endpoint_agent_devices d ON d.device_id = m.device_id WHERE m.alert_id = ? ORDER BY m.meta_id DESC LIMIT 1', [$alertId]);
        if ($m === null) {
            return 'not_found';
        }
        if ($m['state'] === 'resolved') {
            return 'resolved';
        }
        if ($m['state'] === 'acknowledged') {
            return 'already';
        }
        $this->sql->run("UPDATE rmm_alert_meta SET state = 'acknowledged', acked_by = ?, acked_at = ?, esc_next_at = NULL WHERE meta_id = ? AND state = 'open'", [$userId, $this->sql->utcNow(), $m['meta_id']]);
        $this->safely(fn () => $this->notifier->acknowledgeAlert($this->settings->integrationId(), $alertId, $userId));
        $this->events?->emit(RmmEvent::ALERT_ACKNOWLEDGED, $m, ['alert_id' => $alertId, 'check_key' => (string) $m['check_key'], 'user_id' => $userId]);

        return 'acknowledged';
    }

    /**
     * A technician closes an alert by hand. The edition's alert is resolved (with its conservative ticket auto-close) and the check starts a
     * fresh failure count, so a condition that is still bad opens a NEW episode after the debounce instead of staying silent.
     *
     * @return string 'resolved', 'already' or 'not_found'
     */
    public function resolveManually(int $alertId, int $userId): string
    {
        $m = $this->sql->one('SELECT m.*, d.hostname, d.asset_id FROM rmm_alert_meta m JOIN endpoint_agent_devices d ON d.device_id = m.device_id WHERE m.alert_id = ? ORDER BY m.meta_id DESC LIMIT 1', [$alertId]);
        if ($m === null) {
            return 'not_found';
        }
        if ($m['state'] === 'resolved') {
            return 'already';
        }
        $this->sql->transaction(function () use ($m, $alertId, $userId): void {
            $this->bridge->resolveAlert($this->settings->integrationId(), $alertId);
            $this->sql->run('UPDATE endpoint_agent_checks SET alert_id = NULL, consecutive_failures = 0, consecutive_ok = 0 WHERE device_id = ? AND check_key = ? AND alert_id = ?', [$m['device_id'], $m['check_key'], $alertId]);
            $this->resolved($m, (string) $m['check_key'], $alertId, 'manual', $userId);
        });

        return 'resolved';
    }

    /**
     * The edition resolved (or ticket-closed) the alert in its own screens: bring Core's record and the check state in line without calling
     * the bridge again.
     */
    public function resolveExternal(int $alertId, ?int $userId = null): bool
    {
        $m = $this->sql->one('SELECT m.*, d.hostname, d.asset_id FROM rmm_alert_meta m JOIN endpoint_agent_devices d ON d.device_id = m.device_id WHERE m.alert_id = ? ORDER BY m.meta_id DESC LIMIT 1', [$alertId]);
        if ($m === null || $m['state'] === 'resolved') {
            return false;
        }
        $this->sql->run('UPDATE endpoint_agent_checks SET alert_id = NULL, consecutive_failures = 0, consecutive_ok = 0 WHERE device_id = ? AND check_key = ? AND alert_id = ?', [$m['device_id'], $m['check_key'], $alertId]);
        $this->resolved($m, (string) $m['check_key'], $alertId, 'manual', $userId);

        return true;
    }

    /**
     * Alerts that were open before the `alerting` switch was turned on have no record yet: create it from the check rows. Cheap when there is
     * nothing to adopt (one indexed anti-join).
     */
    public function adoptOpen(): int
    {
        $rows = $this->sql->all("SELECT c.device_id, c.check_key, c.episode, c.alert_id, c.status, c.detail, d.client_id, d.hostname FROM endpoint_agent_checks c
            JOIN endpoint_agent_devices d ON d.device_id = c.device_id WHERE c.alert_id IS NOT NULL AND NOT EXISTS
            (SELECT 1 FROM rmm_alert_meta m WHERE m.device_id = c.device_id AND m.check_key = c.check_key AND m.episode = c.episode) LIMIT 500");
        foreach ($rows as $r) {
            $this->sql->run('INSERT IGNORE INTO rmm_alert_meta (alert_id, device_id, client_id, check_key, episode, group_key, severity, state, message, opened_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$r['alert_id'], $r['device_id'], $r['client_id'], $r['check_key'], $r['episode'], self::groupKey((int) $r['client_id'], (string) $r['check_key']),
                    self::tierOfStatus((string) $r['status']), 'open', mb_substr("Endpoint agent check '" . $r['check_key'] . "' on " . $r['hostname'] . ($r['detail'] !== '' ? ': ' . $r['detail'] : ''), 0, 500), $this->sql->utcNow()]);
        }

        return count($rows);
    }

    /** Remove old resolved records. */
    public function prune(): int
    {
        return $this->sql->run("DELETE FROM rmm_alert_meta WHERE state = 'resolved' AND resolved_at < ? LIMIT 5000", [$this->sql->utcAt(-self::RETENTION_DAYS * 86400)]);
    }

    private function safely(\Closure $f): void
    {
        try {
            $f();
        } catch (\Throwable $e) {
            error_log('rmm escalation adapter failed: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ reads

    /**
     * @param array{state?:string,severity?:string,device_id?:int,client_id?:int,check_key?:string,group_key?:string} $f state: open, acknowledged, resolved, or active (open + acknowledged)
     * @param list<int>|null $visibleClientIds null = every client
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function list(array $f, ?array $visibleClientIds, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($f, $visibleClientIds);
        $total = (int) $this->sql->val("SELECT COUNT(*) FROM rmm_alert_meta m WHERE $where", $params);
        $rows = $this->sql->all("SELECT m.*, d.hostname, d.asset_id FROM rmm_alert_meta m LEFT JOIN endpoint_agent_devices d ON d.device_id = m.device_id WHERE $where
            ORDER BY (m.state = 'resolved'), m.opened_at DESC, m.meta_id DESC LIMIT " . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset), $params);

        return ['items' => array_map($this->present(...), $rows), 'total' => $total];
    }

    /**
     * @param list<int>|null $visibleClientIds
     * @return array<string,mixed>|null
     */
    public function find(int $alertId, ?array $visibleClientIds = null): ?array
    {
        [$where, $params] = $this->where([], $visibleClientIds);
        $r = $this->sql->one("SELECT m.*, d.hostname, d.asset_id FROM rmm_alert_meta m LEFT JOIN endpoint_agent_devices d ON d.device_id = m.device_id WHERE m.alert_id = ? AND $where ORDER BY m.meta_id DESC LIMIT 1", array_merge([$alertId], $params));

        return $r === null ? null : $this->present($r);
    }

    /**
     * Active alerts grouped by client and check: how many devices are affected and the worst severity.
     *
     * @param list<int>|null $visibleClientIds
     * @return list<array<string,mixed>>
     */
    public function groups(?array $visibleClientIds, int $limit = 100): array
    {
        [$where, $params] = $this->where(['state' => 'active'], $visibleClientIds);
        $out = [];
        foreach ($this->sql->all("SELECT m.group_key, m.client_id, m.check_key, COUNT(*) AS alerts, COUNT(DISTINCT m.device_id) AS devices, MAX(m.severity = 'crit') AS has_crit, MIN(m.opened_at) AS first_at, MAX(m.opened_at) AS last_at
            FROM rmm_alert_meta m WHERE $where GROUP BY m.group_key, m.client_id, m.check_key ORDER BY has_crit DESC, alerts DESC LIMIT " . max(1, min(500, $limit)), $params) as $g) {
            $out[] = ['group_key' => (string) $g['group_key'], 'client_id' => (int) $g['client_id'], 'check_key' => (string) $g['check_key'], 'alerts' => (int) $g['alerts'], 'devices' => (int) $g['devices'],
                'severity' => (int) $g['has_crit'] === 1 ? 'crit' : 'warn', 'first_opened_at' => Sql::iso((string) $g['first_at']), 'last_opened_at' => Sql::iso((string) $g['last_at'])];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $f
     * @param list<int>|null $visible
     * @return array{0:string,1:list<mixed>}
     */
    private function where(array $f, ?array $visible): array
    {
        $w = ['1 = 1'];
        $p = [];
        if ($visible !== null) {
            if ($visible === []) {
                return ['1 = 0', []];
            }
            $w[] = 'm.client_id IN (' . implode(',', array_fill(0, count($visible), '?')) . ')';
            array_push($p, ...$visible);
        }
        $state = $f['state'] ?? null;
        if ($state === 'active') {
            $w[] = "m.state <> 'resolved'";
        } elseif (is_string($state) && in_array($state, self::STATES, true)) {
            $w[] = 'm.state = ?';
            $p[] = $state;
        }
        if (isset($f['severity']) && in_array($f['severity'], ['warn', 'crit'], true)) {
            $w[] = 'm.severity = ?';
            $p[] = $f['severity'];
        }
        foreach (['device_id', 'client_id'] as $k) {
            if (isset($f[$k]) && (int) $f[$k] > 0) {
                $w[] = "m.$k = ?";
                $p[] = (int) $f[$k];
            }
        }
        foreach (['check_key', 'group_key'] as $k) {
            if (isset($f[$k]) && is_string($f[$k]) && $f[$k] !== '') {
                $w[] = "m.$k = ?";
                $p[] = $f[$k];
            }
        }

        return [implode(' AND ', $w), $p];
    }

    /**
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     */
    public function present(array $m): array
    {
        return [
            'alert_id' => (int) $m['alert_id'], 'device_id' => (int) $m['device_id'], 'hostname' => (string) ($m['hostname'] ?? ''), 'asset_id' => ($m['asset_id'] ?? null) === null ? null : (int) $m['asset_id'],
            'client_id' => (int) $m['client_id'], 'check_key' => (string) $m['check_key'], 'episode' => (int) $m['episode'], 'group_key' => (string) $m['group_key'],
            'severity' => (string) $m['severity'], 'state' => (string) $m['state'], 'message' => (string) $m['message'], 'opened_at' => Sql::iso((string) $m['opened_at']),
            'acknowledged_by' => $m['acked_by'] === null ? null : (int) $m['acked_by'], 'acknowledged_at' => Sql::iso($m['acked_at'] === null ? null : (string) $m['acked_at']),
            'resolved_at' => Sql::iso($m['resolved_at'] === null ? null : (string) $m['resolved_at']), 'resolved_by' => $m['resolved_by'] === null ? null : (int) $m['resolved_by'],
            'resolve_reason' => $m['resolve_reason'] === null ? null : (string) $m['resolve_reason'],
            'policy_id' => $m['policy_id'] === null ? null : (int) $m['policy_id'], 'escalation_step' => (int) $m['esc_step'], 'notices_sent' => (int) $m['esc_count'],
            'next_escalation_at' => Sql::iso($m['esc_next_at'] === null ? null : (string) $m['esc_next_at']), 'last_notified_at' => Sql::iso($m['last_notified_at'] === null ? null : (string) $m['last_notified_at']),
        ];
    }
}
