<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

/**
 * Connects to the scratch MySQL/MariaDB named by RIVETCORE_TEST_DB_* env vars.
 * Tests that need it are skipped when unset. Never point this at production.
 */
final class ScratchDb
{
    public static function connect(): ?\mysqli
    {
        $db = getenv('RIVETCORE_TEST_DB_NAME');
        if (!$db) {
            return null;
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $m = new \mysqli(
            getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost',
            getenv('RIVETCORE_TEST_DB_USER') ?: 'root',
            getenv('RIVETCORE_TEST_DB_PASS') ?: '',
            $db,
            (int) (getenv('RIVETCORE_TEST_DB_PORT') ?: 0)
        );
        $m->set_charset('utf8mb4');

        return $m;
    }
}
