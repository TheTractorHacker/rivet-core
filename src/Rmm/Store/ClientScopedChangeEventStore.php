<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * A {@see ChangeEventStore} that stores the device's client with every record and in its ledger (rc.40, phase 2 of #89), so a search and the scoped fleet count need no
 * join with the control database, and that can follow a device that changes client. Both Core stores implement it. A separate interface so the rc.39 contract of
 * {@see ChangeEventStore} is unchanged.
 *
 * @api
 */
interface ClientScopedChangeEventStore extends ChangeEventStore
{
    /**
     * As {@see ChangeEventStore::admit()}, storing $clientId with each record and in the ledger.
     *
     * @param int|null $clientId the device's client (null = unknown)
     */
    public function admit(int $deviceId, array $records, array $caps, int $nowTs, int $rejected, int $agentDropped, string $configVersion, ?string $monitorJson, ?int $clientId = null): array;

    /** The device moved to another client: give its stored records and its ledger the new one. Returns the records changed. */
    public function reassignClient(int $deviceId, int $clientId): int;
}
