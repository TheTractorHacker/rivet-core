<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * The per-device daily budget ledger (1.0.0-rc.38, security review RC36 M1 and L3): one new table ({@see BudgetSchema}). Additive and idempotent, no row inserted,
 * nothing existing altered, so it is safe to run on a live install and the RMM device protocol is untouched.
 *
 * @internal
 */
final class Migration0041DailyBudget implements MigrationInterface
{
    public function id(): string
    {
        return '0041_daily_budget';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (BudgetSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
