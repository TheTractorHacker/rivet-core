<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * RMM Phase 4 (patch management): the scan result, the available updates and their approval status, the install history, the rings, the patch
 * policies with their assignments, the approval rules and the patch job ledger ({@see PatchSchema}). Purely additive and idempotent: every statement
 * is CREATE TABLE IF NOT EXISTS, and the three default rings (pilot 0 days, broad 7, last 14) are inserted only when no ring exists yet. The tables
 * cost nothing until the `patching` sub-switch is on.
 *
 * @internal
 */
final class Migration0028Patching implements MigrationInterface
{
    public function id(): string
    {
        return '0028_rmm_patching';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (PatchSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
        if ($database->fetchOne('SELECT 1 AS x FROM rmm_patch_rings LIMIT 1') === null) {
            foreach ([['pilot', 0, 10, 'Try updates first, on a few machines'], ['broad', 7, 20, 'Most machines: a week after the pilot'], ['last', 14, 30, 'Sensitive machines: two weeks after the pilot']] as [$n, $d, $o, $t]) {
                $database->execute('INSERT IGNORE INTO rmm_patch_rings (name, delay_days, sort_order, description) VALUES (?, ?, ?, ?)', [$n, $d, $o, $t]);
            }
        }
    }
}
