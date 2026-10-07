<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The ten endpoint_agent_* tables in their exact current shape (RivetIT DB 2.6.146), copied verbatim from RivetIT's db.sql.
 * Collation is explicit on every table (MariaDB 11 would default to uca1400). Migration 0014 creates them; do not edit a released
 * statement, add a new migration instead.
 *
 * @internal
 */
final class RmmSchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement (no trailing semicolon) */
    public static function tables(): array
    {
        return [
            'endpoint_agent_settings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_settings` (
  `id` tinyint(4) NOT NULL DEFAULT 1,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `service_url` varchar(500) NOT NULL DEFAULT '',
  `integration_id` int(11) NOT NULL DEFAULT 0,
  `check_in_interval_s` int(11) NOT NULL DEFAULT 300,
  `collect_interval_s` int(11) NOT NULL DEFAULT 60,
  `offline_after_s` int(11) NOT NULL DEFAULT 900,
  `stale_after_s` int(11) NOT NULL DEFAULT 604800,
  `failure_debounce` int(11) NOT NULL DEFAULT 3,
  `recovery_debounce` int(11) NOT NULL DEFAULT 2,
  `retention_days` int(11) NOT NULL DEFAULT 30,
  `job_retention_days` int(11) NOT NULL DEFAULT 180,
  `job_output_max_bytes` int(11) NOT NULL DEFAULT 65536,
  `job_default_timeout_s` int(11) NOT NULL DEFAULT 300,
  `job_max_timeout_s` int(11) NOT NULL DEFAULT 3600,
  `job_expiry_s` int(11) NOT NULL DEFAULT 3600,
  `job_ack_timeout_s` int(11) NOT NULL DEFAULT 120,
  `job_max_attempts` int(11) NOT NULL DEFAULT 3,
  `enroll_max_ttl_h` int(11) NOT NULL DEFAULT 72,
  `unmatched_policy` varchar(20) NOT NULL DEFAULT 'approval',
  `checks_json` text DEFAULT NULL,
  `signing_key_id` varchar(32) NOT NULL DEFAULT '',
  `signing_public_key` varchar(100) NOT NULL DEFAULT '',
  `signing_private_key_enc` text DEFAULT NULL,
  `signing_key_created_at` datetime DEFAULT NULL,
  `mesh_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `mesh_url` varchar(500) NOT NULL DEFAULT '',
  `mesh_domain` varchar(100) NOT NULL DEFAULT '',
  `mesh_login_key_enc` text DEFAULT NULL,
  `mesh_account_template` varchar(100) NOT NULL DEFAULT 'rivetit-support',
  `mesh_policy` varchar(20) NOT NULL DEFAULT 'unattended',
  `mesh_token_ttl_s` int(11) NOT NULL DEFAULT 300,
  `coexistence_policy` text DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `ca_pem` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_enrollment_tokens' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_enrollment_tokens` (
  `token_id` int(11) NOT NULL AUTO_INCREMENT,
  `token_selector` char(12) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `label` varchar(100) NOT NULL DEFAULT '',
  `client_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL DEFAULT 0,
  `ring` varchar(20) NOT NULL DEFAULT 'stable',
  `expires_at` datetime NOT NULL,
  `max_uses` int(11) NOT NULL DEFAULT 1,
  `use_count` int(11) NOT NULL DEFAULT 0,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` int(11) DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uniq_selector` (`token_selector`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_enroll_attempts' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_enroll_attempts` (
  `attempt_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ip_hash` char(64) NOT NULL,
  `ip_text` varchar(64) NOT NULL DEFAULT '',
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `reason` varchar(40) NOT NULL DEFAULT '',
  `token_selector` varchar(12) NOT NULL DEFAULT '',
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attempt_id`),
  KEY `idx_ip_time` (`ip_hash`,`attempted_at`),
  KEY `idx_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_devices' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_devices` (
  `device_id` int(11) NOT NULL AUTO_INCREMENT,
  `install_id` char(36) NOT NULL,
  `machine_guid` varchar(64) DEFAULT NULL,
  `hostname` varchar(200) NOT NULL DEFAULT '',
  `os` varchar(20) NOT NULL DEFAULT 'windows',
  `os_version` varchar(200) NOT NULL DEFAULT '',
  `arch` varchar(10) NOT NULL DEFAULT '',
  `serial` varchar(100) DEFAULT NULL,
  `manufacturer` varchar(200) DEFAULT NULL,
  `model` varchar(200) DEFAULT NULL,
  `mac_addresses` text DEFAULT NULL,
  `agent_version` varchar(40) NOT NULL DEFAULT '',
  `asset_id` int(11) DEFAULT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `location_id` int(11) NOT NULL DEFAULT 0,
  `ring` varchar(20) NOT NULL DEFAULT 'stable',
  `link_state` varchar(20) NOT NULL DEFAULT 'pending_approval',
  `match_reason` varchar(60) NOT NULL DEFAULT '',
  `match_candidates_json` text DEFAULT NULL,
  `token_hash` char(64) NOT NULL DEFAULT '',
  `token_issued_at` datetime DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_reason` varchar(100) DEFAULT NULL,
  `retired_at` datetime DEFAULT NULL,
  `enrolled_via_token_id` int(11) DEFAULT NULL,
  `enroll_count` int(11) NOT NULL DEFAULT 1,
  `first_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_checkin_at` datetime DEFAULT NULL,
  `last_collected_at` datetime DEFAULT NULL,
  `last_inventory_at` datetime DEFAULT NULL,
  `last_ip` varchar(64) DEFAULT NULL,
  `last_seq` bigint(20) NOT NULL DEFAULT 0,
  `inventory_json` mediumtext DEFAULT NULL,
  `last_metrics_json` text DEFAULT NULL,
  `logged_in_user` varchar(200) DEFAULT NULL,
  `pending_reboot` tinyint(1) DEFAULT NULL,
  `uptime_s` bigint(20) DEFAULT NULL,
  `update_state_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`device_id`),
  UNIQUE KEY `uniq_install` (`install_id`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_asset` (`asset_id`),
  KEY `idx_machine_guid` (`machine_guid`),
  KEY `idx_serial` (`serial`),
  KEY `idx_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_checkins' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_checkins` (
  `device_id` int(11) NOT NULL,
  `seq` bigint(20) NOT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `collected_at` datetime DEFAULT NULL,
  PRIMARY KEY (`device_id`,`seq`),
  KEY `idx_received` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_checks' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_checks` (
  `device_id` int(11) NOT NULL,
  `check_key` varchar(100) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'unknown',
  `detail` varchar(500) NOT NULL DEFAULT '',
  `consecutive_failures` int(11) NOT NULL DEFAULT 0,
  `consecutive_ok` int(11) NOT NULL DEFAULT 0,
  `episode` int(11) NOT NULL DEFAULT 0,
  `alert_id` int(11) DEFAULT NULL,
  `last_reported_at` datetime DEFAULT NULL,
  `last_changed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`device_id`,`check_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_jobs' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_jobs` (
  `job_id` char(36) NOT NULL,
  `device_id` int(11) NOT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `type` varchar(20) NOT NULL,
  `script` mediumtext DEFAULT NULL,
  `params_json` text DEFAULT NULL,
  `timeout_s` int(11) NOT NULL DEFAULT 300,
  `max_output_bytes` int(11) NOT NULL DEFAULT 65536,
  `destructive` tinyint(1) NOT NULL DEFAULT 0,
  `run_as` varchar(40) NOT NULL DEFAULT 'SYSTEM',
  `state` varchar(12) NOT NULL DEFAULT 'queued',
  `reason` varchar(60) DEFAULT NULL,
  `attempt` int(11) NOT NULL DEFAULT 1,
  `offered_count` int(11) NOT NULL DEFAULT 0,
  `last_offered_at` datetime DEFAULT NULL,
  `issued_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `exit_code` int(11) DEFAULT NULL,
  `output` mediumtext DEFAULT NULL,
  `output_truncated` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`job_id`),
  KEY `idx_device_state` (`device_id`,`state`),
  KEY `idx_state_updated` (`state`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_mesh_nodes' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_mesh_nodes` (
  `device_id` int(11) NOT NULL,
  `mesh_node_id` varchar(200) NOT NULL,
  `source` varchar(10) NOT NULL DEFAULT 'manual',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`device_id`),
  KEY `idx_node` (`mesh_node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_releases' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_releases` (
  `release_id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(40) NOT NULL,
  `url` varchar(500) NOT NULL,
  `sha256` char(64) NOT NULL,
  `min_version` varchar(40) NOT NULL DEFAULT '0.0.0',
  `ring` varchar(20) NOT NULL DEFAULT 'stable',
  `rollout_pct` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `arch` varchar(10) NOT NULL DEFAULT '',
  `binary_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`release_id`),
  UNIQUE KEY `uniq_version_ring_arch` (`version`,`ring`,`arch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_binaries' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_binaries` (
  `binary_id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(40) NOT NULL,
  `arch` varchar(10) NOT NULL,
  `sha256` char(64) NOT NULL,
  `size_bytes` bigint(20) NOT NULL DEFAULT 0,
  `storage_name` varchar(64) NOT NULL,
  `uploaded_by` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`binary_id`),
  UNIQUE KEY `uniq_version_arch` (`version`,`arch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
}
