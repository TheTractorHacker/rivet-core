<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The tables of RMM Phase 4 (patch management), created by migration 0028. Every statement is CREATE TABLE IF NOT EXISTS with an explicit engine,
 * charset and collation; nothing existing is altered. Do not edit a released statement, add a new migration instead. The tables stay empty (and cost
 * nothing) until the `patching` sub-switch is turned on and a device reports a scan. Row-size note: the widest row is an available update (a 300
 * character title, 1200 bytes in utf8mb4), far below the limit; the only unique keys are on short varchar/char columns.
 *
 * @internal
 */
final class PatchSchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement (no trailing semicolon) */
    public static function tables(): array
    {
        return [
            'rmm_patch_scans' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_patch_scans` (
  `device_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `scanned_at` datetime DEFAULT NULL,
  `requested_at` datetime DEFAULT NULL,
  `evaluated_at` datetime DEFAULT NULL,
  `manager` varchar(10) NOT NULL DEFAULT '',
  `scan_state` varchar(10) NOT NULL DEFAULT 'never',
  `wu_state` varchar(10) NOT NULL DEFAULT 'n/a',
  `reboot_pending` tinyint(1) NOT NULL DEFAULT 0,
  `last_install_at` datetime DEFAULT NULL,
  `total_count` int(11) NOT NULL DEFAULT 0,
  `critical_count` int(11) NOT NULL DEFAULT 0,
  `security_count` int(11) NOT NULL DEFAULT 0,
  `optional_count` int(11) NOT NULL DEFAULT 0,
  `driver_count` int(11) NOT NULL DEFAULT 0,
  `reboot_required_count` int(11) NOT NULL DEFAULT 0,
  `truncated` tinyint(1) NOT NULL DEFAULT 0,
  `job_id` char(36) DEFAULT NULL,
  `error` varchar(240) NOT NULL DEFAULT '',
  PRIMARY KEY (`device_id`),
  KEY `idx_client_scanned` (`client_id`,`scanned_at`),
  KEY `idx_scanned` (`scanned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_patch_available' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_patch_available` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `update_id` varchar(120) NOT NULL,
  `kb` varchar(24) NOT NULL DEFAULT '',
  `title` varchar(300) NOT NULL DEFAULT '',
  `kind` varchar(12) NOT NULL DEFAULT 'other',
  `severity` varchar(12) NOT NULL DEFAULT 'unspecified',
  `size_bytes` bigint(20) NOT NULL DEFAULT 0,
  `reboot_required` tinyint(1) NOT NULL DEFAULT 0,
  `released_at` datetime DEFAULT NULL,
  `eula_accepted` tinyint(1) NOT NULL DEFAULT 1,
  `version` varchar(80) NOT NULL DEFAULT '',
  `current_version` varchar(80) NOT NULL DEFAULT '',
  `status` varchar(12) NOT NULL DEFAULT 'available',
  `status_source` varchar(40) NOT NULL DEFAULT 'scan',
  `status_by` int(11) DEFAULT NULL,
  `status_at` datetime DEFAULT NULL,
  `approval_id` int(11) DEFAULT NULL,
  `decline_reason` varchar(200) NOT NULL DEFAULT '',
  `first_seen_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `fail_count` int(11) NOT NULL DEFAULT 0,
  `last_attempt_at` datetime DEFAULT NULL,
  `last_job_id` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_device_update` (`device_id`,`update_id`),
  KEY `idx_device_status` (`device_id`,`status`),
  KEY `idx_client_kind` (`client_id`,`status`,`kind`,`first_seen_at`),
  KEY `idx_update` (`update_id`,`status`),
  KEY `idx_status_seen` (`status`,`first_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_patch_history' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_patch_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `update_id` varchar(120) NOT NULL,
  `title` varchar(300) NOT NULL DEFAULT '',
  `action` varchar(12) NOT NULL,
  `result` varchar(16) NOT NULL,
  `code` varchar(40) NOT NULL DEFAULT '',
  `detail` varchar(200) NOT NULL DEFAULT '',
  `at` datetime NOT NULL,
  `job_id` char(36) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_device_at` (`device_id`,`at`),
  KEY `idx_client_at` (`client_id`,`at`),
  KEY `idx_job` (`job_id`),
  KEY `idx_at` (`at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_patch_rings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_patch_rings` (
  `ring_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(40) NOT NULL,
  `delay_days` int(11) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `description` varchar(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`ring_id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_patch_policies' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_patch_policies` (
  `policy_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(300) NOT NULL DEFAULT '',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `settings_json` text NOT NULL,
  `reboot_confirmed_by` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`policy_id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_patch_assignments' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_patch_assignments` (
  `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
  `policy_id` int(11) NOT NULL,
  `scope_type` varchar(10) NOT NULL,
  `scope_id` int(11) NOT NULL DEFAULT 0,
  `priority` int(11) NOT NULL DEFAULT 100,
  `enforce` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`assignment_id`),
  UNIQUE KEY `uniq_policy_scope` (`policy_id`,`scope_type`,`scope_id`),
  KEY `idx_scope` (`scope_type`,`scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_patch_rules' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_patch_rules` (
  `rule_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `ring_id` int(11) DEFAULT NULL,
  `kinds` varchar(100) NOT NULL DEFAULT '',
  `severities` varchar(100) NOT NULL DEFAULT '',
  `after_days` int(11) NOT NULL DEFAULT 0,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`rule_id`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_patch_jobs' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_patch_jobs` (
  `job_id` char(36) NOT NULL,
  `device_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `type` varchar(14) NOT NULL,
  `mode` varchar(8) NOT NULL DEFAULT '',
  `ids_json` text DEFAULT NULL,
  `reboot_policy` varchar(20) NOT NULL DEFAULT 'never',
  `source` varchar(16) NOT NULL DEFAULT 'manual',
  `idem_key` char(64) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `applied_at` datetime DEFAULT NULL,
  PRIMARY KEY (`job_id`),
  UNIQUE KEY `uniq_idem` (`idem_key`),
  KEY `idx_device_type` (`device_id`,`type`,`applied_at`),
  KEY `idx_applied` (`applied_at`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
}
