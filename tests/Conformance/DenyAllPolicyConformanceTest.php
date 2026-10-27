<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Support\DenyAllPolicy;

/** The DenyAllPolicy shipped in src/Support passes the kit (the safe default an edition can start from). */
final class DenyAllPolicyConformanceTest extends AccessPolicyConformanceTestCase
{
    protected function policy(): AccessPolicyInterface
    {
        return new DenyAllPolicy();
    }

    protected function unprivilegedUserId(): int
    {
        return 1;
    }
}
