<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * RMM change monitoring: the settings row, the change records, the per-device counters and the alert rules ({@see ChangeSchema}). Purely additive and idempotent: every
 * statement is CREATE TABLE IF NOT EXISTS and no row is inserted. The tables cost nothing until the `change_monitor` sub-switch is on.
 *
 * @internal
 */
final class Migration0030ChangeMonitor implements MigrationInterface
{
    public function id(): string
    {
        return '0030_rmm_change_monitor';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (ChangeSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
