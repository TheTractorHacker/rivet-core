<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Tests\Support\PatchTestCase;

/**
 * Windows Update control (1.0.0-rc.65): the reported state, the reconciler (dry run first, caps, never for a device another RMM manages / an agent without the capability / a device
 * something else manages), the result, audit, the check, the routes and their tenant isolation, and that an older agent is untouched. No migration: the state lives in rmm_patch_jobs.
 */
final class PatchConfigureTest extends PatchTestCase
{
    private int $dev = 0;
    private string $tok = '';

    protected function setUp(): void
    {
        parent::setUp();
        [$this->dev, $this->tok] = $this->linked('W1', null, 'windows', self::caps('windows', true, true));
    }

    /** @param array<string,mixed> $over */
    private static function wu(array $over = []): array
    {
        return array_replace_recursive(['feature_version' => '24H2', 'build' => '26100', 'product' => 'Windows 11', 'external' => [], 'policy' => [], 'core' => ['keys' => [], 'pinned' => null, 'held' => false]], $over);
    }

    /** A scan report of a Windows device (no updates) that carries a Windows Update policy state. */
    private function scan(int $dev, string $tok, ?array $wu): void
    {
        $this->scanWith($dev, $tok, self::winScan([], $wu === null ? [] : ['windows_update' => $wu]));
    }

    /** @param array<string,mixed> $settings */
    private function policy(array $settings, string $scope = 'global', int $scopeId = 0, string $name = 'wu'): int
    {
        [$c, $j] = $this->api('POST', ['patch_policies'], $this->admin, ['name' => $name, 'settings' => $settings]);
        $this->assertSame(201, $c, json_encode($j));
        $pid = (int) $j['policy']['policy_id'];
        $this->assertSame(200, $this->api('POST', ['patch_policies', (string) $pid, 'assignments'], $this->admin, ['scope_type' => $scope, 'scope_id' => $scopeId])[0]);

        return $pid;
    }

    private function pass(): array
    {
        $this->h->module->settings()->refresh();

        return $this->h->module->patchScheduler()->run();
    }

    private function live(bool $on = true): void
    {
        $this->assertSame([], $this->h->module->settings()->update(['limits_json' => json_encode(['patch_live_install' => $on ? 1 : 0])]));
    }

    private function configureJobs(): array
    {
        return $this->h->rows("SELECT job_id, device_id, state, destructive, params_json FROM endpoint_agent_jobs WHERE type = 'patch_configure' ORDER BY created_at, job_id");
    }

    /** @return array<string,mixed> */
    private function offeredConfigure(string $tok): array
    {
        $jobs = array_values(array_filter($this->offered($tok), static fn (array $j): bool => $j['type'] === 'patch_configure'));
        $this->assertCount(1, $jobs);

        return $jobs[0];
    }

    private function auditText(): string
    {
        return json_encode($this->h->audit->records()) ?: '';
    }

    // ------------------------------------------------------------------ the policy

    public function testThePolicyKeysAreValidatedAndTheDefaultsLeaveEverythingAlone(): void
    {
        [$c, $j] = $this->api('POST', ['patch_policies'], $this->admin, ['name' => 'a', 'settings' => ['windows_feature_target' => '25H2;calc', 'windows_update_mode' => 'managed']]);
        $this->assertSame(422, $c);
        $this->assertStringContainsString('windows_feature_target', $j['error']);
        [$c, $j] = $this->api('POST', ['patch_policies'], $this->admin, ['name' => 'b', 'settings' => ['windows_update_mode' => 'managed', 'windows_feature_target' => 'hold', 'windows_quality_defer_days' => 7]]);
        $this->assertSame(201, $c);
        $this->assertEquals(['windows_update_mode' => 'managed', 'windows_feature_target' => 'hold', 'windows_quality_defer_days' => 7], (array) $j['policy']['settings']);
        // an unassigned policy changes nothing: the device's effective Windows control is "touch nothing"
        $this->scan($this->dev, $this->tok, self::wu());
        $s = $this->api('GET', [(string) $this->dev, 'patches'], $this->viewer)[1]['summary'];
        $this->assertFalse($s['policy']['windows']['active']);
        $this->assertSame('off', $s['windows_update']['status']);
        $this->assertSame(0, $this->pass()['patch_configure_dry_runs_queued']);
        $this->assertSame([], $this->configureJobs());
    }

