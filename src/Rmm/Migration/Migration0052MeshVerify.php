<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Verified Mesh node mapping (1.0.0-rc.67): two nullable text columns. `endpoint_agent_mesh_settings.verify_json` holds the administrator's settings
 * (`mesh_verify_nodes`, `mesh_service_account`, the client id to Mesh group id map) and `endpoint_agent_mesh_agents.verify_json` the evidence of the last
 * verification of one device. Additive and idempotent (each column is added only when missing); without it the verification stays off and every other path is
 * unchanged. Numbered 0052 because 0050 and 0051 are claimed by other open changes.
 *
 * @internal
 */
final class Migration0052MeshVerify implements MigrationInterface
{
    public function id(): string
    {
        return '0052_rmm_mesh_verify';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (MeshAgentSchema::tables() as $name => $ddl) {
            if ($name === 'endpoint_agent_mesh_settings' || $name === 'endpoint_agent_mesh_agents') {
                $database->execute($ddl);
            }
        }
        foreach (['endpoint_agent_mesh_settings', 'endpoint_agent_mesh_agents'] as $table) {
            if (!Migration0015EndpointAgentConverge::hasColumn($database, $table, 'verify_json')) {
                $database->execute("ALTER TABLE `$table` ADD COLUMN `verify_json` text DEFAULT NULL");
            }
        }
    }
}
