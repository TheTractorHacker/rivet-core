<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Testing\RmmMetricSinkConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedRmmMetricSink;

/** The kit against the in-memory sink (and the harness target for the sink mutants). */
final class RmmMetricSinkKitTest extends RmmMetricSinkConformanceTestCase
{
    use Flaw;

    private ?FlawedRmmMetricSink $sink = null;

    private function store(): FlawedRmmMetricSink
    {
        return $this->sink ??= new FlawedRmmMetricSink(self::$flaw);
    }

    protected function sink(): RmmMetricSinkInterface
    {
        return $this->store();
    }

    protected function stored(int $assetId): ?array
    {
        $out = [];
        foreach ($this->store()->stored() as $s) {
            if ($s['asset_id'] === $assetId) {
                $out[] = ['key' => $s['key'], 'value' => $s['value']];
            }
        }

        return $out;
    }
}