    public function testOnlyAHolderOfTheInstallGrantMayChangeTheWindowsSettings(): void
    {
        // $this->tech may manage patches (policies included) but not install; $this->installer may do both
        [$c] = $this->api('POST', ['patch_policies'], $this->tech, ['name' => 'plain', 'settings' => ['install_enabled' => true]]);
        $this->assertSame(201, $c, 'a manager may still write every other setting');
        [$c, $j] = $this->api('POST', ['patch_policies'], $this->tech, ['name' => 'win', 'settings' => ['windows_update_mode' => 'managed']]);
        $this->assertSame([403, 'forbidden'], [$c, $j['code']]);
        $this->assertStringContainsString('grant to install patches', $j['error']);
        [$c, $j] = $this->api('POST', ['patch_policies'], $this->installer, ['name' => 'win', 'settings' => ['windows_update_mode' => 'managed']]);
        $this->assertSame(201, $c, json_encode($j));
        $pid = (int) $j['policy']['policy_id'];
        // editing: a manager may not turn it off or change it either, nor drop the key by leaving it out
        $this->assertSame(403, $this->api('PUT', ['patch_policies', (string) $pid], $this->tech, ['name' => 'win', 'settings' => ['windows_update_mode' => 'leave']])[0]);
        $this->assertSame(403, $this->api('PUT', ['patch_policies', (string) $pid], $this->tech, ['name' => 'win', 'settings' => ['install_enabled' => true]])[0], 'leaving the key out removes it');
        $this->assertSame(200, $this->api('PUT', ['patch_policies', (string) $pid], $this->tech, ['name' => 'win', 'description' => 'only a description'])[0], 'no settings in the request: nothing about Windows changes');
        $this->assertSame(200, $this->api('PUT', ['patch_policies', (string) $pid], $this->tech, ['name' => 'win', 'settings' => ['windows_update_mode' => 'managed', 'install_enabled' => true]])[0], 'the same value again is not a change');
        $this->assertSame(200, $this->api('PUT', ['patch_policies', (string) $pid], $this->installer, ['name' => 'win', 'settings' => ['windows_update_mode' => 'leave']])[0]);
        $this->assertSame(403, $this->api('POST', ['patch_policies'], $this->viewer, ['name' => 'v', 'settings' => ['windows_update_mode' => 'managed']])[0]);
        $this->assertStringContainsString('denied changing the Windows Update control', $this->auditText());
    }

    // ------------------------------------------------------------------ the state a scan reports

    public function testAScanKeepsTheReportedWindowsUpdateStateAndHostileValuesAreCleaned(): void
    {
        $this->scan($this->dev, $this->tok, self::wu(['feature_version' => '25H2', 'external' => ['wsus', 'evil'], 'policy' => ['au_options' => 4, 'UseWUServer' => 1, 'target_release_version_info' => "x\ny"], 'core' => ['keys' => ['au_options', 'rm']]]));
        $w = $this->api('GET', [(string) $this->dev, 'patches'], $this->viewer)[1]['summary']['windows_update'];
        $this->assertSame('25H2', $w['feature_version']);
        $this->assertSame(['wsus'], $w['external']);
        $this->assertEquals(['au_options' => 4], (array) $w['policy']);
        $this->assertSame(['au_options'], $w['core_keys']);
        $this->assertTrue($w['capable']);
        // one durable row per device, nothing else added to the ledger, pruned never
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM rmm_patch_jobs WHERE type = 'wu_state' AND device_id = {$this->dev}"));
        $this->h->q("UPDATE rmm_patch_jobs SET created_at = '2001-01-01 00:00:00' WHERE type = 'wu_state'");
        $this->pass();
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM rmm_patch_jobs WHERE type = 'wu_state'"), 'the pruning of the ledger leaves the state row alone');
        // a scan without the object (an older agent) keeps what was reported and does not invent anything
        $this->scan($this->dev, $this->tok, null);
        $this->assertSame('25H2', $this->api('GET', [(string) $this->dev, 'patches'], $this->viewer)[1]['summary']['windows_update']['feature_version']);
    }

