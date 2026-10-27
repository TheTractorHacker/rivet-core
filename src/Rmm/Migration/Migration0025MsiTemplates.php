<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * The MSI installer templates the server stamps per client (`format = msi`): one new table, empty and free until an administrator publishes a template. Purely
 * additive and idempotent (CREATE TABLE IF NOT EXISTS); nothing existing is altered.
 *
 * @internal
 */
final class Migration0025MsiTemplates implements MigrationInterface
{
    public function id(): string
    {
        return '0025_rmm_msi_templates';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (MsiTemplateSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
