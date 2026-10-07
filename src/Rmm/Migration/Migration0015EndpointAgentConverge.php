<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Converges installs that stopped at RivetIT DB 2.6.145 (the RivetIT 2.6.146 deltas): endpoint_agent_settings.ca_pem,
 * endpoint_agent_releases.arch and .binary_id, and the release key (version, ring) becoming (version, ring, arch). Every step is
 * guarded through information_schema (MySQL has no ADD COLUMN IF NOT EXISTS), so it is a no-op on a current schema.
 *
 * @internal
 */
final class Migration0015EndpointAgentConverge implements MigrationInterface
{
    public function id(): string
    {
        return '0015_endpoint_agent_converge';
    }

    public function up(DatabaseInterface $database): void
    {
        if (!self::hasColumn($database, 'endpoint_agent_settings', 'ca_pem')) {
            $database->execute('ALTER TABLE `endpoint_agent_settings` ADD COLUMN `ca_pem` text DEFAULT NULL');
        }
        if (!self::hasColumn($database, 'endpoint_agent_releases', 'arch')) {
            $database->execute("ALTER TABLE `endpoint_agent_releases` ADD COLUMN `arch` varchar(10) NOT NULL DEFAULT ''");
        }
        if (!self::hasColumn($database, 'endpoint_agent_releases', 'binary_id')) {
            $database->execute('ALTER TABLE `endpoint_agent_releases` ADD COLUMN `binary_id` int(11) DEFAULT NULL');
        }
        if (self::hasIndex($database, 'endpoint_agent_releases', 'uniq_version_ring')) {
            $database->execute('ALTER TABLE `endpoint_agent_releases` DROP INDEX `uniq_version_ring`');
        }
        if (!self::hasIndex($database, 'endpoint_agent_releases', 'uniq_version_ring_arch')) {
            $database->execute('ALTER TABLE `endpoint_agent_releases` ADD UNIQUE KEY `uniq_version_ring_arch` (`version`,`ring`,`arch`)');
        }
    }

    public static function hasColumn(DatabaseInterface $database, string $table, string $column): bool
    {
        $row = $database->fetchOne(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        return (int) ($row['c'] ?? 0) > 0;
    }

    public static function hasIndex(DatabaseInterface $database, string $table, string $index): bool
    {
        $row = $database->fetchOne(
            'SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        );

        return (int) ($row['c'] ?? 0) > 0;
    }
}
