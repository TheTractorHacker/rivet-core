<?php

declare(strict_types=1);

namespace RivetCore\Migration;

use RivetCore\Audit\Migration\Migration0001AuditEvents;
use RivetCore\Jobs\Migration\Migration0002IntegrationJobs;
use RivetCore\Automation\Migration\Migration0006AutomationRules;
use RivetCore\Compliance\Migration\Migration0008Compliance;
use RivetCore\Compliance\Migration\Migration0009SharedReport;
use RivetCore\ITSM\Migration\Migration0004ProblemsAndChanges;
use RivetCore\Mcp\Migration\Migration0003McpUnlinkedIdentities;
use RivetCore\Webhooks\Migration\Migration0005WebhookDeliveries;
use RivetCore\Workflow\Migration\Migration0007WorkflowTables;

/** The ordered list of every Core-owned migration. Append only. */
final class CoreMigrations
{
    /** @return list<MigrationInterface> */
    public static function all(): array
    {
        return [
            new Migration0001AuditEvents(),
            new Migration0002IntegrationJobs(),
            new Migration0003McpUnlinkedIdentities(),
            new Migration0004ProblemsAndChanges(),
            new Migration0005WebhookDeliveries(),
            new Migration0006AutomationRules(),
            new Migration0007WorkflowTables(),
            new Migration0008Compliance(),
            new Migration0009SharedReport(),
        ];
    }
}
