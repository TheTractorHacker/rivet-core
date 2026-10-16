<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Tests\Support\PatchTestCase;

/** Phase 4: the housekeeping side: due scans, automatic approval, gated installs (switches, windows, limits, load shedding), compliance and read models. */
final class PatchSchedulerTest extends PatchTestCase
{
    private int $dev = 0;
    private string $tok = '';

    protected function setUp(): void
    {
        parent::setUp();
        [$this->dev, $this->tok] = $this->linked('S1');
    }

    private function pass(): array
    {
        $this->h->module->settings()->refresh();

        return $this->h->module->patchScheduler()->run();
    }

    /** @param array<string,mixed> $settings */
    private function policy(array $settings, string $scope = 'global', int $scopeId = 0, string $name = 'p'): int
    {
        [$c, $j] = $this->api('POST', ['patch_policies'], $this->admin, ['name' => $name, 'settings' => $settings]);
        $this->assertSame(201, $c, json_encode($j));
        $pid = (int) $j['policy']['policy_id'];
        $this->assertSame(200, $this->api('POST', ['patch_policies', (string) $pid, 'assignments'], $this->admin, ['scope_type' => $scope, 'scope_id' => $scopeId])[0]);

        return $pid;
    }

    private function approvedUpdate(): void
    {
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A']]));
        $this->api('POST', ['patches', 'approve'], $this->tech, ['update_ids' => [self::WIN_KB]]);
    }

    private function limits(array $l): void
    {
        $this->assertSame([], $this->h->module->settings()->update(['limits_json' => json_encode($l)]));
    }

