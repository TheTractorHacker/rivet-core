<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Patch\PatchJobTypes;
use RivetCore\Rmm\Patch\PatchPolicyResolver;
use RivetCore\Rmm\Patch\PatchPolicySettings;
use RivetCore\Rmm\Patch\PatchProtocol;
use RivetCore\Rmm\Patch\WindowsUpdatePlan;
use RivetCore\Rmm\Policy\PolicyLayer;

/** Windows Update control (1.0.0-rc.65): policy settings validation (hostile values included), the resolver, the job parameter allow-list, the plan and the report validation. Pure: no database. */
final class WindowsUpdatePlanTest extends TestCase
{
    private const RINGS = ['pilot' => 0];

    /** @param array<string,mixed> $values */
    private static function layer(int $id, string $scope, array $values, int $priority = 100, bool $enforce = false): PolicyLayer
    {
        $s = [];
        foreach ($values as $k => $v) {
            $s[$k] = ['mode' => 'override', 'value' => $v];
        }

        return new PolicyLayer($id, $id, $scope, $priority, $enforce, $s);
    }

    /** @return array{update_mode:string,feature_target:string,quality_defer_days:int,active:bool} */
    private static function w(string $mode = 'leave', string $target = 'none', int $defer = 0): array
    {
        return ['update_mode' => $mode, 'feature_target' => $target, 'quality_defer_days' => $defer, 'active' => $mode === 'managed' || $target !== 'none' || $defer > 0];
    }

    /** @param array<string,mixed> $over */
    private static function state(array $over = []): array
    {
        return array_replace_recursive(['feature_version' => '24H2', 'build' => '26100', 'product' => 'Windows 11', 'external' => [], 'policy' => [], 'core' => ['keys' => [], 'pinned' => null, 'held' => false]], $over);
    }

    // ------------------------------------------------------------------ policy settings

    public function testTheThreeSettingsValidateStrictly(): void
    {
        [$ok, $err] = PatchPolicySettings::normalize(['windows_update_mode' => 'managed', 'windows_feature_target' => '25H2', 'windows_quality_defer_days' => 14], []);
        $this->assertNull($err);
        $this->assertSame(['managed', '25H2', 14], [$ok['windows_update_mode']['value'], $ok['windows_feature_target']['value'], $ok['windows_quality_defer_days']['value']]);
        foreach (['hold', 'none', '22H2', '26H2', '30H1'] as $t) {
            $this->assertNull(PatchPolicySettings::normalize(['windows_feature_target' => $t], [])[1], $t);
        }
        $hostile = ['windows_feature_target' => ['', 'HOLD', 'Hold', '25h2', '25H3', '2025H2', '25H2 ', "25H2\n", ' 25H2', '25H2;calc', '25H2\\..\\x', '$(x)', "25H2\0", 'latest', 'Windows 11', '1909', 21, true, ['25H2'], 'H2', '5H2', str_repeat('A', 5000)],
            'windows_update_mode' => ['', 'Managed', 'auto', 'notify', 'off', true, 1, ['managed']],
            'windows_quality_defer_days' => [-1, 31, 100, '7', 7.0, 7.5, true, '', null === 1, [7]]];
        foreach ($hostile as $key => $values) {
            foreach ($values as $v) {
                [$out, $err] = PatchPolicySettings::normalize([$key => $v], []);
                $this->assertNull($out, $key . '=' . json_encode($v));
                $this->assertNotNull($err);
            }
        }
        $this->assertSame([null, 'Unknown patch setting "windows_registry_key".'], PatchPolicySettings::normalize(['windows_registry_key' => 'HKLM\\x'], []));
        $this->assertContains('windows_feature_target', PatchPolicySettings::keys());
    }

    public function testEveryDefaultLeavesWindowsAlone(): void
    {
        $e = PatchPolicyResolver::resolve([], self::RINGS);
        $this->assertSame(['update_mode' => 'leave', 'feature_target' => 'none', 'quality_defer_days' => 0, 'active' => false], $e['windows']);
        // a policy that only sets an unrelated key does not switch Windows control on
        $this->assertFalse(PatchPolicyResolver::resolve([self::layer(1, 'global', ['install_enabled' => true])], self::RINGS)['windows']['active']);
    }

