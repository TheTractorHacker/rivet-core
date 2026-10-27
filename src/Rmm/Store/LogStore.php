<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * HOT store: collected event-log and syslog entries (`endpoint_agent_log_events`) together with the per-device ledger that counts them
 * (`endpoint_agent_log_state`: stored rows, dropped total, the one-minute budget window). The ledger lives WITH the entries because admitting a batch
 * must read and update it in one atomic step with the insert.
 *
 * Admission rules (checked by the contract test): an entry past the per-minute budget, the device's row cap (plus 20 %) or the fleet's row cap (plus 10 %)
 * is counted, not stored; an entry that repeats (same device, time, level, source, code and text) is stored once and is not an error.
 *
 * (Since rc.40 a store that also implements {@see ClientScopedLogStore} stores the client with each entry.) A store never joins the control table `endpoint_agent_devices`: scoping by client uses the `client_id` stored with each entry, and the
 * caller adds host names to the rows it gets back.
 *
 * @api
 */
interface LogStore
{
    /**
     * Admit and store one cleaned batch for a device, atomically with the ledger update.
     *
     * @param list<array{ts:int,level:int,source:string,code:?int,message:string,hash:string}> $entries `hash` is the 32 hex characters of the dedupe digest (16 bytes)
     * @param array{rate_per_min:int,device_max_rows:int,fleet_max_rows:int} $caps
     * @param int $rejected entries the caller already dropped as malformed (added to the returned `dropped`)
     * @param int $agentDropped entries the agent says it dropped itself (added to the ledger's dropped total, not to the returned `dropped`)
     * @return array{inserted:int,dropped:int,kept:list<array{ts:int,level:int,source:string,code:?int,message:string,hash:string}>} `kept` are the entries admitted (some may have been duplicates)
     */
    public function admit(int $deviceId, array $entries, array $caps, int $nowTs, int $rejected, int $agentDropped): array;

    /**
     * @return array{rows:list<array<string,mixed>>,total:int} newest first; rows carry event_id, device_id, client_id (as stored with the entry; null for a row written
     *         before the client was recorded), occurred_at, level, source, code, message; the host name is NOT here (the control database has it: the caller adds it);
     *         `total` is capped at 10001
     */
    public function search(LogCriteria $criteria, int $limit, int $offset): array;

    /**
     * How many entries of one device match, counting at most $stopAt (a rule threshold plus one).
     *
     * @param int $maxLevel this severity number or a more severe one
     */
    public function countMatching(int $deviceId, string $sinceUtc, int $maxLevel, string $sourceContains, ?int $code, string $messageContains, int $stopAt): int;

    /** Stored entries across the fleet, from the ledger. */
    public function fleetRowCount(): int;

    /**
     * The ledger of one device, or null when it never sent a batch.
     *
     * @return array{rows:int,dropped_total:int,last_event_at:?string,last_batch_at:?string}|null
     */
    public function deviceLedger(int $deviceId): ?array;

    /** The occurred_at (UTC database datetime) of the oldest stored entry, of one device or of the fleet; null when there is none. */
    public function oldestEventAt(?int $deviceId = null): ?string;

    /**
     * Delete what is past retention, then trim every device above its cap, then the fleet above its cap, in batches (each batch with its ledger update, atomically).
     *
     * @param int $runCap rows the whole call may delete
     * @param int $batch rows per batch
     * @param (\Closure(int):void)|null $pause called between batches with the microseconds to wait
     * @return array{retention:int,device_cap:int,fleet_cap:int}
     */
    public function enforceLimits(string $cutoffUtc, int $deviceMaxRows, int $fleetMaxRows, int $runCap, int $batch, ?\Closure $pause = null): array;
}
