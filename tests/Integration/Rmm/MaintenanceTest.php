<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Maintenance\Housekeeping;
use RivetCore\Tests\Support\RmmTestCase;

/** Housekeeping: the offline flip through the bridge, job sweeping and batched pruning. */
final class MaintenanceTest extends RmmTestCase
{
    /** @return array{0:int,1:string,2:int} device id, token, asset id */
    private function linked(string $serial): array
    {
        $tok = $this->h->token(null, 24, 500);
        $a = $this->h->asset(['name' => $serial, 'serial' => $serial]);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => $serial]));

        return [(int) $j['device_id'], $j['device_token'], $a];
    }

    public function testOfflineFlipSetsStatusAndTimestampThroughTheBridgeAndStatusMatches(): void
    {
        [$dev, $T, $asset] = $this->linked('MA-1');
        $intg = $this->h->integrationId();
        $this->h->checkin($T);
        $this->assertSame('online', $this->h->bridge->link($intg, "rivetit:$dev")['status']);
        $this->h->bridge->backdateStatusChange($intg, "rivetit:$dev");
        $this->h->module->settings()->set(['offline_after_s' => 900]);
        $this->assertSame(0, $this->h->module->housekeeping()->run()['offline'], 'a device that just checked in stays online');
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at='" . gmdate('Y-m-d H:i:s', time() - 2000) . "' WHERE device_id=$dev");
        $res = $this->h->module->housekeeping()->run();
        $this->assertSame(1, $res['offline']);
        $link = $this->h->bridge->link($intg, "rivetit:$dev");
        $this->assertSame('offline', $link['status']);
        $this->assertNotSame('2000-01-01 00:00:00', $link['status_changed_at'], 'rmm_status_changed_at moved (this feeds the asset_offline automation)');
        $this->assertSame(0, $this->h->module->housekeeping()->run()['offline'], 'already offline: nothing to flip');
        $st = $this->h->module->devices()->status($this->h->module->devices()->find($dev) ?? []);
        $this->assertSame('offline', $st['state']);
        $this->assertNotNull($st['offline_since']);
        $this->assertNotNull($st['last_checkin_at']);
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at='" . gmdate('Y-m-d H:i:s', time() - 86400 * 30) . "' WHERE device_id=$dev");
        $this->assertSame('stale', $this->h->module->devices()->status($this->h->module->devices()->find($dev) ?? [])['state']);
        $this->h->checkin($T);
        $this->assertSame('online', $this->h->module->devices()->status($this->h->module->devices()->find($dev) ?? [])['state']);
        $this->assertSame('online', $this->h->bridge->link($intg, "rivetit:$dev")['status']);
        $this->assertGreaterThan(0, $asset);
    }

    public function testStatusNeverBeforeTheFirstCheckin(): void
    {
        [$dev] = $this->linked('MA-2');
        $st = $this->h->module->devices()->status($this->h->module->devices()->find($dev) ?? []);
        $this->assertSame(['never', null, null, null], [$st['state'], $st['last_checkin_at'], $st['offline_since'], $st['age_s']]);
    }

    public function testTheOfflineFlipWorksInChunksOfFiveHundred(): void
    {
        $tok = $this->h->token(null, 24, 5000);
        unset($tok);
        $intg = $this->h->integrationId();
        $this->h->mysqli->query('DELETE FROM endpoint_agent_devices');
        $values = [];
        for ($i = 1; $i <= 1100; ++$i) {
            $values[] = "('" . $this->h::uuid() . "', 'H$i', 'amd64', '1.0.0', " . (1000 + $i) . ')';
        }
        $this->h->q('INSERT INTO endpoint_agent_devices (install_id, hostname, arch, agent_version, asset_id) VALUES ' . implode(',', $values));
        $this->h->module->housekeeping()->run();
        $this->assertSame([500, 500, 100], $this->h->bridge->offlineChunks);
        $this->assertGreaterThan(0, $intg);
    }

    public function testNothingHappensWhileTheMasterSwitchIsOff(): void
    {
        [$dev, $T] = $this->linked('MA-3');
        $this->h->checkin($T);
        $this->h->module->settings()->disable();
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at='" . gmdate('Y-m-d H:i:s', time() - 99999) . "'");
        $this->h->q("INSERT INTO endpoint_agent_enroll_attempts (ip_hash, attempted_at) VALUES ('x', '2001-01-01 00:00:00')");
        $this->assertSame(['offline' => 0, 'jobs_swept' => 0, 'pruned_checkins' => 0, 'pruned_attempts' => 0, 'pruned_jobs' => 0], $this->h->module->housekeeping()->run());
        $this->assertSame('online', $this->h->bridge->link($this->h->integrationId(), "rivetit:$dev")['status'], 'links are not flipped while the module is off');
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_enroll_attempts WHERE ip_hash='x'"), 'nothing is deleted while the module is off');
        $this->h->module->settings()->enable();
        $this->assertSame(1, $this->h->module->housekeeping()->run()['offline'], 'the first run after re-enable computes the true status');
    }

    public function testRetentionPruningAndJobSweep(): void
    {
        [$dev, $T] = $this->linked('MA-4');
        $this->h->module->settings()->set(['retention_days' => 30, 'job_retention_days' => 180]);
        $old = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
        $this->h->q("INSERT INTO endpoint_agent_checkins (device_id, seq, received_at) VALUES ($dev, 1, '$old'), ($dev, 2, '" . gmdate('Y-m-d H:i:s') . "')");
        $this->h->q("INSERT INTO endpoint_agent_enroll_attempts (ip_hash, attempted_at) VALUES ('old', '" . gmdate('Y-m-d H:i:s', time() - 31 * 86400) . "'), ('new', '" . gmdate('Y-m-d H:i:s') . "')");
        $row = $this->h->module->devices()->find($dev) ?? [];
        $j1 = (string) $this->h->module->jobs()->create($row, 'collect', null, [], null, false, 1)['job_id'];
        $j2 = (string) $this->h->module->jobs()->create($row, 'collect', null, [], null, false, 1)['job_id'];
        $this->h->q("UPDATE endpoint_agent_jobs SET state='succeeded', finished_at='" . gmdate('Y-m-d H:i:s', time() - 200 * 86400) . "' WHERE job_id='$j1'");
        $this->h->q("UPDATE endpoint_agent_jobs SET expires_at='" . gmdate('Y-m-d H:i:s', time() - 10) . "' WHERE job_id='$j2'");
        $res = $this->h->module->housekeeping()->run();
        $this->assertSame([1, 1, 1, 1], [$res['pruned_checkins'], $res['pruned_attempts'], $res['pruned_jobs'], $res['jobs_swept']]);
        $this->assertSame([2], array_map('intval', array_column($this->h->rows("SELECT seq FROM endpoint_agent_checkins WHERE device_id=$dev"), 'seq')));
        $this->assertSame('expired', $this->h->one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$j2'"));
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE job_id='$j1'"));
        unset($T);
    }

    public function testPruningRunsInBatchesWithAPauseAndAPerRunCap(): void
    {
        [$dev] = $this->linked('MA-5');
        $old = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
        $n = Housekeeping::PRUNE_BATCH * 2 + 123;
        $pauses = [];
        for ($from = 1; $from <= $n; $from += 5000) {
            $vals = [];
            for ($s = $from; $s < min($from + 5000, $n + 1); ++$s) {
                $vals[] = "($dev, $s, '$old')";
            }
            $this->h->q('INSERT INTO endpoint_agent_checkins (device_id, seq, received_at) VALUES ' . implode(',', $vals));
        }
        $hk = new Housekeeping($this->h->module->sql(), $this->h->module->settings(), $this->h->bridge, $this->h->module->jobs(), function (int $us) use (&$pauses): void {
            $pauses[] = $us;
        });
        $res = $hk->run();
        $this->assertSame($n, $res['pruned_checkins']);
        $this->assertSame([Housekeeping::PRUNE_PAUSE_US, Housekeeping::PRUNE_PAUSE_US], $pauses, 'a pause between batches, none after the last');
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checkins WHERE device_id=$dev"));
        $this->assertSame(5000, Housekeeping::PRUNE_BATCH);
    }
}
