<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * CORE-DS-39: `endpoint_agent_settings.mesh_token_ttl_exact`, one flag, default 0. The column default of `mesh_token_ttl_s` (300 s) has always meant "keep the original ~58 minute
 * MeshCentral window" so that upgrading changed nothing; an administrator who really wants a 5 minute window could not ask for it. With the flag set the configured lifetime is
 * honoured exactly, 300 included. Every existing install keeps its behaviour until someone ticks the flag. Additive and idempotent.
 *
 * @internal
 */
final class Migration0053MeshTokenExact implements MigrationInterface
{
    public function id(): string
    {
        return '0053_rmm_mesh_token_exact';
    }

    public function up(DatabaseInterface $database): void
    {
        if (!Migration0015EndpointAgentConverge::hasColumn($database, 'endpoint_agent_settings', 'mesh_token_ttl_exact')) {
            $database->execute('ALTER TABLE `endpoint_agent_settings` ADD COLUMN `mesh_token_ttl_exact` tinyint(1) NOT NULL DEFAULT 0');
        }
    }
}
