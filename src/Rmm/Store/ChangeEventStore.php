<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * HOT store: the change-monitor records (`endpoint_agent_change_records`) and the per-device ledger that counts them (`endpoint_agent_change_state`: stored rows,
 * dropped total, the one-minute window, the agent's configuration version and its last status block). Like {@see LogStore}, the ledger lives with the records
 * because admitting a batch reads and updates it atomically with the insert.
 *
 * The agent's own status block (`monitor_json`) is stored here only because it is written in the same statement as the counters.
 *
 * @api
 */
interface ChangeEventStore
{
    /**
     * Admit and store one cleaned batch for a device, atomically with the ledger update.
     *
     * @param list<array{ts:int,kind:string,watch:string,change:int,target:string,item:string,old:string,new:string,summary:string,old_hash:string,new_hash:string,actor:string,process:string,flags:int,dedupe:string}> $records `dedupe` is the 40 hex characters of the dedupe digest
     * @param array{rate_per_min:int,device_max_rows:int,fleet_max_rows:int} $caps
     * @param string $configVersion the agent's configuration version (16 hex characters) or '' to leave the stored one
     * @param string|null $monitorJson the agent's status block as JSON, or null to leave the stored one
     * @return array{inserted:int,dropped:int,kept:list<array<string,mixed>>} `kept` are the records admitted (some may have been duplicates)
     */
    public function admit(int $deviceId, array $records, array $caps, int $nowTs, int $rejected, int $agentDropped, string $configVersion, ?string $monitorJson): array;

    /**
     * Of a device's records received at exactly $receivedAtUtc, the dedupe digests (lower-case hex, at most 500): tells a resent batch from a new one.
     *
     * @return list<string>
     */
    public function dedupeKeysReceivedAt(int $deviceId, string $receivedAtUtc): array;

    /**
     * @return array{rows:list<array<string,mixed>>,total:int} newest first; rows are the stored record columns plus hostname and client_id; `total` is capped at 10001
     */
    public function search(ChangeCriteria $criteria, int $limit, int $offset): array;

    /**
     * One device's records between two UTC datetimes (inclusive), newest first, with who made them and a per-day count.
     *
     * @return array{rows:list<array<string,mixed>>,actors:list<array{actor:string,c:int}>,days:list<array{d:string,c:int}>} actors: top 20; days: newest 400
     */
    public function timeline(int $deviceId, string $fromUtc, string $toUtc, int $limit): array;

    /**
     * How many records of one device match, counting at most $stopAt (a rule threshold plus one).
     *
     * @param string $watch '' = any
     * @param string $kind '' = any
     * @param int $changeType 0 = any
     */
    public function countMatching(int $deviceId, string $sinceUtc, string $watch, string $kind, int $changeType, string $targetContains, string $itemContains, int $stopAt): int;

    /**
     * Stored records across the fleet, from the ledger.
     *
     * @param list<int>|null $visibleClientIds null = every client
     */
    public function fleetRowCount(?array $visibleClientIds = null): int;

    /**
     * The ledger of one device, or null when it never sent a batch.
     *
     * @return array{rows:int,dropped_total:int,last_change_at:?string,last_batch_at:?string,config_version:string,monitor_json:?string,monitor_at:?string}|null
     */
    public function deviceLedger(int $deviceId): ?array;

    /**
     * Records received since $sinceUtc per watch, most first, at most 30.
     *
     * @param list<int>|null $visibleClientIds null = every client
     * @return list<array{watch:string,c:int}>
     */
    public function countsByWatch(string $sinceUtc, ?array $visibleClientIds = null): array;

    /**
     * Fleet figures for the capacity report: stored rows and the devices that ever sent a batch.
     *
     * @return array{rows:int,devices:int}
     */
    public function ledgerTotals(): array;

    /** Whether any device has a ledger (the housekeeping keeps pruning after the feature is switched off while this is true). */
    public function hasLedger(): bool;

    /**
     * Delete what is past retention, then trim every device above its cap, then the fleet above its cap, in batches (each with its ledger update, atomically).
     *
     * @param (\Closure(int):void)|null $pause called between batches with the microseconds to wait
     * @return array{retention:int,device_cap:int,fleet_cap:int}
     */
    public function enforceLimits(string $cutoffUtc, int $deviceMaxRows, int $fleetMaxRows, int $runCap, int $batch, ?\Closure $pause = null): array;
}
