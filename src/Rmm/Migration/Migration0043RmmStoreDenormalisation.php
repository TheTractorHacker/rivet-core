<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Phase 2 of #89: what a hot store in ANOTHER database needs from the control database, added to MariaDB first so both stores behave the same.
 *
 *  - `client_id` (nullable) on `endpoint_agent_log_events`, `endpoint_agent_change_records` and `endpoint_agent_change_state`: the device's client, written with every
 *    row, so a store can scope a search by client without joining `endpoint_agent_devices`. Backfilled from the device table, device by device, so existing rows keep
 *    their visibility; a row that still has NULL (written by an older release during the upgrade) shows to an unscoped reader and to nobody scoped by client.
 *  - `rmm_metric_rollup_state`: the daily rollup watermark, next to the metric tiers it describes (it used to share `rmm_storage_state` with the warm-start state).
 *    The existing row is copied; {@see \RivetCore\Rmm\Storage\StorageState} keeps writing the old row too, so a rollback to the previous release loses nothing.
 *  - `rmm_pending_client_moves`: a device that changed client while the hot store was unreachable; the housekeeping repeats the move of its history until it works.
 *  - `rmm_hot_store_faults`: what the hot store could not do (writes dropped while a non-MariaDB store was unreachable); empty and unused on MariaDB.
 *
 * Additive and idempotent: nothing existing is altered except the three new nullable columns.
 *
 * @internal
 */
final class Migration0043RmmStoreDenormalisation implements MigrationInterface
{
    public function id(): string
    {
        return '0043_rmm_store_denormalisation';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (['endpoint_agent_log_events', 'endpoint_agent_change_records', 'endpoint_agent_change_state'] as $table) {
            if (!Migration0015EndpointAgentConverge::hasColumn($database, $table, 'client_id')) {
                $database->execute("ALTER TABLE `$table` ADD COLUMN `client_id` int(11) DEFAULT NULL");
            }
        }
        $this->backfill($database);

        $database->execute('CREATE TABLE IF NOT EXISTS `rmm_metric_rollup_state` (
  `name` varchar(64) NOT NULL,
  `watermark` datetime DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $database->execute("INSERT IGNORE INTO `rmm_metric_rollup_state` (`name`, `watermark`, `detail`, `updated_at`)
            SELECT `name`, `watermark`, `detail`, `updated_at` FROM `rmm_storage_state` WHERE `name` = 'metric_daily_rollup'");

        $database->execute('CREATE TABLE IF NOT EXISTS `rmm_pending_client_moves` (
  `device_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

        $database->execute('CREATE TABLE IF NOT EXISTS `rmm_hot_store_faults` (
  `store` varchar(32) NOT NULL,
  `dropped` bigint(20) NOT NULL DEFAULT 0,
  `errors` bigint(20) NOT NULL DEFAULT 0,
  `first_at` datetime NOT NULL,
  `last_at` datetime NOT NULL,
  `last_error` varchar(255) NOT NULL DEFAULT \'\',
  PRIMARY KEY (`store`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
    }

    /** Give every existing row the client its device has now: one short statement per device and table, each bounded by the per-device row cap. */
    private function backfill(DatabaseInterface $database): void
    {
        $database->execute('UPDATE `endpoint_agent_change_state` s JOIN `endpoint_agent_devices` d ON d.`device_id` = s.`device_id` SET s.`client_id` = d.`client_id` WHERE s.`client_id` IS NULL');
        foreach ($database->fetchAll('SELECT DISTINCT `device_id` FROM `endpoint_agent_log_events` WHERE `client_id` IS NULL') as $r) {
            $database->execute('UPDATE `endpoint_agent_log_events` e JOIN `endpoint_agent_devices` d ON d.`device_id` = e.`device_id` SET e.`client_id` = d.`client_id` WHERE e.`device_id` = ? AND e.`client_id` IS NULL', [(int) $r['device_id']]);
        }
        foreach ($database->fetchAll('SELECT DISTINCT `device_id` FROM `endpoint_agent_change_records` WHERE `client_id` IS NULL') as $r) {
            $database->execute('UPDATE `endpoint_agent_change_records` e JOIN `endpoint_agent_devices` d ON d.`device_id` = e.`device_id` SET e.`client_id` = d.`client_id` WHERE e.`device_id` = ? AND e.`client_id` IS NULL', [(int) $r['device_id']]);
        }
    }
}
