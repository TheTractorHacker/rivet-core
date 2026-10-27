<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Contracts\ScheduleGateInterface;
use RivetCore\Tests\Support\DeployTestCase;

/** Phase 5: the housekeeping side: expectations, detection, gated installs (switches, rings, limits, retries, load shedding), the reports and the CSV. */
final class DeploySchedulerTest extends DeployTestCase
{
    private int $dev = 0;
    private string $tok = '';
    private int $pkg = 0;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->dev, $this->tok] = $this->linked('S1');
        $this->pkg = $this->package();
        $this->msi($this->pkg);
    }

    /** Detect the package as missing on a device (the scheduler asks, the device answers). */
    private function detectMissing(string $tok, int $pkg, bool $installed = false): void
    {
        $job = $this->offeredOne($tok, 'software_detect');
        $this->report($tok, $job, 'succeeded', self::detectOut([['key' => "p$pkg", 'installed' => $installed, 'version' => $installed ? '2.4.1' : '', 'satisfied' => $installed]]));
    }

    private function installJobs(string $mode = ''): int
    {
        return (int) $this->h->one("SELECT COUNT(*) FROM rmm_package_jobs WHERE type = 'software_install'" . ($mode === '' ? '' : " AND mode = '$mode'"));
    }

    public function testAnAssignmentLeadsToDetectionThenADryRunThenARealInstallThenCompliance(): void
    {
        $this->assign($this->pkg, 'client', $this->h->clientA);
        $r = $this->pass();
        $this->assertSame([1, 1], [$r['deploy_evaluated'], $r['deploy_detects_queued']], json_encode($r));
        $this->assertSame(0, $this->pass()['deploy_detects_queued'], 'a detection is already waiting');
        $this->detectMissing($this->tok, $this->pkg);
        $s = $this->api('GET', [(string) $this->dev, 'deployments'], $this->viewer)[1];
        $this->assertSame(1, $s['counts']['missing']);
        // live installs are off: a dry run, once a day
        $r = $this->pass();
        $this->assertSame([0, 1], [$r['deploy_installs_queued'], $r['deploy_dry_runs_queued']], json_encode($r));
        $job = $this->offeredOne($this->tok, 'software_install');
        $this->assertSame('dry_run', ((array) $job['params'])['mode']);
        $this->report($this->tok, $job, 'succeeded', self::installOut('would_install', ['mode' => 'dry_run']));
        $this->assertSame(0, $this->pass()['deploy_dry_runs_queued'], 'one scheduler dry run a day');
        $this->assertSame(1, $this->api('GET', [(string) $this->dev, 'deployments'], $this->viewer)[1]['counts']['missing'], 'a dry run changes nothing');
        // switch live installs on
        $this->limits(['software_live_install' => 1]);
        $r = $this->pass();
        $this->assertSame(1, $r['deploy_installs_queued'], json_encode($r));
        $job = $this->offeredOne($this->tok, 'software_install');
        $this->assertSame('run', ((array) $job['params'])['mode']);
        $this->assertNull($this->h->one("SELECT created_by FROM rmm_package_jobs WHERE mode = 'run'"));
        $this->assertSame('scheduler', $this->h->one("SELECT source FROM rmm_package_jobs WHERE mode = 'run'"));
        $this->assertSame(0, $this->pass()['deploy_installs_queued'], 'one install per device at a time');
        $this->report($this->tok, $job, 'succeeded', self::installOut('installed'));
        $s = $this->api('GET', [(string) $this->dev, 'deployments'], $this->viewer)[1];
        $this->assertSame([1, 0], [$s['counts']['compliant'], $s['counts']['missing']]);
        $r = $this->pass();
        $this->assertSame(0, $r['deploy_installs_queued'] + $r['deploy_dry_runs_queued'], 'nothing left to do');
        $this->assertSame(1, $this->installJobs('run'));
    }

    public function testNothingHappensWithoutAnAssignmentAndTheEmptyPassIsCheap(): void
    {
        $r = $this->pass();
        $this->assertSame(0, array_sum($r), json_encode($r));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_deploy_state'), 'no assignment, nothing tracked');
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type LIKE 'software%'"));
    }

    public function testHousekeepingRunsTheSoftwareWork(): void
    {
        $this->assign($this->pkg, 'global');
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame(1, $out['deploy_detects_queued'], json_encode($out));
    }

    public function testNoInstallBeforeAFirstDetectionAndNotOnADeviceThatCannotOrIsOffline(): void
    {
        [$old] = $this->linked('OLD', null, 'windows', self::caps('windows', false));
        [$off, $offTok] = $this->linked('OFF');
        $this->assign($this->pkg, 'global');
        $this->limits(['software_live_install' => 1]);
        $r = $this->pass();
        $this->assertSame(0, $r['deploy_installs_queued'], 'not detected yet');
        $this->assertSame(2, $r['deploy_detects_queued'], 'the old agent never announced detection');
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id = $old"));
        $this->detectMissing($offTok, $this->pkg);
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at = '" . gmdate('Y-m-d H:i:s', time() - 99999) . "' WHERE device_id = $off");
        $this->detectMissing($this->tok, $this->pkg);
        $this->h->q("UPDATE rmm_package_installs SET detected_at = '" . gmdate('Y-m-d H:i:s') . "', installed = 0, satisfied = 0 WHERE device_id = $old");
        $r = $this->pass();
        $this->assertSame(1, $r['deploy_installs_queued'], 'only the online device that announced installing: ' . json_encode($r));
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id IN ($old, $off) AND type = 'software_install'"));
    }

    public function testTheRingDelaysWhenADeviceGetsAPackage(): void
    {
        $this->switchOn(['software_deploy', 'patching']);
        $pid = (int) $this->api('POST', ['patch_policies'], $this->admin, ['name' => 'broad', 'settings' => ['ring' => 'broad']])[1]['policy']['policy_id'];
        $this->assertSame(200, $this->api('POST', ['patch_policies', (string) $pid, 'assignments'], $this->admin, ['scope_type' => 'global'])[0]);
        $this->limits(['software_live_install' => 1]);
        $this->assign($this->pkg, 'global');
        $this->pass();
        $this->detectMissing($this->tok, $this->pkg);
        $this->assertSame(['broad'], array_column($this->h->rows('SELECT ring FROM rmm_package_installs'), 'ring'));
        $r = $this->pass();
        $this->assertSame(0, $r['deploy_installs_queued'], 'the broad ring waits a week: ' . json_encode($r));
        $s = $this->api('GET', [(string) $this->dev, 'deployments'], $this->viewer)[1];
        $this->assertSame([1, 'scheduled'], [$s['counts']['scheduled'], $s['packages'][0]['state']]);
        $this->h->q("UPDATE rmm_package_installs SET expected_since = '" . gmdate('Y-m-d H:i:s', time() - 60) . "'");
        $this->assertSame(1, $this->pass()['deploy_installs_queued'], 'the delay has passed');
    }

    public function testTheMostSpecificAssignmentWinsAndUnassigningUninstallsNothing(): void
    {
        $this->assign($this->pkg, 'global');
        $this->assign($this->pkg, 'device', $this->dev, 'uninstall');
        $this->pass();
        $this->assertSame('uninstall', $this->h->one('SELECT expected_action FROM rmm_package_installs'));
        $this->detectMissing($this->tok, $this->pkg, true);
        $this->limits(['software_live_install' => 1]);
        $this->assertSame(1, $this->pass()['deploy_installs_queued']);
        $p = (array) $this->offeredOne($this->tok, 'software_install')['params'];
        $this->assertSame(['uninstall', 'run', 'msi', strtoupper(self::CODE)], [$p['action'], $p['mode'], $p['fmt'], $p['product_code']]);
        $this->assertArrayNotHasKey('url', $p, 'a removal downloads nothing');
        // removing the assignment removes the expectation and queues nothing
        $this->h->q("UPDATE endpoint_agent_jobs SET state = 'cancelled'");
        foreach ($this->h->rows('SELECT assignment_id FROM rmm_package_assignments') as $a) {
            $this->assertSame(200, $this->api('DELETE', ['software_assignments', (string) $a['assignment_id']], $this->admin)[0]);
        }
        $this->h->q('UPDATE rmm_package_jobs SET applied_at = NOW()');
        $this->pass();
        $this->assertNull($this->h->one('SELECT expected_action FROM rmm_package_installs'));
        $this->assertSame(0, $this->pass()['deploy_installs_queued']);
    }

    public function testAFailedInstallIsRetriedLaterAndNotMoreThanThreeTimes(): void
    {
        $this->assign($this->pkg, 'global');
        $this->limits(['software_live_install' => 1]);
        $this->pass();
        $this->detectMissing($this->tok, $this->pkg);
        for ($i = 1; $i <= 3; ++$i) {
            $this->assertSame(1, $this->pass()['deploy_installs_queued'], "attempt $i");
            $this->report($this->tok, $this->offeredOne($this->tok, 'software_install'), 'failed', self::installOut('hash_mismatch', ['ok' => false]), 1);
            $this->assertSame(0, $this->pass()['deploy_installs_queued'], 'not again straight away');
            $this->assertSame('failed', $this->api('GET', [(string) $this->dev, 'deployments'], $this->viewer)[1]['packages'][0]['state']);
            $this->h->q("UPDATE rmm_package_installs SET last_attempt_at = '" . gmdate('Y-m-d H:i:s', time() - 13 * 3600) . "'");
            $this->h->q('UPDATE rmm_package_jobs SET idem_key = NULL');   // the idempotency key is per hour; a real retry is twelve hours later
        }
        $this->assertSame(0, $this->pass()['deploy_installs_queued'], 'three failures: an administrator must look');
        $this->assertSame(3, $this->installJobs('run'));
    }

    public function testConcurrencyIsLimitedFleetWideAndPerClient(): void
    {
        $this->limits(['software_live_install' => 1, 'software_max_installs' => 2, 'software_max_installs_per_client' => 1]);
        $devs = [[$this->dev, $this->tok], $this->linked('S2'), $this->linked('B1', $this->h->clientB), $this->linked('B2', $this->h->clientB)];
        $this->assign($this->pkg, 'global');
        $this->pass();
        foreach ($devs as [, $t]) {
            $this->detectMissing($t, $this->pkg);
        }
        $r = $this->pass();
        $this->assertSame(2, $r['deploy_installs_queued'], 'one per client, two in all: ' . json_encode($r));
        $this->assertSame(2, $r['deploy_deferred']);
    }

    public function testASuppressWindowOrAGateHoldsInstallsBack(): void
    {
        $this->h->module->setScheduleGate(new class() implements ScheduleGateInterface {
            public function decide(array $device, array $schedule, int $slotTimestamp): string
            {
                return ($schedule['kind'] ?? '') === 'software' ? self::DEFER : self::RUN;
            }
        });
        $this->assign($this->pkg, 'global');
        $this->limits(['software_live_install' => 1]);
        $this->pass();
        $this->detectMissing($this->tok, $this->pkg);
        $r = $this->pass();
        $this->assertSame([0, 0], [$r['deploy_installs_queued'], $r['deploy_dry_runs_queued']]);
        $this->assertSame(1, $r['deploy_deferred']);
    }

    public function testLoadSheddingStopsQueueingAndAppliesResultsLater(): void
    {
        $this->assign($this->pkg, 'global');
        $this->pass();
        $this->h->q('UPDATE endpoint_agent_settings SET shed_level = 2');
        $this->h->module->settings()->refresh();
        $this->detectMissing($this->tok, $this->pkg);
        $this->assertNull($this->h->one('SELECT detected_at FROM rmm_package_installs'), 'at shed level 2 the result waits for the next pass');
        $r = $this->pass();
        $this->assertSame(0, $r['deploy_applied'] + $r['deploy_detects_queued'] + $r['deploy_installs_queued']);
        $this->h->q('UPDATE endpoint_agent_settings SET shed_level = 0');
        $this->assertSame(1, $this->pass()['deploy_applied']);
        $this->assertNotNull($this->h->one('SELECT detected_at FROM rmm_package_installs'));
    }

    public function testAResultThatIsAppliedTwiceChangesNothingTheSecondTime(): void
    {
        $this->assign($this->pkg, 'global');
        $this->limits(['software_live_install' => 1]);
        $this->api('POST', [(string) $this->dev, 'deployments', 'run'], $this->deployer, ['package_id' => $this->pkg, 'mode' => 'run', 'confirm' => true]);
        $this->report($this->tok, $this->offeredOne($this->tok, 'software_install'), 'succeeded', self::installOut('installed'));
        $job = $this->h->rows("SELECT * FROM endpoint_agent_jobs WHERE type = 'software_install'")[0];
        $dev = $this->h->module->devices()->find($this->dev) ?? [];
        $this->assertFalse($this->h->module->deployService()->applyJob($dev, $job));
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_package_history'));
        $this->assertSame(1, (int) $this->h->one('SELECT attempts FROM rmm_package_installs'));
    }

    // ------------------------------------------------------------------ the report

    private function fleet(): void
    {
        $p2 = $this->package('Second');
        $this->msi($p2, '1.0', ['product_code' => '{CCCCCCCC-CCCC-CCCC-CCCC-CCCCCCCCCCCC}']);
        $devs = [[$this->dev, $this->tok], $this->linked('S2'), $this->linked('B1', $this->h->clientB), $this->linked('=cmd|calc', $this->h->clientB)];
        $this->assign($this->pkg, 'global');
        $this->assign($p2, 'client', $this->h->clientB);
        $this->pass();
        foreach ($devs as $i => [, $t]) {
            $keys = [];
            foreach ($this->offered($t) as $j) {
                if ($j['type'] === 'software_detect') {
                    foreach (json_decode((string) ((array) $j['params'])['rules'], true) as $r) {
                        $keys[] = ['key' => $r['key'], 'installed' => $i !== 1, 'version' => '2.4.1', 'satisfied' => $i !== 1];
                    }
                    $this->report($t, $j, 'succeeded', self::detectOut($keys));
                }
            }
        }
    }

    public function testTheReportCountsDeployedAgainstExpectedByPackageClientRingAndDevice(): void
    {
        $this->fleet();
        $byPkg = $this->api('GET', ['software_report'], $this->admin)[1];
        $this->assertSame('package', $byPkg['group_by']);
        $this->assertSame(['Acme Agent' => [4, 3, 1], 'Second' => [2, 2, 0]], array_column(array_map(static fn (array $r): array => [$r['label'], [$r['expected'], $r['compliant'], $r['missing']]], $byPkg['rows']), 1, 0));
        $this->assertSame([6, 5, 83], [$byPkg['totals']['expected'], $byPkg['totals']['compliant'], $byPkg['totals']['percent_compliant']]);
        $byClient = $this->api('GET', ['software_report'], $this->admin, null, ['group_by' => 'client'])[1];
        $this->assertCount(2, $byClient['rows']);
        $byRing = $this->api('GET', ['software_report'], $this->admin, null, ['group_by' => 'ring'])[1];
        $this->assertSame(['(no ring)'], array_column($byRing['rows'], 'label'));
        $byDev = $this->api('GET', ['software_report'], $this->admin, null, ['group_by' => 'device', 'state' => 'missing'])[1];
        $this->assertSame(['S2'], array_column($byDev['rows'], 'hostname'));
        $this->assertSame(200, $this->api('GET', ['software_report'], $this->admin, null, ['group_by' => 'nonsense'])[0], 'an unknown grouping falls back, it is not an error');
        $this->assertSame(422, $this->api('GET', ['software_report'], $this->admin, null, ['client_id' => 'abc'])[0]);
        // filters
        $one = $this->api('GET', ['software_report'], $this->admin, null, ['client_id' => (string) $this->h->clientB])[1];
        $this->assertSame(4, $one['totals']['expected']);
    }

    public function testAClientRestrictedViewerSeesOnlyTheirOwnClient(): void
    {
        $this->fleet();
        $r = $this->api('GET', ['software_report'], $this->scoped)[1];
        $this->assertSame(4, $r['totals']['expected'], 'two devices of client B, with one and two packages');
        $dev = $this->api('GET', ['software_report'], $this->scoped, null, ['group_by' => 'device'])[1];
        $this->assertSame(['=cmd|calc', '=cmd|calc', 'B1', 'B1'], array_column($dev['rows'], 'hostname') === [] ? [] : array_column($dev['rows'], 'hostname'));
        $this->assertSame(404, $this->api('GET', ['software_report'], $this->scoped, null, ['client_id' => (string) $this->h->clientA])[0]);
        $csv = $this->api('GET', ['software_report', 'csv'], $this->scoped, null, ['group_by' => 'device']);
        $this->assertSame(200, $csv[0]);
        $this->assertStringNotContainsString('S2', (string) $csv[2]->body);
    }

    public function testTheCsvNeverStartsACellWithAFormulaCharacter(): void
    {
        $this->fleet();
        [$c, , $resp] = $this->api('GET', ['software_report', 'csv'], $this->admin, null, ['group_by' => 'device']);
        $this->assertSame(200, $c);
        $this->assertStringStartsWith('text/csv', $resp->headers['Content-Type']);
        $this->assertSame('nosniff', $resp->headers['X-Content-Type-Options']);
        $body = (string) $resp->body;
        $this->assertStringContainsString("'=cmd|calc", $body);
        $this->assertStringNotContainsString(',=cmd', $body);
        $this->assertStringEndsWith("\r\n", $body);
        $lines = explode("\r\n", trim($body));
        $this->assertSame('device_id,hostname,client_id,package_id,package,ring,expected,expected_version,installed_version,state,last_result', $lines[0]);
        $this->assertGreaterThan(5, count($lines));
        $this->assertSame(403, $this->api('GET', ['software_report', 'csv'], new \RivetCore\Rmm\Authz\RmmPrincipal(99, 'Nobody'))[0]);
    }

    /** rc.38 (security review RC36 M3): the software deployment scheduler leaves a device that is switched out of automation alone, for detection, install and uninstall. */
    public function testDeviceAutomationDisabledStopsDetectionInstallAndUninstall(): void
    {
        $this->assign($this->pkg, 'client', $this->h->clientA);
        $this->limits(['software_live_install' => 1]);
        $ds = $this->h->module->deviceService();
        $this->assertTrue($ds->setAutomationDisabled($this->dev, true, 1));
        $r = $this->pass();
        $this->assertSame(0, $r['deploy_evaluated'] + $r['deploy_detects_queued'] + $r['deploy_installs_queued'] + $r['deploy_dry_runs_queued'], json_encode($r));
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id = {$this->dev}"));
        // enabled again: detection starts
        $this->assertTrue($ds->setAutomationDisabled($this->dev, false, 1));
        $this->assertSame(1, $this->pass()['deploy_detects_queued']);
        $this->detectMissing($this->tok, $this->pkg);
        // detected as missing, then switched off: no dry run, no live install
        $this->assertTrue($ds->setAutomationDisabled($this->dev, true, 1));
        $r = $this->pass();
        $this->assertSame(0, $r['deploy_installs_queued'] + $r['deploy_dry_runs_queued'], json_encode($r));
        $this->assertSame(0, $this->installJobs());
        $this->assertTrue($ds->setAutomationDisabled($this->dev, false, 1));
        $this->assertSame(1, $this->pass()['deploy_installs_queued'], 'and it continues where it stopped');
        // an uninstall by assignment is held back the same way
        $this->finishInstalled();
        $this->h->q('DELETE FROM rmm_package_assignments');
        $this->assign($this->pkg, 'client', $this->h->clientA, 'uninstall');
        $this->assertTrue($ds->setAutomationDisabled($this->dev, true, 1));
        $this->pass();
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM rmm_package_jobs WHERE action = 'uninstall'"));
    }

    private function finishInstalled(): void
    {
        $job = $this->offeredOne($this->tok, 'software_install');
        $this->report($this->tok, $job, 'succeeded', self::installOut('installed'));
        $this->h->q("UPDATE rmm_package_installs SET installed = 1, satisfied = 1, detected_at = UTC_TIMESTAMP() WHERE device_id = {$this->dev}");
    }

    public function testTurningTheSwitchOnCancelsQueuedSchedulerJobsButNotAManualInstall(): void
    {
        $this->assign($this->pkg, 'client', $this->h->clientA);
        $this->pass();
        $this->detectMissing($this->tok, $this->pkg);
        $this->pass();   // a scheduler dry run is queued
        $queued = $this->h->rows("SELECT j.job_id FROM rmm_package_jobs p JOIN endpoint_agent_jobs j ON j.job_id = p.job_id WHERE p.source = 'scheduler' AND j.state = 'queued'");
        $this->assertCount(1, $queued);
        // a technician's own manual detection on the same device stays
        $dev = $this->h->module->devices()->find($this->dev);
        $m = $this->h->module->deployService()->queueDetect($dev, 5, 'manual');
        $this->assertTrue($m['ok'] && !isset($m['skipped']), json_encode($m));
        $this->assertTrue($this->h->module->deviceService()->setAutomationDisabled($this->dev, true, 5));
        $this->assertSame('cancelled', $this->h->one("SELECT state FROM endpoint_agent_jobs WHERE job_id = '{$queued[0]['job_id']}'"));
        $this->assertSame('automation_disabled', $this->h->one("SELECT reason FROM endpoint_agent_jobs WHERE job_id = '{$queued[0]['job_id']}'"));
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs j JOIN rmm_package_jobs p ON p.job_id = j.job_id WHERE p.source = 'manual' AND j.state = 'queued'"));
    }
}
