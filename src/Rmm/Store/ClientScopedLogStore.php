<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * A {@see LogStore} that stores the device's client with every entry (rc.40, phase 2 of #89), so a search can be scoped by client without joining the control
 * database, and that can follow a device that changes client. Both Core stores implement it. A separate interface so the rc.39 contract of {@see LogStore} is unchanged.
 *
 * @api
 */
interface ClientScopedLogStore extends LogStore
{
    /**
     * As {@see LogStore::admit()}, storing $clientId with each entry.
     *
     * @param int|null $clientId the device's client (null = unknown: the rows are visible to an unscoped reader only)
     */
    public function admit(int $deviceId, array $entries, array $caps, int $nowTs, int $rejected, int $agentDropped, ?int $clientId = null): array;

    /** The device moved to another client: give its stored entries the new one. Returns the entries changed. */
    public function reassignClient(int $deviceId, int $clientId): int;
}
