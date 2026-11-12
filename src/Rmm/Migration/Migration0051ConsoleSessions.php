<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Rivet Console redeem and events: three new Core-owned tables, see {@see ConsoleSchema} (settings, single-use redeem codes, sessions with their report token) and the empty
 * settings row. Additive and idempotent: CREATE TABLE IF NOT EXISTS, the settings row is INSERT IGNORE. Nothing existing is altered and nothing is switched on: the `remote_console`
 * switch lives in `features_json` (default off) and an empty `public_url` refuses every console launch.
 *
 * @internal
 */
final class Migration0051ConsoleSessions implements MigrationInterface
{
    public function id(): string
    {
        return '0051_rmm_console_sessions';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (ConsoleSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
        $database->execute("INSERT IGNORE INTO `rmm_console_settings` (`id`, `public_url`, `min_version`, `log_file_paths`, `cookie_once`, `updated_by`, `updated_at`) VALUES (1, '', '', 0, 1, 0, UTC_TIMESTAMP())");
    }
}
