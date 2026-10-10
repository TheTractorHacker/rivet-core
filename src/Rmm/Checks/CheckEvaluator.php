<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Checks;

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
    ) {
    }

    /**
     * @param array<string,mixed> $dev
     * @param list<array{key:string,status:string,detail:string,at:string}> $results oldest first
     */
    public function apply(array $dev, array $results): void
    {
        if ($results === []) {
            return;
        }
        $cfg = $this->settings->get();
        $failN = max(1, (int) $cfg['failure_debounce']);
        $okN = max(1, (int) $cfg['recovery_debounce']);
        $now = $this->sql->utcNow();
        $limits = $this->settings->limits();
        $keepDays = $limits['check_history_days'];
        $gap = $limits['check_history_gap_s'];
        /** @var list<array{0:string,1:string,2:string,3:string}> $history rows for endpoint_agent_check_history */
        $history = [];
        foreach ($results as $r) {
            $row = $this->sql->one('SELECT * FROM endpoint_agent_checks WHERE device_id = ? AND check_key = ?', [$dev['device_id'], $r['key']]);
            if ($row === null) {
                $this->sql->run('INSERT IGNORE INTO endpoint_agent_checks (device_id, check_key, status, detail, last_reported_at, last_changed_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$dev['device_id'], $r['key'], 'unknown', '', $now, $now]);
                $row = $this->sql->one('SELECT * FROM endpoint_agent_checks WHERE device_id = ? AND check_key = ?', [$dev['device_id'], $r['key']]) ?? [];
            }
            $fails = (int) ($row['consecutive_failures'] ?? 0);
            $oks = (int) ($row['consecutive_ok'] ?? 0);
            $alertId = ($row['alert_id'] ?? null) === null ? null : (int) $row['alert_id'];
            $episode = (int) ($row['episode'] ?? 0);
            if ($keepDays > 0) {
                // Trend data without a row per sample: a change of status is always kept, an unchanged status once per $gap seconds.
                $prev = Sql::ts((string) ($row['last_reported_at'] ?? ''));
                if (($row['status'] ?? 'unknown') !== $r['status'] || intdiv(Sql::ts($r['at']), $gap) !== intdiv($prev, $gap)) {
                    $history[] = [$r['key'], $r['status'], mb_substr($r['detail'], 0, 200), $r['at']];
                }
            }
            $bad = in_array($r['status'], ['fail', 'warn'], true);
            if ($bad) {
                $fails++;
                $oks = 0;
                if ($alertId === null && $fails >= $failN) {
                    $episode++;
                    $alertId = $this->openAlert($dev, $r, $episode);
                    $this->events?->emit(RmmEvent::CHECK_FAILED, $dev, ['check_key' => $r['key'], 'status' => $r['status'], 'detail' => $r['detail'], 'alert_id' => $alertId, 'episode' => $episode]);
                }
            } elseif ($r['status'] === 'ok') {
                $oks++;
                $fails = 0;
                if ($alertId !== null && $oks >= $okN) {
                    $this->bridge->resolveAlert($this->settings->integrationId(), $alertId);
                    $this->events?->emit(RmmEvent::CHECK_RECOVERED, $dev, ['check_key' => $r['key'], 'alert_id' => $alertId, 'episode' => $episode]);
                    $alertId = null;
                }
            }
            $this->sql->run('UPDATE endpoint_agent_checks SET status = ?, detail = ?, consecutive_failures = ?, consecutive_ok = ?, episode = ?, alert_id = ?, last_reported_at = ?,
                last_changed_at = IF(status <> ?, ?, last_changed_at) WHERE device_id = ? AND check_key = ?',
                [$r['status'], $r['detail'], $fails, $oks, $episode, $alertId, $now, $r['status'], $now, $dev['device_id'], $r['key']]);
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
    private function openAlert(array $dev, array $r, int $episode): int
    {
        $msg = mb_substr("Endpoint agent check '" . $r['key'] . "' " . ($r['status'] === 'warn' ? 'warning' : 'failed') . ' on ' . $dev['hostname']
            . ($r['detail'] !== '' ? ': ' . $r['detail'] : ''), 0, 1000);

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
    }
}
