<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * The optional MeshCentral agent install and automatic node-id mapping: the Mesh install settings row, the per-device Mesh agent state and the
 * per-token installer options. Purely additive and idempotent: every statement is CREATE TABLE IF NOT EXISTS and nothing existing is altered.
 * The tables stay empty (and cost nothing) until an administrator saves the Mesh install settings or a device reports a Mesh agent.
 *
 * @internal
 */
final class Migration0023MeshAgent implements MigrationInterface
{
    public function id(): string
    {
        return '0023_rmm_mesh_agent';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (MeshAgentSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
