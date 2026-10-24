<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * `endpoint_agent_settings.data_store` (phase 1 of #89): where the HOT RMM tables live. `mariadb` is today's behaviour and the default; `postgres` is reserved
 * (the setting refuses it until the implementation ships). Additive and idempotent; nothing existing is touched.
 *
 * @internal
 */
final class Migration0042RmmDataStore implements MigrationInterface
{
    public function id(): string
    {
        return '0042_rmm_data_store';
    }

    public function up(DatabaseInterface $database): void
    {
        if (!Migration0015EndpointAgentConverge::hasColumn($database, 'endpoint_agent_settings', 'data_store')) {
            $database->execute("ALTER TABLE `endpoint_agent_settings` ADD COLUMN `data_store` varchar(16) NOT NULL DEFAULT 'mariadb'");
        }
    }
}
