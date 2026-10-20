<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The tables of RMM hypervisor visibility (Proxmox VE and Hyper-V hosts), created by migration 0035. Every statement is CREATE TABLE IF NOT EXISTS with an explicit engine, charset and
 * collation; nothing existing is altered and nothing is inserted. The tables cost nothing until the `hypervisor` sub-switch is on.
 *
 * Row-size note: the widest row is a VM. Its declared columns add up to about 1.9 KB in utf8mb4 (name 100, node 64, tags 300, ignore reason 200 characters, the rest are numbers and short
 * codes), far below the InnoDB limit; no index is on a text column. See docs/rmm/CAPACITY.md for the per-device and fleet numbers.
 *
 * @internal
 */
final class HypervisorSchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement (no trailing semicolon) */
    public static function tables(): array
    {
        return [
            'rmm_hypervisor_hosts' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_hypervisor_hosts` (
  `device_id` int(11) NOT NULL,
  `platform` varchar(12) NOT NULL,
  `version` varchar(60) NOT NULL DEFAULT '',
  `cluster_name` varchar(100) NOT NULL DEFAULT '',
  `quorate` tinyint(4) DEFAULT NULL,
  `cluster_nodes_json` text DEFAULT NULL,
  `failed_groups` int(11) NOT NULL DEFAULT 0,
  `vm_count` int(11) NOT NULL DEFAULT 0,
  `truncated` tinyint(4) NOT NULL DEFAULT 0,
  `cfg_version` varchar(16) NOT NULL DEFAULT '',
  `last_collected_at` datetime DEFAULT NULL,
  `last_full_at` datetime DEFAULT NULL,
  `last_report_at` datetime DEFAULT NULL,
  `error` varchar(300) NOT NULL DEFAULT '',
  `ignored` tinyint(4) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`device_id`),
  KEY `idx_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_hypervisor_vms' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_hypervisor_vms` (
  `vm_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `vm_key` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT '',
  `type` varchar(8) NOT NULL DEFAULT '',
  `state` varchar(14) NOT NULL DEFAULT 'unknown',
  `state_changed_at` datetime DEFAULT NULL,
  `node` varchar(64) NOT NULL DEFAULT '',
  `cpus` int(11) NOT NULL DEFAULT 0,
  `cpu_pct` decimal(4,1) DEFAULT NULL,
  `mem_bytes` bigint(20) DEFAULT NULL,
  `mem_max_bytes` bigint(20) DEFAULT NULL,
  `disk_bytes` bigint(20) DEFAULT NULL,
  `disk_used_bytes` bigint(20) DEFAULT NULL,
  `uptime_s` bigint(20) DEFAULT NULL,
  `template` tinyint(4) NOT NULL DEFAULT 0,
  `tags` varchar(300) NOT NULL DEFAULT '',
  `snapshot_count` int(11) DEFAULT NULL,
  `oldest_snapshot_at` datetime DEFAULT NULL,
  `last_backup_at` datetime DEFAULT NULL,
  `last_backup_result` varchar(8) DEFAULT NULL,
  `backup_covered` tinyint(4) DEFAULT NULL,
  `guest_agent` tinyint(4) DEFAULT NULL,
  `replication` varchar(10) NOT NULL DEFAULT '',
  `ignored` tinyint(4) NOT NULL DEFAULT 0,
  `ignored_reason` varchar(200) NOT NULL DEFAULT '',
  `ignored_by` int(11) NOT NULL DEFAULT 0,
  `ignored_at` datetime DEFAULT NULL,
  `first_seen_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `missing_since` datetime DEFAULT NULL,
  PRIMARY KEY (`vm_id`),
  UNIQUE KEY `uq_device_vm` (`device_id`,`vm_key`),
  KEY `idx_name` (`name`),
  KEY `idx_state` (`state`),
  KEY `idx_missing` (`missing_since`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_hypervisor_storage' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_hypervisor_storage` (
  `device_id` int(11) NOT NULL,
  `name` varchar(48) NOT NULL,
  `type` varchar(24) NOT NULL DEFAULT '',
  `total_bytes` bigint(20) NOT NULL DEFAULT 0,
  `used_bytes` bigint(20) NOT NULL DEFAULT 0,
  `health` varchar(8) NOT NULL DEFAULT 'unknown',
  `shared` tinyint(4) NOT NULL DEFAULT 0,
  `last_seen_at` datetime NOT NULL,
  `missing_since` datetime DEFAULT NULL,
  PRIMARY KEY (`device_id`,`name`),
  KEY `idx_missing` (`missing_since`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'rmm_hypervisor_vm_history' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_hypervisor_vm_history` (
  `history_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `vm_id` bigint(20) NOT NULL,
  `device_id` int(11) NOT NULL,
  `at` datetime NOT NULL,
  `from_state` varchar(14) NOT NULL DEFAULT '',
  `to_state` varchar(14) NOT NULL DEFAULT '',
  PRIMARY KEY (`history_id`),
  KEY `idx_vm` (`vm_id`,`history_id`),
  KEY `idx_device` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
}