    public function testALinuxDeviceHasNoWindowsUpdateSection(): void
    {
        [$lin, $lt] = $this->linked('L1', null, 'linux');
        $rep = (string) json_encode(['v' => 1, 'ok' => true, 'manager' => 'apt', 'scanned_at' => gmdate('c'), 'scan_state' => 'ok', 'wu_state' => 'n/a', 'reboot_pending' => false, 'last_install_at' => null, 'truncated' => false, 'updates' => [],
            'windows_update' => self::wu()]);
        $this->scanWith($lin, $lt, $rep);
        $this->assertNull($this->api('GET', [(string) $lin, 'patches'], $this->viewer)[1]['summary']['windows_update']);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM rmm_patch_jobs WHERE type = 'wu_state' AND device_id = $lin"));
        $this->policy(['windows_update_mode' => 'managed']);
        $this->pass();
        $this->assertSame([], $this->configureJobs());
        [$c, $j] = $this->api('POST', [(string) $lin, 'patches', 'configure'], $this->tech);
        $this->assertSame(409, $c, json_encode($j));
    }

    // ------------------------------------------------------------------ the reconciler

    public function testManagedPolicyQueuesADryRunFirstOnceAndAuditsItWithThePolicy(): void
    {
        $pid = $this->policy(['windows_update_mode' => 'managed', 'windows_feature_target' => '25H2', 'windows_quality_defer_days' => 7]);
        $this->scan($this->dev, $this->tok, self::wu());
        $r = $this->pass();
        $this->assertSame([0, 1], [$r['patch_configures_queued'], $r['patch_configure_dry_runs_queued']], 'live installing is off: a dry run');
        $jobs = $this->configureJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('1', (string) $jobs[0]['destructive']);
        $this->assertEquals(['mode' => 'dry_run', 'update_mode' => 'managed', 'feature_target' => '25H2', 'defer_days' => 7], json_decode((string) $jobs[0]['params_json'], true));
        $this->assertStringContainsString("patch policy #$pid", $this->auditText());
        $this->assertStringContainsString('The scheduler queued a Windows Update policy dry run', $this->auditText());
        $this->assertSame(0, $this->pass()['patch_configure_dry_runs_queued'], 'one at a time, one dry run a day');
        $this->assertCount(1, $this->configureJobs());
        // the agent runs it: nothing is written server side except the report
        $job = $this->offeredConfigure($this->tok);
        $this->assertSame([], array_filter($this->offered($this->tok), static fn (array $j): bool => $j['type'] === 'patch_configure'), 'a destructive job is offered at most once, even when the acknowledgement is lost');
        $this->report($this->tok, $job, 'succeeded', (string) json_encode(['v' => 1, 'ok' => true, 'mode' => 'dry_run', 'outcome' => 'would_change', 'changes' => [['key' => 'au_options', 'from' => null, 'to' => 2], ['key' => 'target_release_version_info', 'from' => null, 'to' => '25H2']],
            'before' => self::wu(), 'after' => self::wu()]));
        $w = $this->api('GET', [(string) $this->dev, 'patches'], $this->viewer)[1]['summary']['windows_update'];
        $this->assertSame('would_change', $w['last_configure']['outcome']);
        $this->assertSame('au_options', $w['last_configure']['changes'][0]['key']);
        $this->assertSame('pending', $w['status']);
        $this->assertStringContainsString('Patch Configure Dry Run', $this->auditText());
        $this->assertSame(0, $this->pass()['patch_configure_dry_runs_queued'], 'not again within the day');
    }

