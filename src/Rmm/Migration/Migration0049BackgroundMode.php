<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Background Mode (the technician console): four new Core-owned tables, see {@see BackgroundSchema} (settings, per-client policy, sessions, single-use check tokens)
 * and the empty settings row. Additive and idempotent: CREATE TABLE IF NOT EXISTS, the settings row is INSERT IGNORE, and an existing install that already has some of
 * the tables keeps them. Nothing existing is altered, no setting is switched on (the `background_mode` switch lives in `features_json`, default off, and the empty
 * policy mode denies every session). Numbering: 0045 is absent from the list on purpose (it was reserved for the reporting views, which shipped as 0047), so this is 0049.
 *
 * @internal
 */
final class Migration0049BackgroundMode implements MigrationInterface
{
    public function id(): string
    {
        return '0049_rmm_background_mode';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (BackgroundSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
        $database->execute("INSERT IGNORE INTO `rmm_background_settings` (`id`, `policy_mode`, `internal_group`, `updated_by`, `updated_at`) VALUES (1, '', '', 0, UTC_TIMESTAMP())");
    }
}
