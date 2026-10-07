<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * Clients, locations and per-user client scope. RivetIT calls clients "departments", RivetMSP "clients"; both store them as
 * clients.client_id / locations.location_client_id, so the contract speaks of clients.
 *
 * @api
 */
interface RmmTenancyInterface
{
    /**
     * Client ids the user may see devices for, or null when unrestricted.
     * null: administrators and users without any per-client restriction rows.
     * []: restricted to nothing. Client id 0 (no client) is always treated as visible by Core and is not listed here.
     *
     * @return list<int>|null
     */
    public function visibleClientIds(int $userId): ?array;

    /** Display name of a client, or null when no such client exists (this doubles as the existence check). */
    public function clientName(int $clientId): ?string;

    /** True when the location exists and belongs to the client (0 is "no location" and is handled by Core). */
    public function locationInClient(int $locationId, int $clientId): bool;
}
