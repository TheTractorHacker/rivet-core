<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

use RivetCore\Rmm\Contracts\RmmBridgeInterface;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Alert storm control: a cap on how many alerts may OPEN per client and in total inside a sliding window (settings row
 * rmm_alerting_settings, defaults 100 per client and 500 in total per 10 minutes; 0 turns a cap off). An alert over a cap is not opened and
 * not lost: the check stays bad, so it opens on a later sample once the rate has dropped. The first held alert of each window opens ONE
 * summary alert through the bridge (key `agent:storm:<scope>:<bucket>`, unique per scope and window) that says storm control is active; the
 * housekeeping tick resolves it when the rate is back under the cap. Counting reads at most `cap` rows of an indexed range and only when an
 * alert is about to open, never on a healthy check-in.
 *
 * @api
 */
final class StormControl
{
    /** @var array<string,int>|null */
    private ?array $cfg = null;

    public function __construct(private readonly Sql $sql, private readonly RmmSettings $settings, private readonly RmmBridgeInterface $bridge)
    {
    }

    /** @return array{storm_global_max:int,storm_global_window_s:int,storm_client_max:int,storm_client_window_s:int} */
    public function config(): array
    {
        if ($this->cfg === null) {
            $r = $this->sql->one('SELECT * FROM rmm_alerting_settings WHERE id = 1');
            if ($r === null) {
                $this->sql->run('INSERT IGNORE INTO rmm_alerting_settings (id, updated_at) VALUES (1, ?)', [$this->sql->utcNow()]);
                $r = $this->sql->one('SELECT * FROM rmm_alerting_settings WHERE id = 1') ?? [];
            }
            $this->cfg = [
                'storm_global_max' => (int) ($r['storm_global_max'] ?? 500), 'storm_global_window_s' => (int) ($r['storm_global_window_s'] ?? 600),
                'storm_client_max' => (int) ($r['storm_client_max'] ?? 100), 'storm_client_window_s' => (int) ($r['storm_client_window_s'] ?? 600),
            ];
        }

        return $this->cfg;
    }

    /**
     * @param array<string,mixed> $in any of the four keys
     * @return array{storm_global_max:int,storm_global_window_s:int,storm_client_max:int,storm_client_window_s:int}
     * @throws \InvalidArgumentException
     */
    public function update(array $in): array
    {
        $cur = $this->config();
        $limits = ['storm_global_max' => [0, 1000000], 'storm_client_max' => [0, 1000000], 'storm_global_window_s' => [60, 86400], 'storm_client_window_s' => [60, 86400]];
        foreach ($in as $k => $v) {
            if (!isset($limits[$k])) {
                throw new \InvalidArgumentException("Unknown setting $k.");
            }
            if (!is_int($v) || $v < $limits[$k][0] || $v > $limits[$k][1]) {
                throw new \InvalidArgumentException("$k must be a whole number from {$limits[$k][0]} to {$limits[$k][1]}.");
            }
            $cur[$k] = $v;
        }
        $this->sql->run('INSERT INTO rmm_alerting_settings (id, storm_global_max, storm_global_window_s, storm_client_max, storm_client_window_s, updated_at) VALUES (1, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE storm_global_max = VALUES(storm_global_max), storm_global_window_s = VALUES(storm_global_window_s), storm_client_max = VALUES(storm_client_max),
            storm_client_window_s = VALUES(storm_client_window_s), updated_at = VALUES(updated_at)',
            [$cur['storm_global_max'], $cur['storm_global_window_s'], $cur['storm_client_max'], $cur['storm_client_window_s'], $this->sql->utcNow()]);
        $this->cfg = null;

        return $this->config();
    }

