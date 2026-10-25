<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration;

use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;

/**
 * {@see StoreEndpoint} for the PostgreSQL store (the connection's search_path names the schema).
 *
 * @internal
 */
final class PostgresEndpoint implements StoreEndpoint
{
    public function __construct(private readonly PostgresConnectionInterface $db)
    {
    }

    public function connection(): PostgresConnectionInterface
    {
        return $this->db;
    }

    public function kind(): string
    {
        return 'postgres';
    }

    public function tableExists(HotTable $table): bool
    {
        return $this->db->value('SELECT to_regclass(?)', [$table->name]) !== null;
    }

    public function columns(HotTable $table): array
    {
        return array_map(static fn (array $r): string => (string) $r['column_name'],
            $this->db->query('SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position', [$table->name]));
    }

    public function count(HotTable $table, ?string $timeBefore = null, ?string $timeFrom = null): int
    {
        [$where, $p] = $this->timeWhere($table, $timeBefore, $timeFrom);

        return (int) $this->db->value('SELECT COUNT(*) FROM ' . $table->name . $where, $p);
    }

    public function maxId(HotTable $table): int
    {
        $id = $table->idColumn();

        return $id === null ? 0 : (int) $this->db->value('SELECT COALESCE(MAX(' . $id . '), 0) FROM ' . $table->name);
    }

    public function readBatch(HotTable $table, ?array $after, int $limit, ?int $upToId = null, ?string $timeBefore = null, ?string $timeFrom = null): array
    {
        $conds = [];
        $p = [];
        if ($after !== null) {
            $conds[] = '(' . implode(', ', $table->key) . ') > (' . implode(', ', array_fill(0, count($table->key), '?')) . ')';
            array_push($p, ...$after);
        }
        if ($upToId !== null && $table->idColumn() !== null) {
            $conds[] = $table->idColumn() . ' <= ?';
            $p[] = $upToId;
        }
        [$tw, $tp] = $this->timeConds($table, $timeBefore, $timeFrom);
        array_push($conds, ...$tw);
        array_push($p, ...$tp);
        $sql = 'SELECT ' . $this->select($table) . ' FROM ' . $table->name . ($conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds)) . ' ORDER BY ' . implode(', ', $table->key) . ' LIMIT ' . max(1, $limit);

