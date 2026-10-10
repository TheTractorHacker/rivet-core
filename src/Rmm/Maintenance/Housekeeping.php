<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Maintenance;

use RivetCore\Rmm\Capacity\IngestQueue;
use RivetCore\Rmm\Capacity\LoadShedder;
use RivetCore\Rmm\Contracts\RmmBridgeInterface;
use RivetCore\Rmm\Device\DeviceState;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\Link\RmmLinker;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * Periodic housekeeping, run from the edition's cron entry: flip the edition's RMM links of devices that stopped checking in to
 * offline (in chunks, through the bridge), expire and fail jobs whose result can no longer arrive, and prune old rows in batches so
 * a large backlog never becomes one huge DELETE. Does nothing while the master switch is off (an admin flipping the switch must
 * not fire an asset_offline automation for every device, and nothing is deleted).
 *
 * @api
 */
final class Housekeeping
{
    public const OFFLINE_CHUNK = 500;
    public const PRUNE_BATCH = 5000;
    /** Rows one table may lose in one run; the next run continues. */
    public const PRUNE_RUN_CAP = 200000;
    public const PRUNE_PAUSE_US = 20000;
    public const ATTEMPT_RETENTION_DAYS = 30;

    /** @var \Closure(int):void */
    private \Closure $pause;

    /**
     * @param (\Closure(int):void)|null $pause called between delete batches with the microseconds to wait (default usleep)
     */
    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly RmmBridgeInterface $bridge,
        private readonly JobService $jobs,
        ?\Closure $pause = null,
        private readonly ?LoadShedder $shedder = null,
        private readonly ?IngestQueue $ingest = null,
        private readonly ?RmmEventPublisher $events = null,
        private readonly ?DeviceState $deviceState = null,
        private readonly ?DatabaseMetricSink $metricSink = null,
    ) {
        $this->pause = $pause ?? static function (int $us): void {
            usleep($us);
        };
    }

    /** @return array<string,int> */
    public function run(): array
    {
        $out = ['offline' => 0, 'jobs_swept' => 0, 'pruned_checkins' => 0, 'pruned_attempts' => 0, 'pruned_jobs' => 0];
        $cfg = $this->settings->get(true);
        if ((int) $cfg['enabled'] !== 1) {
            return $out;
        }
        $out['shed_level'] = $this->shedder === null ? (int) ($cfg['shed_level'] ?? 0) : $this->shedder->evaluate()['level'];
        if ($this->ingest !== null) {
            $out['pruned_ingest_jobs'] = $this->ingest->pruneCompleted();
        }
        $integration = $this->settings->integrationId();
        $out['offline'] = $this->flipOffline($integration, $this->sql->utcAt(-(int) $cfg['offline_after_s']));
        $out['jobs_swept'] = $this->jobs->sweep();
        $out['pruned_checkins'] = $this->prune('endpoint_agent_checkins', 'received_at < ?', [$this->sql->utcAt(-((int) $cfg['retention_days'] + 1) * 86400)]);
        $out['pruned_attempts'] = $this->prune('endpoint_agent_enroll_attempts', 'attempted_at < ?', [$this->sql->utcAt(-self::ATTEMPT_RETENTION_DAYS * 86400)]);
        $out['pruned_jobs'] = $this->prune('endpoint_agent_jobs', "state IN ('succeeded','failed','timed_out','cancelled','expired') AND finished_at < ?",
            [$this->sql->utcAt(-(int) $cfg['job_retention_days'] * 86400)]);
        $limits = $this->settings->limits();
        $out['pruned_check_history'] = $this->prune('endpoint_agent_check_history', 'reported_at < ?', [$this->sql->utcAt(-max(1, $limits['check_history_days']) * 86400)]);
        $softwareBefore = $this->sql->utcAt(-$limits['software_history_days'] * 86400);
        $out['pruned_software_history'] = $this->prune('rmm_software_history', 'occurred_at < ?', [$softwareBefore]);
        $out['pruned_software_removed'] = $this->prune('rmm_device_software', 'removed_at IS NOT NULL AND removed_at < ?', [$softwareBefore]);
        if ($this->metricSink !== null) {
            $out['pruned_metrics'] = $this->metricSink->prune(self::PRUNE_RUN_CAP, $this->pause);
        }
        if ($this->events !== null && $this->events->enabled() && $this->deviceState !== null) {
            $out['offline_events'] = $this->announceOffline((int) $cfg['offline_after_s'], (int) $cfg['stale_after_s']);
        }

        return $out;
    }

    /**
     * Devices linked to an asset that stopped checking in (only they can have a link): flip the edition's link to offline (this also feeds its asset_offline automation). Stale
     * device keys are read in pages and handed to the bridge in chunks of {@see OFFLINE_CHUNK}.
     */
    private function flipOffline(int $integration, string $before): int
    {
        $changed = 0;
        $after = 0;
        while (true) {
            $rows = $this->sql->all('SELECT device_id FROM endpoint_agent_devices WHERE device_id > ? AND asset_id IS NOT NULL AND (last_checkin_at IS NULL OR last_checkin_at < ?) ORDER BY device_id LIMIT ' . self::OFFLINE_CHUNK,
                [$after, $before]);
            if ($rows === []) {
                break;
            }
            $keys = [];
            foreach ($rows as $r) {
                $after = (int) $r['device_id'];
                $keys[] = RmmLinker::agentKey($after);
            }
            $changed += $this->bridge->markOffline($integration, $keys);
            if (count($rows) < self::OFFLINE_CHUNK) {
                break;
            }
        }

        return $changed;
    }

    /**
     * Tell the event bus about devices that just went offline (once per offline period: the state table remembers it). A device that has been silent for
     * longer than the stale window is recorded as offline without an event, so switching events on never announces a fleet of long-dead machines.
     */
    private function announceOffline(int $offlineAfter, int $staleAfter): int
    {
        if ($this->events === null || $this->deviceState === null) {
            return 0;
        }
        $before = $this->sql->utcAt(-$offlineAfter);
        $staleBefore = $this->sql->utcAt(-$staleAfter);
        $after = 0;
        $announced = 0;
        while (true) {
            $rows = $this->sql->all("SELECT d.* FROM endpoint_agent_devices d LEFT JOIN rmm_device_state s ON s.device_id = d.device_id
                WHERE d.device_id > ? AND d.revoked_at IS NULL AND d.retired_at IS NULL AND d.last_checkin_at IS NOT NULL AND d.last_checkin_at < ?
                AND (s.presence IS NULL OR s.presence <> 'offline') ORDER BY d.device_id LIMIT " . self::OFFLINE_CHUNK, [$after, $before]);
            foreach ($rows as $d) {
                $after = (int) $d['device_id'];
                $this->deviceState->markOffline($after);
                if ((string) $d['last_checkin_at'] >= $staleBefore) {
                    $this->events->emit(RmmEvent::DEVICE_OFFLINE, $d, ['last_checkin_at' => Sql::iso((string) $d['last_checkin_at'])]);
                    ++$announced;
                }
            }
            if (count($rows) < self::OFFLINE_CHUNK) {
                break;
            }
        }

        return $announced;
    }

    /** @param list<mixed> $params */
    private function prune(string $table, string $where, array $params): int
    {
        $total = 0;
        while ($total < self::PRUNE_RUN_CAP) {
            $n = $this->sql->run("DELETE FROM `$table` WHERE $where LIMIT " . self::PRUNE_BATCH, $params);
            $total += $n;
            if ($n < self::PRUNE_BATCH) {
                break;
            }
            ($this->pause)(self::PRUNE_PAUSE_US);
        }

        return $total;
    }
}
