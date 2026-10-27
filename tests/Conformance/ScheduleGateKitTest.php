<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\ScheduleGateInterface;
use RivetCore\Rmm\Scripts\OpenScheduleGate;
use RivetCore\Testing\ScheduleGateConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedScheduleGate;

/** The kit against the default (open) gate, and against a rule-based one that is also the harness target for the gate mutants. */
final class ScheduleGateKitTest extends ScheduleGateConformanceTestCase
{
    use Flaw;

    private ?ScheduleGateInterface $gate = null;

    protected function gate(): ScheduleGateInterface
    {
        return $this->gate ??= self::$flaw === null ? new OpenScheduleGate() : new FlawedScheduleGate(self::$flaw);
    }
}
