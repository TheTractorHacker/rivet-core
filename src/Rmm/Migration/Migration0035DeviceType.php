<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Device type for new assets (1.0.0-rc.29): `endpoint_agent_installer_options.device_type` (what the technician chose when the installer was issued, 'auto' by
 * default; kept server-side by token, never inside the installer file) and `endpoint_agent_devices.detected_type` (what {@see \RivetCore\Rmm\Assets\DeviceTypeClassifier}
 * made of the agent's hardware facts at enrollment and on each inventory). Two additive columns, guarded and idempotent, nothing is backfilled: existing
 * rows read 'auto' and NULL, which behave exactly as before.
 *
 * @internal
 */
final class Migration0035DeviceType implements MigrationInterface
{
    private const COLUMNS = [
        'endpoint_agent_installer_options' => ['device_type' => "varchar(20) NOT NULL DEFAULT 'auto'"],
        'endpoint_agent_devices' => ['detected_type' => 'varchar(20) DEFAULT NULL'],
    ];

    public function id(): string
    {
        return '0035_rmm_device_type';
    }

    public function up(DatabaseInterface $database): void
    {
        // The options table is created by migration 0023; an edition that ran 0035 first would not have it, so make sure it exists.
        foreach (MeshAgentSchema::tables() as $name => $ddl) {
            if ($name === 'endpoint_agent_installer_options') {
                $database->execute($ddl);
            }
        }
        foreach (self::COLUMNS as $table => $cols) {
            foreach ($cols as $name => $def) {
                if (!Migration0015EndpointAgentConverge::hasColumn($database, $table, $name)) {
                    $database->execute("ALTER TABLE `$table` ADD COLUMN `$name` $def");
                }
            }
        }
    }
}
