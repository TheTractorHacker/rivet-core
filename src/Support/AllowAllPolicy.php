<?php

declare(strict_types=1);

namespace RivetCore\Support;

use RivetCore\Contracts\AccessPolicyInterface;

/** Allows everything: for editions that authorize at their own call sites, and for tests. Use deliberately. @api */
final class AllowAllPolicy implements AccessPolicyInterface
{
    public function can(?int $userId, string $ability, ?string $subjectType = null, string|int|null $subjectId = null, array $context = []): bool
    {
        return true;
    }
}
