<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

/**
 * The periodic Phase 3 work, called by {@see \RivetCore\Rmm\Maintenance\Housekeeping::run()} while the `alerting` sub-switch is on: announce
 * maintenance windows that opened or closed, adopt alerts that were open before the switch was turned on, fire the escalation notices
 * that are due, resolve storm summaries whose storm is over, and prune old records. Bounded per run (escalation 200 notices, pruning 5000 rows).
 *
 * @api
 */
final class AlertingHousekeeping
{
    public const EVAL_PRUNE_DAYS = 30;

    public function __construct(
        private readonly MaintenanceService $maintenance,
        private readonly EscalationService $escalation,
        private readonly AlertService $alerts,
        private readonly StormControl $storm,
        private readonly CheckEvalStore $eval,
    ) {
    }

    /** @return array<string,int> */
    public function run(): array
    {
        $m = $this->maintenance->tick();
        $adopted = $this->alerts->adoptOpen();
        $e = $this->escalation->tick();

        return [
            'maintenance_started' => $m['started'], 'maintenance_ended' => $m['ended'], 'maintenance_pruned' => $m['pruned'],
            'alerts_adopted' => $adopted, 'escalations_due' => $e['due'], 'escalations_sent' => $e['sent'], 'escalations_failed' => $e['failed'], 'escalations_held' => $e['held'],
            'storm_resolved' => $this->storm->resolveQuiet(), 'alerts_pruned' => $this->alerts->prune(), 'eval_pruned' => $this->eval->prune(self::EVAL_PRUNE_DAYS),
        ];
    }
}
