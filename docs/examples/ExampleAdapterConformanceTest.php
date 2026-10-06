<?php

declare(strict_types=1);

/*
 * How an edition uses the conformance kit: one small class per adapter. This file runs the kit against the example mysqli adapter:
 *
 *   RIVETCORE_TEST_DB_NAME=rivetcore_scratch_x ... vendor/bin/phpunit docs/examples/ExampleAdapterConformanceTest.php
 *
 * (it is outside phpunit.xml.dist's suites on purpose; DocSamplesTest runs it explicitly).
 */

namespace Example\Edition;

require_once __DIR__ . '/MysqliDatabaseAdapter.php';

use RivetCore\Database\DatabaseInterface;
use RivetCore\Tests\Conformance\DatabaseConformanceTestCase;

final class ExampleAdapterConformanceTest extends DatabaseConformanceTestCase
{
    private ?DatabaseInterface $db = null;

    protected function database(): DatabaseInterface
    {
        $name = (string) getenv('RIVETCORE_TEST_DB_NAME');
        if ($name === '' || !preg_match('/scratch|test/i', $name)) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME must name a scratch database.');
        }
        if ($this->db === null) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $m = new \mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: '127.0.0.1', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $name);
            $m->set_charset('utf8mb4');
            $this->db = new MysqliDatabaseAdapter($m);
        }

        return $this->db;
    }
}
