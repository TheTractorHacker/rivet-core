<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Policy\EffectiveConfig;
use RivetCore\Rmm\Policy\PolicyLayer;
use RivetCore\Rmm\Policy\PolicyResolver;
use RivetCore\Rmm\Settings\RmmSettings;

final class EffectiveConfigTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private static function base(): array
    {
        return RmmSettings::defaultChecks();
    }

    /** @param array<string,array{mode:string,value:mixed}> $settings */
    private static function resolve(array $settings, string $scope = 'client'): array
    {
        return PolicyResolver::resolve([new PolicyLayer(1, 1, $scope, 100, false, $settings)]);
    }

    public function testWithNoPolicyTheInstanceConfigurationIsDeliveredUnchanged(): void
    {
        $e = EffectiveConfig::compose([], self::base(), 300, 60);
        $this->assertFalse($e['applied']);
        $this->assertSame([300, 60, null], [$e['check_in_interval_s'], $e['collect_interval_s'], $e['ring']]);
        $this->assertSame(['software_inventory' => true, 'script_checks' => true, 'checks' => true], $e['features']);
        $this->assertSame(['disk_c', 'pending_reboot', 'svc_eventlog'], array_column($e['checks'], 'key'));
        $this->assertEquals(self::base()[1]['params'], $e['checks'][1]['params'], 'an empty params object stays an empty object');
        $this->assertInstanceOf(\stdClass::class, $e['checks'][1]['params']);
    }

    public function testIntervalsAreClampedToTheGlobalLimits(): void
    {
        $e = EffectiveConfig::compose(self::resolve(['interval.check_in_s' => ['mode' => 'override', 'value' => 5], 'interval.collect_s' => ['mode' => 'override', 'value' => 99999]]), self::base(), 300, 60);
        $this->assertSame([RmmSettings::CHECK_IN_INTERVAL_MIN_S, RmmSettings::COLLECT_INTERVAL_MAX_S], [$e['check_in_interval_s'], $e['collect_interval_s']]);
        $e = EffectiveConfig::compose(self::resolve(['interval.collect_s' => ['mode' => 'disable', 'value' => null]]), self::base(), 300, 90);
        $this->assertSame(90, $e['collect_interval_s'], 'a disabled interval falls back to the instance value');
    }

    public function testCheckTemplatesReplaceAddAndRemove(): void
    {
        $r = self::resolve([
            'check.disk_c' => ['mode' => 'override', 'value' => ['type' => 'disk', 'params' => ['mount' => 'D:'], 'interval_s' => 120]],
            'check.zz_new' => ['mode' => 'override', 'value' => ['type' => 'service', 'params' => ['name' => 'sshd'], 'interval_s' => 60]],
            'check.aa_new' => ['mode' => 'override', 'value' => ['type' => 'pending_reboot', 'params' => [], 'interval_s' => 600]],
            'check.svc_eventlog' => ['mode' => 'disable', 'value' => null],
        ]);
        $e = EffectiveConfig::compose($r, self::base(), 300, 60);
        $this->assertTrue($e['applied']);
        $this->assertSame(['disk_c', 'pending_reboot', 'aa_new', 'zz_new'], array_column($e['checks'], 'key'), 'global order first, additions sorted after');
        $this->assertSame(['mount' => 'D:'], $e['checks'][0]['params']);
        $this->assertSame(120, $e['checks'][0]['interval_s']);
        $this->assertSame('client', $e['sources']['check.disk_c']['scope_type']);
        $this->assertSame('disable', $e['sources']['check.svc_eventlog']['mode']);
    }

    public function testFeatureTogglesFilterTheList(): void
    {
        $base = [...self::base(), ['key' => 'scr', 'type' => 'script', 'params' => ['script' => 'exit 0', 'timeout_s' => 5], 'interval_s' => 300]];
        $e = EffectiveConfig::compose(self::resolve(['feature.script_checks' => ['mode' => 'override', 'value' => false]]), $base, 300, 60);
        $this->assertSame(['disk_c', 'pending_reboot', 'svc_eventlog'], array_column($e['checks'], 'key'));
        $this->assertFalse($e['features']['script_checks']);
        $e = EffectiveConfig::compose(self::resolve(['feature.checks' => ['mode' => 'disable', 'value' => null]]), $base, 300, 60);
        $this->assertSame([], $e['checks']);
        $this->assertFalse($e['features']['checks']);
        $this->assertTrue($e['features']['software_inventory']);
        $e = EffectiveConfig::compose(self::resolve(['feature.software_inventory' => ['mode' => 'override', 'value' => false]]), $base, 300, 60);
        $this->assertFalse($e['features']['software_inventory']);
    }

    public function testChecksOfATypeTheDeviceCannotRunAreLeftOut(): void
    {
        $base = [...self::base(), ['key' => 'tls', 'type' => 'certificate', 'params' => [], 'interval_s' => 600]];
        $caps = ['check:disk', 'check:service', 'job:shell'];
        $e = EffectiveConfig::compose([], $base, 300, 60, $caps);
        $this->assertSame(['disk_c', 'svc_eventlog'], array_column($e['checks'], 'key'));
        // an old agent announced no check types: it is sent everything and ignores what it cannot run
        $this->assertCount(4, EffectiveConfig::compose([], $base, 300, 60, ['software_inventory'])['checks']);
        $this->assertCount(4, EffectiveConfig::compose([], $base, 300, 60, null)['checks']);
    }

    public function testTheVersionChangesWithTheContentAndOnlyThen(): void
    {
        $a = EffectiveConfig::compose([], self::base(), 300, 60);
        $b = EffectiveConfig::compose([], self::base(), 300, 60);
        $this->assertSame($a['version'], $b['version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a['version']);
        $this->assertNotSame($a['version'], EffectiveConfig::compose([], self::base(), 301, 60)['version']);
        $this->assertNotSame($a['version'], EffectiveConfig::compose(self::resolve(['agent.ring' => ['mode' => 'override', 'value' => 'pilot']]), self::base(), 300, 60)['version']);
        // sources do not change the version: the same outcome from a different layer is the same configuration
        $x = EffectiveConfig::compose(self::resolve(['interval.collect_s' => ['mode' => 'override', 'value' => 45]], 'client'), self::base(), 300, 60);
        $y = EffectiveConfig::compose(self::resolve(['interval.collect_s' => ['mode' => 'override', 'value' => 45]], 'device'), self::base(), 300, 60);
        $this->assertSame($x['version'], $y['version']);
    }
}
