<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\RmmModuleStateInterface;
use RivetCore\Testing\RmmModuleStateConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedRmmModuleState;

/** The kit against the in-memory module state (and the harness target for the module-state mutants). */
final class RmmModuleStateKitTest extends RmmModuleStateConformanceTestCase
{
    use Flaw;

    protected function state(): RmmModuleStateInterface
    {
        return new FlawedRmmModuleState(self::$flaw);
    }
}
