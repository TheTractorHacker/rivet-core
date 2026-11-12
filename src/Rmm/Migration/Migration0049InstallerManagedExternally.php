<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * "Managed by another RMM" on an installer or enrollment token (1.0.0-rc.59): `endpoint_agent_installer_options.managed_externally` (kept server-side by token,
 * like the device type; never inside the installer file). A device that enrolls with such a token is created with `endpoint_agent_devices.device_automation_disabled = 1`
 * (migration 0039), so Core's automation never fires on a machine another tool already manages. One additive column, guarded and idempotent; existing tokens read 0 and
 * behave exactly as before.
 *
 * @internal
 */
final class Migration0049InstallerManagedExternally implements MigrationInterface
{
    public function id(): string
    {
        return '0049_rmm_installer_managed_externally';
    }

    public function up(DatabaseInterface $database): void
    {
        // The options table is created by migration 0023; make sure it exists when this one runs first on an odd edition.
        foreach (MeshAgentSchema::tables() as $name => $ddl) {
            if ($name === 'endpoint_agent_installer_options') {
                $database->execute($ddl);
            }
        }
        if (!Migration0015EndpointAgentConverge::hasColumn($database, 'endpoint_agent_installer_options', 'managed_externally')) {
            $database->execute('ALTER TABLE `endpoint_agent_installer_options` ADD COLUMN `managed_externally` tinyint(1) NOT NULL DEFAULT 0');
        }
    }
}
