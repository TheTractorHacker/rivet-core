<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration;

use RivetCore\Rmm\Support\Sql;

/**
 * {@see StoreEndpoint} for the control database (MariaDB / MySQL).
 *
 * @internal
 */
final class MariaDbEndpoint implements StoreEndpoint
{
    public function __construct(private readonly Sql $sql)
    {
    }

    public function kind(): string
    {
        return 'mariadb';
    }

    public function tableExists(HotTable $table): bool
    {
        return (int) $this->sql->val('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table->name]) > 0;
    }

    public function columns(HotTable $table): array
    {
        return array_map(static fn (array $r): string => (string) array_values($r)[0],
            $this->sql->all('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table->name]));
    }

    public function count(HotTable $table, ?string $timeBefore = null, ?string $timeFrom = null): int
    {
        [$where, $p] = $this->timeWhere($table, $timeBefore, $timeFrom);

        return (int) $this->sql->val('SELECT COUNT(*) FROM `' . $table->name . '`' . $where, $p);
    }

    public function maxId(HotTable $table): int
    {
        $id = $table->idColumn();

        return $id === null ? 0 : (int) $this->sql->val('SELECT COALESCE(MAX(`' . $id . '`), 0) FROM `' . $table->name . '`');
    }

    /** @param ?list<mixed> $after */
    public function readBatch(HotTable $table, ?array $after, int $limit, ?int $upToId = null, ?string $timeBefore = null, ?string $timeFrom = null): array
    {
        $conds = [];
        $p = [];
        if ($after !== null) {
            [$c, $cp] = $this->after($table->key, $after);
            $conds[] = $c;
            array_push($p, ...$cp);
        }
        if ($upToId !== null && $table->idColumn() !== null) {
            $conds[] = '`' . $table->idColumn() . '` <= ?';
            $p[] = $upToId;
        }
        [$tw, $tp] = $this->timeConds($table, $timeBefore, $timeFrom);
        array_push($conds, ...$tw);
        array_push($p, ...$tp);
        $order = implode(', ', array_map(static fn (string $k): string => '`' . $k . '`', $table->key));
        $sql = 'SELECT ' . $this->select($table) . ' FROM `' . $table->name . '`' . ($conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds)) . ' ORDER BY ' . $order . ' LIMIT ' . max(1, $limit);

        return array_map(fn (array $r): array => $this->normalise($table, $r), $this->sql->all($sql, $p));
    }

    public function readOwner(HotTable $table, int $owner, ?string $timeBefore, int $limit): array
    {
        $col = $table->deviceCol;
        if ($col === null) {
            return [];
        }
        $p = [$owner];
        $w = '`' . $col . '` = ?';
        if ($timeBefore !== null && $table->timeCol !== null) {
            $w .= ' AND `' . $table->timeCol . '` < ?';
            $p[] = $timeBefore;
        }
        $order = ($table->timeCol !== null ? '`' . $table->timeCol . '`, ' : '') . implode(', ', array_map(static fn (string $k): string => '`' . $k . '`', $table->key));

        return array_map(fn (array $r): array => $this->normalise($table, $r), $this->sql->all('SELECT ' . $this->select($table) . ' FROM `' . $table->name . '` WHERE ' . $w . ' ORDER BY ' . $order . ' LIMIT ' . max(1, $limit), $p));
    }

    public function insert(HotTable $table, array $rows, string $mode, bool $withId): int
    {
        if ($rows === []) {
            return 0;
        }
        $cols = $withId || $table->kind !== HotTable::ID ? $table->allColumns() : array_keys($table->columns);
        $ph = '(' . implode(', ', array_map(fn (string $c): string => $table->typeOf($c) === 'x' ? 'UNHEX(?)' : '?', $cols)) . ')';
        $head = 'INSERT INTO `' . $table->name . '` (' . implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $cols)) . ') VALUES ';
        $tail = $this->conflictClause($table, $cols, $mode);
        $written = 0;
        foreach (array_chunk($rows, max(1, intdiv(30000, count($cols)))) as $chunk) {
            $p = [];
            foreach ($chunk as $r) {
                foreach ($cols as $c) {
                    $p[] = $this->bind($table->typeOf($c), $r[$c] ?? null);
                }
            }
            $written += $this->sql->run($head . implode(', ', array_fill(0, count($chunk), $ph)) . $tail, $p);
        }

        return $mode === 'ignore' ? $written : count($rows);
    }