    public function testSwitchingLiveInstallsOnQueuesTheRealChangeAndItsResultMovesTheState(): void
    {
        $this->policy(['windows_update_mode' => 'managed', 'windows_feature_target' => 'hold']);
        $this->live();
        $this->scan($this->dev, $this->tok, self::wu());
        $r = $this->pass();
        $this->assertSame([1, 0], [$r['patch_configures_queued'], $r['patch_configure_dry_runs_queued']]);
        $job = $this->offeredConfigure($this->tok);
        $this->assertSame('apply', $job['params']['mode']);
        $this->assertSame('hold', $job['params']['feature_target']);
        $after = self::wu(['policy' => ['no_auto_update' => 0, 'au_options' => 2, 'target_release_version' => 1, 'target_release_version_info' => '24H2', 'product_version' => 'Windows 11'],
            'core' => ['keys' => ['au_options', 'no_auto_update', 'product_version', 'target_release_version', 'target_release_version_info'], 'pinned' => '24H2', 'held' => true]]);
        $this->report($this->tok, $job, 'succeeded', (string) json_encode(['v' => 1, 'ok' => true, 'mode' => 'apply', 'outcome' => 'changed', 'changes' => [['key' => 'au_options', 'from' => null, 'to' => 2]], 'before' => self::wu(), 'after' => $after]));
        $w = $this->api('GET', [(string) $this->dev, 'patches'], $this->viewer)[1]['summary']['windows_update'];
        $this->assertSame(['in_sync', '24H2', false, 'changed'], [$w['status'], $w['target_version'], $w['newer_than_target'], $w['last_configure']['outcome']]);
        $this->assertStringContainsString('Patch Configure Applied', $this->auditText());
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = 'patch_scan' AND state = 'queued'"), 'a scan is queued to prove the change');
        $this->h->q("UPDATE endpoint_agent_jobs SET state = 'failed' WHERE type = 'patch_scan'");
        $this->h->q("UPDATE rmm_patch_jobs SET applied_at = '2000-01-01 00:00:00' WHERE type = 'wu_state'");
        $r = $this->pass();
        $this->assertSame([0, 0], [$r['patch_configures_queued'], $r['patch_configure_dry_runs_queued']], 'in sync: nothing to do');
    }

    public function testBackToLeaveRestoresWhatCoreOwnsAndNothingElse(): void
    {
        $pid = $this->policy(['windows_update_mode' => 'managed']);
        $this->live();
        $owned = self::wu(['policy' => ['no_auto_update' => 0, 'au_options' => 2], 'core' => ['keys' => ['au_options', 'no_auto_update']]]);
        $this->scan($this->dev, $this->tok, $owned);
        $this->assertSame(0, $this->pass()['patch_configures_queued'], 'in sync');
        $this->assertSame(200, $this->api('PUT', ['patch_policies', (string) $pid], $this->admin, ['name' => 'wu', 'settings' => ['windows_update_mode' => 'leave']])[0]);
        $this->h->q("UPDATE rmm_patch_jobs SET applied_at = '2000-01-01 00:00:00' WHERE type = 'wu_state'");
        $this->assertSame(1, $this->pass()['patch_configures_queued']);
        $job = $this->offeredConfigure($this->tok);
        $this->assertEquals(['mode' => 'apply', 'update_mode' => 'leave', 'feature_target' => 'none', 'defer_days' => 0], $job['params']);
        // a device that never had anything of Core's and a policy that asks nothing is left alone
        [$d2, $t2] = $this->linked('W2', null, 'windows', self::caps('windows', true, true));
        $this->scan($d2, $t2, self::wu());
        $this->pass();
        $this->assertCount(1, $this->configureJobs());
    }