    public function testTheResolverFollowsScopePrecedenceAndEnforceForTheWindowsKeys(): void
    {
        $layers = [
            self::layer(1, 'global', ['windows_update_mode' => 'managed', 'windows_feature_target' => 'hold', 'windows_quality_defer_days' => 7]),
            self::layer(2, 'client', ['windows_feature_target' => '25H2']),
            self::layer(3, 'device', ['windows_update_mode' => 'leave']),
        ];
        $e = PatchPolicyResolver::resolve($layers, self::RINGS);
        $this->assertSame(['leave', '25H2', 7, true], [$e['windows']['update_mode'], $e['windows']['feature_target'], $e['windows']['quality_defer_days'], $e['windows']['active']]);
        $this->assertSame(1, $e['sources']['windows_quality_defer_days']['policy_id']);
        $this->assertSame('device', $e['sources']['windows_update_mode']['scope_type']);
        // enforce on the global layer locks the key against the more specific device layer
        $layers[0] = self::layer(1, 'global', ['windows_update_mode' => 'managed'], 100, true);
        $this->assertSame('managed', PatchPolicyResolver::resolve($layers, self::RINGS)['windows']['update_mode']);
        // a stored value that no longer validates degrades to "touch nothing", never to something else
        $bad = PatchPolicyResolver::resolve([self::layer(1, 'global', ['windows_feature_target' => '25H2; rm', 'windows_update_mode' => 'weird', 'windows_quality_defer_days' => 999])], self::RINGS)['windows'];
        $this->assertSame(['leave', 'none', 30, true], [$bad['update_mode'], $bad['feature_target'], $bad['quality_defer_days'], $bad['active']], 'deferral is clamped, the rest falls back to nothing');
    }

    // ------------------------------------------------------------------ the job

    public function testTheJobParametersAreAStrictAllowList(): void
    {
        $this->assertSame([['mode' => 'dry_run', 'update_mode' => 'leave', 'feature_target' => 'none', 'defer_days' => 0], null], PatchJobTypes::configure([]));
        [$p, $e] = PatchJobTypes::configure(['mode' => 'apply', 'update_mode' => 'managed', 'feature_target' => '26H2', 'defer_days' => 30]);
        $this->assertNull($e);
        $this->assertSame(['apply', 'managed', '26H2', 30], array_values($p));
        foreach ([['path' => 'x'], ['key' => 'HKLM'], ['value' => 1], ['mode' => 'live'], ['mode' => 1], ['update_mode' => 'x'], ['feature_target' => '25h2'], ['feature_target' => "25H2\n"], ['feature_target' => 'x;y'], ['defer_days' => 31], ['defer_days' => -1],
            ['defer_days' => '5'], ['defer_days' => null], ['feature_target' => null]] as $bad) {
            $this->assertNotNull(PatchJobTypes::configure($bad)[1], json_encode($bad));
        }
    }

    public function testTheJobTypeNeedsTheCapabilityIsDestructiveAndWindowsOnly(): void
    {
        $r = new \RivetCore\Rmm\Job\JobTypeRegistry();
        PatchJobTypes::register($r);
        $t = $r->get('patch_configure');
        $this->assertNotNull($t);
        $this->assertSame(['patch:configure', true, ['windows'], \RivetCore\Rmm\Authz\RmmAbility::PATCH_INSTALL], [$t->capability, $t->destructive, $t->platforms, $t->ability]);
        $this->assertLessThanOrEqual(20, strlen('patch_configure'), 'endpoint_agent_jobs.type is varchar(20)');
        $this->assertGreaterThan(14, strlen('patch_configure'), 'which is why the ledger (varchar(14)) uses its own short name');
        $this->assertLessThanOrEqual(14, strlen(PatchProtocol::LEDGER_CONFIGURE));
        $this->assertLessThanOrEqual(14, strlen(PatchProtocol::LEDGER_WU_STATE));
        $this->assertSame(36, strlen(PatchProtocol::wuStateRowId(7)));
        $this->assertSame(36, strlen(PatchProtocol::wuStateRowId(2147483647)));
        $this->assertTrue(PatchProtocol::isPatchJob('patch_configure'));
        $this->assertSame('patch:configure', PatchProtocol::capabilityFor('patch_configure'));
        // an agent that announced only scan and install is never offered it
        $this->assertFalse(\RivetCore\Rmm\Job\JobService::deviceCanRun(['job:patch_scan', 'job:patch_install', 'patch:scan', 'patch:install'], 'patch_configure'));
        $this->assertFalse(\RivetCore\Rmm\Job\JobService::deviceCanRun([], 'patch_configure'), 'never assumed');
        $this->assertTrue(\RivetCore\Rmm\Job\JobService::deviceCanRun(['job:patch_configure', 'patch:configure'], 'patch_configure'));
        $this->assertFalse(\RivetCore\Rmm\Job\JobService::deviceCanRun(['job:patch_configure'], 'patch_configure'), 'the job: flag alone is not the capability');
    }

