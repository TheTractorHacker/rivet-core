<?php

declare(strict_types=1);

namespace RivetCore\Automation\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/** automation_rules is Core-owned. RivetIT created this exact table in 2.6.63; IF NOT EXISTS makes this a no-op there. */
final class Migration0006AutomationRules implements MigrationInterface
{
    public function id(): string
    {
        return '0006_automation_rules';
    }

    public function up(DatabaseInterface $database): void
    {
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `automation_rules` (
                `rule_id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(200) NOT NULL,
                `trigger_event` varchar(150) NOT NULL,
                `condition_json` text DEFAULT NULL,
                `action_type` enum('create_ticket','send_webhook','notify_user') NOT NULL,
                `action_config_json` text DEFAULT NULL,
                `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`rule_id`),
                KEY `idx_automation_rules_trigger` (`trigger_event`, `is_enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
