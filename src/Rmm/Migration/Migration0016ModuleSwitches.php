<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Module switch and capacity columns on endpoint_agent_settings. Defaults reproduce today's behaviour: NULL features_json and
 * limits_json mean "legacy defaults", shed_level 0 is no shedding, ingest_mode 'sync' is inline ingestion and max_devices 0 is
 * unlimited. The existing `enabled` column is the master switch and is never touched here.
 *
 * @internal
 */
final class Migration0016ModuleSwitches implements MigrationInterface
{
    /** @var array<string,string> column => definition, in the order they are added */
    private const COLUMNS = [
        'features_json' => 'text DEFAULT NULL',
        'limits_json' => 'text DEFAULT NULL',
        'shed_level' => 'tinyint(1) NOT NULL DEFAULT 0',
        'ingest_mode' => "varchar(10) NOT NULL DEFAULT 'sync'",
        'max_devices' => 'int(11) NOT NULL DEFAULT 0',
    ];

    public function id(): string
    {
        return '0016_rmm_module_switches';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (self::COLUMNS as $column => $definition) {
            if (!Migration0015EndpointAgentConverge::hasColumn($database, 'endpoint_agent_settings', $column)) {
                $database->execute("ALTER TABLE `endpoint_agent_settings` ADD COLUMN `$column` $definition");
            }
        }
    }
}
