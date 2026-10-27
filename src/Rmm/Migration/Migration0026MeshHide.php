<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * The MeshCentral view parameters of a remote launch: two new columns on `endpoint_agent_mesh_settings`. `mesh_hide` (tinyint unsigned, default 31) is
 * MeshCentral's `hide` bitmask for the desktop launch; `mesh_hide_tools` (nullable; NULL = the same as `mesh_hide`) is the one for terminal and files.
 * Additive and idempotent: each column is added only when missing, no existing row is rewritten (the new default applies to the existing row, which is the
 * intended behaviour: the Remote button opens the remote screen without the MeshCentral chrome; set 0 to get the old URL back).
 *
 * @internal
 */
final class Migration0026MeshHide implements MigrationInterface
{
    public function id(): string
    {
        return '0026_rmm_mesh_hide';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (MeshAgentSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
        if (!Migration0015EndpointAgentConverge::hasColumn($database, 'endpoint_agent_mesh_settings', 'mesh_hide')) {
            $database->execute("ALTER TABLE `endpoint_agent_mesh_settings` ADD COLUMN `mesh_hide` tinyint(3) unsigned NOT NULL DEFAULT 31 AFTER `auto_map_overwrite`");
        }
        if (!Migration0015EndpointAgentConverge::hasColumn($database, 'endpoint_agent_mesh_settings', 'mesh_hide_tools')) {
            $database->execute("ALTER TABLE `endpoint_agent_mesh_settings` ADD COLUMN `mesh_hide_tools` tinyint(3) unsigned DEFAULT NULL AFTER `mesh_hide`");
        }
    }
}
