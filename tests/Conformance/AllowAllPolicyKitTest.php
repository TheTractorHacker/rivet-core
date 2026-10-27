<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Support\AllowAllPolicy;
use RivetCore\Testing\AccessPolicyConformanceTestCase;

/** AllowAllPolicy does not declare deny-by-default, so it passes only the always-on checks. */
final class AllowAllPolicyKitTest extends AccessPolicyConformanceTestCase
{
    protected function policy(): AccessPolicyInterface
    {
        return new AllowAllPolicy();
    }

    protected function knownAllowed(): array
    {
        return [[1, 'anything.at.all', 'problem', 5], [null, 'x.y']];
    }
}