    public function testNeverForAnOldAgentAManagedElsewhereDeviceAnExternallyManagedOneOrOneWithoutAReport(): void
    {
        $this->policy(['windows_update_mode' => 'managed']);
        $this->live();
        // 1. an agent that announced patch:scan and patch:install but not patch:configure (every agent before this release)
        [$old, $oldTok] = $this->linked('OLD', null, 'windows', self::caps('windows', true, false));
        $this->scan($old, $oldTok, self::wu());
        // 2. a device another RMM manages
        [$other, $otherTok] = $this->linked('OTHER', null, 'windows', self::caps('windows', true, true));
        $this->scan($other, $otherTok, self::wu());
        $this->h->q("UPDATE endpoint_agent_devices SET device_automation_disabled = 1 WHERE device_id = $other");
        // 3. WSUS / GPO / MDM
        $ext = [];
        foreach (['wsus', 'gpo', 'mdm', 'changed_since'] as $who) {
            [$d, $t] = $this->linked('X' . $who, null, 'windows', self::caps('windows', true, true));
            $this->scan($d, $t, self::wu(['external' => [$who]]));
            $ext[$who] = $d;
        }
        // 4. announced the capability but never reported a state (no scan yet)
        [$fresh] = $this->linked('FRESH', null, 'windows', self::caps('windows', true, true));
        // 5. offline
        [$off, $offTok] = $this->linked('OFF', null, 'windows', self::caps('windows', true, true));
        $this->scan($off, $offTok, self::wu());
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at = '" . gmdate('Y-m-d H:i:s', time() - 99999) . "' WHERE device_id = $off");
        // the one device that qualifies (this test's own W1 has no report yet)
        $this->scan($this->dev, $this->tok, self::wu());
        $r = $this->pass();
        $this->assertSame(1, $r['patch_configures_queued']);
        $queued = array_map('intval', array_column($this->configureJobs(), 'device_id'));
        $this->assertSame([$this->dev], $queued);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id IN ($old, $other, $fresh, $off, " . implode(',', $ext) . ") AND type = 'patch_configure'"));
        // the manual route says why, in the same words
        [$c, $j] = $this->api('POST', [(string) $old, 'patches', 'configure'], $this->tech);
        $this->assertSame([409, 'unsupported'], [$c, $j['code']]);
        $this->assertSame([200, 'automation_disabled'], [$this->api('POST', [(string) $other, 'patches', 'configure'], $this->tech)[0], $this->api('POST', [(string) $other, 'patches', 'configure'], $this->tech)[1]['skipped']]);
        [$c, $j] = $this->api('POST', [(string) $ext['gpo'], 'patches', 'configure'], $this->tech);
        $this->assertSame([200, 'externally_managed'], [$c, $j['skipped']]);
        [$c, $j] = $this->api('POST', [(string) $fresh, 'patches', 'configure'], $this->tech);
        $this->assertSame([200, 'not_reported'], [$c, $j['skipped']]);
        $s = $this->api('GET', [(string) $ext['wsus'], 'patches'], $this->viewer)[1]['summary']['windows_update'];
        $this->assertSame(['externally_managed', ['wsus']], [$s['status'], $s['external']]);
        $this->assertSame('unsupported_agent', $this->api('GET', [(string) $old, 'patches'], $this->viewer)[1]['summary']['windows_update']['status']);
        $this->assertSame('automation_disabled', $this->api('GET', [(string) $other, 'patches'], $this->viewer)[1]['summary']['windows_update']['status']);
        // and an old agent is never offered the job even if one were forced into the queue
        $this->assertSame([], array_filter($this->offered($oldTok), static fn (array $j): bool => $j['type'] === 'patch_configure'));
    }

    public function testTheFleetAndPerClientCapsLimitLiveJobs(): void
    {
        $this->policy(['windows_update_mode' => 'managed']);
        $this->live();
        $this->assertSame([], $this->h->module->settings()->update(['limits_json' => json_encode(['patch_live_install' => 1, 'patch_max_installs' => 2, 'patch_max_installs_per_client' => 1])]));
        $ids = [$this->dev];
        $this->scan($this->dev, $this->tok, self::wu());
        [$b1, $bt1] = $this->linked('B1', $this->h->clientB, 'windows', self::caps('windows', true, true));
        [$b2, $bt2] = $this->linked('B2', $this->h->clientB, 'windows', self::caps('windows', true, true));
        [$a2, $at2] = $this->linked('A2', null, 'windows', self::caps('windows', true, true));
        foreach ([[$b1, $bt1], [$b2, $bt2], [$a2, $at2]] as [$d, $t]) {
            $this->scan($d, $t, self::wu());
            $ids[] = $d;
        }
        $r = $this->pass();
        $this->assertSame(2, $r['patch_configures_queued'], 'fleet cap 2, per client 1');
        $this->assertGreaterThanOrEqual(1, $r['patch_configure_skipped']);
        $perClient = $this->h->rows("SELECT j.client_id, COUNT(*) c FROM endpoint_agent_jobs j WHERE j.type = 'patch_configure' GROUP BY j.client_id");
        foreach ($perClient as $row) {
            $this->assertSame('1', (string) $row['c']);
        }
        $this->assertCount(2, $this->configureJobs());
        $this->assertSame(0, $this->pass()['patch_configures_queued'], 'the cap counts jobs that are waiting or running');
    }