    private function installJobs(): int
    {
        return (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = 'patch_install'");
    }

    // ------------------------------------------------------------------ scans

    public function testADeviceThatAnnouncedScanningGetsOneScanWhenItsIsDueAndThenWaitsForTheInterval(): void
    {
        $r = $this->pass();
        $this->assertSame(1, $r['patch_scans_queued']);
        $this->assertSame(0, $this->pass()['patch_scans_queued'], 'already waiting');
        $job = $this->offered($this->tok)[0];
        $this->assertSame('patch_scan', $job['type']);
        $this->report($this->tok, $job, 'succeeded', self::winScan([self::WIN_KB => ['optional', 'A']]));
        $this->assertSame(0, $this->pass()['patch_scans_queued'], 'scanned a moment ago');
        $this->h->q("UPDATE rmm_patch_scans SET scanned_at = '" . gmdate('Y-m-d H:i:s', time() - 3 * 86400) . "', requested_at = '" . gmdate('Y-m-d H:i:s', time() - 3 * 86400) . "'");
        $this->assertSame(1, $this->pass()['patch_scans_queued'], 'due again after the interval (plus the device\'s own jitter)');
    }

    public function testNoScanForAnOfflineDeviceAnOldAgentOrWhileLoadIsBeingShed(): void
    {
        [$old] = $this->linked('OLD', null, 'windows', self::caps('windows', false));
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at = '" . gmdate('Y-m-d H:i:s', time() - 99999) . "' WHERE device_id = {$this->dev}");
        $this->assertSame(0, $this->pass()['patch_scans_queued'], 'the other device is offline and the old agent never announced scanning');
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id = $old"));
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at = '" . gmdate('Y-m-d H:i:s') . "'");
        $this->h->q('UPDATE endpoint_agent_settings SET shed_level = 2');
        $r = $this->pass();
        $this->assertSame([0, 0], [$r['patch_scans_queued'], $r['patch_installs_queued']], 'level 2 queues nothing');
        $this->h->q('UPDATE endpoint_agent_settings SET shed_level = 0');
        $this->assertSame(1, $this->pass()['patch_scans_queued']);
    }

    public function testAResultThatArrivesWhileLoadIsShedIsAppliedByTheNextPass(): void
    {
        $this->assertSame(202, $this->api('POST', [(string) $this->dev, 'patches', 'scan'], $this->tech)[0]);
        $job = $this->offered($this->tok)[0];
        $this->h->q('UPDATE endpoint_agent_settings SET shed_level = 2');
        $this->h->module->settings()->refresh();
        $this->report($this->tok, $job, 'succeeded', self::winScan([self::WIN_KB => ['security', 'A']]));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_patch_available'), 'not applied under level 2');
        $this->h->q('UPDATE endpoint_agent_settings SET shed_level = 1');
        $this->assertSame(1, $this->pass()['patch_applied']);
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_patch_available'));
        $this->assertSame(0, $this->pass()['patch_applied'], 'applied once');
    }

    public function testALostResultIsGivenAnHourToArriveLate(): void
    {
        $this->approvedUpdate();
        $this->api('POST', [(string) $this->dev, 'patches', 'install'], $this->installer, ['mode' => 'install', 'confirm' => true]);
        $job = $this->offered($this->tok)[0];
        $this->h->q("UPDATE endpoint_agent_jobs SET state = 'failed', reason = 'result_lost', finished_at = '" . gmdate('Y-m-d H:i:s') . "' WHERE job_id = '{$job['job_id']}'");
        $this->assertSame(0, $this->pass()['patch_applied'], 'a lost result may still arrive');
        $this->h->q("UPDATE endpoint_agent_jobs SET finished_at = '" . gmdate('Y-m-d H:i:s', time() - 7200) . "' WHERE job_id = '{$job['job_id']}'");
        $this->assertSame(1, $this->pass()['patch_applied']);
        $this->assertSame('failed', $this->h->one("SELECT status FROM rmm_patch_available WHERE update_id = '" . self::WIN_KB . "'"));
    }

    // ------------------------------------------------------------------ installs

    public function testNothingIsInstalledWithoutAPolicyThatSwitchesInstallingOn(): void
    {
        $this->approvedUpdate();
        $r = $this->pass();
        $this->assertSame([0, 0, 1], [$r['patch_installs_queued'], $r['patch_dry_runs_queued'], $r['patch_install_deferred']]);
        $this->assertSame(0, $this->installJobs());
    }

    public function testWithInstallingOnTheSchedulerQueuesOnlyADryRunUntilLiveInstallsAreSwitchedOn(): void
    {
        $this->approvedUpdate();
        $this->policy(['install_enabled' => true]);
        $r = $this->pass();
        $this->assertSame([0, 1], [$r['patch_installs_queued'], $r['patch_dry_runs_queued']]);
        $job = array_values(array_filter($this->offered($this->tok), static fn (array $j): bool => $j['type'] === 'patch_install'))[0];
        $this->assertSame('dry_run', ((array) $job['params'])['mode']);
        $this->report($this->tok, $job, 'succeeded', (string) json_encode(['v' => 1, 'ok' => true, 'mode' => 'dry_run', 'manager' => 'wua', 'results' => [['id' => self::WIN_KB, 'title' => 'A', 'result' => 'would_install']], 'truncated' => false]));
        $this->assertSame(0, $this->pass()['patch_dry_runs_queued'], 'one scheduler dry run a day');
        $this->limits(['patch_live_install' => 1]);
        $r = $this->pass();
        $this->assertSame(1, $r['patch_installs_queued']);
        $live = $this->h->rows("SELECT params_json, created_by FROM endpoint_agent_jobs WHERE type = 'patch_install' AND params_json LIKE '%\"install\"%'");
        $this->assertCount(1, $live);
        $this->assertNull($this->h->one("SELECT created_by FROM rmm_patch_jobs WHERE source = 'scheduler' AND mode = 'install'"));
        $this->assertSame(0, $this->pass()['patch_installs_queued'], 'one install per device at a time');
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM rmm_patch_jobs WHERE mode = 'install'"));
    }

    public function testADeclinedOrUnapprovedUpdateIsNeverScheduled(): void
    {
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A'], self::WIN_KB2 => ['security', 'B']]));
        $this->api('POST', ['patches', 'decline'], $this->tech, ['update_ids' => [self::WIN_KB2]]);
        $this->policy(['install_enabled' => true]);
        $this->limits(['patch_live_install' => 1]);
        $r = $this->pass();
        $this->assertSame(0, $r['patch_installs_queued'] + $r['patch_dry_runs_queued']);
        $this->assertSame(0, $this->installJobs());
    }

    public function testADailyWindowHoldsInstallsBackUntilItIsOpen(): void
    {
        $this->approvedUpdate();
        $this->limits(['patch_live_install' => 1]);
        $dow = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $today = $dow[(int) gmdate('N') - 1];
        $other = $dow[(int) gmdate('N') % 7];
        $this->policy(['install_enabled' => true, 'window_mode' => 'daily', 'window_days' => $other, 'window_start' => '00:00', 'window_minutes' => 1440, 'window_tz' => 'UTC']);
        $this->assertSame(0, $this->pass()['patch_installs_queued'], 'tomorrow\'s window is not open');
        $this->api('PUT', ['patch_policies', (string) $this->h->one('SELECT policy_id FROM rmm_patch_policies')], $this->admin, ['name' => 'p', 'settings' => ['install_enabled' => true, 'window_mode' => 'daily', 'window_days' => $today,
            'window_start' => '00:00', 'window_minutes' => 1440, 'window_tz' => 'UTC']]);
        // spread: the device starts a little after the window opens; the whole day is open, so by now (hours in) it surely is due unless the day only just began
        $r = $this->pass();
        $this->assertSame(1, $r['patch_installs_queued'] + ((int) gmdate('G') === 0 && (int) gmdate('i') < 10 ? 1 : 0), json_encode($r));
    }

    public function testAMaintenanceWindowIsAnInstallWindowAndASuppressWindowOfAnotherKindBlocksTheDailyOne(): void
    {
        $this->switchOn(['patching', 'alerting']);
        $this->approvedUpdate();
        $this->limits(['patch_live_install' => 1]);
        [$c, $j] = $this->api('POST', ['maintenance'], $this->admin, ['name' => 'Patch night', 'kind' => 'once', 'starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 600), 'ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600), 'scope_type' => 'all', 'mode' => 'suppress']);
        $this->assertSame(201, $c, json_encode($j));
        $wid = (int) $j['window']['window_id'];
        $this->policy(['install_enabled' => true, 'window_mode' => 'maintenance', 'window_maintenance_id' => $wid]);
        $this->assertSame(1, $this->pass()['patch_installs_queued'], 'the chosen maintenance window is open (even though it is a suppress window: that is the maintenance time)');
        // a different, unrelated suppress window blocks a policy without a window of its own
        $this->h->q('DELETE FROM rmm_patch_jobs');
        $this->h->q("DELETE FROM endpoint_agent_jobs WHERE type = 'patch_install'");
        $this->h->q('DELETE FROM rmm_patch_assignments');
        $this->policy(['install_enabled' => true], 'global', 0, 'plain');
        $this->assertSame(0, $this->pass()['patch_installs_queued'], 'a suppress window says "leave this device alone"');
        $this->api('DELETE', ['maintenance', (string) $wid], $this->admin);
        $this->assertSame(1, $this->pass()['patch_installs_queued']);
    }

