<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Operating system of a hosted agent binary and of its release (1.0.0-rc.30): `endpoint_agent_binaries.os` and `endpoint_agent_releases.os`, 'windows' or 'linux'.
 * Until now both tables keyed only on version and architecture, so a Linux device could be offered (and could download) the Windows executable of the same version.
 * Every row that exists is a Windows binary (only Windows executables were ever stored here), which is exactly the column default, so nothing is backfilled.
 * The unique keys gain the OS (`uniq_version_arch_os`, `uniq_version_ring_arch_os`); the new key is added before the old one is dropped. Every step is guarded and
 * idempotent. Code that runs before this migration falls back to Windows-only behaviour ({@see \RivetCore\Rmm\Support\AgentOs::supported()}).
 *
 * @internal
 */
final class Migration0037AgentOs implements MigrationInterface
{
    public function id(): string
    {
        return '0037_rmm_agent_os';
    }

    public function up(DatabaseInterface $database): void
    {
        $this->table($database, 'endpoint_agent_binaries', 'uniq_version_arch', 'uniq_version_arch_os', '(`version`,`arch`,`os`)');
        $this->table($database, 'endpoint_agent_releases', 'uniq_version_ring_arch', 'uniq_version_ring_arch_os', '(`version`,`ring`,`arch`,`os`)');
    }

    private function table(DatabaseInterface $database, string $table, string $oldKey, string $newKey, string $cols): void
    {
        if (!Migration0015EndpointAgentConverge::hasColumn($database, $table, 'os')) {
            $database->execute("ALTER TABLE `$table` ADD COLUMN `os` varchar(10) NOT NULL DEFAULT 'windows'");
        }
        if (!Migration0015EndpointAgentConverge::hasIndex($database, $table, $newKey)) {
            $database->execute("ALTER TABLE `$table` ADD UNIQUE KEY `$newKey` $cols");
        }
        if (Migration0015EndpointAgentConverge::hasIndex($database, $table, $oldKey)) {
            $database->execute("ALTER TABLE `$table` DROP INDEX `$oldKey`");
        }
    }
}
