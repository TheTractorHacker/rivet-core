<?php

declare(strict_types=1);

namespace RivetCore\Webhooks\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/** webhook_deliveries is Core-owned (the delivery log). The edition-owned `webhooks` table is NOT touched.
 *
 * @internal
 */
final class Migration0005WebhookDeliveries implements MigrationInterface
{
    public function id(): string
    {
        return '0005_webhook_deliveries';
    }

    public function up(DatabaseInterface $database): void
    {
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
                `delivery_id` int(11) NOT NULL AUTO_INCREMENT,
                `webhook_id` int(11) NOT NULL,
                `event_type` varchar(150) NOT NULL,
                `http_status` smallint(6) DEFAULT NULL,
                `duration_ms` int(11) NOT NULL DEFAULT 0,
                `attempt_number` tinyint(3) NOT NULL DEFAULT 1,
                `request_payload_json` longtext DEFAULT NULL,
                `response_body_snippet` varchar(1000) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`delivery_id`),
                KEY `idx_webhook_deliveries_webhook` (`webhook_id`, `created_at`),
                KEY `idx_webhook_deliveries_event` (`event_type`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
