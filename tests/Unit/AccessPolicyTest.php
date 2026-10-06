<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Contracts\AccessDenied;
use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Support\AllowAllPolicy;
use RivetCore\Support\DenyAllPolicy;

final class AccessPolicyTest extends TestCase
{
    public function testBuiltInPolicies(): void
    {
        $this->assertTrue((new AllowAllPolicy())->can(1, 'x.y'));
        $this->assertFalse((new DenyAllPolicy())->can(1, 'x.y', 'problem', 5, ['client_id' => 2]));
    }

    public function testUnlessThrowsWithDetailsAndPassesWhenAllowed(): void
    {
        AccessDenied::unless(new AllowAllPolicy(), null, 'automation.rule.execute');
        try {
            AccessDenied::unless(new DenyAllPolicy(), 7, 'itsm.problem.close', 'problem', 12);
            $this->fail('expected AccessDenied');
        } catch (AccessDenied $e) {
            $this->assertSame('itsm.problem.close', $e->ability);
            $this->assertSame('problem', $e->subjectType);
            $this->assertSame(12, $e->subjectId);
            $this->assertStringContainsString('problem #12', $e->getMessage());
        }
    }

    public function testEditionPolicyReceivesSubjectAndContext(): void
    {
        $policy = new class () implements AccessPolicyInterface {
            public array $seen = [];

            public function can(?int $userId, string $ability, ?string $subjectType = null, string|int|null $subjectId = null, array $context = []): bool
            {
                $this->seen = [$userId, $ability, $subjectType, $subjectId, $context];

                return ($context['client_id'] ?? 0) === 3;
            }
        };
        $this->assertTrue($policy->can(9, 'workflow.run.start', 'client', 3, ['client_id' => 3]));
        $this->assertFalse($policy->can(9, 'workflow.run.start', 'client', 4, ['client_id' => 4]));
        $this->assertSame([9, 'workflow.run.start', 'client', 4, ['client_id' => 4]], $policy->seen);
    }
}
