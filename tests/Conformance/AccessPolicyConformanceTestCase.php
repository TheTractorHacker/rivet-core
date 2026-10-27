<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use RivetCore\Contracts\AccessPolicyInterface;

/**
 * Behaviour every AccessPolicyInterface adapter must have (ADR-003). Core asks, the edition answers; the safe default for
 * anything the edition does not know about is "no".
 */
abstract class AccessPolicyConformanceTestCase extends TestCase
{
    abstract protected function policy(): AccessPolicyInterface;

    /** A real user id that holds no special rights. */
    abstract protected function unprivilegedUserId(): int;

    public function testAnUnknownAbilityIsDeniedForAnOrdinaryUser(): void
    {
        $this->assertFalse($this->policy()->can($this->unprivilegedUserId(), 'rivetcore.conformance.never-granted'));
    }

    public function testAnUnknownUserIsDenied(): void
    {
        $this->assertFalse($this->policy()->can(987654321, 'rivetcore.conformance.never-granted', 'client', 1));
    }

    public function testAnswersAreBooleanAndNeverThrowForOddInput(): void
    {
        $p = $this->policy();
        foreach ([null, 0, -1, $this->unprivilegedUserId()] as $user) {
            foreach (['', 'x', str_repeat('a', 500), "a.b'c"] as $ability) {
                $this->assertIsBool($p->can($user, $ability, null, null, []));
                $this->assertIsBool($p->can($user, $ability, 'client', 'not-a-number', ['k' => ['nested']]));
            }
        }
    }

    public function testRepeatedQuestionsGiveTheSameAnswer(): void
    {
        $p = $this->policy();
        $this->assertSame(
            $p->can($this->unprivilegedUserId(), 'rivetcore.conformance.x', 'client', 7),
            $p->can($this->unprivilegedUserId(), 'rivetcore.conformance.x', 'client', 7)
        );
    }
}
