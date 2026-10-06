<?php

declare(strict_types=1);

namespace RivetCore\Jobs\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/** Adds integration_jobs.heartbeat_at so a stale-job sweep only reclaims jobs whose worker really stopped. Additive and idempotent.
 *
 * @internal
 */
final class Migration0012JobHeartbeat implements MigrationInterface
{
    public function id(): string
    {
        return '0012_job_heartbeat';
    }

    public function up(DatabaseInterface $database): void
    {
        $has = $database->fetchOne(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'integration_jobs' AND COLUMN_NAME = 'heartbeat_at'"
        );
        if ((int) ($has['c'] ?? 0) === 0) {
            $database->execute('ALTER TABLE `integration_jobs` ADD COLUMN `heartbeat_at` datetime DEFAULT NULL AFTER `started_at`');
        }
    }
}
