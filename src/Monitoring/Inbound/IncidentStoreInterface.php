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
 *  - locked() calls NEST for different keys: while it holds a monitor's lock the engine takes the lock of its ticket-cap counters (source
 *    {@see IncidentEngine::CAP_SOURCE}, keys "client:<id>", "unassigned", "all"; always in that order). GET_LOCK supports this (one named lock each).
 *  - The engine keeps those counters as ordinary rows of the store (source "_cap"): an admin view of the incidents should label or hide them.
 *  - Keys: the source is at most 32 characters; the monitor key is the monitor id (single-secret mode) or "<sender>/<monitor id>", at most 31.
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
