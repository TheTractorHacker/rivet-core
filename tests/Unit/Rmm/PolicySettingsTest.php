<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Policy\PolicySettings;

final class PolicySettingsTest extends TestCase
{
    public function testKeyKinds(): void
    {
        $this->assertSame('interval', PolicySettings::kind('interval.collect_s'));
        $this->assertSame('interval', PolicySettings::kind('interval.check_in_s'));
        $this->assertSame('ring', PolicySettings::kind('agent.ring'));
        $this->assertSame('feature', PolicySettings::kind('feature.software_inventory'));
        $this->assertSame('check', PolicySettings::kind('check.disk_c'));
        foreach (['feature.nope', 'interval.x', 'check.', 'check.bad key', 'agent', 'ring', ''] as $bad) {
            $this->assertNull(PolicySettings::kind($bad), $bad);
        }
    }

    public function testNormalisesShorthandAndExplicitForms(): void
    {
        [$n, $err] = PolicySettings::normalize([
            'interval.collect_s' => 120,
            'feature.checks' => ['mode' => 'disable'],
            'agent.ring' => ['mode' => 'override', 'value' => 'pilot'],
            'feature.script_checks' => ['mode' => 'inherit'],
            'check.svc' => ['type' => 'service', 'params' => ['name' => 'sshd'], 'interval_s' => 60],
        ]);
        $this->assertNull($err);
        $this->assertSame([
            'agent.ring' => ['mode' => 'override', 'value' => 'pilot'],
            'check.svc' => ['mode' => 'override', 'value' => ['type' => 'service', 'params' => ['name' => 'sshd'], 'interval_s' => 60]],
            'feature.checks' => ['mode' => 'disable', 'value' => null],
            'feature.script_checks' => ['mode' => 'inherit', 'value' => null],
            'interval.collect_s' => ['mode' => 'override', 'value' => 120],
        ], $n);
    }

    /** @return iterable<string,array{0:mixed,1:string}> */
    public static function invalid(): iterable
    {
        yield 'not an object' => [[1, 2], 'object'];
        yield 'unknown key' => [['bogus' => 1], 'Unknown setting bogus'];
        yield 'interval too small' => [['interval.collect_s' => 5], 'from 30 to 3600'];
        yield 'check in too large' => [['interval.check_in_s' => 4000], 'from 60 to 3600'];
        yield 'interval not an int' => [['interval.collect_s' => '60'], 'whole number'];
        yield 'feature not a bool' => [['feature.checks' => 'yes'], 'true or false'];
        yield 'ring unknown' => [['agent.ring' => 'canary'], 'stable'];
        yield 'bad mode' => [['interval.collect_s' => ['mode' => 'maybe']], 'mode must be'];
        yield 'disable with a value' => [['feature.checks' => ['mode' => 'disable', 'value' => true]], 'carries no value'];
        yield 'check not an object' => [['check.x' => 5], 'check template'];
        yield 'check bad type' => [['check.x' => ['type' => 'nonsense', 'params' => [], 'interval_s' => 60]], 'Check type'];
        yield 'check interval too small' => [['check.x' => ['type' => 'service', 'params' => [], 'interval_s' => 5]], 'interval_s'];
        yield 'check with a float' => [['check.x' => ['type' => 'disk', 'params' => ['warn' => 1.5], 'interval_s' => 60]], 'whole numbers'];
        yield 'script check without a script' => [['check.x' => ['type' => 'script', 'params' => [], 'interval_s' => 60]], 'Script checks need'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testRejects(mixed $in, string $needle): void
    {
        [$n, $err] = PolicySettings::normalize($in);
        $this->assertNull($n);
        $this->assertStringContainsString($needle, (string) $err);
    }

    public function testNormalizeBody(): void
    {
        [$b, $err] = PolicySettings::normalizeBody('{"settings":{"interval.collect_s":90}}');
        $this->assertNull($err);
        $this->assertSame(['settings' => ['interval.collect_s' => ['mode' => 'override', 'value' => 90]]], $b);
        $this->assertSame(['settings' => []], PolicySettings::normalizeBody([])[0]);
        $this->assertNotNull(PolicySettings::normalizeBody(['settings' => [], 'extra' => 1])[1]);
        $this->assertNotNull(PolicySettings::normalizeBody('not json')[1]);
        $big = ['settings' => []];
        for ($i = 0; $i < PolicySettings::MAX_SETTINGS + 1; ++$i) {
            $big['settings']["check.k$i"] = ['type' => 'pending_reboot', 'params' => [], 'interval_s' => 60];
        }
        $this->assertStringContainsString('at most', (string) PolicySettings::normalizeBody($big)[1]);
    }

    public function testScriptCheckTimeoutIsBoundedByTheCheckValidator(): void
    {
        [$n] = PolicySettings::normalize(['check.s' => ['type' => 'script', 'params' => ['script' => 'exit 0', 'timeout_s' => 9999], 'interval_s' => 60]]);
        $this->assertSame(60, $n['check.s']['value']['params']['timeout_s']);
    }
}
