<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Contracts\AccessPolicyInterface;

/**
 * A small deny-by-default policy over a rule table, or one with a named flaw: throws_unknown_subject, null_user_throws,
 * system_bypass, context_elevates, nondeterministic, ignores_user, allow_all.
 */
final class RuleBasedPolicy implements AccessPolicyInterface
{
    /** @var array<string,list<int|null>> ability => users (null = system actor) allowed to do it */
    private const RULES = [
        'itsm.problem.close' => [1],
        'workflow.run.start' => [1, 7],
        'automation.rule.execute' => [null],
    ];

    private int $calls = 0;

    public function __construct(private ?string $flaw = null)
    {
    }

    public function can(?int $userId, string $ability, ?string $subjectType = null, string|int|null $subjectId = null, array $context = []): bool
    {
        $this->calls++;
        switch ($this->flaw) {
            case 'throws_unknown_subject':
                if ($subjectType !== null && $subjectType !== 'problem' && $subjectType !== 'client') {
                    throw new \InvalidArgumentException("Unknown subject type $subjectType");
                }
                break;
            case 'null_user_throws':
                if ($userId === null) {
                    throw new \TypeError('userId required');
                }
                break;
            case 'system_bypass':
                if ($userId === null) {
                    return true;
                }
                break;
            case 'context_elevates':
                if (($context['is_admin'] ?? false) === true || ($context['role'] ?? '') === 'admin' || ($context['allowed'] ?? false) === true || ($context['bypass'] ?? 0) === 1) {
                    return true;
                }
                break;
            case 'nondeterministic':
                return $this->calls % 2 === 0 ? (self::RULES[$ability] ?? false) !== false && in_array($userId, self::RULES[$ability], true) : false;
            case 'ignores_user':
                return isset(self::RULES[$ability]);
            case 'allow_all':
                return true;
        }

        return in_array($userId, self::RULES[$ability] ?? [], true);
    }
}
