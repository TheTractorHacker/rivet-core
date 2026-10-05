<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Testing\DatabaseContractTestCase;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

final class MysqliContractTest extends DatabaseContractTestCase
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
