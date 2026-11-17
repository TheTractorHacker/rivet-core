<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Repair for a database that applied an interim head of the deep-scan fixes (CRR-12). Migrations 0053 and 0054 first added `mesh_token_ttl_exact`, `signed_config` and
 * `release_rollover_json` as columns of `endpoint_agent_settings`; they were then reworked to keep them in `rmm_hardening_settings` under the same ids, so a database that had
 * already recorded both ids never got that table. This migration creates it, copies the interim values over and removes the interim columns (the ten original
 * `endpoint_agent_*` tables are frozen). On every other database it only makes sure the table and its row exist. Idempotent.
 *
 * @internal
 */
final class Migration0055HardeningRepair implements MigrationInterface
{
    private const INTERIM = ['mesh_token_ttl_exact' => '0', 'signed_config' => '0', 'release_rollover_json' => 'NULL'];

    public function id(): string
    {
        return '0055_rmm_hardening_repair';
    }

    public function up(DatabaseInterface $database): void
    {
        (new Migration0053MeshTokenExact())->up($database);
        (new Migration0054SignedContracts())->up($database);
        $have = [];
        foreach (array_keys(self::INTERIM) as $col) {
            if (Migration0015EndpointAgentConverge::hasColumn($database, 'endpoint_agent_settings', $col)) {
                $have[] = $col;
            }
        }
        if ($have === []) {
            return;
        }
        $database->execute('UPDATE `rmm_hardening_settings` h JOIN `endpoint_agent_settings` s ON s.id = 1 SET '
            . implode(', ', array_map(static fn (string $c): string => "h.`$c` = s.`$c`", $have)) . ' WHERE h.id = 1');
        foreach ($have as $col) {
            $database->execute("ALTER TABLE `endpoint_agent_settings` DROP COLUMN `$col`");
        }
    }
}
