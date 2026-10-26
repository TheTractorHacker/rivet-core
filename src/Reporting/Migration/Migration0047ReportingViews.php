<?php

declare(strict_types=1);

namespace RivetCore\Reporting\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;
use RivetCore\Reporting\ReportingSchema;

/**
 * #91 phase 2: the `rpt_` reporting views in the control database. Only views are created: no table, column or row is added or changed, so code before the
 * migration is safe and a rollback leaves nothing but unused views (`DROP VIEW rpt_...`). Idempotent (`CREATE OR REPLACE VIEW`).
 *
 * The user that runs the migration owns the views (`SQL SECURITY DEFINER`): keep that account (or re-run the migration as the new owner) for as long as the views are used.
 *
 * @internal
 */
final class Migration0047ReportingViews implements MigrationInterface
{
    public function id(): string
    {
        return '0047_reporting_views';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (ReportingSchema::mariadbStatements() as $sql) {
            $database->execute($sql);
        }
    }
}
