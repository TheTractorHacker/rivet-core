<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * One-method bridge to the edition's activity log (RivetIT: logAction('Endpoint Agent', ...)).
 *
 * @api
 */
interface RmmAuditInterface
{
    /** $action is a short title ("Enrolled", "Job Submitted"); $description is already free of secrets (scripts are logged as a hash). */
    public function record(string $action, string $description, int $clientId, int $entityId): void;
}
