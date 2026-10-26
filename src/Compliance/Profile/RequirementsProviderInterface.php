<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Profile;

/**
 * Where the checks and the retention enforcement read the selected profile from. {@see FrameworkProfileStore} is the database-backed one;
 * {@see FixedRequirementsProvider} serves tests and editions that keep the choice elsewhere.
 *
 * @api
 */
interface RequirementsProviderInterface
{
    /** The profile this installation works to, or null when none is selected (nothing new is checked or enforced). */
    public function selectedProfile(): ?FrameworkProfile;
}
