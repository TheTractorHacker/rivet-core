<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Contracts\RmmMetricReaderInterface;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Testing\RmmMetricReaderConformanceTestCase;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\RmmHarness;

/** The reader conformance kit against Core's database sink on the scratch database. */
final class DatabaseMetricReaderKitTest extends RmmMetricReaderConformanceTestCase
{
    private ?DatabaseMetricSink $sink = null;

    protected function setUp(): void
    {
        if (!str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
            $this->markTestSkipped('A scratch database (RIVETCORE_TEST_DB_NAME) is required.');
        }
        $this->sink = new DatabaseMetricSink((new RmmHarness())->db, new MutableClock());
    }

    protected function tearDown(): void
    {
        RmmHarness::closeAll();
    }

    protected function store(): RmmMetricSinkInterface&RmmMetricReaderInterface
    {
        return $this->sink ?? throw new \LogicException('set up');
    }
}
