<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Policy\PolicyLayer;
use RivetCore\Rmm\Policy\PolicyResolver;

/** The pure resolver: precedence, conflicts inside one level, enforce, disable and inherit, determinism. */
final class PolicyResolverTest extends TestCase
{
    /** @param array<string,array{mode:string,value:mixed}>|array<string,mixed> $settings */
    private static function layer(int $aid, string $scope, array $settings, int $priority = 100, bool $enforce = false, ?int $policy = null): PolicyLayer
    {
        $norm = [];
        foreach ($settings as $k => $v) {
            $norm[$k] = is_array($v) && array_key_exists('mode', $v) ? $v : ['mode' => 'override', 'value' => $v];
        }

        return new PolicyLayer($aid, $policy ?? $aid, $scope, $priority, $enforce, $norm);
    }

    private static function value(array $resolved, string $key): mixed
    {
        return $resolved[$key]['value'] ?? '(absent)';
    }

    public function testEveryScopeBeatsTheOnesBelowIt(): void
    {
        $order = ['global', 'client', 'site', 'group', 'tag', 'device'];
        // The matrix: for every pair, the more specific scope wins, whichever order the layers are given in.
        foreach ($order as $i => $low) {
            foreach ($order as $j => $high) {
                if ($j <= $i) {
                    continue;
                }
                foreach ([[$low, $high], [$high, $low]] as $given) {
                    $layers = [];
                    foreach ($given as $n => $scope) {
                        $layers[] = self::layer($n + 1, $scope, ['interval.collect_s' => $scope === $high ? 111 : 222]);
                    }
                    $this->assertSame(111, self::value(PolicyResolver::resolve($layers), 'interval.collect_s'), "$high must beat $low");
                }
            }
        }
    }

    public function testEachKeyIsDecidedOnItsOwn(): void
    {
        $r = PolicyResolver::resolve([
            self::layer(1, 'client', ['interval.collect_s' => 100, 'interval.check_in_s' => 300, 'feature.script_checks' => false]),
            self::layer(2, 'tag', ['interval.collect_s' => 60]),
            self::layer(3, 'device', ['agent.ring' => 'pilot']),
        ]);
        $this->assertSame([60, 300, false, 'pilot'], [self::value($r, 'interval.collect_s'), self::value($r, 'interval.check_in_s'), self::value($r, 'feature.script_checks'), self::value($r, 'agent.ring')]);
        $this->assertSame(['assignment_id' => 1, 'policy_id' => 1, 'scope_type' => 'client', 'enforced' => false], $r['interval.check_in_s']['source']);
        $this->assertSame('tag', $r['interval.collect_s']['source']['scope_type']);
        $this->assertSame(['agent.ring', 'feature.script_checks', 'interval.check_in_s', 'interval.collect_s'], array_keys($r), 'keys are sorted');
    }

    public function testAKeyNobodyMentionsIsAbsent(): void
    {
        $this->assertSame([], PolicyResolver::resolve([]));
        $this->assertSame([], PolicyResolver::resolve([self::layer(1, 'global', ['interval.collect_s' => ['mode' => 'inherit', 'value' => null]])]));
    }

    public function testPriorityThenNewerAssignmentBreaksATieInsideOneScope(): void
    {
        $a = self::layer(10, 'tag', ['interval.collect_s' => 100], 100);
        $b = self::layer(11, 'tag', ['interval.collect_s' => 200], 100);
        $c = self::layer(12, 'tag', ['interval.collect_s' => 300], 500);
        $this->assertSame(200, self::value(PolicyResolver::resolve([$a, $b]), 'interval.collect_s'), 'equal priority: the newer assignment wins');
        $this->assertSame(300, self::value(PolicyResolver::resolve([$a, $b, $c]), 'interval.collect_s'), 'a higher priority wins over a newer assignment');
        $this->assertSame(300, self::value(PolicyResolver::resolve([$c, $b, $a]), 'interval.collect_s'));
    }

    public function testDisableIsADecisionNotAnAbsence(): void
    {
        $r = PolicyResolver::resolve([
            self::layer(1, 'client', ['check.disk_c' => ['mode' => 'override', 'value' => ['type' => 'disk', 'params' => [], 'interval_s' => 300]]]),
            self::layer(2, 'device', ['check.disk_c' => ['mode' => 'disable', 'value' => null]]),
        ]);
        $this->assertSame('disable', $r['check.disk_c']['mode']);
        $this->assertSame(2, $r['check.disk_c']['source']['assignment_id']);
        // and a more specific override re-enables over a less specific disable
        $r = PolicyResolver::resolve([
            self::layer(1, 'client', ['feature.checks' => ['mode' => 'disable', 'value' => null]]),
            self::layer(2, 'tag', ['feature.checks' => true]),
        ]);
        $this->assertSame('override', $r['feature.checks']['mode']);
    }

