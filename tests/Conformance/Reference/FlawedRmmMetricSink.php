<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Testing\InMemoryRmmMetricSink;

/** The reference sink with an optional named flaw: clamps, keeps_first_only, throws_empty. */
final class FlawedRmmMetricSink extends InMemoryRmmMetricSink
{
    public function __construct(private ?string $flaw = null)
    {
    }

    public function ingest(array $samples, int $integrationId): void
    {
        if ($this->flaw === 'throws_empty' && $samples === []) {
            throw new \InvalidArgumentException('nothing to ingest');
        }
        if ($this->flaw === 'clamps') {
            foreach ($samples as &$s) {
                if (str_ends_with($s['key'], '.utilization')) {
                    $s['value'] = max(0, min(100, $s['value']));
                }
            }
            unset($s);
        }
        if ($this->flaw === 'keeps_first_only') {
            $samples = array_slice($samples, 0, 1);
        }
        parent::ingest($samples, $integrationId);
    }
}
