<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;

/**
 * A private database next to the scratch one (`<RIVETCORE_TEST_DB_NAME>_<suffix>`) with every Core migration applied, for the reporting tests (#91). Built once per
 * process and suffix. Needs the scratch account to be allowed to create databases (the CI account and the Docker root are); skipped without RIVETCORE_TEST_DB_*.
 */
final class ReportingTestDb
{
    /** @var array<string,array{mysqli:\mysqli,db:MysqliDatabase,name:string}> */
    private static array $built = [];

    /** @return array{mysqli:\mysqli,db:MysqliDatabase,name:string}|null null when no scratch database is configured */
    public static function get(string $suffix = 'rpt', bool $seed = true): ?array
    {
        $base = (string) getenv('RIVETCORE_TEST_DB_NAME');
        if ($base === '' || !str_contains($base, 'scratch')) {
            return null;
        }
        if (isset(self::$built[$suffix])) {
            return self::$built[$suffix];
        }
        $name = $base . '_' . $suffix;
        mysqli_report(MYSQLI_REPORT_OFF);
        $root = self::connect('');
        if ($root->connect_errno) {
            return null;
        }
        $root->query("DROP DATABASE IF EXISTS `$name`");
        if (!$root->query("CREATE DATABASE `$name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
            return null;
        }
        $root->close();
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $m = self::connect($name);
        $m->set_charset('utf8mb4');
        $db = new MysqliDatabase($m);
        (new MigrationRunner($db, CoreMigrations::all(), new FixedClock()))->run();
        if ($seed) {
            ReportingSeed::maria($db);
        }

        return self::$built[$suffix] = ['mysqli' => $m, 'db' => $db, 'name' => $name];
    }

    public static function connect(string $database, ?string $user = null, ?string $password = null): \mysqli
    {
        return new \mysqli((string) (getenv('RIVETCORE_TEST_DB_HOST') ?: '127.0.0.1'), $user ?? (string) (getenv('RIVETCORE_TEST_DB_USER') ?: 'root'),
            $password ?? (string) getenv('RIVETCORE_TEST_DB_PASS'), $database, (int) (getenv('RIVETCORE_TEST_DB_PORT') ?: 0));
    }

    public static function isMariaDb(\mysqli $m): bool
    {
        return stripos((string) $m->server_info, 'mariadb') !== false;
    }
}
