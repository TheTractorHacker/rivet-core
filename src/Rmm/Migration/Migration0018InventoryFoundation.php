<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * RMM Phase 1: per-device state (capabilities, presence, software hash), the software inventory with its change history, tags,
 * static groups, the per-check history ring and the two tables of the database metric sink. Purely additive: every statement is
 * CREATE TABLE IF NOT EXISTS and nothing existing is altered, so a second run, or an install that already has some of the
 * tables, changes nothing. The tables stay empty (and cost nothing) until the matching feature is switched on.
 *
 * @internal
 */
final class Migration0018InventoryFoundation implements MigrationInterface
{
    public function id(): string
    {
        return '0018_rmm_inventory_foundation';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (RmmSchema::phase1Tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
