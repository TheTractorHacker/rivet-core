<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Install apps when the agent installs (1.0.0-rc.26): eight new tables ({@see AutoInstallSchema}). Purely additive and idempotent (CREATE TABLE IF NOT EXISTS), no row
 * inserted, nothing existing altered. They stay empty and cost nothing until the `software_deploy` switch is on and a package is marked for auto-install.
 *
 * @internal
 */
final class Migration0033AutoInstall implements MigrationInterface
{
    public function id(): string
    {
        return '0033_rmm_auto_install';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (AutoInstallSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