        return array_map(fn (array $r): array => $this->normalise($table, $r), $this->db->query($sql, $p));
    }

    public function readOwner(HotTable $table, int $owner, ?string $timeBefore, int $limit): array
    {
        if ($table->deviceCol === null) {
            return [];
        }
        $p = [$owner];
        $w = $table->deviceCol . ' = ?';
        if ($timeBefore !== null && $table->timeCol !== null) {
            $w .= ' AND ' . $this->quoteIdent($table->timeCol) . ' < ?';
            $p[] = $timeBefore;
        }
        $order = ($table->timeCol !== null ? $this->quoteIdent($table->timeCol) . ', ' : '') . implode(', ', $table->key);

        return array_map(fn (array $r): array => $this->normalise($table, $r), $this->db->query('SELECT ' . $this->select($table) . ' FROM ' . $table->name . ' WHERE ' . $w . ' ORDER BY ' . $order . ' LIMIT ' . max(1, $limit), $p));
    }

    public function insert(HotTable $table, array $rows, string $mode, bool $withId): int
    {
        if ($rows === []) {
            return 0;
        }
        $cols = $withId || $table->kind !== HotTable::ID ? $table->allColumns() : array_keys($table->columns);
        $ph = '(' . implode(', ', array_map(fn (string $c): string => match ($table->typeOf($c)) {
            'x' => "decode(?, 'hex')",
            'j' => '?::jsonb',
            default => '?',
        }, $cols)) . ')';
        $head = 'INSERT INTO ' . $table->name . ' (' . implode(', ', array_map([$this, 'quoteIdent'], $cols)) . ')' . ($withId && $table->kind === HotTable::ID ? ' OVERRIDING SYSTEM VALUE' : '') . ' VALUES ';
        $tail = $this->conflictClause($table, $cols, $mode);
        $written = 0;
        foreach (array_chunk($rows, max(1, intdiv(30000, count($cols)))) as $chunk) {
            $p = [];
            foreach ($chunk as $r) {
                foreach ($cols as $c) {
                    $p[] = $this->bind($table->typeOf($c), $r[$c] ?? null);
                }
            }
            $written += $this->db->execute($head . implode(', ', array_fill(0, count($chunk), $ph)) . $tail, $p);
        }

        return $written;
    }

    public function raiseNextId(HotTable $table, int $next): void
    {
        $id = $table->idColumn();
        if ($id === null) {
            return;
        }
        $v = max($next - 1, $this->maxId($table));
        $seq = $this->db->value('SELECT pg_get_serial_sequence(?, ?)', [$table->name, $id]);
        if (!is_string($seq) || $seq === '') {
            return;
        }
        $cur = $this->db->queryOne('SELECT last_value, is_called FROM ' . $seq);
        $called = $cur !== null && in_array($cur['is_called'], [true, 't', 'true', '1', 1], true);
        $curNext = $cur === null ? 1 : ($called ? (int) $cur['last_value'] + 1 : (int) $cur['last_value']);
        if ($v + 1 <= $curNext) {
            return;
        }
        $this->db->value('SELECT setval(?, ?, true)', [$seq, max(1, $v)]);
    }

    public function truncate(HotTable $table): void
    {
        $this->db->execute('TRUNCATE TABLE ' . $table->name . ' RESTART IDENTITY');
    }

    public function countsByDevice(HotTable $table): array
    {
        $col = $table->deviceCol ?? 'device_id';
        $out = [];
        foreach ($this->db->query('SELECT ' . $col . ' AS d, COUNT(*) AS n FROM ' . $table->name . ' GROUP BY ' . $col) as $r) {
            $out[(int) $r['d']] = (int) $r['n'];
        }

        return $out;
    }

    public function owners(HotTable $table, int $limit): array
    {
        if ($table->deviceCol === null) {
            return [];
        }

        return array_map(static fn (array $r): int => (int) array_values($r)[0], $this->db->query('SELECT DISTINCT ' . $table->deviceCol . ' FROM ' . $table->name . ' ORDER BY 1 LIMIT ' . max(1, $limit)));
    }

    public function transaction(\Closure $work): mixed
    {
        return $this->db->transaction($work);
    }

    public function ping(): void
    {
        $this->db->value('SELECT 1');
    }

    // ------------------------------------------------------------------ SQL pieces

    /** `at` is a reserved word in some contexts: every column name is quoted by its (lower case, validated) name. */
    public function quoteIdent(string $c): string
    {
        return '"' . $c . '"';
    }

    private function select(HotTable $table): string
    {
        return implode(', ', array_map(fn (string $c): string => match ($table->typeOf($c)) {
            't' => "to_char(" . $this->quoteIdent($c) . " AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS " . $this->quoteIdent($c),
            'x' => "encode(" . $this->quoteIdent($c) . ", 'hex') AS " . $this->quoteIdent($c),
            'j' => $this->quoteIdent($c) . '::text AS ' . $this->quoteIdent($c),
            default => $this->quoteIdent($c),
        }, $table->allColumns()));
    }

    /** @return array{0:string,1:list<mixed>} */
    private function timeWhere(HotTable $table, ?string $before, ?string $from): array
    {
        [$c, $p] = $this->timeConds($table, $before, $from);

        return [$c === [] ? '' : ' WHERE ' . implode(' AND ', $c), $p];
    }

    /** @return array{0:list<string>,1:list<mixed>} */
    private function timeConds(HotTable $table, ?string $before, ?string $from): array
    {
        $c = [];
        $p = [];
        if ($table->timeCol !== null) {
            if ($before !== null) {
                $c[] = $this->quoteIdent($table->timeCol) . ' < ?';
                $p[] = $before;
            }
            if ($from !== null) {
                $c[] = $this->quoteIdent($table->timeCol) . ' >= ?';
                $p[] = $from;
            }
        }

        return [$c, $p];
    }

    /** @param list<string> $cols */
    private function conflictClause(HotTable $table, array $cols, string $mode): string
    {
        if ($mode === 'ignore') {
            return ' ON CONFLICT DO NOTHING';
        }
        $key = implode(', ', array_map([$this, 'quoteIdent'], $table->key));
        $data = array_values(array_diff($cols, $table->key));
        if ($mode === 'overwrite') {
            if ($data === []) {
                return ' ON CONFLICT (' . $key . ') DO NOTHING';
            }

            return ' ON CONFLICT (' . $key . ') DO UPDATE SET ' . implode(', ', array_map(fn (string $c): string => $this->quoteIdent($c) . ' = EXCLUDED.' . $this->quoteIdent($c), $data));
        }
        if ($mode === 'newer' && $table->timeCol !== null) {
            $t = $this->quoteIdent($table->timeCol);

            return ' ON CONFLICT (' . $key . ') DO UPDATE SET ' . implode(', ', array_map(fn (string $c): string => $this->quoteIdent($c) . ' = EXCLUDED.' . $this->quoteIdent($c), $data))
                . ' WHERE EXCLUDED.' . $t . ' > ' . $table->name . '.' . $t;
        }
        throw new \InvalidArgumentException('Unknown insert mode ' . $mode);
    }

    private function bind(string $type, mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }

        return match ($type) {
            'i' => (int) $v,
            'f' => (string) (float) $v,
            default => (string) $v,
        };
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function normalise(HotTable $table, array $r): array
    {
        $out = [];
        foreach ($table->allColumns() as $c) {
            $v = $r[$c] ?? null;
            $out[$c] = $v === null ? null : match ($table->typeOf($c)) {
                'i' => (int) $v,
                'f' => (float) $v,
                default => (string) $v,
            };
        }

        return $out;
    }
}