    public function testConcurrencyIsLimitedFleetWideAndPerClient(): void
    {
        $this->limits(['patch_live_install' => 1, 'patch_max_installs' => 2, 'patch_max_installs_per_client' => 1]);
        $devs = [[$this->dev, $this->tok]];
        $devs[] = $this->linked('S2');
        $devs[] = $this->linked('B1', $this->h->clientB);
        $devs[] = $this->linked('B2', $this->h->clientB);
        foreach ($devs as [$d, $t]) {
            $this->scanWith($d, $t, self::winScan([self::WIN_KB => ['security', 'A']]));
        }
        $this->api('POST', ['patches', 'approve'], $this->admin, ['update_ids' => [self::WIN_KB]]);
        $this->policy(['install_enabled' => true]);
        $r = $this->pass();
        $this->assertSame(2, $r['patch_installs_queued'], 'one per client, two in all');
        $this->assertSame(2, $r['patch_install_deferred']);
        $per = array_column($this->h->rows("SELECT client_id, COUNT(*) AS c FROM endpoint_agent_jobs WHERE type = 'patch_install' GROUP BY client_id"), 'c', 'client_id');
        $this->assertEquals([$this->h->clientA => 1, $this->h->clientB => 1], $per);
    }

    public function testAFailedUpdateIsRetriedAfterAPauseAndNotMoreThanThreeTimes(): void
    {
        $this->approvedUpdate();
        $this->limits(['patch_live_install' => 1]);
        $this->policy(['install_enabled' => true]);
        $this->h->q("UPDATE rmm_patch_available SET status = 'failed', fail_count = 1, last_attempt_at = '" . gmdate('Y-m-d H:i:s', time() - 600) . "'");
        $this->assertSame(0, $this->pass()['patch_installs_queued'], 'not again within the pause');
        $this->h->q("UPDATE rmm_patch_available SET last_attempt_at = '" . gmdate('Y-m-d H:i:s', time() - 86400) . "'");
        $this->assertSame(1, $this->pass()['patch_installs_queued']);
        $this->h->q("DELETE FROM endpoint_agent_jobs WHERE type = 'patch_install'");
        $this->h->q('DELETE FROM rmm_patch_jobs');
        $this->h->q("UPDATE rmm_patch_available SET fail_count = 3, status = 'failed', last_attempt_at = '" . gmdate('Y-m-d H:i:s', time() - 86400) . "'");
        $this->assertSame(0, $this->pass()['patch_installs_queued'], 'given up after three failures: an administrator approves it again');
    }

