<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration\DualWrite;

use RivetCore\Database\DatabaseException;
use RivetCore\Rmm\Store\Postgres\Guard\WriteBehind;
use RivetCore\Rmm\Support\Sql;

/**
 * What the dual-write decorators share (phase 3 of #89, docs/design/rmm-data-store.md section 13.4): whether retention is paused, whether there is a second store, and how a
 * write to it is made. The PRIMARY store (the one the setting names) answers every read and every write exactly as before; the SECONDARY gets the same write afterwards
 * and its failure never reaches the caller: it is counted in the migration run (`dual_faults`), which makes the verification refuse the cut-over until a catch-up pass has
 * repaired the gap.
 *
 * Ordering rule: primary first, secondary second, so a crash between the two leaves the secondary short (repairable by the catch-up) and never ahead of the primary.
 *
 * @internal
 */
final class DualWriteContext
{
    private int $logged = 0;
    private int $unflushed = 0;
    private int $flushedAt = 0;

    /**
     * @param bool $paused retention, row caps and rollups do not run (a migration is copying)
     * @param bool $deferSecondary the secondary is in another database: its write waits for the control transaction's commit (WriteBehind), like the PostgreSQL stores' own writes
     */
    public function __construct(
        public readonly bool $paused,
        private readonly ?Sql $control = null,
        private readonly ?WriteBehind $behind = null,
        private readonly bool $deferSecondary = false,
    ) {
    }

    /** Run a secondary write; never throws. */
    public function secondary(string $what, \Closure $write): void
    {
        if ($this->deferSecondary && $this->behind?->open()) {
            $this->behind->defer(function () use ($what, $write): void {
                $this->run($what, $write);
            });

            return;
        }
        $this->run($what, $write);
    }

    private function run(string $what, \Closure $write): void
    {
        try {
            $write();
        } catch (\Throwable $e) {
            $this->fault($what, $e);
        }
    }

    private function fault(string $what, \Throwable $e): void
    {
        $msg = mb_substr($what . ': ' . ($e instanceof DatabaseException ? rtrim($e->getMessage(), '. ') : get_class($e)), 0, 480);
        ++$this->unflushed;
        if ($this->control !== null && $this->flushedAt + 5 <= time()) {
            $this->flushedAt = time();
            $n = $this->unflushed;
            $this->unflushed = 0;
            try {
                $this->control->run('UPDATE rmm_store_migration SET dual_faults = dual_faults + ?, last_error = ? WHERE dual_write = 1', [$n, $msg]);
            } catch (\Throwable) {
                // the counter is best effort; the error_log line below still names it
            }
        }
        if ($this->logged + 60 <= time()) {
            $this->logged = time();
            error_log('rmm store migration: a write to the second store failed (the catch-up pass will repair it): ' . $msg);
        }
    }

    /** Faults counted since the last write to the migration row are written when the request ends. */
    public function __destruct()
    {
        if ($this->unflushed > 0 && $this->control !== null) {
            try {
                $this->control->run('UPDATE rmm_store_migration SET dual_faults = dual_faults + ? WHERE dual_write = 1', [$this->unflushed]);
            } catch (\Throwable) {
            }
            $this->unflushed = 0;
        }
    }
}