    public function raiseNextId(HotTable $table, int $next): void
    {
        if ($table->idColumn() === null) {
            return;
        }
        $n = max($next, $this->maxId($table) + 1, 1);
        $this->sql->run('ALTER TABLE `' . $table->name . '` AUTO_INCREMENT = ' . $n);
    }

    public function truncate(HotTable $table): void
    {
        $this->sql->run('TRUNCATE TABLE `' . $table->name . '`');
    }

    /** @return array<int,int> */
    public function countsByDevice(HotTable $table): array
    {
        $col = $table->deviceCol ?? 'device_id';
        $out = [];
        foreach ($this->sql->all('SELECT `' . $col . '` AS d, COUNT(*) AS n FROM `' . $table->name . '` GROUP BY `' . $col . '`') as $r) {
            $out[(int) $r['d']] = (int) $r['n'];
        }

        return $out;
    }

    /** @return list<int> */
    public function owners(HotTable $table, int $limit): array
    {
        $col = $table->deviceCol;
        if ($col === null) {
            return [];
        }

        return array_map(static fn (array $r): int => (int) array_values($r)[0], $this->sql->all('SELECT DISTINCT `' . $col . '` FROM `' . $table->name . '` ORDER BY `' . $col . '` LIMIT ' . max(1, $limit)));
    }

    public function transaction(\Closure $work): mixed
    {
        return $this->sql->database()->transaction($work);
    }

    public function ping(): void
    {
        $this->sql->val('SELECT 1');
    }

    // ------------------------------------------------------------------ SQL pieces

    private function select(HotTable $table): string
    {
        return implode(', ', array_map(static fn (string $c): string => match ($table->typeOf($c)) {
            'x' => 'LOWER(HEX(`' . $c . '`)) AS `' . $c . '`',
            default => '`' . $c . '`',
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
                $c[] = '`' . $table->timeCol . '` < ?';
                $p[] = $before;
            }
            if ($from !== null) {
                $c[] = '`' . $table->timeCol . '` >= ?';
                $p[] = $from;
            }
        }

        return [$c, $p];
    }

    /**
     * Lexicographic "key greater than $after" without a row constructor.
     *
     * @param list<string> $key
     * @param list<mixed> $after
     * @return array{0:string,1:list<mixed>}
     */
    private function after(array $key, array $after): array
    {
        $terms = [];
        $p = [];
        for ($i = 0, $n = count($key); $i < $n; ++$i) {
            $parts = [];
            for ($j = 0; $j < $i; ++$j) {
                $parts[] = '`' . $key[$j] . '` = ?';
                $p[] = $after[$j];
            }
            $parts[] = '`' . $key[$i] . '` > ?';
            $p[] = $after[$i];
            $terms[] = '(' . implode(' AND ', $parts) . ')';
        }

        return ['(' . implode(' OR ', $terms) . ')', $p];
    }

    /** @param list<string> $cols */
    private function conflictClause(HotTable $table, array $cols, string $mode): string
    {
        if ($mode === 'ignore') {
            $k = '`' . $table->key[0] . '`';

            return ' ON DUPLICATE KEY UPDATE ' . $k . ' = ' . $k;
        }
        $data = array_values(array_diff($cols, $table->key));
        if ($mode === 'overwrite') {
            if ($data === []) {
                return ' ON DUPLICATE KEY UPDATE `' . $table->key[0] . '` = `' . $table->key[0] . '`';
            }

            return ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = VALUES(`' . $c . '`)', $data));
        }
        if ($mode === 'newer' && $table->timeCol !== null) {
            $t = $table->timeCol;
            $newer = 'VALUES(`' . $t . '`) > `' . $t . '`';
            $sets = [];
            foreach ($data as $c) {
                if ($c !== $t) {
                    $sets[] = '`' . $c . '` = IF(' . $newer . ', VALUES(`' . $c . '`), `' . $c . '`)';
                }
            }
            $sets[] = '`' . $t . '` = IF(' . $newer . ', VALUES(`' . $t . '`), `' . $t . '`)';   // last: the columns above compare with the OLD time

            return ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
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
            'f' => (float) $v,
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
