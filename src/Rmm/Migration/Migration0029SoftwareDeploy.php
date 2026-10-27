<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * RMM Phase 5 (software deployment): the allowed download hosts, the package catalog with its versions, the assignments, the per-device state, the history and
 * the job ledger ({@see DeploySchema}). Purely additive and idempotent: every statement is CREATE TABLE IF NOT EXISTS and no row is inserted. The tables cost
 * nothing until the `software_deploy` sub-switch is on.
 *
 * @internal
 */
final class Migration0029SoftwareDeploy implements MigrationInterface
{
    public function id(): string
    {
        return '0029_rmm_software_deploy';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (DeploySchema::tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
