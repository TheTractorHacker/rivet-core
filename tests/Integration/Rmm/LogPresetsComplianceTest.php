<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Compliance\Check\SecurityLogPresetsCheck;
use RivetCore\Compliance\Profile\BuiltInProfiles;
use RivetCore\Compliance\Profile\FixedRequirementsProvider;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\Requirement;
use RivetCore\Compliance\Profile\RequirementType;
use RivetCore\Compliance\Status;
use RivetCore\Rmm\Logs\SecurityLogPresets;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Tests\Support\RemoteTestCase;

/** The compliance baseline of the Windows log presets: read model, explicit storage, existing installs untouched, and the compliance check. */
final class LogPresetsComplianceTest extends RemoteTestCase
{
    private function remote(): \RivetCore\Rmm\Remote\RemoteSettings
    {
        return $this->h->module->remoteSettings();
    }

    /** @return array<string,array<string,mixed>> */
    private function presetRows(): array
    {
        $this->remote()->refresh();
        $rows = [];
        foreach ($this->remote()->summary()['log_presets'] as $p) {
            $rows[$p['id']] = $p;
        }

        return $rows;
    }

    private function stored(): ?string
    {
        $v = $this->h->one('SELECT logs_collect_json FROM endpoint_agent_remote_settings WHERE id = 1');

        return $v === null || $v === false ? null : (string) $v;
    }

    public function testNeverChosenShowsTheBaselineTickedButStoresAndSendsNothing(): void
    {
        $this->switchOn(['logs']);
        $this->assertFalse($this->remote()->logPresetsChosen());
        $rows = $this->presetRows();
        $this->assertSame(SecurityLogPresets::ids(), array_keys($rows));
        foreach ($rows as $id => $r) {
            $this->assertSame(in_array($id, SecurityLogPresets::complianceBaseline(), true), $r['recommended']);
            $this->assertSame($r['recommended'], $r['selected'], 'pre-ticked');
            $this->assertFalse($r['enabled'], 'but not enabled');
            $this->assertNotSame([], $r['frameworks']);
            $this->assertNotSame('', $r['audit_policy']);
        }
        $this->assertSame([], $this->remote()->logPresets());
        $this->assertNull($this->remote()->agentLogConfig('windows', ['logs:presets']), 'the agent is told nothing until an administrator saves');
        $this->assertNull($this->stored() ?: null);
        $this->assertStringContainsString('audit', strtolower($this->remote()->summary()['log_presets_audit_note']));
    }

    public function testFirstEnableWithTheFlagStoresTheBaselineAndNeverTurnsTheSwitchOnByItself(): void
    {
        $this->switchOn([]);
        // flag without turning logs on: stored explicitly, switch still off
        $r = $this->h->module->remoteActions()->updateSettings($this->admin, ['compliance_baseline_if_unset' => true]);
        $this->assertSame(200, $r->http);
        $this->assertSame(SecurityLogPresets::complianceBaseline(), $this->remote()->logPresets());
        $this->assertTrue($this->remote()->logPresetsChosen());
        $this->assertFalse($this->h->module->settings()->featureOn('logs'));
        $this->assertNull($this->remote()->agentLogConfig('linux'), 'no Linux source');
        // the first enable with the flag, from a clean slate
        $this->h->q('UPDATE endpoint_agent_remote_settings SET logs_collect_json = NULL WHERE id = 1');
        $this->remote()->refresh();
        $this->assertFalse($this->remote()->logPresetsChosen());
        $r = $this->h->module->remoteActions()->updateSettings($this->admin, ['features' => ['logs' => true], 'accept_logs_storage' => true, 'compliance_baseline_if_unset' => true]);
        $this->assertSame(200, $r->http);
        $this->assertTrue($this->h->module->settings()->featureOn('logs'));
        $this->assertSame(SecurityLogPresets::complianceBaseline(), $this->remote()->logPresets());
        $this->assertTrue($this->presetRows()['win.sec.accounts']['enabled']);
        $this->assertFalse($this->presetRows()['win.sec.devices']['selected']);
        $this->assertNotNull($this->remote()->agentLogConfig('windows', ['logs:presets']));
        // a flag that is not a bool is refused
        $this->assertSame(422, $this->h->module->remoteActions()->updateSettings($this->admin, ['compliance_baseline_if_unset' => 'yes'])->http);
    }

