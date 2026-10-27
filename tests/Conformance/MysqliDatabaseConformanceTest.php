<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

/** Reference run against a real MariaDB/MySQL: the test mysqli adapter (identical in behaviour to the editions') passes the kit. */
final class MysqliDatabaseConformanceTest extends DatabaseConformanceTestCase
{
    private ?DatabaseInterface $db = null;

    protected function database(): DatabaseInterface
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }

        return $this->db ??= new MysqliDatabase($m);
    }
}
