<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration;

/**
 * One hot table as the migration sees it: its columns with their portable types, its key and how its rows are copied. The registry is {@see HotTables}; a test compares it with
 * the real columns of both databases so a column added to one store cannot be forgotten here.
 *
 * Column types: `i` integer, `f` double, `s` string, `t` UTC timestamp as `Y-m-d H:i:s`, `x` binary as lower case hex, `j` JSON text (a jsonb on PostgreSQL).
 *
 * @internal
 */
final class HotTable
{
    /** A table with a surrogate id (identity / AUTO_INCREMENT) and append-style rows. */
    public const ID = 'id';
    /** An hourly or daily metric tier: one row per series and bucket, rows are additive while they are live. */
    public const TIER = 'tier';
    /** `rmm_metric_latest`: one row per series, the newest reading wins. */
    public const LATEST = 'latest';
    /** A per-device counter row (`log_state`, `change_state`), rebuilt from the copied events. */
    public const LEDGER = 'ledger';
    /** `rmm_metric_rollup_state`: the rollup watermark. */
    public const STATE = 'state';

    /**
     * @param array<string,string> $columns every column except the surrogate id, in order: name => type
     * @param list<string> $key primary key columns (for an id table: the id column alone)
     * @param ?string $timeCol the server-assigned time used to cut windows and to verify (null for the ledgers and the state row)
     * @param bool $natural whether a unique natural key (dedupe) makes a repeated insert harmless
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly array $columns,
        public readonly array $key,
        public readonly ?string $timeCol,
        public readonly ?string $deviceCol = null,
        public readonly bool $natural = false,
        public readonly ?string $ledgerOf = null,
    ) {
    }

    public function idColumn(): ?string
    {
        return $this->kind === self::ID ? $this->key[0] : null;
    }

    /** @return list<string> the columns a copied row carries (the id first for an id table) */
    public function allColumns(): array
    {
        $cols = array_keys($this->columns);

        return $this->kind === self::ID ? [$this->key[0], ...$cols] : $cols;
    }

    public function typeOf(string $column): string
    {
        return $column === $this->idColumn() ? 'i' : $this->columns[$column];
    }
}