    public function testAnInstallThatAlreadyChoseKeepsExactlyWhatItHas(): void
    {
        $this->switchOn(['logs']);
        // stored presets: a subset
        $this->assertSame([], $this->remote()->update(['logs_collect' => ['presets' => ['win.sec.devices']]], 1));
        $before = $this->stored();
        $this->assertSame([], $this->remote()->update(['compliance_baseline_if_unset' => true], 1));
        $this->assertSame($before, $this->stored(), 'a chosen install is not touched by the flag');
        $this->assertSame(['win.sec.devices'], $this->remote()->logPresets());
        $rows = $this->presetRows();
        $this->assertTrue($rows['win.sec.devices']['selected']);
        $this->assertFalse($rows['win.sec.accounts']['selected'], 'recommended but not selected: the stored choice wins');
        $this->assertTrue($rows['win.sec.accounts']['recommended']);

        // chose none, explicitly: stays none, and is distinguishable from never chosen
        $this->assertSame([], $this->remote()->update(['logs_collect' => ['presets' => []]], 1));
        $this->assertTrue($this->remote()->logPresetsChosen());
        $before = $this->stored();
        $this->assertSame([], $this->remote()->update(['compliance_baseline_if_unset' => true], 1));
        $this->assertSame($before, $this->stored());
        $this->assertSame([], $this->remote()->logPresets());
        foreach ($this->presetRows() as $r) {
            $this->assertFalse($r['selected']);
        }
        // saving sources without mentioning presets keeps "chose none"
        $this->assertSame([], $this->remote()->update(['logs_collect' => ['windows' => [['channel' => 'System', 'levels' => ['error'], 'event_ids' => [7036]]], 'linux' => []]], 1));
        $this->assertTrue($this->remote()->logPresetsChosen());
        $this->assertSame([], $this->remote()->logPresets());
        $this->assertSame([['channel' => 'System', 'event_ids' => [7036]]], array_map(static fn ($s) => ['channel' => $s['channel'], 'event_ids' => $s['event_ids']], $this->remote()->logSources('windows')));
    }

    public function testAnInstallWithLogsOffStaysOffAndTheAgentConfigFollowsOnlyTheSavedPresets(): void
    {
        $this->switchOn([]);
        $this->assertSame([], $this->remote()->update(['logs_collect' => ['presets' => ['win.sec.accounts']]], 1));
        $this->assertFalse($this->h->module->settings()->featureOn('logs'));
        $cfg = $this->remote()->agentLogConfig('windows');
        $this->assertSame([4720, 4722, 4724, 4725, 4726], $cfg['sources'][0]['event_ids']);
        $res = $this->remote()->applyComplianceBaseline(1, 'Alex');
        $this->assertSame(array_values(array_diff(SecurityLogPresets::complianceBaseline(), ['win.sec.accounts'])), $res['added']);
        $this->assertFalse($this->h->module->settings()->featureOn('logs'), 'applying never switches collection on');
    }

    public function testApplyComplianceBaselineIsOneAuditedIdempotentCallThatKeepsEverythingElse(): void
    {
        $this->switchOn(['logs']);
        $this->assertSame([], $this->remote()->update(['logs_collect' => ['presets' => ['win.sec.devices'], 'windows' => [['channel' => 'System', 'levels' => ['error'], 'event_ids' => [7036]]], 'linux' => []]], 1));
        $n = count($this->h->audit->records());
        $res = $this->remote()->applyComplianceBaseline(1, 'Alex');
        $this->assertSame(SecurityLogPresets::complianceBaseline(), $res['added']);
        $this->assertSame(['win.sec.devices', ...SecurityLogPresets::complianceBaseline()], $res['presets']);
        $this->assertCount(1, $this->remote()->logSources('windows'), 'custom sources kept');
        $recs = $this->h->audit->records();
        $this->assertCount($n + 1, $recs);
        $last = end($recs);
        $this->assertSame('Remote Settings Changed', $last['action']);
        $this->assertStringContainsString('Alex', $last['description']);
        $this->assertStringContainsString('win.sec.accounts', $last['description']);
        $again = $this->remote()->applyComplianceBaseline(1, 'Alex');
        $this->assertSame([], $again['added']);
        $this->assertSame($res['presets'], $again['presets']);
        $this->assertCount($n + 2, $this->h->audit->records());
        // through the action: administrators only
        $this->assertSame(403, $this->h->module->remoteActions()->applyLogPresetBaseline($this->tech)->http);
        $this->assertSame(200, $this->h->module->remoteActions()->applyLogPresetBaseline($this->admin)->http);
    }

