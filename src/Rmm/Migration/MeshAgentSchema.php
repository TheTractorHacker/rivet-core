<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The tables of the optional "also install the MeshCentral agent" feature, created by migration 0023. Every statement is CREATE TABLE IF NOT
 * EXISTS with an explicit engine, charset and collation; nothing existing is altered. Do not edit a released statement, add a new migration instead.
 *
 *  - endpoint_agent_mesh_settings: one row (id 1) of install settings that are not part of the module's settings row (the device group id, the
 *    optional server certificate pin, the overwrite switch and the optional corrections of the agent-id and install-argument tables).
 *    Kept apart from `endpoint_agent_settings` for the same reason as the remote-tools row: that table is owned by the edition.
 *  - endpoint_agent_mesh_agents: what a device last reported about its MeshCentral agent (installed, version, node id, error) and what the
 *    server did with the node id (mapped, kept, conflict). One row per device.
 *  - endpoint_agent_installer_options: the per-token choices made when an installer was issued (today: install the Mesh agent), so the token-gated
 *    download that scripts call produces the same payload as the interactive one.
 *
 * @internal
 */
final class MeshAgentSchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement (no trailing semicolon) */
    public static function tables(): array
    {
        return [
            'endpoint_agent_mesh_settings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_mesh_settings` (
  `id` tinyint(4) NOT NULL DEFAULT 1,
  `group_id` varchar(128) NOT NULL DEFAULT '',
  `server_cert_pin` varchar(96) NOT NULL DEFAULT '',
  `auto_map_overwrite` tinyint(1) NOT NULL DEFAULT 0,
  `agent_ids_json` text DEFAULT NULL,
  `install_args_json` text DEFAULT NULL,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_mesh_agents' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_mesh_agents` (
  `device_id` int(11) NOT NULL,
  `installed` tinyint(1) NOT NULL DEFAULT 0,
  `version` varchar(60) NOT NULL DEFAULT '',
  `node_id` varchar(200) NOT NULL DEFAULT '',
  `error` varchar(300) NOT NULL DEFAULT '',
  `map_state` varchar(20) NOT NULL DEFAULT '',
  `conflict_device_id` int(11) DEFAULT NULL,
  `first_installed_at` datetime DEFAULT NULL,
  `reported_at` datetime NOT NULL,
  `changed_at` datetime NOT NULL,
  PRIMARY KEY (`device_id`),
  KEY `idx_node` (`node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'endpoint_agent_installer_options' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_installer_options` (
  `token_id` int(11) NOT NULL,
  `install_mesh_agent` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
}
