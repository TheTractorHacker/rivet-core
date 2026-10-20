<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Checks\RmmChecks;
use RivetCore\Rmm\Policy\EffectiveConfig;
use RivetCore\Rmm\Policy\PolicyLayer;
use RivetCore\Rmm\Policy\PolicyResolver;
use RivetCore\Rmm\Policy\PolicySettings;
use RivetCore\Rmm\Settings\ChecksValidator;

final class CheckOsTargetingTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private static function list(): array
    {
        return [
            ['key' => 'svc_eventlog', 'type' => 'service', 'params' => ['name' => 'EventLog'], 'interval_s' => 300, 'os' => 'windows'],
            ['key' => 'svc_cron', 'type' => 'service', 'params' => ['name' => 'cron'], 'interval_s' => 300, 'os' => 'linux'],
            ['key' => 'disk_root', 'type' => 'disk', 'params' => ['mount' => '/'], 'interval_s' => 300],
        ];
    }

    public function testForOsKeepsAnyAndTheMatchingOsInOrder(): void
    {
        $this->assertSame(['svc_eventlog', 'disk_root'], array_column(RmmChecks::forOs(self::list(), 'windows'), 'key'));
        $this->assertSame(['svc_cron', 'disk_root'], array_column(RmmChecks::forOs(self::list(), 'linux'), 'key'));
        $this->assertSame(['svc_eventlog', 'svc_cron', 'disk_root'], array_column(RmmChecks::forOs(self::list(), 'plan9'), 'key'), 'an unknown device OS is not filtered');
    }

    public function testValidatorKeepsOsAndDropsAny(): void
    {
        [$list, $err] = ChecksValidator::validate((string) json_encode([
            ['key' => 'a', 'type' => 'service', 'params' => ['name' => 'x'], 'interval_s' => 60, 'os' => 'linux'],
            ['key' => 'b', 'type' => 'service', 'params' => ['name' => 'x'], 'interval_s' => 60, 'os' => 'any'],
            ['key' => 'c', 'type' => 'service', 'params' => ['name' => 'x'], 'interval_s' => 60],
        ]));
        $this->assertNull($err);
        $this->assertSame('linux', $list[0]['os']);
        $this->assertArrayNotHasKey('os', $list[1]);
        $this->assertArrayNotHasKey('os', $list[2]);
    }

    public function testValidatorRejectsAnUnknownOsWithAClearMessage(): void
    {
        foreach (['macos', 'Windows', 7, ['linux']] as $bad) {
            [$list, $err] = ChecksValidator::validate((string) json_encode([['key' => 'a', 'type' => 'service', 'params' => ['name' => 'x'], 'interval_s' => 60, 'os' => $bad]]));
            $this->assertNull($list);
            $this->assertStringContainsString('Check a: os must be one of windows, linux or any', (string) $err);
        }
    }

    public function testPolicyTemplateHasAnAppliesToThatIsValidatedAndFiltersTheEffectiveList(): void
    {
        [$s, $err] = PolicySettings::normalize(['check.svc_x' => ['type' => 'service', 'params' => ['name' => 'x'], 'interval_s' => 60, 'os' => 'linux']]);
        $this->assertNull($err);
        $this->assertSame('linux', $s['check.svc_x']['value']['os']);
        [, $err] = PolicySettings::normalize(['check.svc_x' => ['type' => 'service', 'params' => ['name' => 'x'], 'interval_s' => 60, 'os' => 'bsd']]);
        $this->assertStringContainsString('os must be one of', (string) $err);
        [$s] = PolicySettings::normalize(['check.svc_y' => ['type' => 'service', 'params' => ['name' => 'y'], 'interval_s' => 60, 'os' => 'any']]);
        $this->assertArrayNotHasKey('os', $s['check.svc_y']['value']);

        $res = PolicyResolver::resolve([new PolicyLayer(1, 1, 'client', 100, false, $s + PolicySettings::normalize(['check.svc_x' => ['type' => 'service', 'params' => ['name' => 'x'], 'interval_s' => 60, 'os' => 'linux']])[0])]);
        $win = EffectiveConfig::compose($res, [], 300, 60, null, 'windows');
        $lin = EffectiveConfig::compose($res, [], 300, 60, null, 'linux');
        $this->assertSame(['svc_y'], array_column($win['checks'], 'key'));
        $this->assertSame(['svc_x', 'svc_y'], array_column($lin['checks'], 'key'));
        $this->assertSame('linux', $lin['checks'][0]['os']);
        $this->assertNotSame($win['version'], $lin['version']);
    }

    public function testADefinitionWithoutOsKeepsItsExactEffectiveShape(): void
    {
        $e = EffectiveConfig::compose([], [['key' => 'k', 'type' => 'disk', 'params' => [], 'interval_s' => 300]], 300, 60, null, 'linux');
        $this->assertSame([['key' => 'k', 'type' => 'disk', 'params' => [], 'interval_s' => 300]], $e['checks']);
    }
}