    public function testValidationStillRefusesUnknownPresets(): void
    {
        $this->assertNotSame([], $this->remote()->update(['logs_collect' => ['presets' => ['win.sec.nope']]], 1));
        $this->assertNotSame([], $this->remote()->update(['compliance_baseline_if_unset' => 1], 1));
        $this->assertFalse($this->remote()->logPresetsChosen(), 'nothing stored on error');
    }

    // ------------------------------------------------------------------ compliance check

    private function check(): SecurityLogPresetsCheck
    {
        $p = new FixedRequirementsProvider(new FrameworkProfile('contract_x', 'Contract X', '', 1, false, [new Requirement(RequirementType::LOG_PRESETS_BASELINE, null, 1)]));

        return new SecurityLogPresetsCheck($p, new FixedClock(new \DateTimeImmutable('2026-10-27 12:00:00', new \DateTimeZone('UTC'))), $this->remote(), $this->h->module->settings(), '/admin/rmm');
    }

    public function testTheCheckFailsWithRemediationThenPassesWithTheAuditPolicyCaveat(): void
    {
        // logs off
        $this->switchOn([]);
        $r = $this->check()->run();
        $this->assertSame(Status::Fail, $r->status);
        $this->assertStringContainsString('switch is off', (string) $r->detail);
        $this->assertSame('/admin/rmm', $r->fixPath);
        // on, one preset missing
        $this->switchOn(['logs']);
        $this->assertSame([], $this->remote()->update(['logs_collect' => ['presets' => array_values(array_diff(SecurityLogPresets::complianceBaseline(), ['win.sec.audit_policy']))]], 1));
        $r = $this->check()->run();
        $this->assertSame(Status::Fail, $r->status);
        $this->assertStringContainsString('Remediation: Enable preset win.sec.audit_policy (needs Windows audit policy: Audit Audit Policy Change (Success)).', (string) $r->detail);
        $this->assertStringNotContainsString('win.sec.accounts (needs', (string) $r->detail);
        $this->assertSame(6, $r->metrics['presets_enabled']);
        // complete
        $this->remote()->applyComplianceBaseline(1);
        $r = $this->check()->run();
        $this->assertSame(Status::Pass, $r->status);
        $this->assertStringContainsString('Caveat:', (string) $r->detail);
        $this->assertStringContainsString('audit policy', (string) $r->detail);
        // an extra, non-baseline preset does not matter
        $this->assertSame([], $this->remote()->update(['logs_collect' => ['presets' => SecurityLogPresets::ids()]], 1));
        $this->assertSame(Status::Pass, $this->check()->run()->status);
    }

    public function testTheCheckIsNotApplicableWithoutTheRequirementAndBuiltInsAreUnchanged(): void
    {
        $p = new FixedRequirementsProvider(new FrameworkProfile('contract_x', 'Contract X', '', 1, false, [new Requirement(RequirementType::RETENTION_MIN, 'audit_events', 365)]));
        $c = new SecurityLogPresetsCheck($p, new FixedClock(new \DateTimeImmutable('2026-10-27 12:00:00', new \DateTimeZone('UTC'))), $this->remote(), $this->h->module->settings());
        $this->assertSame(Status::NotApplicable, $c->run()->status);
        $this->assertSame(Status::NotApplicable, (new SecurityLogPresetsCheck(new FixedRequirementsProvider(null), new FixedClock(new \DateTimeImmutable('2026-10-27 12:00:00', new \DateTimeZone('UTC'))), $this->remote(), $this->h->module->settings()))->run()->status);
        foreach (BuiltInProfiles::all() as $key => $profile) {
            $this->assertFalse($profile->has(RequirementType::LOG_PRESETS_BASELINE), 'a built-in asks for nothing new');
            $this->assertSame(RequirementType::LOG_PRESETS_BASELINE, BuiltInProfiles::suggestedExtras($key)[0]->type);
        }
        foreach ($c->controls() as $fw => $_) {
            $this->assertTrue(\RivetCore\Compliance\Framework::isValid($fw));
        }
    }
}
