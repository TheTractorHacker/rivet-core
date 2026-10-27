<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The tables of RMM Phase 3 (alerting maturity), created by migration 0020. Every statement is CREATE TABLE IF NOT EXISTS with an
 * explicit engine, charset and collation; nothing existing is altered. Do not edit a released statement, add a new migration instead.
 * Row-size note: no table here has more than one TEXT column and every key is on small columns (utf8mb4 key length stays under 3072 bytes).
 *
 * @internal
 */
final class AlertingSchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement (no trailing semicolon) */
    public static function tables(): array
    {
        return [
            'rmm_alerting_settings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_alerting_settings` (
  `id` tinyint(4) NOT NULL DEFAULT 1,
  `storm_global_max` int(11) NOT NULL DEFAULT 500,
  `storm_global_window_s` int(11) NOT NULL DEFAULT 600,
  `storm_client_max` int(11) NOT NULL DEFAULT 100,
  `storm_client_window_s` int(11) NOT NULL DEFAULT 600,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_check_eval' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_check_eval` (
  `device_id` int(11) NOT NULL,
  `check_key` varchar(100) NOT NULL,
  `tier` varchar(4) NOT NULL DEFAULT 'ok',
  `cand_tier` varchar(4) NOT NULL DEFAULT 'ok',
  `cand_since` datetime DEFAULT NULL,
  `cand_count` int(11) NOT NULL DEFAULT 0,
  `last_reading` double DEFAULT NULL,
  `flap_bits` bigint(20) NOT NULL DEFAULT 0,
  `flap_n` int(11) NOT NULL DEFAULT 0,
  `flapping` tinyint(1) NOT NULL DEFAULT 0,
  `override_json` text DEFAULT NULL,
  `override_by` int(11) NOT NULL DEFAULT 0,
  `override_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`device_id`,`check_key`),
  KEY `idx_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_alert_meta' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_alert_meta` (
  `meta_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `alert_id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `check_key` varchar(100) NOT NULL,
  `episode` int(11) NOT NULL,
  `group_key` varchar(120) NOT NULL DEFAULT '',
  `severity` varchar(4) NOT NULL DEFAULT 'warn',
  `state` varchar(14) NOT NULL DEFAULT 'open',
  `message` varchar(500) NOT NULL DEFAULT '',
  `opened_at` datetime NOT NULL,
  `acked_by` int(11) DEFAULT NULL,
  `acked_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `resolve_reason` varchar(20) DEFAULT NULL,
  `policy_id` int(11) DEFAULT NULL,
  `esc_step` int(11) NOT NULL DEFAULT 0,
  `esc_count` int(11) NOT NULL DEFAULT 0,
  `esc_attempts` int(11) NOT NULL DEFAULT 0,
  `esc_next_at` datetime DEFAULT NULL,
  `last_notified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`meta_id`),
  UNIQUE KEY `uniq_episode` (`device_id`,`check_key`,`episode`),
  KEY `idx_alert` (`alert_id`),
  KEY `idx_escalation` (`state`,`esc_next_at`),
  KEY `idx_client_opened` (`client_id`,`opened_at`),
  KEY `idx_opened` (`opened_at`),
  KEY `idx_group` (`group_key`,`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_storm_summaries' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_storm_summaries` (
  `scope` varchar(30) NOT NULL,
  `bucket` datetime NOT NULL,
  `alert_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`scope`,`bucket`),
  KEY `idx_open` (`resolved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_maintenance_windows' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_maintenance_windows` (
  `window_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `mode` varchar(8) NOT NULL DEFAULT 'mute',
  `scope_type` varchar(8) NOT NULL DEFAULT 'all',
  `scope_id` int(11) NOT NULL DEFAULT 0,
  `kind` varchar(10) NOT NULL DEFAULT 'once',
  `timezone` varchar(64) NOT NULL DEFAULT 'UTC',
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `recur_freq` varchar(8) DEFAULT NULL,
  `recur_interval` int(11) NOT NULL DEFAULT 1,
  `recur_days` varchar(120) NOT NULL DEFAULT '',
  `local_start` char(5) NOT NULL DEFAULT '00:00',
  `duration_min` int(11) NOT NULL DEFAULT 60,
  `recur_from` date DEFAULT NULL,
  `recur_until` date DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `active_since` datetime DEFAULT NULL,
  `note` varchar(300) NOT NULL DEFAULT '',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`window_id`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_scope` (`scope_type`,`scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_device_parents' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_device_parents` (
  `device_id` int(11) NOT NULL,
  `parent_device_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`device_id`),
  KEY `idx_parent` (`parent_device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_escalation_policies' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_escalation_policies` (
  `policy_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `scope_type` varchar(8) NOT NULL DEFAULT 'all',
  `scope_id` int(11) NOT NULL DEFAULT 0,
  `min_severity` varchar(4) NOT NULL DEFAULT 'warn',
  `repeat_every_min` int(11) NOT NULL DEFAULT 0,
  `repeat_max` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`policy_id`),
  UNIQUE KEY `uniq_name` (`name`),
  KEY `idx_scope` (`scope_type`,`scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_escalation_steps' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_escalation_steps` (
  `policy_id` int(11) NOT NULL,
  `step_no` int(11) NOT NULL,
  `after_min` int(11) NOT NULL DEFAULT 0,
  `targets_json` text NOT NULL,
  PRIMARY KEY (`policy_id`,`step_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
}
