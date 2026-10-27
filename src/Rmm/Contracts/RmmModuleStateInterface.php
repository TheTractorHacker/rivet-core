<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * Edition input to the module switch: the edition-level kill switch and where the zero-database state file lives.
 *
 * @api
 */
interface RmmModuleStateInterface
{
    /** Edition-level kill switch. RivetMSP: config_core_rmm_enabled; RivetIT: true. Must not throw; false on any failure. */
    public function editionAllows(): bool;

    /** Directory (writable by the web user, shared by all web nodes) where Core keeps the zero-DB state file, or null to disable the fast path. */
    public function stateDirectory(): ?string;
}
