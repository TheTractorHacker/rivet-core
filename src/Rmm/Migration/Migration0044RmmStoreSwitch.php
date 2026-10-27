<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Phase 3 of #89: what switching the RMM data store needs in the control database. One new nullable column and two new tables; additive and idempotent.
 *
 *  - `endpoint_agent_settings.data_store_phase`: NULL normally; `copy:<store>` while a migration copies history to <store> (retention is paused), `dual:<store>` while
 *    new writes go to both stores. The module reads it with the settings row it reads anyway, so no extra query is needed to know a migration is running.
 *  - `rmm_data_store_connection`: the one-row PostgreSQL connection of the data store setting (host, port, database, role, sslmode, schema and the password SEALED
 *    with the edition's SecretBox). Kept apart from `endpoint_agent_settings` on purpose: that row is read whole (`SELECT *`) and handed to the edition's pages,
 *    and a credential must never travel with it.
 *  - `rmm_store_migration`: one row per migration run between the stores (state, direction, per-table checkpoints, the dual-write flag, the cut-over and
 *    rollback deadline, the summary). The newest row is the current one; older rows are the history.
 *
 * @internal
 */
final class Migration0044RmmStoreSwitch implements MigrationInterface
{
    public function id(): string
    {
        return '0044_rmm_store_switch';
    }

    public function up(DatabaseInterface $database): void
    {
        if (!Migration0015EndpointAgentConverge::hasColumn($database, 'endpoint_agent_settings', 'data_store_phase')) {
            $database->execute('ALTER TABLE `endpoint_agent_settings` ADD COLUMN `data_store_phase` varchar(32) DEFAULT NULL');
        }

        $database->execute('CREATE TABLE IF NOT EXISTS `rmm_data_store_connection` (
  `id` tinyint(4) NOT NULL,
  `host` varchar(255) NOT NULL DEFAULT \'127.0.0.1\',
  `port` int(11) NOT NULL DEFAULT 5432,
  `db_name` varchar(63) NOT NULL DEFAULT \'\',
  `db_user` varchar(63) NOT NULL DEFAULT \'\',
  `password_sealed` text DEFAULT NULL,
  `sslmode` varchar(16) NOT NULL DEFAULT \'prefer\',
  `sslrootcert` varchar(255) DEFAULT NULL,
  `schema_name` varchar(63) NOT NULL DEFAULT \'public\',
  `storage_mode` varchar(16) DEFAULT NULL,
  `server_version` varchar(64) DEFAULT NULL,
  `tested_at` datetime DEFAULT NULL,
  `test_ok` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

        $database->execute('CREATE TABLE IF NOT EXISTS `rmm_store_migration` (
  `run_id` int(11) NOT NULL AUTO_INCREMENT,
  `from_store` varchar(16) NOT NULL,
  `to_store` varchar(16) NOT NULL,
  `mode` varchar(8) NOT NULL DEFAULT \'full\',
  `state` varchar(24) NOT NULL,
  `dual_write` tinyint(1) NOT NULL DEFAULT 0,
  `dual_faults` int(11) NOT NULL DEFAULT 0,
  `started_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `cutover_at` datetime DEFAULT NULL,
  `rollback_until` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `purged_at` datetime DEFAULT NULL,
  `actor` varchar(100) NOT NULL DEFAULT \'\',
  `params` text DEFAULT NULL,
  `checkpoints` mediumtext DEFAULT NULL,
  `summary` mediumtext DEFAULT NULL,
  `last_error` varchar(500) NOT NULL DEFAULT \'\',
  PRIMARY KEY (`run_id`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
    }
}
