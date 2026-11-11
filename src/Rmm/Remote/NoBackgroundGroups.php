<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Contracts\BackgroundGroupMembershipInterface;

/** The default group lookup: nobody is a member, so Internal mode allows site administrators only until an edition supplies a real one. @api */
final class NoBackgroundGroups implements BackgroundGroupMembershipInterface
{
    public function isMember(int $userId, string $group): bool
    {
        return false;
    }
}
