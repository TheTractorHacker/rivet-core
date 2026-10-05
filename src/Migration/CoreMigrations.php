<?php

declare(strict_types=1);

namespace RivetCore\Migration;

use RivetCore\Audit\Migration\Migration0001AuditEvents;

/** The ordered list of every Core-owned migration. Append only. */
final class CoreMigrations
{
    /** @return list<MigrationInterface> */
    public static function all(): array
    {
        return [
            new Migration0001AuditEvents(),
        ];
    }
}