    public function testAnExternallyManagedAnswerIsRecordedAndNotRepeatedForADay(): void
    {
        $this->policy(['windows_update_mode' => 'managed']);
        $this->live();
        $this->scan($this->dev, $this->tok, self::wu());
        $this->assertSame(1, $this->pass()['patch_configures_queued']);
        $job = $this->offeredConfigure($this->tok);
        $gpo = self::wu(['external' => ['gpo']]);
        $this->report($this->tok, $job, 'succeeded', (string) json_encode(['v' => 1, 'ok' => true, 'mode' => 'apply', 'outcome' => 'externally_managed', 'changes' => [], 'before' => $gpo, 'after' => $gpo, 'detail' => 'something else manages it']));
        $w = $this->api('GET', [(string) $this->dev, 'patches'], $this->viewer)[1]['summary']['windows_update'];
        $this->assertSame(['externally_managed', ['gpo'], 'externally_managed'], [$w['status'], $w['external'], $w['last_configure']['outcome']]);
        $this->assertStringContainsString('Patch Configure Skipped', $this->auditText());
        $this->assertStringContainsString('[managed by: gpo]', $this->auditText());
        $this->h->q("UPDATE rmm_patch_jobs SET applied_at = '2000-01-01 00:00:00' WHERE type = 'wu_state'");
        $this->assertSame(0, $this->pass()['patch_configures_queued'], 'the state says external: nothing is queued');
        // the setting becomes Core's again (the GPO is gone): a fresh scan reports it and the job is queued again, but not within the quiet hour for the same request
        $this->scan($this->dev, $this->tok, self::wu());
        $this->assertSame(0, $this->pass()['patch_configures_queued'], 'the same request that was just refused waits a day');
        $this->h->q("UPDATE rmm_patch_jobs SET idem_key = NULL, created_at = '" . gmdate('Y-m-d H:i:s', time() - 2 * 86400) . "' WHERE type = 'configure'");
        $this->h->q("UPDATE rmm_patch_jobs SET applied_at = '2000-01-01 00:00:00' WHERE type = 'wu_state'");
        $this->assertSame(1, $this->pass()['patch_configures_queued']);
    }

    public function testAFailedJobIsRecordedAndAuditedAndNotRepeatedAtOnce(): void
    {
        $this->policy(['windows_update_mode' => 'managed']);
        $this->live();
        $this->scan($this->dev, $this->tok, self::wu());
        $this->pass();
        $job = $this->offeredConfigure($this->tok);
        $this->report($this->tok, $job, 'failed', '{"v":1,"ok":false,"detail":"registry_error","message":"access denied"}', 1);
        $this->assertSame('failed', $this->api('GET', [(string) $this->dev, 'patches'], $this->viewer)[1]['summary']['windows_update']['last_configure']['outcome']);
        $this->assertStringContainsString('registry_error', $this->auditText());
        $this->h->q("UPDATE rmm_patch_jobs SET applied_at = '2000-01-01 00:00:00' WHERE type = 'wu_state'");
        $this->assertSame(0, $this->pass()['patch_configures_queued']);
    }

    public function testALostAcknowledgementNeverResendsTheJobAndALateResultStillApplies(): void
    {
        $this->policy(['windows_update_mode' => 'managed']);
        $this->live();
        $this->scan($this->dev, $this->tok, self::wu());
        $this->pass();
        $job = $this->offeredConfigure($this->tok);
        // the agent never acknowledged (the response was lost): destructive, so it is not offered again
        $this->h->q("UPDATE endpoint_agent_jobs SET last_offered_at = '" . gmdate('Y-m-d H:i:s', time() - 7200) . "'");
        $this->assertSame([], array_filter($this->offered($this->tok), static fn (array $j): bool => $j['type'] === 'patch_configure'));
        $this->assertSame($job['job_id'], $this->configureJobs()[0]['job_id']);
    }

    // ------------------------------------------------------------------ the manual route

