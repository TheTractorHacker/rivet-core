<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * The Mesh device group id in the form MeshCentral prints it: one new column on `endpoint_agent_mesh_settings` (`group_id_url`, the 64-character URL
 * form of the group id, kept verbatim when the administrator pasted it; empty when only the hex is known, in which case the URL form is derived).
 * Additive and idempotent: the column is added only when missing (MySQL has no ADD COLUMN IF NOT EXISTS), nothing is changed in an existing row, and the
 * canonical hex in `group_id` is untouched.
 *
 * @internal
 */
final class Migration0024MeshGroupUrl implements MigrationInterface
{
    public function id(): string
    {
        return '0024_rmm_mesh_group_url';
    }

    public function up(DatabaseInterface $database): void
    {
        // Migration 0023 creates the table; a database that is somehow without it (hand-edited) gets it first, so this migration never fails on order.
        foreach (MeshAgentSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
        if (!Migration0015EndpointAgentConverge::hasColumn($database, 'endpoint_agent_mesh_settings', 'group_id_url')) {
            $database->execute("ALTER TABLE `endpoint_agent_mesh_settings` ADD COLUMN `group_id_url` varchar(64) NOT NULL DEFAULT '' AFTER `group_id`");
        }
    }
}
