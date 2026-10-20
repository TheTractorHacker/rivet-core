<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * RMM hypervisor visibility (Proxmox VE, Hyper-V): the host, VM, storage and state-history tables ({@see HypervisorSchema}). Purely additive and idempotent: every statement is CREATE
 * TABLE IF NOT EXISTS and no row is inserted. The tables cost nothing until the `hypervisor` sub-switch is on. (0034 belongs to the Mesh work on another branch.)
 *
 * @internal
 */
final class Migration0035Hypervisors implements MigrationInterface
{
    public function id(): string
    {
        return '0035_rmm_hypervisors';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (HypervisorSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
