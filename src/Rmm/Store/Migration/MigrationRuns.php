<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration;

use RivetCore\Rmm\Support\Sql;

/**
 * The runs of the data store migration (table `rmm_store_migration`, migration 0044): one row per run, the newest is the current one.
 *
 * States of a run: `copying` and `dual_write` (running; `failed` is a run that stopped and can be resumed), `verified` (ready to cut over), `cutover` (done: the new store is
 * active, the old one is kept until `rollback_until`), `rolled_back`, `aborted`, `switched_only` (the setting was switched without moving history, mode `none`).
 *
 * @internal
 */
final class MigrationRuns
{
    public const COPYING = 'copying';
    public const DUAL = 'dual_write';
    public const VERIFIED = 'verified';
    public const CUTOVER = 'cutover';
    public const ROLLED_BACK = 'rolled_back';
    public const ABORTED = 'aborted';
    public const FAILED = 'failed';
    public const SWITCHED_ONLY = 'switched_only';

    /** States in which a run owns the stores (retention is paused). */
    public const ACTIVE = [self::COPYING, self::DUAL, self::VERIFIED, self::FAILED];

    public function __construct(private readonly Sql $sql)
    {
    }

    /** @return array<string,mixed>|null */
    public function current(): ?array
    {
        return $this->decode($this->sql->one('SELECT * FROM rmm_store_migration ORDER BY run_id DESC LIMIT 1'));
    }

    /** @return array<string,mixed>|null the run that owns the stores, if any */
    public function active(): ?array
    {
        $in = implode(', ', array_map(static fn (string $s): string => "'" . $s . "'", self::ACTIVE));

        return $this->decode($this->sql->one("SELECT * FROM rmm_store_migration WHERE state IN ($in) ORDER BY run_id DESC LIMIT 1"));
    }

    /** @return array<string,mixed>|null */
    public function get(int $runId): ?array
    {
        return $this->decode($this->sql->one('SELECT * FROM rmm_store_migration WHERE run_id = ?', [$runId]));
    }

    /** @return list<array<string,mixed>> newest first, without the heavy checkpoint column */
    public function history(int $limit = 20): array
    {
        return $this->sql->all('SELECT run_id, from_store, to_store, mode, state, dual_write, dual_faults, started_at, updated_at, cutover_at, rollback_until, finished_at, purged_at, actor, last_error FROM rmm_store_migration ORDER BY run_id DESC LIMIT ' . max(1, min(200, $limit)));
    }

    /** @param array<string,mixed> $params */
    public function create(string $from, string $to, string $mode, string $state, string $actor, array $params = []): int
    {
        $now = gmdate('Y-m-d H:i:s', $this->sql->clock()->now()->getTimestamp());

        return $this->sql->insert('INSERT INTO rmm_store_migration (from_store, to_store, mode, state, dual_write, dual_faults, started_at, updated_at, actor, params, checkpoints) VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?)',
            [$from, $to, $mode, $state, $now, $now, mb_substr($actor, 0, 100), json_encode($params), '{}']);
    }

    /** @param array<string,mixed> $fields column => value (arrays are stored as JSON) */
    public function update(int $runId, array $fields): void
    {
        $fields['updated_at'] = gmdate('Y-m-d H:i:s', $this->sql->clock()->now()->getTimestamp());
        $sets = [];
        $p = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, ['state', 'dual_write', 'dual_faults', 'updated_at', 'cutover_at', 'rollback_until', 'finished_at', 'purged_at', 'params', 'checkpoints', 'summary', 'last_error'], true)) {
                throw new \InvalidArgumentException('unknown run column ' . $k);
            }
            $sets[] = "`$k` = ?";
            $p[] = is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES) : ($k === 'last_error' ? mb_substr((string) $v, 0, 480) : $v);
        }
        $p[] = $runId;
        $this->sql->run('UPDATE rmm_store_migration SET ' . implode(', ', $sets) . ' WHERE run_id = ?', $p);
    }

    /**
     * History that stayed in another store when the setting was switched without a migration: the newest `switched_only` run that no later cut-over has fixed.
     *
     * @return array{run_id:int,from:string,to:string,at:string}|null
     */
    public function leftBehind(): ?array
    {
        $r = $this->sql->one("SELECT run_id, from_store, to_store, started_at FROM rmm_store_migration WHERE state = 'switched_only' ORDER BY run_id DESC LIMIT 1");
        if ($r === null) {
            return null;
        }
        $later = $this->sql->val("SELECT COUNT(*) FROM rmm_store_migration WHERE run_id > ? AND state = 'cutover' AND to_store = ?", [(int) $r['run_id'], (string) $r['to_store']]);
        if ((int) $later > 0) {
            return null;
        }

        return ['run_id' => (int) $r['run_id'], 'from' => (string) $r['from_store'], 'to' => (string) $r['to_store'], 'at' => (string) $r['started_at']];
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    private function decode(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (['params', 'checkpoints', 'summary'] as $k) {
            $v = $row[$k] ?? null;
            $row[$k] = is_string($v) && $v !== '' ? (json_decode($v, true) ?: []) : [];
        }
        $row['run_id'] = (int) $row['run_id'];
        $row['dual_write'] = (int) $row['dual_write'];
        $row['dual_faults'] = (int) $row['dual_faults'];

        return $row;
    }
}
