<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Creates the ten endpoint_agent_* tables in their exact current shape (RivetIT DB 2.6.146) and the single settings row.
 * Installs that already have them (RivetIT) are untouched: every statement is CREATE TABLE IF NOT EXISTS / INSERT IGNORE.
 *
 * @internal
 */
final class Migration0014EndpointAgent implements MigrationInterface
{
    public function id(): string
    {
        return '0014_endpoint_agent_core';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (RmmSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
        $database->execute('INSERT IGNORE INTO `endpoint_agent_settings` (`id`) VALUES (1)');
    }
}
