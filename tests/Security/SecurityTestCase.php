<?php

declare(strict_types=1);

namespace RivetCore\Tests\Security;

use PHPUnit\Framework\TestCase;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Redis\RedisClientProviderInterface;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;
use RivetCore\Tests\Support\TestRedis;

/**
 * Shared plumbing for the second security review (docs/SECURITY-REVIEW-2.md). Tests in this directory assert the
 * CURRENT behaviour of each finding; when a finding is fixed the matching assertion is flipped (see each docblock).
 * Database tests need RIVETCORE_TEST_DB_NAME (scratch DB), Redis tests need RIVETCORE_TEST_REDIS_PORT (throwaway
 * Redis); both skip cleanly when unset. Nothing here talks to a network other than 127.0.0.1.
 */
abstract class SecurityTestCase extends TestCase
{
    /** A scratch database with every Core table present and the named tables emptied. */
    protected function db(string ...$truncate): DatabaseInterface
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        $db = new MysqliDatabase($m);
        (new MigrationRunner($db, CoreMigrations::all(), new FixedClock()))->run();
        foreach ($truncate as $table) {
            $db->execute('TRUNCATE TABLE ' . $table);
        }

        return $db;
    }

    protected function redis(): TestRedis
    {
        if (!TestRedis::available()) {
            $this->markTestSkipped('RIVETCORE_TEST_REDIS_PORT not set (throwaway Redis required).');
        }

        return new TestRedis();
    }

    /** A unique key prefix so tests never depend on (or clobber) other keys in the throwaway Redis. */
    protected function prefix(): string
    {
        return 'sr2:' . bin2hex(random_bytes(6)) . ':';
    }

    /** A provider whose Redis is unreachable. */
    protected function downRedis(): RedisClientProviderInterface
    {
        return new TestRedis(null, true);
    }

    /** Starts `php -S` on a free 127.0.0.1 port serving $router; returns [port, process]. Caller must stop it. */
    protected function startServer(string $router): array
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($sock, false), strlen('127.0.0.1:'));
        fclose($sock);
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($c) {
                fclose($c);

                return [$port, $proc];
            }
            usleep(100000);
        }
        proc_terminate($proc);
        $this->fail('could not start the local test server');
    }

    /** @param resource $proc */
    protected function stopServer($proc): void
    {
        proc_terminate($proc);
        proc_close($proc);
    }

    protected function tmpFile(string $name): string
    {
        $dir = sys_get_temp_dir() . '/rivetcore-sr2-' . getmypid();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir . '/' . $name;
    }
}
