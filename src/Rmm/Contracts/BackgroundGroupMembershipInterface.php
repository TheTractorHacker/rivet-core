<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * Whether a user belongs to the named role or group of Background Mode's Internal policy. Core has no groups or roles of its own, so the edition maps the name to
 * its own role or group (RivetIT: a `user_roles` role). Must return false for an unknown user, an inactive user or an unknown group, and never grant on an empty name.
 *
 * @api
 */
interface BackgroundGroupMembershipInterface
{
    public function isMember(int $userId, string $group): bool;
}
