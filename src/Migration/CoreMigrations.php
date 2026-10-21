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
use RivetCore\Rmm\Migration\Migration0018InventoryFoundation;
use RivetCore\Rmm\Migration\Migration0019PoliciesAndScripts;
use RivetCore\Rmm\Migration\Migration0020AlertingMaturity;
use RivetCore\Rmm\Migration\Migration0021ScaleStorage;
use RivetCore\Rmm\Migration\Migration0022RemoteAndLogs;
use RivetCore\Rmm\Migration\Migration0023MeshAgent;
use RivetCore\Rmm\Migration\Migration0024MeshGroupUrl;
use RivetCore\Rmm\Migration\Migration0025MsiTemplates;
use RivetCore\Rmm\Migration\Migration0026MeshHide;
use RivetCore\Rmm\Migration\Migration0027MeshNodeIds;
use RivetCore\Rmm\Migration\Migration0028Patching;
use RivetCore\Rmm\Migration\Migration0029SoftwareDeploy;
use RivetCore\Rmm\Migration\Migration0030ChangeMonitor;
use RivetCore\Rmm\Migration\Migration0031ClockSkew;
use RivetCore\Rmm\Migration\Migration0032CheckSet;
use RivetCore\Rmm\Migration\Migration0033AutoInstall;
use RivetCore\Rmm\Migration\Migration0034MeshAfterApproval;
use RivetCore\Rmm\Migration\Migration0035DeviceType;
use RivetCore\Rmm\Migration\Migration0036Hypervisors;
use RivetCore\Rmm\Migration\Migration0037AgentOs;
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
            new Migration0018InventoryFoundation(),
            new Migration0019PoliciesAndScripts(),
            new Migration0020AlertingMaturity(),
            new Migration0021ScaleStorage(),
            new Migration0022RemoteAndLogs(),
            new Migration0023MeshAgent(),
            new Migration0024MeshGroupUrl(),
            new Migration0025MsiTemplates(),
            new Migration0026MeshHide(),
            new Migration0027MeshNodeIds(),
            new Migration0028Patching(),
            new Migration0029SoftwareDeploy(),
            new Migration0030ChangeMonitor(),
            new Migration0031ClockSkew(),
            new Migration0032CheckSet(),
            new Migration0033AutoInstall(),
            new Migration0034MeshAfterApproval(),
            new Migration0035DeviceType(),
            new Migration0036Hypervisors(),
            new Migration0037AgentOs(),
        ];
    }
}
