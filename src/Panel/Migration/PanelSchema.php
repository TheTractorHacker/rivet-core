<?php

declare(strict_types=1);

namespace RivetCore\Panel\Migration;

/**
 * The DDL of the RivetPanel device-token API (migration 0040), spelled once so an edition's db.sql can mirror it. The two
 * `ALTER` lists are what a fresh install's db.sql must already contain (they are the last columns of the existing tables).
 *
 * @api
 */
final class PanelSchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS */
    public static function tables(): array
    {
        return [
            'panel_client_settings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `panel_client_settings` (
  `client_id` int(11) NOT NULL,
  `allow_client_level_tickets` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            'panel_ticket_owner' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `panel_ticket_owner` (
  `ticket_id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `contact_id` int(11) DEFAULT NULL,
  `request_id` char(36) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ticket_id`),
  UNIQUE KEY `uniq_device_request` (`device_id`,`request_id`),
  KEY `idx_device_created` (`device_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }

    /**
     * Additive columns of existing tables, keyed "table.column". A device or enrollment token created before the migration is an
     * `agent`; `contact_id` is NULL for a client-level panel device.
     *
     * @return array<string,string> "table.column" => ALTER TABLE ... ADD COLUMN
     */
    public static function columns(): array
    {
        return [
            'endpoint_agent_enrollment_tokens.kind' => "ALTER TABLE `endpoint_agent_enrollment_tokens` ADD COLUMN `kind` varchar(10) NOT NULL DEFAULT 'agent'",
            'endpoint_agent_enrollment_tokens.contact_id' => 'ALTER TABLE `endpoint_agent_enrollment_tokens` ADD COLUMN `contact_id` int(11) DEFAULT NULL',
            'endpoint_agent_devices.kind' => "ALTER TABLE `endpoint_agent_devices` ADD COLUMN `kind` varchar(10) NOT NULL DEFAULT 'agent'",
            'endpoint_agent_devices.contact_id' => 'ALTER TABLE `endpoint_agent_devices` ADD COLUMN `contact_id` int(11) DEFAULT NULL',
        ];
    }
}