    // ------------------------------------------------------------------ compliance

    public function testComplianceWarnsThenFailsAsACriticalUpdateAgesAndRecoversOnceInstalled(): void
    {
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A']]));
        $check = fn (): array => $this->h->rows("SELECT status, detail FROM endpoint_agent_checks WHERE check_key = 'patch.compliance'")[0] ?? [];
        $this->assertSame('ok', $check()['status'], 'a fresh update is no problem yet');
        $this->h->q("UPDATE rmm_patch_available SET first_seen_at = '" . gmdate('Y-m-d H:i:s', time() - 15 * 86400) . "'");
        $this->h->q('UPDATE rmm_patch_scans SET evaluated_at = NULL');
        $this->pass();
        $c = $check();
        $this->assertSame('warn', $c['status']);
        $this->assertStringContainsString('missing for more than 14 days', $c['detail']);
        $this->h->q("UPDATE rmm_patch_available SET first_seen_at = '" . gmdate('Y-m-d H:i:s', time() - 40 * 86400) . "'");
        $this->h->q('UPDATE rmm_patch_scans SET evaluated_at = NULL');
        $this->pass();
        $this->assertSame('fail', $check()['status']);
        $this->assertGreaterThan(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_checks WHERE check_key = \'patch.compliance\' AND alert_id IS NOT NULL'));
        // the update is installed: the next scan no longer lists it
        $this->scanWith($this->dev, $this->tok, self::winScan([]));
        $this->assertSame('ok', $check()['status']);
        $this->assertNull($this->h->one("SELECT alert_id FROM endpoint_agent_checks WHERE check_key = 'patch.compliance'"), 'the alert is resolved');
    }

    public function testTheComplianceReadModelCountsDevicesByClientWithTheRingDistribution(): void
    {
        [$b, $bt] = $this->linked('B1', $this->h->clientB);
        [$n] = $this->linked('NEVER');
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A']]));
        $this->scanWith($b, $bt, self::winScan([]));
        $this->h->q("UPDATE rmm_patch_available SET first_seen_at = '" . gmdate('Y-m-d H:i:s', time() - 20 * 86400) . "'");
        $this->policy(['ring' => 'pilot'], 'client', $this->h->clientA);
        $this->h->q("UPDATE rmm_patch_scans SET reboot_pending = 1 WHERE device_id = {$this->dev}");
        $r = $this->api('GET', ['patches', 'compliance'], $this->admin)[1];
        $s = $r['summary'];
        $this->assertSame([3, 1, 1, 1, 1, 1], [$s['devices'], $s['noncompliant'], $s['compliant'], $s['never_scanned'], $s['reboot_pending'], $s['missing_critical'] + 1]);
        $this->assertSame(33.3, $s['compliance_pct']);
        $byClient = array_column($r['groups'], null, 'key');
        $this->assertSame([2, 1, 1], [$byClient[$this->h->clientA]['devices'], $byClient[$this->h->clientA]['noncompliant'], $byClient[$this->h->clientA]['never_scanned']]);
        $this->assertSame(0, $byClient[$this->h->clientB]['noncompliant']);
        $this->assertSame([$this->dev], array_column($r['devices'], 'device_id'));
        $this->assertSame(['pilot' => 1, '(none)' => 1], $r['rings']['counts']);
        $this->assertTrue($r['rings']['exact']);
        // the same figures through the read model, for an edition's page; a lower threshold counts the younger update
        $rm = $this->h->module->readModel();
        $this->assertSame(3, $rm->patchCompliance(null)['summary']['devices']);
        $this->assertSame(1, $rm->patchCompliance([$this->h->clientB])['summary']['devices']);
        $this->assertSame(0, $rm->patchCompliance([])['summary']['devices']);
        $this->assertSame('pilot', $rm->patchSummary($this->h->module->devices()->find($this->dev) ?? [])['policy']['ring']);
        $this->assertSame(0, $this->api('GET', ['patches', 'compliance'], $this->admin, null, ['overdue_days' => '30'])[1]['summary']['noncompliant']);
        $this->assertSame(422, $this->api('GET', ['patches', 'compliance'], $this->admin, null, ['client_id' => 'x'])[0]);
        $site = $this->api('GET', ['patches', 'compliance'], $this->admin, null, ['group_by' => 'site'])[1];
        $this->assertSame('site', $site['group_by']);
        $this->assertNotEmpty($this->api('GET', ['patches'], $this->admin)[1]['items']);
        unset($n);
    }

    // ------------------------------------------------------------------ policies and rings through the API

    public function testPolicyRingAndRuleCrud(): void
    {
        [$c, $j] = $this->api('POST', ['patch_policies'], $this->admin, ['name' => 'Servers', 'settings' => ['ring' => 'last', 'install_enabled' => true]]);
        $this->assertSame(201, $c);
        $pid = (string) $j['policy']['policy_id'];
        $this->assertSame(422, $this->api('POST', ['patch_policies'], $this->admin, ['name' => 'Servers'])[0], 'duplicate name');
        $this->assertSame(422, $this->api('POST', ['patch_policies'], $this->admin, ['name' => 'x', 'settings' => ['ring' => 'nope']])[0]);
        $this->assertSame(422, $this->api('POST', ['patch_policies'], $this->admin, ['name' => 'x', 'settings' => ['reboot_policy' => 'if_required_now']])[0], 'a restart policy without its confirmation');
        $this->assertSame(['Servers'], array_column($this->api('GET', ['patch_policies'], $this->viewer)[1]['data'], 'name'));
        $this->assertSame(403, $this->api('POST', ['patch_policies'], $this->viewer, ['name' => 'v'])[0]);
        $this->assertSame(403, $this->api('POST', ['patch_policies'], $this->tech, ['name' => 'v', 'settings' => ['reboot_policy' => 'if_required_now', 'reboot_confirm' => true]])[0], 'letting a policy restart devices needs the reboot grant');
        $this->assertSame(201, $this->api('POST', ['patch_policies'], $this->installer, ['name' => 'Restarts', 'settings' => ['reboot_policy' => 'if_required_now', 'reboot_confirm' => true]])[0]);
        $this->assertSame(11, (int) $this->h->one("SELECT reboot_confirmed_by FROM rmm_patch_policies WHERE name = 'Restarts'"));
        $this->assertSame(404, $this->api('POST', ['patch_policies', '9999', 'assignments'], $this->admin, ['scope_type' => 'global'])[0]);
        foreach ([['scope_type' => 'galaxy'], ['scope_type' => 'client'], ['scope_type' => 'client', 'scope_id' => 99999], ['scope_type' => 'tag', 'scope_id' => 99999], ['scope_type' => 'global', 'priority' => -1]] as $bad) {
            $this->assertSame(422, $this->api('POST', ['patch_policies', $pid, 'assignments'], $this->admin, $bad)[0], json_encode($bad));
        }
        $a = $this->api('POST', ['patch_policies', $pid, 'assignments'], $this->admin, ['scope_type' => 'client', 'scope_id' => $this->h->clientA, 'priority' => 5, 'enforce' => true])[1]['assignment'];
        $this->assertSame([true, 5], [$a['enforce'], $a['priority']]);
        $this->assertSame(1, count($this->api('GET', ['patch_policies', $pid], $this->viewer)[1]['policy']['assignments']));
        $this->assertSame(200, $this->api('DELETE', ['patch_policies', $pid, 'assignments', (string) $a['assignment_id']], $this->admin)[0]);
        $this->assertSame(404, $this->api('DELETE', ['patch_policies', $pid, 'assignments', (string) $a['assignment_id']], $this->admin)[0]);
        // the effective policy of a device shows where each setting came from
        $this->api('POST', ['patch_policies', $pid, 'assignments'], $this->admin, ['scope_type' => 'device', 'scope_id' => $this->dev]);
        $pol = $this->api('GET', [(string) $this->dev, 'patches'], $this->viewer)[1]['summary']['policy'];
        $this->assertSame(['last', 14, true], [$pol['ring'], $pol['ring_delay_days'], $pol['install_enabled']]);
        $this->assertSame('device', $pol['sources']['ring']['scope_type']);
        $this->assertSame(200, $this->api('DELETE', ['patch_policies', $pid], $this->admin)[0]);
        $this->assertSame(404, $this->api('GET', ['patch_policies', $pid], $this->admin)[0]);
        // rings
        [$c, $j] = $this->api('POST', ['patches', 'rings'], $this->admin, ['name' => 'canary', 'delay_days' => 0, 'sort_order' => 5]);
        $this->assertSame(201, $c);
        $rid = (string) $j['ring']['ring_id'];
        $this->assertSame(422, $this->api('POST', ['patches', 'rings'], $this->admin, ['name' => 'Bad Name', 'delay_days' => 1])[0]);
        $this->assertSame(422, $this->api('POST', ['patches', 'rings'], $this->admin, ['name' => 'canary', 'delay_days' => 1])[0]);
        $this->assertSame(422, $this->api('POST', ['patches', 'rings'], $this->admin, ['name' => 'late', 'delay_days' => 366])[0]);
        $this->assertSame('canary', $this->api('GET', ['patches', 'rings'], $this->viewer)[1]['rings'][0]['name']);
        $this->api('POST', ['patch_policies'], $this->admin, ['name' => 'uses canary', 'settings' => ['ring' => 'canary']]);
        $this->assertSame(409, $this->api('DELETE', ['patches', 'rings', $rid], $this->admin)[0], 'a ring in use stays');
        $this->assertSame(200, $this->api('PUT', ['patches', 'rings', $rid], $this->admin, ['name' => 'canary2', 'delay_days' => 1])[0]);
        $this->assertSame('canary2', json_decode((string) $this->h->one("SELECT settings_json FROM rmm_patch_policies WHERE name = 'uses canary'"), true)['ring']['value'], 'a rename is followed by the policies');
        // rules
        $this->assertSame(422, $this->api('POST', ['patches', 'rules'], $this->admin, ['name' => 'r', 'kinds' => ['nonsense']])[0]);
        $this->assertSame(422, $this->api('POST', ['patches', 'rules'], $this->admin, ['name' => 'r', 'ring' => 'nope'])[0]);
        $rule = $this->api('POST', ['patches', 'rules'], $this->admin, ['name' => 'sec', 'kinds' => ['security'], 'severities' => ['critical', 'important'], 'after_days' => 2])[1]['rule'];
        $this->assertSame(['sec', ['security'], 2], [$rule['name'], $rule['kinds'], $rule['after_days']]);
        $this->assertSame(200, $this->api('DELETE', ['patches', 'rules', (string) $rule['rule_id']], $this->admin)[0]);
        $this->assertSame(404, $this->api('DELETE', ['patches', 'rules', (string) $rule['rule_id']], $this->admin)[0]);
    }

    public function testTheSettingsLimitsAreValidated(): void
    {
        foreach ([['patch_scan_interval_s' => 60], ['patch_overdue_days' => 0], ['patch_max_installs' => 0], ['patch_live_install' => 2], ['patch_batch' => 5]] as $bad) {
            $this->assertNotSame([], $this->h->module->settings()->update(['limits_json' => json_encode($bad)]), json_encode($bad));
        }
        $this->limits(['patch_scan_interval_s' => 7200, 'patch_overdue_days' => 3]);
        $l = $this->h->module->settings()->limits();
        $this->assertSame([7200, 3, 25, 5, 0, 365, 100], [$l['patch_scan_interval_s'], $l['patch_overdue_days'], $l['patch_max_installs'], $l['patch_max_installs_per_client'], $l['patch_live_install'], $l['patch_history_days'], $l['patch_batch']]);
    }

    public function testHistoryAndInstalledRowsArePrunedAndOrphanAssignmentsDropped(): void
    {
        $this->approvedUpdate();
        $this->h->q("INSERT INTO rmm_patch_history (device_id, client_id, update_id, action, result, at) VALUES ({$this->dev}, 1, 'x', 'install', 'installed', '" . gmdate('Y-m-d H:i:s', time() - 400 * 86400) . "'), ({$this->dev}, 1, 'y', 'install', 'installed', '" . gmdate('Y-m-d H:i:s') . "')");
        $this->h->q("UPDATE rmm_patch_available SET status = 'installed', status_at = '" . gmdate('Y-m-d H:i:s', time() - 20 * 86400) . "'");
        $this->h->q("INSERT INTO rmm_patch_policies (name, settings_json, created_at, updated_at) VALUES ('o', '{}', NOW(), NOW())");
        $this->h->q("INSERT INTO rmm_patch_assignments (policy_id, scope_type, scope_id, created_at) SELECT policy_id, 'tag', 777, NOW() FROM rmm_patch_policies");
        $r = $this->pass();
        $this->assertGreaterThanOrEqual(3, $r['patch_pruned']);
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_patch_history'));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_patch_available'));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_patch_assignments'));
    }

    public function testTheCapacityReportShowsPatchingOnlyWhileTheSwitchIsOn(): void
    {
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A']]));
        $c = $this->h->module->capacity()->build();
        $this->assertSame([true, 1, 0, 1], [$c['patching']['available'], $c['patching']['devices_scanned'], $c['patching']['installs_waiting_or_running'], $c['patching']['missing_update_rows']]);
        $this->assertSame(86400, $c['patching']['limits']['patch_scan_interval_s']);
        $this->assertContains('rmm_patch_available', array_column($c['tables'], 'table'));
        $this->switchOn([]);
        $this->assertNull($this->h->module->capacity()->build()['patching']);
    }
}
