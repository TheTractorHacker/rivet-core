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

    /**
     * The Phase 1 tables (migration 0018): per-device state, software inventory and history, tags, groups, the per-check history
     * ring and the database metric sink. They are Core-owned and new, so they never alter the ten tables above (which an edition
     * mirrors in its own db.sql). Same collation rule: explicit on every table.
     *
     * @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement (no trailing semicolon)
     */
    public static function phase1Tables(): array
    {
        return [
            'rmm_device_state' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_device_state` (
  `device_id` int(11) NOT NULL,
  `platform` varchar(20) DEFAULT NULL,
  `capabilities_json` text DEFAULT NULL,
  `presence` varchar(8) DEFAULT NULL,
  `presence_at` datetime DEFAULT NULL,
  `software_hash` char(64) DEFAULT NULL,
  `software_count` int(11) DEFAULT NULL,
  `software_at` datetime DEFAULT NULL,
  `software_full_at` datetime DEFAULT NULL,
  `software_resync` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`device_id`),
  KEY `idx_presence` (`presence`,`presence_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_device_software' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_device_software` (
  `device_id` int(11) NOT NULL,
  `software_key` char(40) NOT NULL,
  `name` varchar(200) NOT NULL,
  `source` varchar(12) NOT NULL,
  `version` varchar(100) NOT NULL DEFAULT '',
  `publisher` varchar(200) NOT NULL DEFAULT '',
  `installed_on` date DEFAULT NULL,
  `first_seen_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `removed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`device_id`,`software_key`),
  KEY `idx_name` (`name`,`device_id`),
  KEY `idx_removed` (`removed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_software_history' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_software_history` (
  `history_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `software_key` char(40) NOT NULL,
  `name` varchar(200) NOT NULL,
  `source` varchar(12) NOT NULL,
  `change_type` varchar(12) NOT NULL,
  `old_version` varchar(100) DEFAULT NULL,
  `new_version` varchar(100) DEFAULT NULL,
  `publisher` varchar(200) NOT NULL DEFAULT '',
  `occurred_at` datetime NOT NULL,
  PRIMARY KEY (`history_id`),
  KEY `idx_device_time` (`device_id`,`occurred_at`),
  KEY `idx_device_key` (`device_id`,`software_key`),
  KEY `idx_time` (`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_tags' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_tags` (
  `tag_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `color` varchar(7) NOT NULL DEFAULT '',
  `description` varchar(200) NOT NULL DEFAULT '',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`tag_id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_device_tags' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_device_tags` (
  `device_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  `source` varchar(10) NOT NULL DEFAULT 'manual',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`device_id`,`tag_id`),
  KEY `idx_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_groups' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_groups` (
  `group_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(200) NOT NULL DEFAULT '',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`group_id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_group_devices' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_group_devices` (
  `group_id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`group_id`,`device_id`),
  KEY `idx_device` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_group_tags' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_group_tags` (
  `group_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`group_id`,`tag_id`),
  KEY `idx_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_check_history' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_check_history` (
  `hist_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `check_key` varchar(100) NOT NULL,
  `status` varchar(10) NOT NULL,
  `detail` varchar(200) NOT NULL DEFAULT '',
  `reported_at` datetime NOT NULL,
  PRIMARY KEY (`hist_id`),
  KEY `idx_check_time` (`device_id`,`check_key`,`reported_at`),
  KEY `idx_time` (`reported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_metric_latest' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_metric_latest` (
  `asset_id` int(11) NOT NULL,
  `metric_key` varchar(64) NOT NULL,
  `instance` varchar(64) NOT NULL DEFAULT '',
  `value` double NOT NULL,
  `label` varchar(64) DEFAULT NULL,
  `sampled_at` datetime NOT NULL,
  PRIMARY KEY (`asset_id`,`metric_key`,`instance`),
  KEY `idx_sampled` (`sampled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_metric_hourly' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_metric_hourly` (
  `asset_id` int(11) NOT NULL,
  `metric_key` varchar(64) NOT NULL,
  `instance` varchar(64) NOT NULL DEFAULT '',
  `hour_start` datetime NOT NULL,
  `samples` int(10) unsigned NOT NULL DEFAULT 0,
  `sum_value` double NOT NULL DEFAULT 0,
  `min_value` double NOT NULL,
  `max_value` double NOT NULL,
  PRIMARY KEY (`asset_id`,`metric_key`,`instance`,`hour_start`),
  KEY `idx_hour` (`hour_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
    /**
     * RMM Phase 2 (migration 0019): policies and their assignments, the script library with its versions, the sidecar that links a job to
     * its script, schedule and approval, scheduled scripts and their runs, approvals, and custom fields with their values. Core-owned and new;
     * none of the earlier tables is altered. The library table is `rmm_scripts_v2` on purpose: RivetIT's own saved-script table is `rmm_scripts`.
     *
     * @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement (no trailing semicolon)
     */
    public static function phase2Tables(): array
    {
        return [
            'rmm_policies' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_policies` (
  `policy_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(300) NOT NULL DEFAULT '',
  `kind` varchar(20) NOT NULL DEFAULT 'agent',
  `body_json` mediumtext NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`policy_id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_policy_versions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_policy_versions` (
  `policy_id` int(11) NOT NULL,
  `version` int(11) NOT NULL,
  `body_json` mediumtext NOT NULL,
  `changed_by` int(11) NOT NULL DEFAULT 0,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`policy_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_policy_assignments' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_policy_assignments` (
  `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
  `policy_id` int(11) NOT NULL,
  `scope_type` varchar(10) NOT NULL,
  `scope_id` int(11) NOT NULL DEFAULT 0,
  `priority` int(11) NOT NULL DEFAULT 100,
  `enforce` tinyint(1) NOT NULL DEFAULT 0,
  `overrides_json` text DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`assignment_id`),
  UNIQUE KEY `uniq_assignment` (`policy_id`,`scope_type`,`scope_id`),
  KEY `idx_scope` (`scope_type`,`scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_scripts_v2' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_scripts_v2` (
  `script_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(500) NOT NULL DEFAULT '',
  `language` varchar(12) NOT NULL,
  `platform` varchar(10) NOT NULL,
  `tags_json` text DEFAULT NULL,
  `owner_id` int(11) NOT NULL DEFAULT 0,
  `requires_approval` tinyint(1) NOT NULL DEFAULT 0,
  `destructive` tinyint(1) NOT NULL DEFAULT 0,
  `timeout_s` int(11) NOT NULL DEFAULT 300,
  `current_version` int(11) NOT NULL DEFAULT 0,
  `retired_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`script_id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_script_versions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_script_versions` (
  `script_id` int(11) NOT NULL,
  `version` int(11) NOT NULL,
  `body` mediumtext NOT NULL,
  `body_sha256` char(64) NOT NULL,
  `params_schema_json` text DEFAULT NULL,
  `signature` varchar(100) NOT NULL DEFAULT '',
  `signing_key_id` varchar(32) NOT NULL DEFAULT '',
  `note` varchar(200) NOT NULL DEFAULT '',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`script_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_job_extra' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_job_extra` (
  `job_id` char(36) NOT NULL,
  `device_id` int(11) NOT NULL DEFAULT 0,
  `script_id` int(11) DEFAULT NULL,
  `script_version` int(11) DEFAULT NULL,
  `schedule_id` int(11) DEFAULT NULL,
  `run_id` bigint(20) DEFAULT NULL,
  `approval_id` int(11) DEFAULT NULL,
  `idem_key` char(64) DEFAULT NULL,
  `secret_params_enc` mediumtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`job_id`),
  UNIQUE KEY `uniq_idem` (`idem_key`),
  KEY `idx_schedule_device` (`schedule_id`,`device_id`,`created_at`),
  KEY `idx_script` (`script_id`,`created_at`),
  KEY `idx_created` (`created_at`),
  KEY `idx_secret` (`secret_params_enc`(8))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_schedules' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_schedules` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `script_id` int(11) NOT NULL,
  `script_version` int(11) NOT NULL,
  `body_sha256` char(64) NOT NULL,
  `params_json` text DEFAULT NULL,
  `target_type` varchar(10) NOT NULL,
  `target_id` int(11) NOT NULL DEFAULT 0,
  `kind` varchar(10) NOT NULL,
  `interval_s` int(11) DEFAULT NULL,
  `cron_expr` varchar(100) NOT NULL DEFAULT '',
  `jitter_s` int(11) NOT NULL DEFAULT 0,
  `overlap` varchar(6) NOT NULL DEFAULT 'skip',
  `expires_s` int(11) NOT NULL DEFAULT 3600,
  `timeout_s` int(11) NOT NULL DEFAULT 300,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `approval_id` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `next_run_at` datetime DEFAULT NULL,
  `last_run_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`schedule_id`),
  UNIQUE KEY `uniq_name` (`name`),
  KEY `idx_due` (`enabled`,`next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_schedule_runs' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_schedule_runs` (
  `run_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `schedule_id` int(11) NOT NULL,
  `slot_at` datetime NOT NULL,
  `state` varchar(10) NOT NULL DEFAULT 'running',
  `cursor_device_id` int(11) NOT NULL DEFAULT 0,
  `targeted` int(11) NOT NULL DEFAULT 0,
  `jobs_created` int(11) NOT NULL DEFAULT 0,
  `skipped_overlap` int(11) NOT NULL DEFAULT 0,
  `skipped_gate` int(11) NOT NULL DEFAULT 0,
  `skipped_other` int(11) NOT NULL DEFAULT 0,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`run_id`),
  UNIQUE KEY `uniq_slot` (`schedule_id`,`slot_at`),
  KEY `idx_state` (`state`),
  KEY `idx_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_approvals' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_approvals` (
  `approval_id` int(11) NOT NULL AUTO_INCREMENT,
  `kind` varchar(12) NOT NULL,
  `state` varchar(16) NOT NULL DEFAULT 'pending_approval',
  `summary` varchar(300) NOT NULL DEFAULT '',
  `request_json` mediumtext NOT NULL,
  `request_sha256` char(64) NOT NULL,
  `device_count` int(11) NOT NULL DEFAULT 0,
  `script_id` int(11) DEFAULT NULL,
  `script_version` int(11) DEFAULT NULL,
  `requested_by` int(11) NOT NULL,
  `requested_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_note` varchar(300) NOT NULL DEFAULT '',
  `result_json` text DEFAULT NULL,
  PRIMARY KEY (`approval_id`),
  KEY `idx_state` (`state`,`expires_at`),
  KEY `idx_requested` (`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_custom_fields' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_custom_fields` (
  `field_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(40) NOT NULL,
  `label` varchar(100) NOT NULL DEFAULT '',
  `scope` varchar(8) NOT NULL,
  `type` varchar(8) NOT NULL,
  `options_json` text DEFAULT NULL,
  `default_value` varchar(500) DEFAULT NULL,
  `description` varchar(300) NOT NULL DEFAULT '',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`field_id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_custom_field_values' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_custom_field_values` (
  `field_id` int(11) NOT NULL,
  `scope_id` int(11) NOT NULL,
  `value_text` varchar(2000) DEFAULT NULL,
  `value_enc` text DEFAULT NULL,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`field_id`,`scope_id`),
  KEY `idx_scope` (`scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
}
