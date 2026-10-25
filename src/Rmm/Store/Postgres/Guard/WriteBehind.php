<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres\Guard;

/**
 * Holds the history writes of a request until the control database transaction they ran in has committed.
 *
 * A check-in runs in ONE control-database transaction that holds the device row locked from the first statement to the commit, and the history writes (metrics, check
 * results, software and hypervisor changes) happen inside it. On MariaDB that is the same database and the same transaction, as it always was. With the hot store in another
 * database, a write made there while the transaction is open would make the device row wait for PostgreSQL's latency, or for a connect timeout when it is down, and would
 * write history for a check-in that then rolls back. So {@see HotStoreGuard::lossy()} queues its writes here while a control transaction is open
 * ({@see \RivetCore\Rmm\Support\TrackedDatabase} reports that) and they run after the commit, in order, each still never throwing; a rollback discards them.
 *
 * Lossy by design: a request that dies between the commit and the flush loses the queued history, which is the same trade as a dropped write during an outage.
 *
 * @internal
 */
final class WriteBehind
{
    /** Most writes held for one transaction; beyond it a write runs at once (a runaway loop must not hold memory). */
    public const CAP = 1000;

    private int $depth = 0;
    /** @var list<\Closure(): void> */
    private array $queue = [];

    /** A control transaction was opened (nested ones count). */
    public function enter(): void
    {
        ++$this->depth;
    }

    /**
     * A control transaction ended. When it was the outermost one, the queued writes run (committed) or are dropped (rolled back).
     */
    public function leave(bool $committed): void
    {
        $this->depth = max(0, $this->depth - 1);
        if ($this->depth > 0) {
            return;
        }
        $queue = $this->queue;
        $this->queue = [];
        if ($committed) {
            foreach ($queue as $write) {
                $write();
            }
        }
    }

    public function open(): bool
    {
        return $this->depth > 0 && count($this->queue) < self::CAP;
    }

    /** @param \Closure(): void $write */
    public function defer(\Closure $write): void
    {
        $this->queue[] = $write;
    }

    public function pending(): int
    {
        return count($this->queue);
    }
}
