<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The tables of RMM change monitoring (registry, configuration files, local groups, scheduled tasks), created by migration 0030. Every statement is CREATE TABLE IF NOT EXISTS
 * with an explicit engine, charset and collation; nothing existing is altered and nothing is inserted. The tables cost nothing until the `change_monitor` sub-switch is on.
 *
 * Row-size note: the widest row is a change record. Its declared columns add up to about 8.3 KB in utf8mb4 (target 300, item 200, old and new 512 each, summary 200, actor 120,
 * process 200, watch 40 characters), far below the InnoDB limit; no index is on a text column. A real row is mostly much smaller: see docs/rmm/CHANGE_MONITOR.md, "Scale".
 *
 * @internal
 */
final class ChangeSchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement (no trailing semicolon) */
    public static function tables(): array
    {
        return [
            'endpoint_agent_change_settings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_change_settings` (
  `id` tinyint(4) NOT NULL DEFAULT 1,
  `config_json` text DEFAULT NULL,
  `limits_json` text DEFAULT NULL,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_change_records' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_change_records` (
  `change_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `occurred_at` datetime NOT NULL,
  `received_at` datetime NOT NULL,
  `kind` varchar(12) NOT NULL,
  `watch` varchar(48) NOT NULL,
  `change_type` tinyint(4) NOT NULL,
  `target` varchar(300) NOT NULL DEFAULT '',
  `item` varchar(200) NOT NULL DEFAULT '',
  `old_value` varchar(512) NOT NULL DEFAULT '',
  `new_value` varchar(512) NOT NULL DEFAULT '',
  `summary` varchar(200) NOT NULL DEFAULT '',
  `old_hash` char(32) NOT NULL DEFAULT '',
  `new_hash` char(32) NOT NULL DEFAULT '',
  `actor` varchar(120) NOT NULL DEFAULT '',
  `process` varchar(200) NOT NULL DEFAULT '',
  `flags` tinyint(4) NOT NULL DEFAULT 0,
  `dedupe` binary(16) NOT NULL,
  PRIMARY KEY (`change_id`),
  UNIQUE KEY `uq_device_dedupe` (`device_id`,`dedupe`),
  KEY `idx_device_time` (`device_id`,`occurred_at`),
  KEY `idx_occurred` (`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_change_state' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_change_state` (
  `device_id` int(11) NOT NULL,
  `row_count` int(11) NOT NULL DEFAULT 0,
  `dropped_total` bigint(20) NOT NULL DEFAULT 0,
  `last_change_at` datetime DEFAULT NULL,
  `last_batch_at` datetime DEFAULT NULL,
  `window_start` datetime DEFAULT NULL,
  `window_count` int(11) NOT NULL DEFAULT 0,
  `config_version` char(16) NOT NULL DEFAULT '',
  `monitor_json` text DEFAULT NULL,
  `monitor_at` datetime DEFAULT NULL,
  PRIMARY KEY (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_change_rules' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_change_rules` (
  `rule_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `watch` varchar(48) NOT NULL DEFAULT '',
  `kind` varchar(12) NOT NULL DEFAULT '',
  `target_contains` varchar(200) NOT NULL DEFAULT '',
  `item_contains` varchar(200) NOT NULL DEFAULT '',
  `change_type` tinyint(4) NOT NULL DEFAULT 0,
  `threshold` int(11) NOT NULL DEFAULT 1,
  `window_min` int(11) NOT NULL DEFAULT 5,
  `severity` varchar(4) NOT NULL DEFAULT 'warn',
  `resolve_after_min` int(11) NOT NULL DEFAULT 60,
  `recommended` varchar(40) NOT NULL DEFAULT '',
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
