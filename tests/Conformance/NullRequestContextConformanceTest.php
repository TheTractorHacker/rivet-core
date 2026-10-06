<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\RequestContextInterface;
use RivetCore\Support\NullRequestContext;

/** The CLI/cron context shipped in src/Support passes the kit too. */
final class NullRequestContextConformanceTest extends RequestContextConformanceTestCase
{
    protected function context(): RequestContextInterface
    {
        return new NullRequestContext();
    }
}
