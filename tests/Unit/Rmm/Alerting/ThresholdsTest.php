<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\Alerting;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Alerting\Thresholds;

final class ThresholdsTest extends TestCase
{
    /** @return array<string,mixed> */
    private function t(array $extra = []): array
    {
        [$t, $e] = Thresholds::normalize($extra + ['warn' => ['op' => 'gt', 'value' => 80], 'crit' => ['op' => 'gt', 'value' => 95]]);
        $this->assertNull($e);
        $this->assertNotNull($t);

        return $t;
    }

    public function testNormalizeFillsDefaults(): void
    {
        $t = $this->t();
        $this->assertSame(0, $t['hysteresis']);
        $this->assertSame(1, $t['for_samples']);
        $this->assertSame(0, $t['for_minutes']);
    }

    /** @return list<array{mixed,string}> */
    public static function bad(): array
    {
        return [
            ['x', 'must be an object'],
            [[1, 2], 'must be an object'],
            [[], 'needs a warn or a crit'],
            [['warn' => ['op' => 'between', 'value' => 1]], 'thresholds.warn'],
            [['warn' => ['op' => 'gt', 'value' => 1.5]], 'whole-number'],
            [['warn' => ['op' => 'gt', 'value' => '5']], 'whole-number'],
            [['crit' => ['op' => 'gt']], 'thresholds.crit'],
            [['warn' => ['op' => 'gt', 'value' => 90], 'crit' => ['op' => 'gt', 'value' => 80]], 'beyond'],
            [['warn' => ['op' => 'lt', 'value' => 10], 'crit' => ['op' => 'lt', 'value' => 20]], 'beyond'],
            [['warn' => ['op' => 'gt', 'value' => 1], 'extra' => 1], 'unknown field'],
            [['warn' => ['op' => 'gt', 'value' => 1], 'for_samples' => 0], 'for_samples'],
            [['warn' => ['op' => 'gt', 'value' => 1], 'for_samples' => 101], 'for_samples'],
            [['warn' => ['op' => 'gt', 'value' => 1], 'for_minutes' => 1441], 'for_minutes'],
            [['warn' => ['op' => 'gt', 'value' => 1], 'hysteresis' => -1], 'hysteresis'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad')]
    public function testNormalizeRefuses(mixed $in, string $needle): void
    {
        [$t, $e] = Thresholds::normalize($in);
        $this->assertNull($t);
        $this->assertStringContainsString($needle, (string) $e);
    }

    public function testTiersAndOperators(): void
    {
        $t = $this->t();
        $this->assertSame('ok', Thresholds::rawTier(80, $t));
        $this->assertSame('warn', Thresholds::rawTier(80.5, $t));
        $this->assertSame('warn', Thresholds::rawTier(95, $t));
        $this->assertSame('crit', Thresholds::rawTier(95.1, $t));
        [$low] = Thresholds::normalize(['warn' => ['op' => 'lte', 'value' => 20], 'crit' => ['op' => 'lt', 'value' => 10]]);
        $this->assertNotNull($low);
        $this->assertSame('ok', Thresholds::rawTier(21, $low));
        $this->assertSame('warn', Thresholds::rawTier(20, $low));
        $this->assertSame('warn', Thresholds::rawTier(10, $low));
        $this->assertSame('crit', Thresholds::rawTier(9, $low));
        [$warnOnly] = Thresholds::normalize(['warn' => ['op' => 'gte', 'value' => 5]]);
        $this->assertNotNull($warnOnly);
        $this->assertSame('warn', Thresholds::rawTier(1000, $warnOnly), 'a missing crit limit never triggers crit');
    }

    public function testHysteresisKeepsATierUntilTheValueIsClearlyBack(): void
    {
        $t = $this->t(['hysteresis' => 5]);
        $this->assertSame('warn', Thresholds::rawTier(81, $t, 'ok'));
        $this->assertSame('warn', Thresholds::rawTier(76, $t, 'warn'), 'inside the band: kept');
        $this->assertSame('ok', Thresholds::rawTier(75, $t, 'warn'), 'at limit minus hysteresis: cleared');
        $this->assertSame('ok', Thresholds::rawTier(79, $t, 'ok'), 'a value that was never in the tier does not enter it below the limit');
        $this->assertSame('crit', Thresholds::rawTier(91, $t, 'crit'), 'crit kept down to 90 exclusive');
        $this->assertSame('warn', Thresholds::rawTier(90, $t, 'crit'), 'out of crit, still warn');
        // lower-is-worse metrics: the band is above the limit
        [$low] = Thresholds::normalize(['warn' => ['op' => 'lt', 'value' => 20], 'hysteresis' => 3]);
        $this->assertNotNull($low);
        $this->assertSame('warn', Thresholds::rawTier(22, $low, 'warn'));
        $this->assertSame('ok', Thresholds::rawTier(23, $low, 'warn'));
    }

    public function testDurationBySamples(): void
    {
        $t = $this->t(['for_samples' => 3]);
        $s = ['tier' => 'ok', 'cand_tier' => 'ok', 'cand_since' => null, 'cand_count' => 0];
        $s = Thresholds::step($s, 'warn', 1000, $t);
        $this->assertSame('ok', $s['tier']);
        $s = Thresholds::step($s, 'warn', 1060, $t);
        $this->assertSame('ok', $s['tier']);
        $s = Thresholds::step($s, 'warn', 1120, $t);
        $this->assertSame('warn', $s['tier'], 'the third consecutive sample raises the tier');
        $this->assertSame(0, $s['cand_count']);
    }

    public function testASingleGoodSampleResetsTheCount(): void
    {
        $t = $this->t(['for_samples' => 3]);
        $s = ['tier' => 'ok', 'cand_tier' => 'ok', 'cand_since' => null, 'cand_count' => 0];
        $s = Thresholds::step($s, 'warn', 1000, $t);
        $s = Thresholds::step($s, 'warn', 1060, $t);
        $s = Thresholds::step($s, 'ok', 1120, $t);
        $this->assertSame(0, $s['cand_count']);
        $s = Thresholds::step($s, 'warn', 1180, $t);
        $s = Thresholds::step($s, 'warn', 1240, $t);
        $this->assertSame('ok', $s['tier'], 'only two since the reset');
    }

    public function testDurationByMinutesNeedsBothSamplesAndTime(): void
    {
        $t = $this->t(['for_samples' => 2, 'for_minutes' => 10]);
        $s = ['tier' => 'ok', 'cand_tier' => 'ok', 'cand_since' => null, 'cand_count' => 0];
        $s = Thresholds::step($s, 'warn', 0, $t);
        $s = Thresholds::step($s, 'warn', 300, $t);
        $this->assertSame('ok', $s['tier'], '2 samples but only 5 minutes');
        $s = Thresholds::step($s, 'warn', 599, $t);
        $this->assertSame('ok', $s['tier']);
        $s = Thresholds::step($s, 'warn', 600, $t);
        $this->assertSame('warn', $s['tier'], '10 minutes');
    }

    public function testRaisingToCritAfterWarnRestartsTheCandidate(): void
    {
        $t = $this->t(['for_samples' => 2]);
        $s = ['tier' => 'ok', 'cand_tier' => 'ok', 'cand_since' => null, 'cand_count' => 0];
        $s = Thresholds::step(Thresholds::step($s, 'warn', 0, $t), 'warn', 60, $t);
        $this->assertSame('warn', $s['tier']);
        $s = Thresholds::step($s, 'crit', 120, $t);
        $this->assertSame('warn', $s['tier']);
        $s = Thresholds::step($s, 'crit', 180, $t);
        $this->assertSame('crit', $s['tier']);
    }

    public function testLoweringAndClearingAreImmediate(): void
    {
        $t = $this->t(['for_samples' => 5]);
        $s = ['tier' => 'crit', 'cand_tier' => 'ok', 'cand_since' => null, 'cand_count' => 0];
        $this->assertSame('warn', Thresholds::step($s, 'warn', 0, $t)['tier']);
        $this->assertSame('ok', Thresholds::step($s, 'ok', 0, $t)['tier']);
    }

    public function testStatusMappingAndDescribe(): void
    {
        $this->assertSame('fail', Thresholds::status('crit'));
        $this->assertSame('warn', Thresholds::status('warn'));
        $this->assertSame('ok', Thresholds::status('ok'));
        $this->assertSame('crit', Thresholds::tierOfStatus('fail'));
        $t = $this->t();
        $this->assertSame('value 91.5 > 80 (warn)', Thresholds::describe(91.5, 'warn', $t));
        $this->assertSame('value 3', Thresholds::describe(3.0, 'ok', $t));
    }
}