    // ------------------------------------------------------------------ versions

    public function testFeatureVersionsValidateAndCompare(): void
    {
        foreach (['22H2', '23H2', '24H2', '25H2', '26H2', '26H1'] as $v) {
            $this->assertTrue(PatchProtocol::validFeatureVersion($v), $v);
        }
        foreach (['', 'H2', '25H3', '25h2', "25H2\n", '2025H2', null, 25, '1909'] as $v) {
            $this->assertFalse(PatchProtocol::validFeatureVersion($v), json_encode($v));
        }
        $this->assertLessThan(0, PatchProtocol::compareFeatureVersions('24H2', '25H2'));
        $this->assertLessThan(0, PatchProtocol::compareFeatureVersions('25H1', '25H2'));
        $this->assertGreaterThan(0, PatchProtocol::compareFeatureVersions('26H2', '25H2'));
        $this->assertSame(0, PatchProtocol::compareFeatureVersions('25H2', '25H2'));
    }

    // ------------------------------------------------------------------ the plan

    public function testThePlanSaysWhatToDo(): void
    {
        $managed = self::w('managed');
        $this->assertSame('not_reported', WindowsUpdatePlan::evaluate($managed, null, true)['status']);
        $this->assertSame('unsupported_agent', WindowsUpdatePlan::evaluate($managed, self::state(), false)['status']);
        $this->assertSame('off', WindowsUpdatePlan::evaluate(self::w(), self::state(), true)['status']);
        $p = WindowsUpdatePlan::evaluate($managed, self::state(), true);
        $this->assertSame(['pending', true, ['no_auto_update', 'au_options']], [$p['status'], $p['needs'], $p['changes']]);
        $synced = self::state(['policy' => ['no_auto_update' => 0, 'au_options' => 2], 'core' => ['keys' => ['no_auto_update', 'au_options']]]);
        $this->assertSame(['in_sync', false], [WindowsUpdatePlan::evaluate($managed, $synced, true)['status'], WindowsUpdatePlan::evaluate($managed, $synced, true)['needs']]);
        // back to leave: restore what Core owns
        $r = WindowsUpdatePlan::evaluate(self::w(), $synced, true);
        $this->assertSame(['restore_pending', true], [$r['status'], $r['needs']]);
        // someone else owns it: never queued
        foreach (['wsus', 'gpo', 'mdm', 'changed_since'] as $who) {
            $x = WindowsUpdatePlan::evaluate($managed, self::state(['external' => [$who]]), true);
            $this->assertSame(['externally_managed', false], [$x['status'], $x['needs']], $who);
            $this->assertSame('externally_managed', WindowsUpdatePlan::evaluate(self::w(), self::state(['external' => [$who]]), true)['status']);
        }
    }

    public function testHoldIsPendingUntilPinnedAndThenFollowsThePin(): void
    {
        $hold = self::w('leave', 'hold');
        $this->assertTrue(WindowsUpdatePlan::evaluate($hold, self::state(), true)['needs'], 'nothing pinned yet');
        $pinned = self::state(['policy' => ['target_release_version' => 1, 'target_release_version_info' => '24H2'], 'core' => ['keys' => ['target_release_version', 'target_release_version_info', 'product_version'], 'pinned' => '24H2', 'held' => true]]);
        $this->assertSame('in_sync', WindowsUpdatePlan::evaluate($hold, $pinned, true)['status']);
        $this->assertSame('24H2', WindowsUpdatePlan::targetVersion($hold, $pinned));
        $this->assertNull(WindowsUpdatePlan::targetVersion($hold, self::state()));
        // a pin that came from a NAMED version is not a hold: switching to hold must re-pin to what the device runs
        $named = self::state(['policy' => ['target_release_version' => 1, 'target_release_version_info' => '25H2'], 'core' => ['keys' => ['target_release_version', 'target_release_version_info'], 'pinned' => '25H2', 'held' => false]]);
        $this->assertTrue(WindowsUpdatePlan::evaluate($hold, $named, true)['needs']);
        $this->assertNull(WindowsUpdatePlan::targetVersion($hold, $named));
        $this->assertSame('25H2', WindowsUpdatePlan::targetVersion(self::w('leave', '25H2'), self::state()));
        // changing a named target is a change
        $this->assertSame(['target_release_version_info'], WindowsUpdatePlan::evaluate(self::w('leave', '26H2'), $named, true)['changes']);
    }

