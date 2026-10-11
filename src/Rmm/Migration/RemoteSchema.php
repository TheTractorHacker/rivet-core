<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The tables of RMM Phase 6 (remote tools: file transfers) and Phase 8 (event-log and syslog collection), created by migration 0022. Every
 * statement is CREATE TABLE IF NOT EXISTS with an explicit engine, charset and collation; nothing existing is altered. Do not edit a released
 * statement, add a new migration instead. Row-size note: the widest row is a log event (a 1024-character message, 4096 bytes in utf8mb4), far
 * below the limit; no key is on a text column.
 *
 * @internal
 */
final class RemoteSchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement (no trailing semicolon) */
    public static function tables(): array
    {
        return [
            'endpoint_agent_remote_settings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_remote_settings` (
  `id` tinyint(4) NOT NULL DEFAULT 1,
  `transfer_allow_json` text DEFAULT NULL,
  `logs_collect_json` text DEFAULT NULL,
  `limits_json` text DEFAULT NULL,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_transfers' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_transfers` (
  `transfer_id` char(36) NOT NULL,
  `device_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `asset_id` int(11) DEFAULT NULL,
  `direction` varchar(8) NOT NULL,
  `remote_path` varchar(1024) NOT NULL,
  `file_name` varchar(120) NOT NULL DEFAULT '',
  `state` varchar(20) NOT NULL,
  `size_bytes` bigint(20) NOT NULL DEFAULT 0,
  `received_bytes` bigint(20) NOT NULL DEFAULT 0,
  `served_bytes` bigint(20) NOT NULL DEFAULT 0,
  `max_bytes` bigint(20) NOT NULL DEFAULT 0,
  `sha256` char(64) DEFAULT NULL,
  `token_hash` char(64) NOT NULL,
  `storage_name` varchar(60) NOT NULL,
  `overwrite` tinyint(1) NOT NULL DEFAULT 0,
  `job_id` char(36) DEFAULT NULL,
  `error` varchar(200) NOT NULL DEFAULT '',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`transfer_id`),
  KEY `idx_device_created` (`device_id`,`created_at`),
  KEY `idx_state_expires` (`state`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_log_events' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_log_events` (
  `event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `occurred_at` datetime NOT NULL,
  `received_at` datetime NOT NULL,
  `level` tinyint(4) NOT NULL,
  `source` varchar(100) NOT NULL DEFAULT '',
  `code` int(11) DEFAULT NULL,
  `message` varchar(1024) NOT NULL DEFAULT '',
  `dedupe` binary(16) NOT NULL,
  PRIMARY KEY (`event_id`),
  UNIQUE KEY `uq_device_dedupe` (`device_id`,`dedupe`),
  KEY `idx_device_time` (`device_id`,`occurred_at`),
  KEY `idx_occurred` (`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_log_state' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_log_state` (
  `device_id` int(11) NOT NULL,
  `row_count` int(11) NOT NULL DEFAULT 0,
  `dropped_total` bigint(20) NOT NULL DEFAULT 0,
  `last_event_at` datetime DEFAULT NULL,
  `last_batch_at` datetime DEFAULT NULL,
  `window_start` datetime DEFAULT NULL,
  `window_count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_log_rules' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_log_rules` (
  `rule_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `min_level` tinyint(4) NOT NULL DEFAULT 2,
  `source_contains` varchar(100) NOT NULL DEFAULT '',
  `code` int(11) DEFAULT NULL,
  `contains` varchar(200) NOT NULL DEFAULT '',
  `threshold` int(11) NOT NULL DEFAULT 1,
  `window_min` int(11) NOT NULL DEFAULT 5,
  `severity` varchar(4) NOT NULL DEFAULT 'warn',
  `resolve_after_min` int(11) NOT NULL DEFAULT 30,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`rule_id`),
  KEY `idx_enabled_client` (`enabled`,`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
}
