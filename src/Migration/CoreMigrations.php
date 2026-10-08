<?php

declare(strict_types=1);

namespace RivetCore\Migration;

use RivetCore\Audit\Migration\Migration0001AuditEvents;
use RivetCore\Jobs\Migration\Migration0002IntegrationJobs;
use RivetCore\Jobs\Migration\Migration0012JobHeartbeat;
use RivetCore\Automation\Migration\Migration0006AutomationRules;
use RivetCore\Compliance\Migration\Migration0008Compliance;
use RivetCore\Compliance\Migration\Migration0009SharedReport;
use RivetCore\Compliance\Migration\Migration0010Subjects;
use RivetCore\Compliance\Migration\Migration0011Responsibilities;
use RivetCore\ITSM\Migration\Migration0004ProblemsAndChanges;
use RivetCore\Mcp\Migration\Migration0003McpUnlinkedIdentities;
use RivetCore\Mcp\Migration\Migration0017McpIdentityBinaryCollation;
use RivetCore\Retention\Migration\Migration0013RetentionIndexes;
use RivetCore\Rmm\Migration\Migration0014EndpointAgent;
use RivetCore\Rmm\Migration\Migration0015EndpointAgentConverge;
use RivetCore\Rmm\Migration\Migration0016ModuleSwitches;
use RivetCore\Webhooks\Migration\Migration0005WebhookDeliveries;
use RivetCore\Workflow\Migration\Migration0007WorkflowTables;

/** The ordered list of every Core-owned migration. Append only.
 *
 * @api
 */
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
            new Migration0010Subjects(),
            new Migration0011Responsibilities(),
            new Migration0012JobHeartbeat(),
            new Migration0013RetentionIndexes(),
            new Migration0014EndpointAgent(),
            new Migration0015EndpointAgentConverge(),
            new Migration0016ModuleSwitches(),
            new Migration0017McpIdentityBinaryCollation(),
        ];
    }
}
