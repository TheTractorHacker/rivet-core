<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\RmmEscalationInterface;
use RivetCore\Testing\RmmEscalationConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedRmmEscalation;

/** The kit against the in-memory adapter (and the harness target for the escalation mutants). */
final class RmmEscalationKitTest extends RmmEscalationConformanceTestCase
{
    use Flaw;

    private ?FlawedRmmEscalation $adapter = null;

    private function adapter(): FlawedRmmEscalation
    {
        return $this->adapter ??= new FlawedRmmEscalation(self::$flaw);
    }

    protected function escalation(): RmmEscalationInterface
    {
        return $this->adapter();
    }

    protected function notifications(): ?array
    {
        return $this->adapter()->notifications();
    }
}
