<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Contracts\AccessPolicyInterface;

/**
 * A stub AccessPolicy for tests: the listed users may do everything (or the listed abilities), everybody else nothing. It records
 * every question so a test can assert what Core asked.
 */
final class AllowUsersPolicy implements AccessPolicyInterface
{
    /** @var list<array{0:?int,1:string,2:?string,3:string|int|null}> */
    public array $asked = [];

    /**
     * @param array<int,list<string>|true> $grants user id => the abilities allowed, or true for all of them
     */
    public function __construct(private array $grants = [])
    {
    }

    /** @param list<string>|true $abilities */
    public function grant(int $userId, array|bool $abilities): void
    {
        $this->grants[$userId] = $abilities === false ? [] : ($abilities === true ? true : $abilities);
    }

    public function can(?int $userId, string $ability, ?string $subjectType = null, string|int|null $subjectId = null, array $context = []): bool
    {
        $this->asked[] = [$userId, $ability, $subjectType, $subjectId];
        if ($userId === null || !isset($this->grants[$userId])) {
            return false;
        }
        $g = $this->grants[$userId];

        return $g === true || in_array($ability, $g, true);
    }
}
