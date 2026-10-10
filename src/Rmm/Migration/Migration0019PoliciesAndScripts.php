<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * RMM Phase 2: policies and assignments, the script library with signed versions, scheduled scripts, approvals and custom fields
 * (twelve new tables, see {@see RmmSchema::phase2Tables()}). Purely additive and idempotent: every statement is CREATE TABLE IF NOT
 * EXISTS and nothing existing is altered, so a second run, or an install that already has some of the tables, changes nothing. The
 * tables stay empty (and cost nothing) until the matching feature is switched on and used.
 *
 * @internal
 */
final class Migration0019PoliciesAndScripts implements MigrationInterface
{
    public function id(): string
    {
        return '0019_rmm_policies_and_scripts';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (RmmSchema::phase2Tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
