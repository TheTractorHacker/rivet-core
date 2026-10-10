<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * RMM Phase 3: alerting maturity. Threshold and flap state per check (with the per-device threshold override), the alert lifecycle mirror (state,
 * acknowledgement, escalation schedule), storm-control summaries, maintenance windows, device parent links, escalation policies and
 * their steps, and the single alerting settings row. Purely additive: every statement is CREATE TABLE IF NOT EXISTS and nothing existing
 * is altered, so a second run, or an install that already has some of the tables, changes nothing. The tables stay empty (and cost
 * nothing) until the `alerting` sub-switch is turned on.
 *
 * @internal
 */
final class Migration0020AlertingMaturity implements MigrationInterface
{
    public function id(): string
    {
        return '0020_rmm_alerting_maturity';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (AlertingSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
