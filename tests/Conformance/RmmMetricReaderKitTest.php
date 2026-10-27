<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\RmmMetricReaderInterface;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Testing\RmmMetricReaderConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedRmmMetricReader;

/** The reader kit against the in-memory sink (and the harness target for the reader mutants). */
final class RmmMetricReaderKitTest extends RmmMetricReaderConformanceTestCase
{
    use Flaw;

    private ?FlawedRmmMetricReader $store = null;

    protected function store(): RmmMetricSinkInterface&RmmMetricReaderInterface
    {
        return $this->store ??= new FlawedRmmMetricReader(self::$flaw);
    }
}
