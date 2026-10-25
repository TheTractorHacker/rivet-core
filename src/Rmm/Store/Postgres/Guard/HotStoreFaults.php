<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres\Guard;

use RivetCore\Rmm\Support\Sql;

/**
 * The record, in the CONTROL database, of what the hot store could not do: per store the writes that were dropped, the failures, the first and last time and the last
 * error text (`rmm_hot_store_faults`, migration 0043). The capacity panel shows it as a warning and an administrator clears it once the cause is fixed.
 *
 * Counting must never make an outage worse, so it is cheap and total: increments accumulate in memory and are written with one atomic upsert per store when the
 * process ends (or on {@see flush()}), and a control database that cannot be written (the table is missing, the database is down too) is ignored.
 *
 * @api
 */
final class HotStoreFaults
{
    /** @var array<string,array{dropped:int,errors:int,error:string}> */
    private array $pending = [];
    private bool $registered = false;

    public function __construct(private readonly Sql $sql)
    {
    }

    /** Remember a failure (and the writes it cost) for the next {@see flush()}. */
    public function note(string $store, int $dropped, string $error): void
    {
        $p = $this->pending[$store] ?? ['dropped' => 0, 'errors' => 0, 'error' => ''];
        $p['dropped'] += max(0, $dropped);
        ++$p['errors'];
        $p['error'] = mb_substr($error, 0, 200);
        $this->pending[$store] = $p;
        if (!$this->registered) {
            $this->registered = true;
            register_shutdown_function($this->flush(...));
        }
    }

    /** Write what is pending; never throws. */
    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        $now = $this->sql->utcNow();
        foreach ($pending as $store => $p) {
            try {
                $this->sql->run('INSERT INTO rmm_hot_store_faults (store, dropped, errors, first_at, last_at, last_error) VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE dropped = dropped + VALUES(dropped), errors = errors + VALUES(errors), last_at = VALUES(last_at), last_error = VALUES(last_error)',
                    [$store, $p['dropped'], $p['errors'], $now, $now, $p['error']]);
            } catch (\Throwable) {
                // the control database cannot take a note either: the error_log line written when the fault happened is what is left
            }
        }
    }

    /**
     * What has been recorded since the last {@see clear()}.
     *
     * @return array{dropped:int,errors:int,first_at:?string,last_at:?string,last_error:string,stores:array<string,array{dropped:int,errors:int,last_at:string,last_error:string}>}
     */
    public function summary(): array
    {
        $out = ['dropped' => 0, 'errors' => 0, 'first_at' => null, 'last_at' => null, 'last_error' => '', 'stores' => []];
        try {
            $rows = $this->sql->all('SELECT store, dropped, errors, first_at, last_at, last_error FROM rmm_hot_store_faults ORDER BY last_at DESC, store');
        } catch (\Throwable) {
            return $out;   // migration 0043 not applied yet
        }
        foreach ($rows as $r) {
            $out['dropped'] += (int) $r['dropped'];
            $out['errors'] += (int) $r['errors'];
            $out['first_at'] = $out['first_at'] === null ? (string) $r['first_at'] : min($out['first_at'], (string) $r['first_at']);
            if ($out['last_at'] === null) {
                $out['last_at'] = (string) $r['last_at'];
                $out['last_error'] = (string) $r['last_error'];
            }
            $out['stores'][(string) $r['store']] = ['dropped' => (int) $r['dropped'], 'errors' => (int) $r['errors'], 'last_at' => (string) $r['last_at'], 'last_error' => (string) $r['last_error']];
        }

        return $out;
    }

    /** Forget the record (the administrator fixed the cause and acknowledged the warning). */
    public function clear(): void
    {
        $this->pending = [];
        try {
            $this->sql->run('DELETE FROM rmm_hot_store_faults');
        } catch (\Throwable) {
            // nothing to clear
        }
    }
}