    public function testDeferralAndRestoreOfASingleKey(): void
    {
        $state = self::state(['policy' => ['defer_quality_updates' => 1, 'defer_quality_days' => 7], 'core' => ['keys' => ['defer_quality_updates', 'defer_quality_days']]]);
        $this->assertSame('in_sync', WindowsUpdatePlan::evaluate(self::w('leave', 'none', 7), $state, true)['status']);
        $this->assertSame(['defer_quality_days'], WindowsUpdatePlan::evaluate(self::w('leave', 'none', 14), $state, true)['changes']);
        $this->assertSame('restore_pending', WindowsUpdatePlan::evaluate(self::w(), $state, true)['status']);
    }

    // ------------------------------------------------------------------ reports are never trusted

    public function testHostileReportsAreCleaned(): void
    {
        $this->assertNull(WindowsUpdatePlan::cleanState('x'));
        $this->assertNull(WindowsUpdatePlan::cleanState([1, 2]));
        $s = WindowsUpdatePlan::cleanState([
            'feature_version' => "25H2\n", 'build' => '26100; drop', 'product' => 'Windows 12', 'external' => ['wsus', 'wsus', 'evil', 5, 'gpo'],
            'policy' => ['au_options' => 2, 'target_release_version_info' => '25H2', 'UseWUServer' => 1, 'defer_quality_days' => -3, 'no_auto_update' => '0', 'product_version' => '<script>', 'defer_quality_updates' => 4294967296],
            'core' => ['keys' => ['au_options', 'UseWUServer', 7, 'au_options'], 'pinned' => 'latest', 'held' => 'yes'],
        ]);
        $this->assertSame('', $s['feature_version']);
        $this->assertSame('', $s['build']);
        $this->assertSame('', $s['product']);
        $this->assertSame(['wsus', 'gpo'], $s['external']);
        $this->assertSame(['au_options' => 2, 'target_release_version_info' => '25H2'], $s['policy']);
        $this->assertSame(['au_options'], $s['core']['keys']);
        $this->assertNull($s['core']['pinned']);
        $ok = WindowsUpdatePlan::cleanState(['feature_version' => '25H2', 'build' => '26200', 'product' => 'Windows 11', 'core' => ['keys' => [], 'pinned' => '25H2', 'held' => true]]);
        $this->assertSame(['25H2', '26200', 'Windows 11', '25H2', true], [$ok['feature_version'], $ok['build'], $ok['product'], $ok['core']['pinned'], $ok['core']['held']]);
    }

    public function testAResultIsValidatedAndAnUnreadableOneIsNull(): void
    {
        $this->assertNull(WindowsUpdatePlan::cleanResult('not json'));
        $this->assertNull(WindowsUpdatePlan::cleanResult('{"ok":false}'));
        $this->assertNull(WindowsUpdatePlan::cleanResult('{"ok":true,"outcome":"exploded","mode":"apply"}'));
        $this->assertNull(WindowsUpdatePlan::cleanResult('{"ok":true,"outcome":"changed","mode":"nope"}'));
        $r = WindowsUpdatePlan::cleanResult((string) json_encode(['ok' => true, 'outcome' => 'changed', 'mode' => 'apply', 'detail' => "x\ny", 'changes' => [['key' => 'au_options', 'from' => null, 'to' => 2], ['key' => 'rm -rf', 'from' => 1, 'to' => 2], 'junk'],
            'before' => ['feature_version' => '24H2'], 'after' => ['feature_version' => '24H2', 'core' => ['keys' => ['au_options']]]]));
        $this->assertSame('changed', $r['outcome']);
        $this->assertSame([['key' => 'au_options', 'from' => null, 'to' => 2]], $r['changes']);
        $this->assertSame('x y', $r['detail']);
        $this->assertSame(['au_options'], $r['after']['core']['keys']);
    }
}
