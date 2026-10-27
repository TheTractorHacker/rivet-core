<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Testing\InMemoryRmmMetricSink;

/** The in-memory sink that also counts its calls and can be told to fail (the queued-ingest tests). */
final class CountingMetricSink extends InMemoryRmmMetricSink
{
    public int $calls = 0;
    /** @var list<int> samples per call */
    public array $callSizes = [];
    public int $failures = 0;

    public function ingest(array $samples, int $integrationId): void
    {
        if ($this->failures > 0) {
            --$this->failures;
            throw new \RuntimeException('sink unavailable');
        }
        ++$this->calls;
        $this->callSizes[] = count($samples);
        parent::ingest($samples, $integrationId);
    }
}
