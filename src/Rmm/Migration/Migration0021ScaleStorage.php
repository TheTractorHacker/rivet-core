<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * RMM scale work (S4, S5, S6, S8): the daily metric rollup, the storage job state, the read-path cache, the external metric store outbox and the
 * per-client token buckets. Purely additive: every statement is CREATE TABLE IF NOT EXISTS and nothing existing is altered, so a second run, or
 * an install that already has some of the tables, changes nothing. The tables stay empty (and cost nothing) until the feature that owns them is used.
 *
 * @internal
 */
final class Migration0021ScaleStorage implements MigrationInterface
{
    public function id(): string
    {
        return '0021_rmm_scale_storage';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (ScaleSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
