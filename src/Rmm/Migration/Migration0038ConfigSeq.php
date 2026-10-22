<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * The anti-replay counter of the signed agent configurations (1.0.0-rc.31): one row per configuration (`change:windows`, `change:linux`, `hypervisor:windows`, ...) holding the highest
 * `seq` the server has offered and a hash of the configuration body that seq belongs to. Until now `seq` was the `updated_at` time of the settings row, so a database restore or a
 * clock step backwards made every agent that had accepted a higher `seq` refuse all new configurations. See {@see \RivetCore\Rmm\Support\ConfigSequence}.
 *
 * Purely additive and idempotent (CREATE TABLE IF NOT EXISTS, nothing inserted). Code that runs before this migration offers the old `updated_at`-based seq.
 *
 * @internal
 */
final class Migration0038ConfigSeq implements MigrationInterface
{
    public function id(): string
    {
        return '0038_rmm_config_seq';
    }

    public function up(DatabaseInterface $database): void
    {
        $database->execute(self::table());
    }

    /** @internal */
    public static function table(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_config_seq` (
  `name` varchar(40) NOT NULL,
  `seq` bigint(20) unsigned NOT NULL,
  `body_hash` char(64) NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL;
    }
}
