<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Tests\Conformance\Reference\RoleAccessPolicy;

final class RoleAccessPolicyConformanceTest extends AccessPolicyConformanceTestCase
{
    protected function policy(): AccessPolicyInterface
    {
        return new RoleAccessPolicy([1 => ['itsm.problem.close'], 2 => []]);
    }

    protected function unprivilegedUserId(): int
    {
        return 2;
    }

    public function testAGrantedAbilityIsAllowedAndOnlyThat(): void
    {
        $p = $this->policy();
        $this->assertTrue($p->can(1, 'itsm.problem.close', 'problem', 3));
        $this->assertFalse($p->can(1, 'itsm.problem.delete', 'problem', 3));
        $this->assertFalse($p->can(null, 'itsm.problem.close'));
    }
}
