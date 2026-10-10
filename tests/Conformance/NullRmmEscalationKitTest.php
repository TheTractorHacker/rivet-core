<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\RmmEscalationInterface;
use RivetCore\Rmm\Support\NullRmmEscalation;
use RivetCore\Testing\RmmEscalationConformanceTestCase;

/** Core's own null adapter passes the kit: it delivers nothing and says so. */
final class NullRmmEscalationKitTest extends RmmEscalationConformanceTestCase
{
    protected function escalation(): RmmEscalationInterface
    {
        return new NullRmmEscalation();
    }

    protected function delivers(): bool
    {
        return false;
    }
}