    public function testTheManualRouteIsADryRunForAManagerAndARealChangeNeedsTheInstallGrantAndConfirm(): void
    {
        $this->policy(['windows_update_mode' => 'managed']);
        $this->scan($this->dev, $this->tok, self::wu());
        $d = (string) $this->dev;
        $this->assertSame(401, $this->h->tech('POST', [$d, 'patches', 'configure'], null)[0]);
        $this->assertSame(403, $this->api('POST', [$d, 'patches', 'configure'], $this->viewer)[0]);
        $this->assertSame(403, $this->api('POST', [$d, 'patches', 'configure'], $this->tech, ['mode' => 'apply', 'confirm' => true])[0], 'a manager may not run the real thing');
        $this->assertSame([422, 'confirmation_required'], [$this->api('POST', [$d, 'patches', 'configure'], $this->installer, ['mode' => 'apply'])[0], $this->api('POST', [$d, 'patches', 'configure'], $this->installer, ['mode' => 'apply'])[1]['code']]);
        $this->assertSame(422, $this->api('POST', [$d, 'patches', 'configure'], $this->installer, ['mode' => 'live', 'confirm' => true])[0]);
        [$c, $j] = $this->api('POST', [$d, 'patches', 'configure'], $this->tech);
        $this->assertSame([202, 'dry_run'], [$c, $j['mode']], json_encode($j));
        $this->assertSame(['already_queued'], [$this->api('POST', [$d, 'patches', 'configure'], $this->tech)[1]['skipped']]);
        $this->h->q("UPDATE endpoint_agent_jobs SET state = 'cancelled'");
        $this->h->q("UPDATE rmm_patch_jobs SET applied_at = '2000-01-01 00:00:00' WHERE type = 'configure'");
        // live installs are off: even an authorised, confirmed apply is a dry run
        [$c, $j] = $this->api('POST', [$d, 'patches', 'configure'], $this->installer, ['mode' => 'apply', 'confirm' => true]);
        $this->assertSame([202, 'dry_run'], [$c, $j['mode']]);
        $this->h->q("UPDATE endpoint_agent_jobs SET state = 'cancelled'");
        $this->live();
        [$c, $j] = $this->api('POST', [$d, 'patches', 'configure'], $this->installer, ['mode' => 'apply', 'confirm' => true]);
        $this->assertSame([202, 'apply'], [$c, $j['mode']]);
        $row = $this->configureJobs();
        $this->assertSame('apply', json_decode((string) end($row)['params_json'], true)['mode']);
        $this->assertStringContainsString('Patch Configure Requested', $this->auditText());
        $this->assertSame(405, $this->api('GET', [$d, 'patches', 'configure'], $this->tech)[0]);
        // nothing a caller types reaches the job
        $this->h->q("UPDATE endpoint_agent_jobs SET state = 'cancelled'");
        [$c] = $this->api('POST', [$d, 'patches', 'configure'], $this->installer, ['mode' => 'apply', 'confirm' => true, 'feature_target' => '99H9', 'key' => 'HKLM\\x']);
        $this->assertSame(202, $c);
        $this->assertStringNotContainsString('99H9', (string) $this->h->one("SELECT params_json FROM endpoint_agent_jobs WHERE type = 'patch_configure' ORDER BY created_at DESC, job_id LIMIT 1"));
    }

    public function testTenantIsolationOnTheNewRoute(): void
    {
        $this->policy(['windows_update_mode' => 'managed']);
        [$b, $bt] = $this->linked('B1', $this->h->clientB, 'windows', self::caps('windows', true, true));
        $this->scan($b, $bt, self::wu());
        $this->scan($this->dev, $this->tok, self::wu());
        // $this->scoped holds every ability for client B only
        $this->assertSame([404, 'not_found'], [$this->api('POST', [(string) $this->dev, 'patches', 'configure'], $this->scoped)[0], $this->api('POST', [(string) $this->dev, 'patches', 'configure'], $this->scoped)[1]['code']], 'client A device');
        $this->assertSame(404, $this->api('POST', [(string) $this->dev, 'patches', 'configure'], $this->scoped, ['mode' => 'apply', 'confirm' => true])[0]);
        $this->assertSame(404, $this->api('POST', ['99999', 'patches', 'configure'], $this->scoped)[0], 'a missing device answers the same');
        $this->assertSame(404, $this->api('GET', [(string) $this->dev, 'patches'], $this->scoped)[0], 'and its Windows Update state is not readable');
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = 'patch_configure'"));
        $this->assertSame(202, $this->api('POST', [(string) $b, 'patches', 'configure'], $this->scoped)[0], 'its own client works');
        $this->assertSame([(string) $b], array_column($this->configureJobs(), 'device_id'));
        // the policies that decide it are fleet-wide: a restricted caller cannot write them
        $this->assertSame(403, $this->api('POST', ['patch_policies'], $this->scoped, ['name' => 'mine', 'settings' => ['windows_update_mode' => 'managed']])[0]);
    }

    public function testNotQueuedThroughTheGenericJobEndpoint(): void
    {
        [$c, $j] = $this->api('POST', [(string) $this->dev, 'jobs'], $this->admin, ['type' => 'patch_configure', 'params' => ['mode' => 'apply', 'update_mode' => 'managed'], 'confirm' => true]);
        $this->assertSame([422, 'invalid'], [$c, $j['code']]);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = 'patch_configure'"));
    }

