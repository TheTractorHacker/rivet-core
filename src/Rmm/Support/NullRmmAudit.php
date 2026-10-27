<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Support;

use RivetCore\Rmm\Contracts\RmmAuditInterface;

/**
 * Discards every record (tests, or an edition that audits elsewhere).
 *
 * @api
 */
final class NullRmmAudit implements RmmAuditInterface
{
    public function record(string $action, string $description, int $clientId, int $entityId): void
    {
    }
}
