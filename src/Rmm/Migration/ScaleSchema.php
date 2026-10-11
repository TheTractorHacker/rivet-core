<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The tables of the RMM scale work (S4, S5, S8), created by migration 0021. Every statement is CREATE TABLE IF NOT EXISTS with an explicit engine,
 * charset and collation; nothing existing is altered (converting an existing table to time partitions is a separate, explicit, probed step, see
 * {@see \RivetCore\Rmm\Storage\PartitionMaintenance}). Do not edit a released statement, add a new migration instead.
 *
 * @internal
 */
final class ScaleSchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement (no trailing semicolon) */
    public static function tables(): array
    {
        return [
            // Daily tier of the database metric sink: built from rmm_metric_hourly by the rollup job, read for ranges beyond a few days.
            'rmm_metric_daily' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_metric_daily` (
  `asset_id` int(11) NOT NULL,
  `metric_key` varchar(64) NOT NULL,
  `instance` varchar(64) NOT NULL DEFAULT '',
  `day_start` datetime NOT NULL,
  `samples` int(10) unsigned NOT NULL DEFAULT 0,
  `sum_value` double NOT NULL DEFAULT 0,
  `min_value` double NOT NULL,
  `max_value` double NOT NULL,
  PRIMARY KEY (`asset_id`,`metric_key`,`instance`,`day_start`),
  KEY `idx_day` (`day_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            // Small named watermarks and notes of the storage jobs (rollup watermark, last partition maintenance result).
            'rmm_storage_state' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_storage_state` (
  `name` varchar(64) NOT NULL,
  `watermark` datetime DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            // Read-path cache with stale-while-revalidate (Support\RmmCache) for installs without a shared cache.
            'rmm_cache' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_cache` (
  `cache_key` varchar(100) NOT NULL,
  `cache_value` mediumtext NOT NULL,
  `fresh_until` datetime NOT NULL,
  `stale_until` datetime NOT NULL,
  `refresh_until` datetime DEFAULT NULL,
  PRIMARY KEY (`cache_key`),
  KEY `idx_stale` (`stale_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            // Bounded, durable buffer in front of an external metric store (Metrics\ExternalMetricSink): one row per delivered batch.
            'rmm_metric_outbox' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_metric_outbox` (
  `outbox_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `points` int(11) NOT NULL DEFAULT 0,
  `payload` mediumtext NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `next_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `lease_until` datetime DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`outbox_id`),
  KEY `idx_due` (`next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            // Token buckets per client for enrollment and check-in bursts (Capacity\TokenBucket, database store).
            'rmm_token_buckets' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_token_buckets` (
  `bucket_key` varchar(80) NOT NULL,
  `tokens` double NOT NULL DEFAULT 0,
  `refilled_ms` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`bucket_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
}
