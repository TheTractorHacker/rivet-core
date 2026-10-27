<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\MariaDb;

/**
 * Migration 0043 adds `client_id` to the log events, the change records and the change ledger. An edition that deploys the code BEFORE it runs the migration must keep
 * working, so the MariaDB stores treat the column as optional: they use it, and when the server answers "Unknown column 'client_id'" they remember that (once per process)
 * and run the statement they ran before rc.40 instead (no client written; client scoping by a sub-select of the device table, which is what the old join did).
 * The cost when the column exists is zero: no probe, no extra query. When it does not, the first statement of a process fails once.
 *
 * @internal
 */
final class ClientColumn
{
    private static bool $missing = false;

    /** The SQL for "the client of the row" in a statement over the alias $alias of a table that has a `device_id`. */
    public static function expr(string $alias): string
    {
        return self::$missing ? "(SELECT d.client_id FROM endpoint_agent_devices d WHERE d.device_id = $alias.device_id)" : "$alias.client_id";
    }

    public static function present(): bool
    {
        return !self::$missing;
    }

    /** Forget what was learned (a test that adds or drops the column; a long-running worker after the migration). */
    public static function forget(): void
    {
        self::$missing = false;
    }

    /**
     * Run $work(bool $hasColumn); if it fails because the column is not there, remember and run it again without.
     *
     * @template T
     * @param \Closure(bool): T $work
     * @return T
     */
    public static function run(\Closure $work): mixed
    {
        try {
            return $work(!self::$missing);
        } catch (\Throwable $e) {
            if (self::$missing || !self::isMissingColumn($e)) {
                throw $e;
            }
            self::$missing = true;

            return $work(false);
        }
    }

    private static function isMissingColumn(\Throwable $e): bool
    {
        $m = strtolower($e->getMessage());

        return str_contains($m, 'client_id') && (str_contains($m, 'unknown column') || $e->getCode() === 1054);
    }
}
