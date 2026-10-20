<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The tables of "install apps when the agent installs" (1.0.0-rc.26, migration 0033). All new, none alters an existing table, no row is inserted.
 *
 *  - rmm_package_auto          per package: the auto-install scope (off | all | clients | os), install order, required or best effort
 *  - rmm_package_auto_clients  the clients (departments) a `clients` scope covers, optionally narrowed to one location
 *  - rmm_package_params        vendor parameter VALUES, sealed with the settings vault (never returned, never in a job row)
 *  - rmm_installer_apps        the catalog apps a technician ticked when an installer was issued (per enrollment token)
 *  - rmm_device_apps           one row per (device, app) the enrollment planned: status queued | installing | installed | failed | skipped, with the reason
 *  - rmm_hosted_files          installer files uploaded to the server (stored outside the web root; the row holds the sha256 and the random storage name)
 *  - rmm_version_hosted        which package version points at which hosted file
 *  - rmm_hosted_grants         the one job-bound, secret-bearing grant that lets a device download the hosted file of its install job
 *
 * @internal
 */
final class AutoInstallSchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement */
    public static function tables(): array
    {
        return [
            'rmm_package_auto' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_package_auto` (
  `package_id` int(11) NOT NULL,
  `scope` varchar(8) NOT NULL DEFAULT 'off',
  `os_filter` varchar(8) NOT NULL DEFAULT '',
  `install_order` int(11) NOT NULL DEFAULT 100,
  `required` tinyint(1) NOT NULL DEFAULT 0,
  `set_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`package_id`),
  KEY `idx_scope` (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_package_auto_clients' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_package_auto_clients` (
  `package_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`package_id`,`client_id`,`location_id`),
  KEY `idx_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_package_params' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_package_params` (
  `package_id` int(11) NOT NULL,
  `scope_type` varchar(8) NOT NULL,
  `scope_id` int(11) NOT NULL DEFAULT 0,
  `name` varchar(30) NOT NULL,
  `value_enc` text NOT NULL,
  `set_by` int(11) NOT NULL DEFAULT 0,
  `set_at` datetime NOT NULL,
  PRIMARY KEY (`package_id`,`scope_type`,`scope_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_installer_apps' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_installer_apps` (
  `token_id` int(11) NOT NULL,
  `package_id` int(11) NOT NULL,
  `selected_by` int(11) NOT NULL DEFAULT 0,
  `selected_at` datetime NOT NULL,
  PRIMARY KEY (`token_id`,`package_id`),
  KEY `idx_package` (`package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_device_apps' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_device_apps` (
  `device_id` int(11) NOT NULL,
  `package_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `source` varchar(8) NOT NULL DEFAULT 'auto',
  `token_id` int(11) DEFAULT NULL,
  `selected_by` int(11) DEFAULT NULL,
  `install_order` int(11) NOT NULL DEFAULT 100,
  `required` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(10) NOT NULL DEFAULT 'queued',
  `reason` varchar(200) NOT NULL DEFAULT '',
  `version_id` int(11) DEFAULT NULL,
  `job_id` char(36) DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `planned_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`device_id`,`package_id`),
  KEY `idx_open` (`status`,`device_id`),
  KEY `idx_package` (`package_id`,`status`),
  KEY `idx_job` (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_hosted_files' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_hosted_files` (
  `file_id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(100) NOT NULL DEFAULT '',
  `storage_name` char(36) NOT NULL,
  `sha256` char(64) NOT NULL,
  `size_bytes` bigint(20) NOT NULL,
  `uploaded_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`file_id`),
  UNIQUE KEY `uniq_storage` (`storage_name`),
  KEY `idx_sha` (`sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_version_hosted' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_version_hosted` (
  `version_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`version_id`),
  KEY `idx_file` (`file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_hosted_grants' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_hosted_grants` (
  `job_id` char(36) NOT NULL,
  `file_id` int(11) NOT NULL,
  `secret_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`job_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
}
