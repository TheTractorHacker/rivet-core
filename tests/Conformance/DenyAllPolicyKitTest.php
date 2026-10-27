<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Support\DenyAllPolicy;
use RivetCore\Testing\AccessPolicyConformanceTestCase;

/** DenyAllPolicy is the extreme deny-by-default adapter. */
final class DenyAllPolicyKitTest extends AccessPolicyConformanceTestCase
{
    protected function policy(): AccessPolicyInterface
    {
        return new DenyAllPolicy();
    }

    protected function declaresDenyByDefault(): bool
    {
        return true;
    }

    protected function knownDenied(): array
    {
        return [[1, 'itsm.problem.close', 'problem', 5], [null, 'x.y']];
    }
}
