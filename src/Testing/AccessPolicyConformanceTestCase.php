<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Contracts\AccessDenied;
use RivetCore\Contracts\AccessPolicyInterface;

/**
 * Conformance kit for {@see AccessPolicyInterface} (ADR-003).
 *
 * Always checked: can() answers a bool and never throws, whatever the user (null = system actor, unknown ids), ability,
 * subject or context it is given, and answers the same way twice; the edition's own sample decisions
 * ({@see self::knownAllowed()} / {@see self::knownDenied()}) hold, including through {@see AccessDenied::unless()}.
 *
 * Opt in with {@see self::declaresDenyByDefault()} when the policy refuses abilities it does not know (recommended for any
 * policy that enforces something): unknown abilities are then denied for every user including the system actor, and no
 * context value can talk its way past that.
 *
 * @api
 */
abstract class AccessPolicyConformanceTestCase extends TestCase
{
    use UntypedValues;

    abstract protected function policy(): AccessPolicyInterface;

    /** Does this policy deny an ability it has no rule for? (An allow-everything policy returns false.) */
    protected function declaresDenyByDefault(): bool
    {
        return false;
    }

    /**
     * Sample decisions the edition knows to be allowed: [userId, ability, subjectType, subjectId, context] (trailing items optional).
     *
     * @return list<array{0:?int,1:string,2?:?string,3?:string|int|null,4?:array<string,mixed>}>
     */
    protected function knownAllowed(): array
    {
        return [];
    }

    /**
     * Sample decisions the edition knows to be denied, same shape as {@see self::knownAllowed()}.
     *
     * @return list<array{0:?int,1:string,2?:?string,3?:string|int|null,4?:array<string,mixed>}>
     */
    protected function knownDenied(): array
    {
        return [];
    }

    /** @return list<array{0:?int,1:string,2:?string,3:string|int|null,4:array<string,mixed>}> */
    private static function matrix(): array
    {
        $users = [null, 0, 1, -5, 2_147_483_647, PHP_INT_MAX];
        $abilities = ['itsm.problem.close', 'x', '', 'a.b.c.d.e.f.g', str_repeat('a.', 400), "clé.été.\u{1F600}", "x'; DROP TABLE users; --", 'workflow.run.start'];
        $types = [null, 'problem', 'nonexistent_type', '', "'; --"];
        $ids = [null, 0, -1, 7, 'abc', '', PHP_INT_MAX, "7' OR '1'='1"];
        $contexts = [[], ['client_id' => 3], ['client_id' => 'x', 'nested' => ['a' => [1, 2, null]]], ['agent' => null, 'user' => new \stdClass()]];
        $out = [];
        foreach ($users as $u) {
            foreach ($abilities as $a) {
                $i = count($out);
                $out[] = [$u, $a, $types[$i % count($types)], $ids[$i % count($ids)], $contexts[$i % count($contexts)]];
            }
        }
        // every subject type / id on its own as well
        foreach ($types as $t) {
            foreach ($ids as $id) {
                $out[] = [1, 'itsm.problem.close', $t, $id, []];
            }
        }

        return $out;
    }

    public function testNeverThrowsForAnyUserAbilitySubjectOrContext(): void
    {
        $policy = $this->policy();
        $n = 0;
        foreach (self::matrix() as [$u, $a, $t, $id, $ctx]) {
            try {
                $result = $policy->can($u, $a, $t, $id, $ctx);
            } catch (\Throwable $e) {
                $this->fail('can() threw ' . $e::class . ': ' . $e->getMessage() . ' for ' . json_encode([$u, substr($a, 0, 30), $t, $id], JSON_PARTIAL_OUTPUT_ON_ERROR));
            }
            $this->assertIsBool(self::untyped($result));
            $n++;
        }
        $this->assertGreaterThan(40, $n);
    }

    public function testNullUserIsAcceptedAsTheSystemActor(): void
    {
        $this->assertIsBool(self::untyped($this->policy()->can(null, 'automation.rule.execute')));
        $this->assertIsBool(self::untyped($this->policy()->can(null, 'itsm.problem.close', 'problem', 5, ['client_id' => 2])));
    }

    public function testTheSameQuestionGetsTheSameAnswer(): void
    {
        $policy = $this->policy();
        foreach (self::matrix() as [$u, $a, $t, $id, $ctx]) {
            $this->assertSame($policy->can($u, $a, $t, $id, $ctx), $policy->can($u, $a, $t, $id, $ctx), 'can() must be deterministic');
        }
        $this->assertSame($this->policy()->can(1, 'itsm.problem.close', 'problem', 5), $this->policy()->can(1, 'itsm.problem.close', 'problem', 5));
    }

    public function testTheEditionsSampleDecisionsHold(): void
    {
        $allowed = $this->knownAllowed();
        $denied = $this->knownDenied();
        if ($allowed === [] && $denied === []) {
            $this->markTestSkipped('knownAllowed()/knownDenied() are empty: no sample decisions to verify.');
        }
        $policy = $this->policy();
        foreach ($allowed as $case) {
            $this->assertTrue($policy->can($case[0], $case[1], $case[2] ?? null, $case[3] ?? null, $case[4] ?? []), 'expected ALLOW for ' . json_encode($case));
            AccessDenied::unless($policy, $case[0], $case[1], $case[2] ?? null, $case[3] ?? null, $case[4] ?? []);
        }
        foreach ($denied as $case) {
            $this->assertFalse($policy->can($case[0], $case[1], $case[2] ?? null, $case[3] ?? null, $case[4] ?? []), 'expected DENY for ' . json_encode($case));
            try {
                AccessDenied::unless($policy, $case[0], $case[1], $case[2] ?? null, $case[3] ?? null, $case[4] ?? []);
                $this->fail('AccessDenied::unless() must throw when can() says no');
            } catch (AccessDenied $e) {
                $this->assertSame($case[1], $e->ability);
            }
        }
    }

    public function testUnknownAbilitiesAreDeniedForEveryUser(): void
    {
        if (!$this->declaresDenyByDefault()) {
            $this->markTestSkipped('Edition did not declare deny-by-default (declaresDenyByDefault() === false).');
        }
        $policy = $this->policy();
        $unknown = ['conformance.unknown.' . bin2hex(random_bytes(4)), 'zzz', '', '*', 'admin', 'conformance.' . str_repeat('x', 300)];
        foreach ([null, 1, 2, 999_999_999] as $user) {
            foreach ($unknown as $ability) {
                $this->assertFalse($policy->can($user, $ability), 'an ability the policy has no rule for must be denied (user ' . var_export($user, true) . ", '" . substr($ability, 0, 30) . "')");
                $this->assertFalse($policy->can($user, $ability, 'problem', 5, ['client_id' => 1]));
            }
        }
    }

    public function testContextCannotElevateAnUnknownAbility(): void
    {
        if (!$this->declaresDenyByDefault()) {
            $this->markTestSkipped('Edition did not declare deny-by-default (declaresDenyByDefault() === false).');
        }
        $ability = 'conformance.unknown.' . bin2hex(random_bytes(4));
        foreach ([['is_admin' => true], ['role' => 'admin'], ['allowed' => true], ['bypass' => 1, 'system' => true], ['client_id' => 0]] as $ctx) {
            $this->assertFalse($this->policy()->can(1, $ability, 'client', 1, $ctx), 'context ' . json_encode($ctx) . ' must not grant an unknown ability');
        }
    }
}
