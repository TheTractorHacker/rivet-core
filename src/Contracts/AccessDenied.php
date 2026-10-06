<?php

declare(strict_types=1);

namespace RivetCore\Contracts;

/** Thrown by a service when its {@see AccessPolicyInterface} refuses an action. @api */
final class AccessDenied extends \RuntimeException
{
    public function __construct(
        public readonly string $ability,
        public readonly ?string $subjectType = null,
        public readonly string|int|null $subjectId = null,
    ) {
        parent::__construct('Not allowed: ' . $ability . ($subjectType !== null ? " on $subjectType" . ($subjectId !== null ? " #$subjectId" : '') : ''));
    }

    /** Convenience for services: ask the policy and throw when it says no. */
    public static function unless(AccessPolicyInterface $policy, ?int $userId, string $ability, ?string $subjectType = null, string|int|null $subjectId = null, array $context = []): void
    {
        if (!$policy->can($userId, $ability, $subjectType, $subjectId, $context)) {
            throw new self($ability, $subjectType, $subjectId);
        }
    }
}
