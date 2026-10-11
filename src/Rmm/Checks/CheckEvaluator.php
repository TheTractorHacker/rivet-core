<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Checks;

use RivetCore\Rmm\Alerting\AlertingEngine;
use RivetCore\Rmm\Contracts\RmmBridgeInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * Check results to the edition's alerts, with debounce, dedupe and auto-resolve.
 *
 *  - fail/warn count as "bad", ok as "good", unknown changes nothing.
 *  - An alert opens after `failure_debounce` consecutive bad results and closes after `recovery_debounce` consecutive ok results.
 *  - The alert key is "agent:<device>:<check>:<episode>"; (integration, key) is unique in the bridge, so a re-delivered check-in can
 *    never create a second alert for the same episode.
 *  - Recovery resolves the alert through the bridge, which runs the edition's conservative auto-close of the linked ticket.
 *  - With the `alerting` sub-switch on (Phase 3) the results first pass through {@see AlertingEngine}: threshold tiers on a reported
 *    `value`, flap dampening, maintenance windows, dependency and storm control, and the alert lifecycle record. With it off none of that
 *    runs and no Phase 3 table is touched.
 *
 * @api
 */
final class CheckEvaluator
{
    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly RmmBridgeInterface $bridge,
        private readonly DeviceRepository $devices,
        private readonly ?RmmEventPublisher $events = null,
        private readonly ?AlertingEngine $alerting = null,
    ) {
    }

    /**
     * @param array<string,mixed> $dev
     * @param list<array{key:string,status:string,detail:string,at:string,value?:?float}> $results oldest first
     * @param array<string,array<string,mixed>>|null $defs the alerting parameters of the device's own check list ({@see AlertingEngine::definitionsOf()}), null = the instance's list
     */
    public function apply(array $dev, array $results, ?array $defs = null): void
    {
        if ($results === []) {
            return;
        }
        $cfg = $this->settings->get();
        $failN = max(1, (int) $cfg['failure_debounce']);
        $okN = max(1, (int) $cfg['recovery_debounce']);
        $now = $this->sql->utcNow();
        $pipe = $this->alerting !== null && $this->alerting->enabled() ? $this->alerting->begin($dev, $results, $defs) : null;
        $limits = $this->settings->limits();
        $keepDays = $limits['check_history_days'];
        $gap = $limits['check_history_gap_s'];
        /** @var list<array{0:string,1:string,2:string,3:string}> $history rows for endpoint_agent_check_history */
        $history = [];
        // ONE read of this device's check rows for every key in the batch, then the evaluation runs on an in-memory copy (a key that appears twice,
        // a buffered result followed by the current one, sees the first result's effect exactly as it did when each result was its own UPDATE) and
        // the changed rows are written back in ONE multi-row upsert. A key that has no row yet starts as the row the old INSERT IGNORE created.
        $keys = [];
        foreach ($results as $r) {
            $keys[$r['key']] = true;
        }
        /** @var array<string,array<string,mixed>> $rows */
        $rows = [];
        foreach ($this->sql->all('SELECT * FROM endpoint_agent_checks WHERE device_id = ? AND check_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')',
            [$dev['device_id'], ...array_map('strval', array_keys($keys))]) as $row) {
            $rows[(string) $row['check_key']] = $row;
        }
        /** @var array<string,true> $dirty */
        $dirty = [];
        $touch = $limits['check_touch_s'];
        $nowTs = Sql::ts($now);
        foreach ($results as $r) {
            $isNew = !isset($rows[$r['key']]);
            $row = $rows[$r['key']] ?? ['status' => 'unknown', 'detail' => '', 'consecutive_failures' => 0, 'consecutive_ok' => 0, 'episode' => 0, 'alert_id' => null,
                'last_reported_at' => $now, 'last_changed_at' => $now];
            $loaded = $row;
            $fails = (int) ($row['consecutive_failures'] ?? 0);
            $oks = (int) ($row['consecutive_ok'] ?? 0);
            $alertId = ($row['alert_id'] ?? null) === null ? null : (int) $row['alert_id'];
            $episode = (int) ($row['episode'] ?? 0);
            $prevStatus = (string) ($row['status'] ?? 'unknown');
            $needFails = $failN;
            $hold = false;
            if ($pipe !== null) {
                $adj = $pipe->adjust($r);
                $r['status'] = $adj['status'];
                $r['detail'] = $adj['detail'];
                $hold = $adj['hold'];
                $needFails = $adj['fail_n'] ?? $failN;
            }
            $prev = Sql::ts((string) ($row['last_reported_at'] ?? ''));
            $sameBucket = true;
            if ($keepDays > 0) {
                // Trend data without a row per sample: a change of status is always kept, an unchanged status once per $gap seconds.
                $sameBucket = intdiv(Sql::ts($r['at']), $gap) === intdiv($prev, $gap);
                if (($row['status'] ?? 'unknown') !== $r['status'] || !$sameBucket) {
                    $history[] = [$r['key'], $r['status'], mb_substr($r['detail'], 0, 200), $r['at']];
                }
            }
            $bad = in_array($r['status'], ['fail', 'warn'], true);
            // A flapping check, or a check inside a `suppress` maintenance window, is recorded but not evaluated: no counter moves, nothing opens or resolves.
            if ($pipe !== null && ($hold || (($bad || $alertId !== null) && $pipe->suppressChecks()))) {
                $row['status'] = $r['status'];
                $row['detail'] = $r['detail'];
                $row['last_reported_at'] = $now;
                $row['last_changed_at'] = $prevStatus !== $r['status'] ? $now : $row['last_changed_at'];
                $rows[$r['key']] = $row;
                $dirty[$r['key']] = true;
                continue;
            }
            if ($bad) {
                $fails++;
                $oks = 0;
                if ($alertId === null && $fails >= $needFails) {
                    $block = $pipe?->openBlock($r['key']);
                    if ($block === null) {
                        $episode++;
                        $alertId = $this->openAlert($dev, $r, $episode);
                        $this->events?->emit(RmmEvent::CHECK_FAILED, $dev, ['check_key' => $r['key'], 'status' => $r['status'], 'detail' => $r['detail'], 'alert_id' => $alertId, 'episode' => $episode]);
                        $pipe?->opened($r['key'], $episode, $alertId, $r['status'], $this->alertMessage($dev, $r));
                    }
                } elseif ($alertId !== null && $pipe !== null && $prevStatus !== $r['status'] && in_array($prevStatus, ['warn', 'fail'], true)) {
                    $pipe->severityChanged($r['key'], $alertId, $r['status']);
                }
            } elseif ($r['status'] === 'ok') {
                $oks++;
                $fails = 0;
                if ($alertId !== null && $oks >= $okN) {
                    $this->bridge->resolveAlert($this->settings->integrationId(), $alertId);
                    $this->events?->emit(RmmEvent::CHECK_RECOVERED, $dev, ['check_key' => $r['key'], 'alert_id' => $alertId, 'episode' => $episode]);
                    $pipe?->resolved($r['key'], $alertId);
                    $alertId = null;
                }
            }
            if ($touch > 0 && $r['status'] === 'ok' && $alertId === null && (int) $row['consecutive_ok'] >= $okN) {
                $oks = (int) $row['consecutive_ok'];   // opt-in: with no alert open the count of ok results past the recovery debounce decides nothing, so it stops growing
            }
            $unchanged = !$isNew && $prevStatus === $r['status'] && (string) $row['detail'] === $r['detail'] && (int) $row['consecutive_failures'] === $fails
                && (int) $row['consecutive_ok'] === $oks && (int) $row['episode'] === $episode && (($row['alert_id'] ?? null) === null ? null : (int) $row['alert_id']) === $alertId;
            if ($unchanged && $touch > 0 && $sameBucket && $nowTs - $prev < $touch && !isset($dirty[$r['key']])) {
                continue;   // opt-in (check_touch_s): nothing but the "last reported" stamp would move, and the history gap logic cannot tell the difference
            }
            $rows[$r['key']] = ['status' => $r['status'], 'detail' => $r['detail'], 'consecutive_failures' => $fails, 'consecutive_ok' => $oks, 'episode' => $episode,
                'alert_id' => $alertId, 'last_reported_at' => $now, 'last_changed_at' => $prevStatus !== $r['status'] ? $now : $loaded['last_changed_at']];
            $dirty[$r['key']] = true;
        }
        $pipe?->finish();
        foreach (array_chunk(array_keys($dirty), 200) as $chunk) {
            $params = [];
            foreach ($chunk as $key) {
                $row = $rows[(string) $key];
                array_push($params, $dev['device_id'], (string) $key, $row['status'], $row['detail'], $row['consecutive_failures'], $row['consecutive_ok'], $row['episode'], $row['alert_id'],
                    $row['last_reported_at'], $row['last_changed_at']);
            }
            $this->sql->run('INSERT INTO endpoint_agent_checks (device_id, check_key, status, detail, consecutive_failures, consecutive_ok, episode, alert_id, last_reported_at, last_changed_at) VALUES '
                . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'))
                . ' ON DUPLICATE KEY UPDATE status = VALUES(status), detail = VALUES(detail), consecutive_failures = VALUES(consecutive_failures), consecutive_ok = VALUES(consecutive_ok),'
                . ' episode = VALUES(episode), alert_id = VALUES(alert_id), last_reported_at = VALUES(last_reported_at), last_changed_at = VALUES(last_changed_at)', $params);
        }
        foreach (array_chunk($history, 200) as $chunk) {
            $params = [];
            foreach ($chunk as [$key, $status, $detail, $at]) {
                array_push($params, $dev['device_id'], $key, $status, $detail, $at);
            }
            $this->sql->run('INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)')), $params);
        }
    }

    /**
     * @param array<string,mixed> $dev
     * @param array{key:string,status:string,detail:string,at:string} $r
     */
    private function alertMessage(array $dev, array $r): string
    {
        return mb_substr("Endpoint agent check '" . $r['key'] . "' " . ($r['status'] === 'warn' ? 'warning' : 'failed') . ' on ' . $dev['hostname']
            . ($r['detail'] !== '' ? ': ' . $r['detail'] : ''), 0, 1000);
    }

    /**
     * @param array<string,mixed> $dev
     * @param array{key:string,status:string,detail:string,at:string} $r
     */
    private function openAlert(array $dev, array $r, int $episode): int
    {
        $msg = $this->alertMessage($dev, $r);

        return $this->bridge->openAlert(
            $this->settings->integrationId(),
            RmmProtocol::ALERT_KEY_PREFIX . $dev['device_id'] . ':' . $r['key'] . ':' . $episode,
            empty($dev['asset_id']) ? null : (int) $dev['asset_id'],
            (int) $dev['client_id'],
            $r['status'] === 'warn' ? 'warning' : 'error',
            $msg,
            ['source' => RmmProtocol::INTEGRATION_TYPE, 'device_id' => (int) $dev['device_id'], 'check' => $r['key'], 'status' => $r['status'], 'episode' => $episode],
        );
    }

    /** Device retired or removed: resolve everything still open so no orphan alert stays "new". */
    public function resolveAllOpen(int $deviceId, string $why): void
    {
        if ($this->devices->find($deviceId) === null) {
            return;
        }
        $integration = $this->settings->integrationId();
        foreach ($this->sql->all('SELECT check_key, alert_id FROM endpoint_agent_checks WHERE device_id = ? AND alert_id IS NOT NULL', [$deviceId]) as $c) {
            $this->bridge->resolveAlert($integration, (int) $c['alert_id']);
        }
        $this->sql->run('UPDATE endpoint_agent_checks SET alert_id = NULL WHERE device_id = ?', [$deviceId]);
        if ($this->alerting !== null && $this->alerting->enabled()) {
            $this->alerting->alerts->resolvedAllFor($deviceId, 'device_retired');
        }
    }
}
