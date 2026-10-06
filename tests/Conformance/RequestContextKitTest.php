<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\RequestContextInterface;
use RivetCore\Testing\RequestContextConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedRequestContext;

/** The kit against a ServerRequestContext-like adapter (and the harness target for the request-context mutants). */
final class RequestContextKitTest extends RequestContextConformanceTestCase
{
    use Flaw;

    protected function context(): RequestContextInterface
    {
        return new FlawedRequestContext(self::$flaw);
    }
}
