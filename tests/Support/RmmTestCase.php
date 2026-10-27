<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use PHPUnit\Framework\TestCase;

/** Base class of the RMM integration tests: skipped without a scratch database, otherwise a fresh {@see RmmHarness} per test. */
abstract class RmmTestCase extends TestCase
{
    protected RmmHarness $h;
    private string $previousLog = '';

    protected function setUp(): void
    {
        if (!getenv('RIVETCORE_TEST_DB_NAME')) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        if (!str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
            $this->markTestSkipped('The test database name must contain "scratch".');
        }
        // The module logs the details of an internal error (never sent to the device); keep the test output clean.
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');
        $this->h = $this->makeHarness();
    }

    protected function tearDown(): void
    {
        RmmHarness::closeAll();
        ini_set('error_log', $this->previousLog);
    }

    protected function makeHarness(): RmmHarness
    {
        return new RmmHarness();
    }
}
