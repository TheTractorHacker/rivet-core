<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

use RivetCore\Database\DatabaseException;

/**
 * A HOT RMM store could not do what it was asked because its database is unreachable, not configured, or not migrated (the PostgreSQL store, phase 2 of #89).
 * Core turns it into a retryable `503 unavailable` where a device sent the data (logs, change records), so the agent keeps its batch and sends it again; a page or API
 * that reads history may catch it and show "history is unavailable". Check-ins and job results never see it: their history writes are dropped and counted instead
 * (docs/design/rmm-data-store.md, "Degraded behaviour"). The message is safe to show to an administrator: it names the store and the cause and carries no credential.
 *
 * @api
 */
final class HotStoreUnavailableException extends DatabaseException
{
}
