<?php

declare(strict_types=1);

namespace RivetCore\Support;

use RivetCore\Contracts\AccessPolicyInterface;

/** Refuses everything. For tests, and for proving a service really asks before it acts. @api */
final class DenyAllPolicy implements AccessPolicyInterface
{
    public function can(?int $userId, string $ability, ?string $subjectType = null, string|int|null $subjectId = null, array $context = []): bool
    {
        return false;
    }
}
