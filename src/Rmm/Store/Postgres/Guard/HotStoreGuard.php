<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres\Guard;

use RivetCore\Database\DatabaseException;
use RivetCore\Rmm\Store\HotStoreUnavailableException;
use RivetCore\Rmm\Store\Postgres\PostgresException;

/**
 * Decides what a failure of the PostgreSQL store means to its caller (docs/design/rmm-data-store.md, "Degraded behaviour"). Only storage failures
 * ({@see DatabaseException}: the server is down, the schema is missing, a statement was rejected) are handled here; a programming error (TypeError, LogicException)
 * still surfaces, because swallowing it would hide a bug.
 *
 *  - {@see lossy()}: a write on the check-in or job-result path. NEVER throws: the data is dropped, counted ({@see HotStoreFaults}) and logged, and the caller carries on.
 *    While a control database transaction is open the write is held until it commits ({@see WriteBehind}), and dropped if it rolls back.
 *  - {@see strict()}: a write whose sender can try again (log and change batches) or whose caller records the failure and repeats it ({@see \RivetCore\Rmm\Store\ClientMoves}). Throws {@see HotStoreUnavailableException}; the service answers a retryable 503.
 *  - {@see read()}: a read. Throws {@see HotStoreUnavailableException} with a message an administrator can act on.
 *  - {@see maintenance()}: retention and rollup jobs. Never throws; returns the neutral answer; the failure is counted.
 *
 * @internal
 */
final class HotStoreGuard
{
    private const LOG_EVERY_S = 60;

    /** @var array<string,int> store => time of the last error_log line */
    private array $logged = [];

    /**
     * @param (\Closure(): int)|null $now clock seam for tests
     * @param WriteBehind|null $behind where {@see lossy()} writes wait while a control database transaction is open (null = they run at once)
     */
    public function __construct(private readonly HotStoreFaults $faults, private readonly ?\Closure $now = null, private readonly ?WriteBehind $behind = null)
    {
    }

    /**
     * @template T
     * @param \Closure(): T $do
     * @param T|null $onFail
     * @return T|null
     */
    public function lossy(string $store, string $op, \Closure $do, mixed $onFail = null, int $items = 1): mixed
    {
        if ($this->behind?->open()) {
            // a control transaction is open: the write waits for its commit (WriteBehind); the answer of a lossy write is never used
            $this->behind->defer(function () use ($store, $op, $do, $items): void {
                $this->lossy($store, $op, $do, null, $items);
            });

            return $onFail;
        }
        try {
            return $do();
        } catch (DatabaseException $e) {
            $this->fault($store, $op, $e, $items);

            return $onFail;
        }
    }

    /**
     * @template T
     * @param \Closure(): T $do
     * @return T
     * @throws HotStoreUnavailableException
     */
    public function strict(string $store, string $op, \Closure $do): mixed
    {
        try {
            return $do();
        } catch (DatabaseException $e) {
            throw $this->fault($store, $op, $e, 0);
        }
    }

    /**
     * @template T
     * @param \Closure(): T $do
     * @return T
     * @throws HotStoreUnavailableException
     */
    public function read(string $store, string $op, \Closure $do): mixed
    {
        return $this->strict($store, $op, $do);
    }

    /**
     * @template T
     * @param \Closure(): T $do
     * @param T $onFail
     * @return T
     */
    public function maintenance(string $store, string $op, \Closure $do, mixed $onFail): mixed
    {
        try {
            return $do();
        } catch (DatabaseException $e) {
            $this->fault($store, $op, $e, 0);

            return $onFail;
        }
    }

    private function fault(string $store, string $op, DatabaseException $e, int $items): HotStoreUnavailableException
    {
        $cause = $e instanceof HotStoreUnavailableException ? $e->getMessage() : self::describe($e);
        $message = 'The RMM data store (PostgreSQL) is unavailable for ' . $store . '.' . $op . ': ' . $cause;
        $this->faults->note($store, $items, $store . '.' . $op . ': ' . $cause);
        $t = $this->now !== null ? ($this->now)() : time();
        if (($this->logged[$store] ?? 0) + self::LOG_EVERY_S <= $t) {
            $this->logged[$store] = $t;
            error_log('rmm data store degraded: ' . $message);
        }

        return new HotStoreUnavailableException($message, 0, $e);
    }

    private static function describe(DatabaseException $e): string
    {
        $hint = '';
        if ($e instanceof PostgresException && in_array($e->sqlState(), ['42P01', '3F000'], true)) {
            $hint = ' The PostgreSQL schema is missing: run the PostgreSQL migration (docs/rmm/POSTGRES.md).';
        }

        return rtrim($e->getMessage(), '. ') . '.' . $hint;
    }
}
