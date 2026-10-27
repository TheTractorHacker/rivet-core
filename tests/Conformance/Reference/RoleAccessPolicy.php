<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Contracts\AccessPolicyInterface;

/** Reference AccessPolicyInterface: explicit grants only, default deny. */
final class RoleAccessPolicy implements AccessPolicyInterface
{
    /** @param array<int, list<string>> $grants user id => abilities */
    public function __construct(private array $grants = [])
    {
    }

    public function can(?int $userId, string $ability, ?string $subjectType = null, string|int|null $subjectId = null, array $context = []): bool
    {
        if ($userId === null) {
            return false;   // a system actor must be granted explicitly by the edition, never implicitly
        }

        return in_array($ability, $this->grants[$userId] ?? [], true);
    }
}
