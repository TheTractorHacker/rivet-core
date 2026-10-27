<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The tables of RMM Phase 5 (software deployment), created by migration 0029. Every statement is CREATE TABLE IF NOT EXISTS with an explicit engine, charset and
 * collation; nothing existing is altered. Do not edit a released statement, add a new migration instead. The tables stay empty (and cost nothing) until the
 * `software_deploy` sub-switch is turned on and an administrator adds a package. Row-size note: the widest row is a package version (a 600 character url, 2400 bytes in
 * utf8mb4), far below the limit; the only unique keys are on short varchar/char columns.
 *
 * @internal
 */
final class DeploySchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement (no trailing semicolon) */
    public static function tables(): array
    {
        return [
            'rmm_software_hosts' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_software_hosts` (
  `host` varchar(253) NOT NULL,
  `note` varchar(120) NOT NULL DEFAULT '',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_packages' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_packages` (
  `package_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `publisher` varchar(100) NOT NULL DEFAULT '',
  `description` varchar(300) NOT NULL DEFAULT '',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `two_person` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`package_id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_package_versions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_package_versions` (
  `version_id` int(11) NOT NULL AUTO_INCREMENT,
  `package_id` int(11) NOT NULL,
  `version` varchar(64) NOT NULL,
  `os` varchar(10) NOT NULL,
  `fmt` varchar(4) NOT NULL,
  `pkg_name` varchar(100) NOT NULL DEFAULT '',
  `pin_version` tinyint(1) NOT NULL DEFAULT 0,
  `url` varchar(600) NOT NULL DEFAULT '',
  `sha256` char(64) NOT NULL DEFAULT '',
  `size_bytes` bigint(20) NOT NULL DEFAULT 0,
  `silent_args` varchar(200) NOT NULL DEFAULT '',
  `product_code` varchar(40) NOT NULL DEFAULT '',
  `detect_kind` varchar(16) NOT NULL DEFAULT '',
  `detect_value` varchar(120) NOT NULL DEFAULT '',
  `detect_min` varchar(64) NOT NULL DEFAULT '',
  `retired` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`version_id`),
  UNIQUE KEY `uniq_package_version` (`package_id`,`os`,`fmt`,`version`),
  KEY `idx_package_os` (`package_id`,`os`,`retired`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_package_assignments' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_package_assignments` (
  `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
  `package_id` int(11) NOT NULL,
  `version_id` int(11) DEFAULT NULL,
  `scope_type` varchar(10) NOT NULL,
  `scope_id` int(11) NOT NULL DEFAULT 0,
  `action` varchar(9) NOT NULL DEFAULT 'install',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `approval_id` int(11) DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`assignment_id`),
  UNIQUE KEY `uniq_package_scope` (`package_id`,`scope_type`,`scope_id`),
  KEY `idx_scope` (`scope_type`,`scope_id`,`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_deploy_state' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_deploy_state` (
  `device_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `evaluated_at` datetime DEFAULT NULL,
  `detect_requested_at` datetime DEFAULT NULL,
  `detected_at` datetime DEFAULT NULL,
  PRIMARY KEY (`device_id`),
  KEY `idx_evaluated` (`evaluated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_package_installs' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_package_installs` (
  `device_id` int(11) NOT NULL,
  `package_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `expected_action` varchar(9) DEFAULT NULL,
  `expected_version_id` int(11) DEFAULT NULL,
  `assignment_id` int(11) DEFAULT NULL,
  `ring` varchar(40) NOT NULL DEFAULT '',
  `expected_since` datetime DEFAULT NULL,
  `installed` tinyint(1) DEFAULT NULL,
  `installed_version` varchar(64) NOT NULL DEFAULT '',
  `satisfied` tinyint(1) DEFAULT NULL,
  `detected_at` datetime DEFAULT NULL,
  `last_action` varchar(9) NOT NULL DEFAULT '',
  `last_result` varchar(18) NOT NULL DEFAULT '',
  `last_code` varchar(40) NOT NULL DEFAULT '',
  `last_detail` varchar(200) NOT NULL DEFAULT '',
  `last_job_id` char(36) DEFAULT NULL,
  `last_attempt_at` datetime DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `fail_count` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`device_id`,`package_id`),
  KEY `idx_client_package` (`client_id`,`package_id`,`expected_action`),
  KEY `idx_package` (`package_id`,`expected_action`,`satisfied`),
  KEY `idx_detected` (`detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_package_history' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_package_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `package_id` int(11) NOT NULL,
  `version_id` int(11) DEFAULT NULL,
  `action` varchar(9) NOT NULL,
  `mode` varchar(8) NOT NULL,
  `result` varchar(18) NOT NULL,
  `code` varchar(40) NOT NULL DEFAULT '',
  `detail` varchar(200) NOT NULL DEFAULT '',
  `version` varchar(64) NOT NULL DEFAULT '',
  `at` datetime NOT NULL,
  `job_id` char(36) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_device_at` (`device_id`,`at`),
  KEY `idx_client_at` (`client_id`,`at`),
  KEY `idx_package_at` (`package_id`,`at`),
  KEY `idx_job` (`job_id`),
  KEY `idx_at` (`at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_package_jobs' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_package_jobs` (
  `job_id` char(36) NOT NULL,
  `device_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `type` varchar(16) NOT NULL,
  `package_id` int(11) DEFAULT NULL,
  `version_id` int(11) DEFAULT NULL,
  `action` varchar(9) NOT NULL DEFAULT '',
  `mode` varchar(8) NOT NULL DEFAULT '',
  `keys_json` text DEFAULT NULL,
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
