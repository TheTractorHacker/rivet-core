<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\RmmEventsInterface;
use RivetCore\Testing\RmmEventsConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedRmmEvents;

/** The kit against the in-memory bus (and the harness target for the bus mutants). */
final class RmmEventsKitTest extends RmmEventsConformanceTestCase
{
    use Flaw;

    private ?FlawedRmmEvents $bus = null;

    private function bus(): FlawedRmmEvents
    {
        return $this->bus ??= new FlawedRmmEvents(self::$flaw);
    }

    protected function events(): RmmEventsInterface
    {
        return $this->bus();
    }

    protected function received(): ?array
    {
        return $this->bus()->published();
    }
}
