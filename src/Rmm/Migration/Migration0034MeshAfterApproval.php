<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Install the Mesh agent after the device is approved (1.0.0-rc.27): one new table ({@see MeshAfterApprovalSchema}). Additive and idempotent, no row inserted, nothing existing altered.
 *
 * @internal
 */
final class Migration0034MeshAfterApproval implements MigrationInterface
{
    public function id(): string
    {
        return '0034_rmm_mesh_after_approval';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (MeshAfterApprovalSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
