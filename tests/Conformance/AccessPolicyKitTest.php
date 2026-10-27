<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Testing\AccessPolicyConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\RuleBasedPolicy;

/** The kit against a deny-by-default rule table (and the harness target for the policy mutants). */
final class AccessPolicyKitTest extends AccessPolicyConformanceTestCase
{
    use Flaw;

    protected function policy(): AccessPolicyInterface
    {
        return new RuleBasedPolicy(self::$flaw);
    }

    protected function declaresDenyByDefault(): bool
    {
        return true;
    }

    protected function knownAllowed(): array
    {
        return [[1, 'itsm.problem.close', 'problem', 5], [null, 'automation.rule.execute'], [7, 'workflow.run.start', 'client', 3, ['client_id' => 3]]];
    }

    protected function knownDenied(): array
    {
        return [[2, 'itsm.problem.close', 'problem', 5], [1, 'automation.rule.execute'], [null, 'itsm.problem.close']];
    }
}