    // ------------------------------------------------------------------ the check

    public function testTheFeatureVersionCheckFailsWhenTheDeviceRunsNewerThanItsTargetAndRecovers(): void
    {
        $this->policy(['windows_feature_target' => '25H2']);
        $failN = (int) $this->h->module->settings()->get()['failure_debounce'];
        $okN = (int) $this->h->module->settings()->get()['recovery_debounce'];
        $this->scan($this->dev, $this->tok, self::wu(['feature_version' => '26H2']));
        $c = $this->h->rows("SELECT status, detail FROM endpoint_agent_checks WHERE check_key = 'patch.feature_version'");
        $this->assertCount(1, $c);
        $this->assertSame('fail', $c[0]['status'], "debounce $failN");
        $this->assertStringContainsString('runs Windows 26H2', $c[0]['detail']);
        $this->assertStringContainsString('holds it at 25H2', $c[0]['detail']);
        $w = $this->api('GET', [(string) $this->dev, 'patches'], $this->viewer)[1]['summary']['windows_update'];
        $this->assertTrue($w['newer_than_target']);
        $this->assertSame('25H2', $w['target_version']);
        // rolled back to the target: recovers
        for ($i = 0; $i <= $okN; ++$i) {
            $this->scan($this->dev, $this->tok, self::wu(['feature_version' => '25H2']));
        }
        $this->assertSame('ok', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key = 'patch.feature_version'"));
        // older than the target is fine (the device is on its way)
        $this->scan($this->dev, $this->tok, self::wu(['feature_version' => '24H2']));
        $this->assertSame('ok', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key = 'patch.feature_version'"));
    }

    public function testHoldUsesWhatCorePinnedAndAPolicyWithoutATargetNeverRaisesTheCheck(): void
    {
        $pid = $this->policy(['windows_feature_target' => 'hold']);
        $this->scan($this->dev, $this->tok, self::wu(['feature_version' => '26H2']));
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE check_key = 'patch.feature_version'"), 'nothing pinned yet: no target, no alert');
        $this->scan($this->dev, $this->tok, self::wu(['feature_version' => '26H2', 'policy' => ['target_release_version' => 1, 'target_release_version_info' => '25H2'], 'core' => ['keys' => ['target_release_version', 'target_release_version_info'], 'pinned' => '25H2', 'held' => true]]));
        $this->assertSame('fail', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key = 'patch.feature_version'"));
        // the policy stops holding: the open check recovers (the policy no longer has a target)
        $this->assertSame(200, $this->api('PUT', ['patch_policies', (string) $pid], $this->admin, ['name' => 'wu', 'settings' => ['windows_feature_target' => 'none']])[0]);
        $okN = (int) $this->h->module->settings()->get()['recovery_debounce'];
        for ($i = 0; $i <= $okN; ++$i) {
            $this->scan($this->dev, $this->tok, self::wu(['feature_version' => '26H2']));
        }
        $this->assertSame('ok', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key = 'patch.feature_version'"));
    }

    // ------------------------------------------------------------------ switches

    public function testNothingRunsWhilePatchingIsSwitchedOff(): void
    {
        $this->policy(['windows_update_mode' => 'managed']);
        $this->scan($this->dev, $this->tok, self::wu());
        $this->assertSame([], $this->h->module->settings()->update(['features_json' => ['monitoring' => true, 'jobs' => true]]));
        $r = $this->pass();
        $this->assertSame([0, 0], [$r['patch_configures_queued'], $r['patch_configure_dry_runs_queued']]);
        [$c, $j] = $this->api('POST', [(string) $this->dev, 'patches', 'configure'], $this->tech);
        $this->assertSame([409, 'feature_disabled'], [$c, $j['code']]);
        $this->assertSame([], $this->configureJobs());
    }

    public function testAnUnscopedPolicyOnlyReachesTheDevicesItIsAssignedTo(): void
    {
        $this->policy(['windows_update_mode' => 'managed'], 'client', $this->h->clientB);
        $this->scan($this->dev, $this->tok, self::wu());
        $this->assertSame(0, $this->pass()['patch_configure_dry_runs_queued'], 'device of client A, policy of client B');
        $this->assertSame('off', $this->api('GET', [(string) $this->dev, 'patches'], $this->viewer)[1]['summary']['windows_update']['status']);
    }
}
