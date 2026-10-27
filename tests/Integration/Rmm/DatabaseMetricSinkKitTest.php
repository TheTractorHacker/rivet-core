<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Testing\RmmMetricSinkConformanceTestCase;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\RmmHarness;

/** The sink conformance kit against Core's database sink on the scratch database. */
final class DatabaseMetricSinkKitTest extends RmmMetricSinkConformanceTestCase
{
    private ?RmmHarness $h = null;
    private ?DatabaseMetricSink $sink = null;

    protected function setUp(): void
    {
        if (!str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
            $this->markTestSkipped('A scratch database (RIVETCORE_TEST_DB_NAME) is required.');
        }
        $this->h = new RmmHarness();
        $this->sink = new DatabaseMetricSink($this->h->db, new MutableClock());
    }

    protected function tearDown(): void
    {
        RmmHarness::closeAll();
    }

    protected function sink(): RmmMetricSinkInterface
    {
        return $this->sink ?? throw new \LogicException('set up');
    }

    protected function stored(int $assetId): ?array
    {
        $out = [];
        foreach ($this->h?->rows("SELECT metric_key, value FROM rmm_metric_latest WHERE asset_id = $assetId ORDER BY metric_key") ?? [] as $r) {
            $out[] = ['key' => (string) $r['metric_key'], 'value' => (float) $r['value']];
        }

        return $out;
    }
}
