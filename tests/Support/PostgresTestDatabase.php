<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Rmm\Store\Postgres\Pdo\PdoPostgresConnection;
use RivetCore\Rmm\Store\Postgres\PostgresConfig;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresMigrator;
use RivetCore\Rmm\Store\Postgres\PostgresSchema;

/**
 * The throwaway PostgreSQL of the tests (phase 2 of #89): RIVETCORE_TEST_PG_HOST / _PORT / _NAME / _USER / _PASS name a SCRATCH database (the name must contain
 * "scratch"; the tests drop every hot table in it). {@see available()} is false, and the tests skip with a clear message, when the extension, the variables or the
 * server are missing, the way the MariaDB tests skip without RIVETCORE_TEST_DB_NAME. RIVETCORE_TEST_PG_MODE=timescale|native|auto picks the storage mode
 * (default auto: TimescaleDB when the image has the extension).
 */
final class PostgresTestDatabase
{
    private static ?string $why = null;
    private static bool $checked = false;

    /** Why the tests cannot run, or null when a scratch PostgreSQL is reachable. */
    public static function unavailable(): ?string
    {
        if (self::$checked) {
            return self::$why;
        }
        self::$checked = true;
        if (!extension_loaded('pdo_pgsql')) {
            return self::$why = 'The pdo_pgsql extension is not loaded (run the PostgreSQL tests in the container of docs/rmm/POSTGRES.md or CI).';
        }
        if (!getenv('RIVETCORE_TEST_PG_NAME')) {
            return self::$why = 'RIVETCORE_TEST_PG_NAME is not set (a scratch PostgreSQL is required).';
        }
        if (!str_contains((string) getenv('RIVETCORE_TEST_PG_NAME'), 'scratch')) {
            return self::$why = 'The PostgreSQL test database name must contain "scratch".';
        }
        try {
            self::connect()->value('SELECT 1');
        } catch (\Throwable $e) {
            return self::$why = 'No PostgreSQL reachable for the tests: ' . $e->getMessage();
        }

        return self::$why = null;
    }

    public static function config(array $over = []): PostgresConfig
    {
        return PostgresConfig::fromArray($over + ['database' => (string) getenv('RIVETCORE_TEST_PG_NAME'), 'user' => (string) getenv('RIVETCORE_TEST_PG_USER'),
            'password' => (string) getenv('RIVETCORE_TEST_PG_PASS'), 'host' => (string) (getenv('RIVETCORE_TEST_PG_HOST') ?: '127.0.0.1'), 'port' => (int) (getenv('RIVETCORE_TEST_PG_PORT') ?: 5432),
            'sslmode' => 'disable', 'statement_timeout_ms' => 20000, 'lock_timeout_ms' => 5000]);
    }

    public static function connect(array $over = []): PostgresConnectionInterface
    {
        return new PdoPostgresConnection(self::config($over));
    }

    /**
     * Make sure the schema exists in the wanted mode and is EMPTY: a full rebuild when the mode differs or nothing is there, otherwise a TRUNCATE of every table (and
     * the partitions a test may have created are dropped by the rebuild, so tests that create partitions call {@see reset()}).
     *
     * @return array{applied:list<string>,mode:string,timescale_available:bool}|null the migrator's answer when it rebuilt
     */
    public static function prepare(PostgresConnectionInterface $db, ?string $mode = null): ?array
    {
        $want = $mode ?? ((string) getenv('RIVETCORE_TEST_PG_MODE') ?: PostgresMigrator::MODE_AUTO);
        $m = new PostgresMigrator($db);
        $s = $m->status();
        $have = $s['mode'];
        $ok = $s['exists'] && $s['pending'] === [] && $have !== null && ($want === PostgresMigrator::MODE_AUTO ? $have === ($s['timescale_available'] ? PostgresSchema::MODE_TIMESCALE : PostgresSchema::MODE_NATIVE) : $have === $want);
        if ($ok && $have === PostgresSchema::MODE_NATIVE && (int) $db->value("SELECT COUNT(*) FROM pg_inherits i JOIN pg_class p ON p.oid = i.inhparent
            WHERE p.relname IN ('rmm_metric_hourly', 'rmm_metric_daily', 'endpoint_agent_check_history') AND p.relnamespace = current_schema()::regnamespace") > 3) {
            $ok = false;   // a test created day partitions: start from the pristine layout
        }
        if (!$ok) {
            return self::reset($db, $want);
        }
        $db->execute('TRUNCATE ' . implode(', ', PostgresSchema::TABLES) . ' RESTART IDENTITY');

        return null;
    }

    /** Drop everything the hot store created and build it again; returns the migrator's answer. @return array{applied:list<string>,mode:string,timescale_available:bool} */
    public static function reset(PostgresConnectionInterface $db, ?string $mode = null): array
    {
        $db->close();
        foreach (array_reverse(PostgresSchema::TABLES) as $t) {
            $db->execute("DROP TABLE IF EXISTS $t CASCADE");
        }
        foreach (['endpoint_agent_check_history_hist_id_seq'] as $s) {
            $db->execute("DROP SEQUENCE IF EXISTS $s");
        }
        $db->execute('DROP TABLE IF EXISTS ' . PostgresMigrator::LEDGER);
        $db->execute('DROP TABLE IF EXISTS ' . PostgresMigrator::META);

        return (new PostgresMigrator($db))->migrate($mode ?? ((string) getenv('RIVETCORE_TEST_PG_MODE') ?: PostgresMigrator::MODE_AUTO));
    }
}
