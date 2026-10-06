<?php

declare(strict_types=1);

namespace RivetCore\Migration;

use RivetCore\Database\DatabaseInterface;

/**
 * One additive, idempotent schema step owned by RivetCore. Use
 * CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS; never drop, rename
 * or truncate. Core migrations may only touch Core-owned tables.
 *
 * @api
 */
interface MigrationInterface
{
    /** Stable, sortable id, e.g. "0001_audit_events". Never change once released. */
    public function id(): string;

    public function up(DatabaseInterface $database): void;
}