    /**
     * May another alert open for this client now? Null when yes, else 'storm_client' or 'storm_global' (and the summary alert is opened).
     */
    public function check(int $clientId, ?int $assetId = null): ?string
    {
        $c = $this->config();
        if ($c['storm_client_max'] > 0) {
            $n = (int) $this->sql->val('SELECT COUNT(*) FROM (SELECT 1 FROM rmm_alert_meta WHERE client_id = ? AND opened_at >= ? LIMIT ' . $c['storm_client_max'] . ') t',
                [$clientId, $this->sql->utcAt(-$c['storm_client_window_s'])]);
            if ($n >= $c['storm_client_max']) {
                $this->summary('client:' . $clientId, $clientId, $c['storm_client_max'], $c['storm_client_window_s'], "client $clientId", $assetId);

                return 'storm_client';
            }
        }
        if ($c['storm_global_max'] > 0) {
            $n = (int) $this->sql->val('SELECT COUNT(*) FROM (SELECT 1 FROM rmm_alert_meta WHERE opened_at >= ? LIMIT ' . $c['storm_global_max'] . ') t', [$this->sql->utcAt(-$c['storm_global_window_s'])]);
            if ($n >= $c['storm_global_max']) {
                $this->summary('global', $clientId, $c['storm_global_max'], $c['storm_global_window_s'], 'all clients', $assetId);

                return 'storm_global';
            }
        }

        return null;
    }

    private function summary(string $scope, int $clientId, int $max, int $windowS, string $label, ?int $assetId): void
    {
        $bucket = gmdate('Y-m-d H:i:s', intdiv($this->sql->time(), $windowS) * $windowS);
        if ($this->sql->one('SELECT 1 AS x FROM rmm_storm_summaries WHERE scope = ? AND bucket = ?', [$scope, $bucket]) !== null) {
            return;
        }
        $alertId = $this->bridge->openAlert($this->settings->integrationId(), RmmProtocol::ALERT_KEY_PREFIX . 'storm:' . $scope . ':' . str_replace([' ', ':'], ['T', ''], $bucket), null, $clientId, 'error',
            "Alert storm control: more than $max endpoint alerts opened for $label in " . intdiv($windowS, 60) . ' minutes. Further alerts are held back and open when the rate drops.',
            ['source' => RmmProtocol::INTEGRATION_TYPE, 'storm' => $scope, 'cap' => $max, 'window_s' => $windowS]);
        $this->sql->run('INSERT IGNORE INTO rmm_storm_summaries (scope, bucket, alert_id, client_id, created_at) VALUES (?, ?, ?, ?, ?)', [$scope, $bucket, $alertId, $clientId, $this->sql->utcNow()]);
    }

    /** Resolve summary alerts whose scope is back under its cap. Returns how many were resolved. */
    public function resolveQuiet(): int
    {
        $c = $this->config();
        $n = 0;
        foreach ($this->sql->all('SELECT * FROM rmm_storm_summaries WHERE resolved_at IS NULL ORDER BY created_at LIMIT 100') as $s) {
            $scope = (string) $s['scope'];
            $isClient = str_starts_with($scope, 'client:');
            $max = $isClient ? $c['storm_client_max'] : $c['storm_global_max'];
            $win = $isClient ? $c['storm_client_window_s'] : $c['storm_global_window_s'];
            if ($max > 0 && Sql::ts((string) $s['created_at']) > $this->sql->time() - $win) {
                continue;   // too early to call it over
            }
            $count = $max <= 0 ? 0 : (int) $this->sql->val('SELECT COUNT(*) FROM (SELECT 1 FROM rmm_alert_meta WHERE ' . ($isClient ? 'client_id = ? AND ' : '') . 'opened_at >= ? LIMIT ' . $max . ') t',
                $isClient ? [(int) $s['client_id'], $this->sql->utcAt(-$win)] : [$this->sql->utcAt(-$win)]);
            if ($max > 0 && $count >= $max) {
                continue;
            }
            $this->bridge->resolveAlert($this->settings->integrationId(), (int) $s['alert_id']);
            $this->sql->run('UPDATE rmm_storm_summaries SET resolved_at = ? WHERE scope = ? AND bucket = ?', [$this->sql->utcNow(), $scope, $s['bucket']]);
            ++$n;
        }

        return $n;
    }
}