    public function testInheritSaysNothingSoTheNextLayerDecides(): void
    {
        $r = PolicyResolver::resolve([
            self::layer(1, 'client', ['interval.collect_s' => 100]),
            self::layer(2, 'device', ['interval.collect_s' => ['mode' => 'inherit', 'value' => null]]),
        ]);
        $this->assertSame(100, self::value($r, 'interval.collect_s'));
    }

    public function testEnforceCannotBeOverriddenAndTheOutermostEnforcerWins(): void
    {
        $client = self::layer(1, 'client', ['interval.collect_s' => 300], 100, true);
        $device = self::layer(2, 'device', ['interval.collect_s' => 30]);
        $this->assertSame(300, self::value(PolicyResolver::resolve([$device, $client]), 'interval.collect_s'));
        $this->assertTrue(PolicyResolver::resolve([$device, $client])['interval.collect_s']['source']['enforced']);
        // two enforced layers: the least specific one has the last word
        $tag = self::layer(3, 'tag', ['interval.collect_s' => 90], 100, true);
        $this->assertSame(300, self::value(PolicyResolver::resolve([$tag, $client, $device]), 'interval.collect_s'));
        $this->assertSame(90, self::value(PolicyResolver::resolve([$tag, $device]), 'interval.collect_s'));
        // enforce only protects the keys the enforcing layer sets
        $other = PolicyResolver::resolve([$client, self::layer(4, 'device', ['agent.ring' => 'pilot'])]);
        $this->assertSame('pilot', self::value($other, 'agent.ring'));
        // same level, both enforced: priority then newer
        $e1 = self::layer(5, 'client', ['interval.collect_s' => 111], 100, true);
        $e2 = self::layer(6, 'client', ['interval.collect_s' => 222], 100, true);
        $this->assertSame(222, self::value(PolicyResolver::resolve([$e1, $e2]), 'interval.collect_s'));
    }

    public function testAnEnforcedDisableHoldsToo(): void
    {
        $r = PolicyResolver::resolve([
            self::layer(1, 'global', ['feature.script_checks' => ['mode' => 'disable', 'value' => null]], 100, true),
            self::layer(2, 'device', ['feature.script_checks' => true]),
        ]);
        $this->assertSame('disable', $r['feature.script_checks']['mode']);
    }

    public function testTheResultDoesNotDependOnTheOrderOfTheLayers(): void
    {
        $layers = [
            self::layer(1, 'global', ['interval.collect_s' => 600, 'feature.script_checks' => false]),
            self::layer(2, 'client', ['interval.collect_s' => 300, 'interval.check_in_s' => 120], 100, true),
            self::layer(3, 'site', ['agent.ring' => 'pilot']),
            self::layer(4, 'group', ['interval.check_in_s' => 90, 'interval.collect_s' => 45], 200),
            self::layer(5, 'group', ['interval.check_in_s' => 80], 100),
            self::layer(6, 'tag', ['interval.collect_s' => 40, 'feature.script_checks' => true]),
            self::layer(7, 'tag', ['interval.collect_s' => 50, 'feature.script_checks' => ['mode' => 'disable', 'value' => null]], 100),
            self::layer(8, 'device', ['interval.check_in_s' => 61, 'agent.ring' => 'stable']),
        ];
        $expected = PolicyResolver::resolve($layers);
        mt_srand(7);
        for ($i = 0; $i < 25; ++$i) {
            $shuffled = $layers;
            shuffle($shuffled);
            $this->assertSame($expected, PolicyResolver::resolve($shuffled));
        }
    }

    public function testAnUnknownScopeIsAProgrammerError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PolicyResolver::resolve([self::layer(1, 'planet', ['interval.collect_s' => 100])]);
    }

    public function testAnAssignmentOverrideLaidOverThePolicy(): void
    {
        $policy = ['interval.collect_s' => ['mode' => 'override', 'value' => 100], 'agent.ring' => ['mode' => 'override', 'value' => 'pilot'], 'feature.checks' => ['mode' => 'override', 'value' => true]];
        $merged = PolicyLayer::merge($policy, [
            'interval.collect_s' => ['mode' => 'override', 'value' => 200],
            'agent.ring' => ['mode' => 'inherit', 'value' => null],
            'feature.checks' => ['mode' => 'disable', 'value' => null],
        ]);
        $this->assertSame(['feature.checks' => ['mode' => 'disable', 'value' => null], 'interval.collect_s' => ['mode' => 'override', 'value' => 200]], $merged);
    }
}
