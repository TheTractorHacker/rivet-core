<?php

declare(strict_types=1);

namespace RivetCore\Mcp\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/** mcp_unlinked_identities is Core-owned. RivetIT created this exact table in migration 2.6.123; IF NOT EXISTS makes this a no-op there. */
final class Migration0003McpUnlinkedIdentities implements MigrationInterface
{
    public function id(): string
    {
        return '0003_mcp_unlinked_identities';
    }

    public function up(DatabaseInterface $database): void
    {
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `mcp_unlinked_identities` (
                `mcp_unlinked_id` int(11) NOT NULL AUTO_INCREMENT,
                `issuer` varchar(255) NOT NULL,
                `subject` varchar(255) NOT NULL,
                `email` varchar(200) DEFAULT NULL,
                `display_name` varchar(200) DEFAULT NULL,
                `attempts` int(11) NOT NULL DEFAULT 1,
                `first_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
                `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`mcp_unlinked_id`),
                UNIQUE KEY `uniq_mcp_identity` (`issuer`, `subject`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
