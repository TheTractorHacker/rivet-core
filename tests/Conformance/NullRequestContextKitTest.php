<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\RequestContextInterface;
use RivetCore\Support\NullRequestContext;
use RivetCore\Testing\RequestContextConformanceTestCase;

/** Core's own NullRequestContext (all fields null) is a valid, if uninformative, adapter. */
final class NullRequestContextKitTest extends RequestContextConformanceTestCase
{
    protected function context(): RequestContextInterface
    {
        return new NullRequestContext();
    }
}
