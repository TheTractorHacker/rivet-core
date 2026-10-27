<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

use RivetCore\Rmm\Checks\CheckCatalog;
use RivetCore\Rmm\Settings\RmmSettings;

/**
 * Phase 3 alert evaluation behind the existing {@see \RivetCore\Rmm\Checks\CheckEvaluator}. It exists only while the `alerting` sub-switch
 * is on; with the switch off the evaluator runs exactly the Phase 0 code and none of the Phase 3 tables is read.
 *
 * Cost rules (CAPACITY.md): a healthy check-in without threshold or flap checks adds NO statement. A check whose definition declares
 * `params.thresholds` or `params.flap` adds one batched read of its rmm_check_eval rows per check-in and a write only when its state
 * changed. Maintenance windows, dependency and storm control are consulted lazily, only when a result is bad or an alert is open.
 *
 * @api
 */
final class AlertingEngine
{
    private ?bool $on = null;

    public function __construct(
        private readonly RmmSettings $settings,
        public readonly CheckEvalStore $eval,
        public readonly ThresholdResolverInterface $resolver,
        public readonly MaintenanceService $maintenance,
        public readonly DependencyService $dependencies,
        public readonly StormControl $storm,
        public readonly AlertService $alerts,
    ) {
    }

    public function enabled(): bool
    {
        return $this->on ??= $this->settings->featureOn('alerting');
    }

    public function forget(): void
    {
        $this->on = null;
    }

    /**
     * The parts of a check list the evaluation reads (thresholds, flap, ignore_parent), by check key. Checks with none of them are left out.
     *
     * @param list<array<string,mixed>> $checks
     * @return array<string,array<string,mixed>>
     */
    public static function definitionsOf(array $checks): array
    {
        $defs = [];
        foreach ($checks as $c) {
            $p = is_array($c['params'] ?? null) ? $c['params'] : [];
            $keep = array_intersect_key($p, array_flip(CheckCatalog::COMMON));
            if ($keep !== []) {
                $defs[(string) $c['key']] = $keep;
            }
        }

        return $defs;
    }

    /**
     * @param array<string,mixed> $dev the device row
     * @param list<array{key:string,status:string,detail:string,at:string,value?:?float}> $results
     * @param array<string,array<string,mixed>>|null $defs {@see definitionsOf()} of the device's own check list (the policy engine's effective list); null = the instance's check list
     */
    public function begin(array $dev, array $results, ?array $defs = null): CheckPipeline
    {
        $defs ??= self::definitionsOf($this->settings->checks());
        $want = [];
        foreach ($results as $r) {
            $p = $defs[$r['key']] ?? null;
            if ($p !== null && (isset($p['thresholds']) || isset($p['flap']))) {
                $want[$r['key']] = $r['key'];
            }
        }

        return new CheckPipeline($this, $dev, $defs, $this->eval->load((int) $dev['device_id'], array_values($want)));
    }
}
