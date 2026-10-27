<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Support;

use RivetCore\Audit\AuditService;
use RivetCore\Rmm\Contracts\RmmAuditInterface;

/**
 * The default {@see RmmAuditInterface} for editions with no legacy activity log: every record becomes an `audit_events` row of
 * type "endpoint_agent.<action>" through Core's {@see AuditService}. Device calls have no session user (actor null).
 *
 * @api
 */
final class AuditServiceRmmAudit implements RmmAuditInterface
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function record(string $action, string $description, int $clientId, int $entityId): void
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($action)), '_');
        $this->audit->log('endpoint_agent.' . $slug, null, $entityId > 0 ? 'asset' : null, $entityId > 0 ? $entityId : null, $action, $description, ['client_id' => $clientId]);
    }
}
