<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * Persistence of {@see Incident} rows, keyed by (source, monitorKey), plus the per-key mutual exclusion the engine needs so two
 * simultaneous deliveries for one monitor cannot both open a ticket.
 *
 * Contract (see {@see \RivetCore\Testing\IncidentStoreConformanceTestCase}):
 *  - get() returns null for an unknown key, otherwise an Incident equal to what put() stored (all fields, nulls included).
 *  - put() creates or replaces; keys with different source or monitorKey never collide.
 *  - locked() runs $fn while holding a lock on that key (e.g. MariaDB GET_LOCK, or SELECT ... FOR UPDATE on a transaction), returns
 *    its result, releases the lock when $fn throws and rethrows. Different keys do not block each other. A store with a single
 *    process may simply call $fn.
 *
 * @api
 */
interface IncidentStoreInterface
{
    public function get(string $source, string $monitorKey): ?Incident;

    public function put(Incident $incident): void;

    /**
     * @template T
     * @param \Closure():T $fn
     * @return T
     */
    public function locked(string $source, string $monitorKey, \Closure $fn): mixed;
}
