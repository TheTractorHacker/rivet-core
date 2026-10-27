<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Profile;

/**
 * A provider that answers the same profile every time (or null). Also a per-request memo: wrap a store with {@see self::memoize()} so the nine checks of one
 * assessment read the database once.
 *
 * @api
 */
final class FixedRequirementsProvider implements RequirementsProviderInterface
{
    public function __construct(private ?FrameworkProfile $profile)
    {
    }

    public static function memoize(RequirementsProviderInterface $source): self
    {
        return new self($source->selectedProfile());
    }

    public function selectedProfile(): ?FrameworkProfile
    {
        return $this->profile;
    }
}
