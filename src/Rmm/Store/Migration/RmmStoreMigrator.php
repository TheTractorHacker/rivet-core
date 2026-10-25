<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresMigrator;
use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetCore\Rmm\Support\Sql;

/**
 * Moves the HOT RMM history from one store to the other, both ways (phase 3 of #89; the design, the ordering rules and the proof obligations are in
 * docs/design/rmm-data-store.md section 13.4, the operator steps in docs/rmm/POSTGRES.md). Phases, each resumable from a checkpoint kept in the control database:
 *
 *  1. `bulk`    copy everything that exists, table by table, in keyset pages (never OFFSET), surrogate ids kept, `ignore` on conflict, so a page that is repeated after a crash writes nothing twice.
 *               Retention, row caps and rollups are PAUSED for the active store from here until the cut-over (a delete between copy and verification would be a false difference).
 *  2. `catchup` copy the rows that arrived while copying, until little is left.
 *  3. `seed`    make the target's next ids higher than anything copied, copy the metric buckets that were still open and the latest readings, then switch DUAL-WRITE on: from this
 *               instant every write also goes to the second store, primary first (see DualWriteContext).
 *  4. `settle`  wait until every worker has seen the flag.
 *  5. `tail`    close the gap between the last copied id and the first dual write: compare the rows of that time window in both stores as multisets and insert what is missing.
 *  6. `finalize` copy the per-device ledgers (the row count is recomputed from the copied events, so ledger and rows agree by construction), the rollup watermark and the recent daily tier.
 *  7. `verify`  per table row counts over a stable time range, ledger against rows, and a checksum of a sample of devices (or every device); a failed check repairs once (tail + finalize) and
 *               checks again; if it still differs the run stops BEFORE the cut-over with dual-write still on, so nothing is lost and nothing is switched.
 *  8. `cutover` flip the setting in one control-database transaction, turn dual-write off, record the rollback deadline. The old store is NOT touched; deleting it is the separate {@see purgeOld()}.
 *
 * {@see rollback()} runs the same pipeline backwards for the rows written since the cut-over, so that nothing recorded in the new store is lost when going back.
 *
 * The migration never calls the stores' public methods for the bulk copy; it works on table level through {@see StoreEndpoint}. Everything it writes is idempotent: ids kept and
 * `ignore`, buckets `overwrite` only before dual-write is on, latest readings by "newer wins", ledgers recomputed.
 *
 * @api
 */
final class RmmStoreMigrator
{
    public const PHASES = ['bulk', 'catchup', 'seed', 'settle', 'tail', 'finalize', 'verify', 'cutover'];
    /** Width of a reconcile slice (seconds): bounds the rows held in memory at once. */
    private const SLICE_S = 300;
    private const SEED_SLACK_S = 120;
    private const MAX_SAMPLE_ROWS = 20000;

    private ?PostgresConnectionInterface $pgResolved = null;
    private readonly MigrationRuns $runs;
    private ?\Closure $progress = null;
    private readonly \Closure $sleep;
    private bool $lockHeld = false;

    /**
     * @param PostgresConnectionInterface|\Closure():?PostgresConnectionInterface|null $postgres the PostgreSQL connection (a closure is called on first use, so a MariaDB-only call never connects)
     * @param ?\Closure(int):void $sleep waits this many microseconds (default usleep; tests pass a no-op)
     */
    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly PostgresConnectionInterface|\Closure|null $postgres = null,
        private readonly ?RmmAuditInterface $audit = null,
        ?\Closure $sleep = null,
    ) {
        $this->runs = new MigrationRuns($sql);
        $this->sleep = $sleep ?? static function (int $us): void {
            usleep($us);
        };
    }

    // ------------------------------------------------------------------ public commands

    /**
     * Migrate the history to the other store and cut over.
     *
     * @param array{from?:string,to:string,dry_run?:bool,resume?:bool,batch?:int,pause_ms?:int,sample?:int,actor?:string,settle_s?:int,verify_lag_s?:int,verify_attempts?:int,cutover?:bool} $options
     *        `from` defaults to the active store; `sample` = devices checksummed per table (0 = counts only, -1 = every device); `cutover` false stops after verification
     * @param ?\Closure(array<string,mixed>):void $progress receives one event array per step (the command prints them as JSON lines); an exception thrown from it stops the run like a crash
     * @return array<string,mixed> the summary
     * @throws MigrationException
     */
    public function migrate(array $options, ?\Closure $progress = null): array
    {
        $o = $this->options($options);
        $this->progress = $progress;
        $from = $o['from'];
        $to = $o['to'];
        $this->assertKinds($from, $to);
        if ($this->settings->dataStore() !== $from && $this->runs->active() === null) {
            throw new MigrationException('The active data store is ' . $this->settings->dataStore() . ', not ' . $from . '. Migrate from the store that is active now.');
        }
        $src = $this->endpoint($from);
        $tgt = $this->endpoint($to);
        $src->ping();
        $tgt->ping();
        $active = $this->runs->active();
        if ($active !== null && !$o['resume']) {
            throw new MigrationException('A migration is already in progress (run ' . $active['run_id'] . ', ' . $active['state'] . '). Resume it (--resume) or abort it (--abort).');
        }
        if ($active === null && $o['resume']) {
            throw new MigrationException('There is no migration to resume.');
        }
        if ($active !== null && ($active['from_store'] !== $from || $active['to_store'] !== $to)) {
            throw new MigrationException('The run in progress goes ' . $active['from_store'] . ' to ' . $active['to_store'] . '; resume it with those options.');
        }
        if ($to === RmmDataStoreKind::POSTGRES) {
            $this->prepareSchema($o['dry_run']);
        }
        $this->assertTables($src, $tgt);
        if ($o['dry_run']) {
            return $this->dryRun($src, $tgt, $o);
        }
        $this->lock();
        try {
            if ($active === null) {
                $this->assertTargetEmpty($tgt, $to);
                $runId = $this->runs->create($from, $to, 'full', MigrationRuns::COPYING, $o['actor'], ['batch' => $o['batch'], 'pause_ms' => $o['pause_ms'], 'sample' => $o['sample']]);
                $this->settings->set(['data_store_phase' => 'copy:' . $to]);
                $this->note('rmm.store_migration.start', 'RMM data store migration started: ' . $from . ' to ' . $to . ' (run ' . $runId . ').');
                $this->emit(['event' => 'start', 'run' => $runId, 'from' => $from, 'to' => $to]);
                $run = $this->runs->get($runId);
            } else {
                $run = $active;
                $this->emit(['event' => 'resume', 'run' => $run['run_id'], 'phase' => $run['checkpoints']['phase'] ?? 'bulk']);
            }

            return $this->pipeline($run, $o, $src, $tgt);
        } finally {
            $this->unlock();
        }
    }

    /**
     * Go back to the previous store after a cut-over, within the rollback period: the rows written to the new store since the cut-over are copied back (same dual-write and
     * verification), then the setting is flipped. Nothing is deleted from either store.
     *
     * @param array{force?:bool,batch?:int,pause_ms?:int,sample?:int,actor?:string,settle_s?:int,verify_lag_s?:int,verify_attempts?:int} $options `force` = go back after the rollback period (the old store must still hold its data)
     * @return array<string,mixed>
     * @throws MigrationException
     */
    public function rollback(array $options = [], ?\Closure $progress = null): array
    {
        $this->progress = $progress;
        $cur = $this->runs->current();
        if ($this->runs->active() !== null) {
            throw new MigrationException('A migration is in progress; finish or abort it before rolling back.');
        }
        if ($cur === null || $cur['state'] !== MigrationRuns::CUTOVER || $cur['mode'] !== 'full') {
            throw new MigrationException('There is no completed migration to roll back.');
        }
        if ($cur['purged_at'] !== null) {
            throw new MigrationException('The old store was purged on ' . $cur['purged_at'] . ' UTC; there is nothing to go back to.');
        }
        $now = $this->now();
        if (empty($options['force']) && $cur['rollback_until'] !== null && self::epoch((string) $cur['rollback_until']) < $now) {
            throw new MigrationException('The rollback period ended on ' . $cur['rollback_until'] . ' UTC. Use force to go back anyway if the old store still holds its data.');
        }
        $active = $this->settings->dataStore();
        if ($active !== $cur['to_store']) {
            throw new MigrationException('The active data store (' . $active . ') is not the one this migration moved to (' . $cur['to_store'] . ').');
        }
        $from = $active;
        $to = (string) $cur['from_store'];
        $o = $this->options(['from' => $from, 'to' => $to] + $options);
        $src = $this->endpoint($from);
        $tgt = $this->endpoint($to);
        $src->ping();
        $tgt->ping();
        if ($to === RmmDataStoreKind::POSTGRES) {
            $this->prepareSchema(false);
        }
        $this->assertTables($src, $tgt);
        $this->lock();
        try {
            $since = self::epoch((string) $cur['cutover_at']) - 300;
            $runId = $this->runs->create($from, $to, 'delta', MigrationRuns::COPYING, $o['actor'], ['since' => $since, 'rolls_back' => $cur['run_id'], 'batch' => $o['batch'], 'sample' => $o['sample']]);
            $this->settings->set(['data_store_phase' => 'copy:' . $to]);
            $this->note('rmm.store_migration.rollback_start', 'RMM data store rollback started: ' . $from . ' to ' . $to . ' (run ' . $runId . ', undoing run ' . $cur['run_id'] . ').');
            $this->emit(['event' => 'start', 'run' => $runId, 'from' => $from, 'to' => $to, 'mode' => 'delta']);

            return $this->pipeline($this->runs->get($runId) ?? [], $o, $src, $tgt);
        } finally {
            $this->unlock();
        }
    }

    /**
     * Delete the history from the store that is NOT active. Explicit, never automatic: needs `yes`, and inside the rollback period also `force`.
     *
     * @param array{yes?:bool,force?:bool,actor?:string} $options
     * @return array{purged:string,tables:array<string,int>}
     * @throws MigrationException
     */
    public function purgeOld(array $options = [], ?\Closure $progress = null): array
    {
        $this->progress = $progress;
        if (empty($options['yes'])) {
            throw new MigrationException('Purging deletes all RMM history from the store that is not active. Pass yes to confirm.');
        }
        if ($this->runs->active() !== null) {
            throw new MigrationException('A migration is in progress; finish or abort it first.');
        }
        $cur = $this->runs->current();
        if ($cur === null || !in_array($cur['state'], [MigrationRuns::CUTOVER, MigrationRuns::ROLLED_BACK], true)) {
            throw new MigrationException('There is no completed migration; the inactive store is not known to hold copied history. Nothing purged.');
        }
        if ($cur['state'] === MigrationRuns::CUTOVER && $cur['mode'] === 'full' && empty($options['force']) && $cur['rollback_until'] !== null && self::epoch((string) $cur['rollback_until']) > $this->now()) {
            throw new MigrationException('The rollback period lasts until ' . $cur['rollback_until'] . ' UTC. Purging earlier needs force (and removes the way back).');
        }
        $inactive = $this->settings->dataStore() === RmmDataStoreKind::MARIADB ? RmmDataStoreKind::POSTGRES : RmmDataStoreKind::MARIADB;
        $ep = $this->endpoint($inactive);
        $ep->ping();
        $done = [];
        foreach (array_reverse(HotTables::all()) as $t) {
            if ($ep->tableExists($t)) {
                $done[$t->name] = $ep->count($t);
                $ep->truncate($t);
            }
        }
        $this->runs->update((int) $cur['run_id'], ['purged_at' => $this->utc($this->now())]);
        $this->note('rmm.store_migration.purge', 'RMM history purged from the ' . $inactive . ' store (' . array_sum($done) . ' rows).');
        $this->emit(['event' => 'purged', 'store' => $inactive, 'rows' => array_sum($done)]);

        return ['purged' => $inactive, 'tables' => $done];
    }

    /** Stop a run in progress: dual-write off, retention back on. Copied rows stay in the target (purge it before the next try). */
    public function abort(string $actor = ''): void
    {
        $run = $this->runs->active();
        if ($run === null) {
            throw new MigrationException('There is no migration in progress.');
        }
        $this->runs->update((int) $run['run_id'], ['state' => MigrationRuns::ABORTED, 'dual_write' => 0, 'finished_at' => $this->utc($this->now())]);
        $this->settings->set(['data_store_phase' => null]);
        $this->note('rmm.store_migration.abort', 'RMM data store migration aborted (run ' . $run['run_id'] . ').');
    }

    /** @return array<string,mixed>|null the newest run, without the checkpoints */
    public function status(): ?array
    {
        $r = $this->runs->current();
        if ($r !== null) {
            $r['phase'] = $r['checkpoints']['phase'] ?? null;
            unset($r['checkpoints']);
        }

        return $r;
    }

    // ------------------------------------------------------------------ the pipeline

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    private function pipeline(array $run, array $o, StoreEndpoint $src, StoreEndpoint $tgt): array
    {
        $runId = (int) $run['run_id'];
        $delta = $run['mode'] === 'delta';
        /** @var array<string,mixed> $ck */
        $ck = $run['checkpoints'] ?: [];
        $ck['phase'] ??= 'bulk';
        $ck['bulk_start'] ??= $delta ? (int) $run['params']['since'] : $this->now();
        $ck['since'] = $delta ? (int) $run['params']['since'] : null;
        $started = $this->now();
        try {
            foreach (self::PHASES as $ph) {
                if (array_search($ph, self::PHASES, true) < array_search($ck['phase'], self::PHASES, true)) {
                    continue;
                }
                if ($ph === 'cutover' && !$o['cutover']) {
                    $this->runs->update($runId, ['state' => MigrationRuns::VERIFIED, 'checkpoints' => $ck]);
                    $this->emit(['event' => 'ready', 'run' => $runId, 'next' => 'cutover']);

                    return ['ok' => true, 'state' => MigrationRuns::VERIFIED, 'run' => $runId, 'cutover' => false, 'summary' => $ck['summary'] ?? []];
                }
                $ck['phase'] = $ph;
                $this->runs->update($runId, ['checkpoints' => $ck]);
                $this->emit(['event' => 'phase', 'phase' => $ph, 'run' => $runId]);
                $this->phase($ph, $runId, $run, $ck, $o, $src, $tgt);
                $ck['phase'] = self::PHASES[array_search($ph, self::PHASES, true) + 1] ?? 'done';
                $this->runs->update($runId, ['checkpoints' => $ck]);
            }
        } catch (MigrationException $e) {
            $this->runs->update($runId, ['state' => MigrationRuns::FAILED, 'checkpoints' => $ck, 'last_error' => $e->getMessage()]);
            $this->emit(['event' => 'failed', 'run' => $runId, 'error' => $e->getMessage()]);
            throw $e;
        } catch (\Throwable $e) {
            $this->runs->update($runId, ['state' => MigrationRuns::FAILED, 'checkpoints' => $ck, 'last_error' => get_class($e) . ': ' . $e->getMessage()]);
            throw $e;
        }
        $summary = ['run' => $runId, 'ok' => true, 'state' => MigrationRuns::CUTOVER, 'from' => $run['from_store'], 'to' => $run['to_store'], 'mode' => $run['mode'], 'seconds' => $this->now() - $started,
            'tables' => $ck['summary'] ?? [], 'dual_faults' => $this->runs->get($runId)['dual_faults'] ?? 0];
        $this->emit(['event' => 'done'] + $summary);

        return $summary;
    }

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $ck
     * @param array<string,mixed> $o
     */
    private function phase(string $ph, int $runId, array $run, array &$ck, array $o, StoreEndpoint $src, StoreEndpoint $tgt): void
    {
        $delta = $run['mode'] === 'delta';
        switch ($ph) {
            case 'bulk':
                if (!$delta) {
                    foreach (HotTables::all() as $t) {
                        $this->bulk($t, $runId, $ck, $o, $src, $tgt);
                    }
                }
                break;
            case 'catchup':
                if (!$delta) {
                    for ($pass = 0; $pass < 5; ++$pass) {
                        $copied = 0;
                        foreach (HotTables::all() as $t) {
                            if ($t->kind === HotTable::ID) {
                                $copied += $this->copyIds($t, $runId, $ck, $o, $src, $tgt, $src->maxId($t));
                            }
                        }
                        $this->emit(['event' => 'catchup', 'pass' => $pass + 1, 'rows' => $copied]);
                        if ($copied < 20000) {
                            break;
                        }
                    }
                    $ck['hw_at'] = $this->now();
                }
                break;
            case 'seed':
                $this->seed($runId, $run, $ck, $o, $src, $tgt);
                break;
            case 'settle':
                $this->sleepSeconds($o['settle_s']);
                break;
            case 'tail':
                $this->tail($ck, $o, $src, $tgt, $runId);
                break;
            case 'finalize':
                $this->finalize($ck, $o, $src, $tgt);
                break;
            case 'verify':
                $this->verifyWithRepair($runId, $ck, $o, $src, $tgt);
                $this->runs->update($runId, ['state' => MigrationRuns::VERIFIED, 'checkpoints' => $ck, 'summary' => $ck['summary'] ?? []]);
                break;
            case 'cutover':
                $this->cutover($runId, $run, $ck);
                break;
        }
    }

    // ------------------------------------------------------------------ copy

    /**
     * @param array<string,mixed> $ck
     * @param array<string,mixed> $o
     */
    private function bulk(HotTable $t, int $runId, array &$ck, array $o, StoreEndpoint $src, StoreEndpoint $tgt): void
    {
        if (in_array($t->kind, [HotTable::LEDGER, HotTable::STATE], true)) {
            return;
        }
        $tk = &$ck['t'][$t->name];
        $tk ??= ['after' => null, 'rows' => 0, 'done' => false];
        if ($tk['done']) {
            return;
        }
        if ($t->kind === HotTable::ID) {
            $tk['hw'] ??= $src->maxId($t);
            $this->copyIds($t, $runId, $ck, $o, $src, $tgt, $tk['hw']);
            $tk['done'] = true;
            $this->runs->update($runId, ['checkpoints' => $ck]);

            return;
        }
        $before = null;
        if ($t->name === 'rmm_metric_hourly') {
            $ck['hb'] ??= $this->floor((int) $ck['bulk_start'], 3600);
            $before = $this->utc((int) $ck['hb']);   // the bucket that was open when copying began, and everything after it, is the seed's job
        }
        while (($rows = $src->readBatch($t, $tk['after'], $o['batch'], null, $before)) !== []) {
            $tgt->insert($t, $rows, 'ignore', false);
            $tk['after'] = array_map(static fn (string $k) => end($rows)[$k], $t->key);
            $tk['rows'] += count($rows);
            $this->runs->update($runId, ['checkpoints' => $ck]);
            $this->emit(['event' => 'batch', 'table' => $t->name, 'rows' => $tk['rows']]);
            $this->throttle($o);
        }
        $tk['done'] = true;
        $this->runs->update($runId, ['checkpoints' => $ck]);
        $this->emit(['event' => 'table_done', 'table' => $t->name, 'rows' => $tk['rows']]);
    }

    /**
     * Copy the ids above the table's checkpoint up to $upTo, keeping them. Returns the rows copied.
     *
     * @param array<string,mixed> $ck
     * @param array<string,mixed> $o
     */
    private function copyIds(HotTable $t, int $runId, array &$ck, array $o, StoreEndpoint $src, StoreEndpoint $tgt, int $upTo): int
    {
        $tk = &$ck['t'][$t->name];
        $tk ??= ['after' => null, 'rows' => 0, 'done' => false];
        $copied = 0;
        $idCol = (string) $t->idColumn();
        while (($rows = $src->readBatch($t, $tk['after'], $o['batch'], $upTo)) !== []) {
            $tgt->insert($t, $rows, 'ignore', true);
            $last = (int) end($rows)[$idCol];
            $tk['after'] = [$last];
            $tk['rows'] += count($rows);
            $copied += count($rows);
            $this->runs->update($runId, ['checkpoints' => $ck]);
            $this->emit(['event' => 'batch', 'table' => $t->name, 'rows' => $tk['rows'], 'id' => $last]);
            $this->throttle($o);
        }
        $tk['hw'] = max((int) ($tk['hw'] ?? 0), $upTo);

        return $copied;
    }

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $ck
     * @param array<string,mixed> $o
     */
    private function seed(int $runId, array $run, array &$ck, array $o, StoreEndpoint $src, StoreEndpoint $tgt): void
    {
        $delta = $run['mode'] === 'delta';
        $since = $delta ? (int) $ck['since'] : (int) $ck['bulk_start'];
        foreach (HotTables::all() as $t) {
            if (!$delta && $t->kind === HotTable::ID) {
                $tgt->raiseNextId($t, ((int) ($ck['t'][$t->name]['hw'] ?? 0)) + 1);
            }
            if ($t->name === 'rmm_metric_hourly') {
                $from = $this->utc($this->floor($delta ? $since : (int) ($ck['hb'] ?? $this->floor($since, 3600)), 3600));
                $this->copyRange($t, $o, $src, $tgt, 'overwrite', $from);
            } elseif ($t->kind === HotTable::LATEST) {
                $this->copyRange($t, $o, $src, $tgt, 'newer', $this->utc($since - self::SEED_SLACK_S));
            }
        }
        $ck['dual_start'] = $this->now();
        $this->sql->database()->transaction(function () use ($runId, $run, &$ck): void {
            $this->runs->update($runId, ['state' => MigrationRuns::DUAL, 'dual_write' => 1, 'checkpoints' => $ck + []]);
            $this->settings->set(['data_store_phase' => 'dual:' . $run['to_store']]);
        });
        $this->emit(['event' => 'dual_write_on', 'run' => $runId]);
    }

    /**
     * @param array<string,mixed> $o
     */
    private function copyRange(HotTable $t, array $o, StoreEndpoint $src, StoreEndpoint $tgt, string $mode, ?string $timeFrom, ?string $timeBefore = null): int
    {
        $after = null;
        $n = 0;
        while (($rows = $src->readBatch($t, $after, $o['batch'], null, $timeBefore, $timeFrom)) !== []) {
            $tgt->insert($t, $rows, $mode, false);
            $after = array_map(static fn (string $k) => end($rows)[$k], $t->key);
            $n += count($rows);
            $this->throttle($o);
        }
        $this->emit(['event' => 'range', 'table' => $t->name, 'mode' => $mode, 'rows' => $n]);

        return $n;
    }

    /**
     * @param array<string,mixed> $ck
     * @param array<string,mixed> $o
     */
    private function tail(array &$ck, array $o, StoreEndpoint $src, StoreEndpoint $tgt, int $runId): void
    {
        $run = $this->runs->get($runId) ?? [];
        $ck['faults_seen'] = (int) ($run['dual_faults'] ?? 0);
        $this->repairWindow($ck, $o, $src, $tgt);
    }

    /**
     * Compare the rows of the window around the dual-write start as multisets and insert what the target lacks.
     *
     * @param array<string,mixed> $ck
     * @param array<string,mixed> $o
     */
    private function repairWindow(array $ck, array $o, StoreEndpoint $src, StoreEndpoint $tgt): void
    {
        $since = $ck['since'] !== null ? (int) $ck['since'] : min((int) ($ck['hw_at'] ?? $ck['dual_start']), (int) $ck['dual_start']) - self::SEED_SLACK_S;
        $until = $this->now() - 2;
        foreach (HotTables::all() as $t) {
            if ($t->kind === HotTable::ID) {
                $this->reconcile($t, $since, $until, $o, $src, $tgt);
            } elseif ($t->kind === HotTable::LATEST) {
                $this->copyRange($t, $o, $src, $tgt, 'newer', $this->utc($since));
            }
        }
    }

    /**
     * Multiset difference of one table over [$since, $until) in slices: every source row without a counterpart in the target is inserted (without its id).
     *
     * @param array<string,mixed> $o
     */
    private function reconcile(HotTable $t, int $since, int $until, array $o, StoreEndpoint $src, StoreEndpoint $tgt): int
    {
        $inserted = 0;
        for ($s = $this->floor($since, self::SLICE_S); $s < $until; $s += self::SLICE_S) {
            $from = $this->utc($s);
            $to = $this->utc($s + self::SLICE_S);
            $have = [];
            $after = null;
            while (($rows = $tgt->readBatch($t, $after, 5000, null, $to, $from)) !== []) {
                foreach ($rows as $r) {
                    $f = Fingerprint::of($t, $r);
                    $have[$f] = ($have[$f] ?? 0) + 1;
                }
                $after = array_map(static fn (string $k) => end($rows)[$k], $t->key);
            }
            $missing = [];
            $after = null;
            while (($rows = $src->readBatch($t, $after, 5000, null, $to, $from)) !== []) {
                foreach ($rows as $r) {
                    $f = Fingerprint::of($t, $r);
                    if (($have[$f] ?? 0) > 0) {
                        --$have[$f];
                    } else {
                        $missing[] = $r;
                    }
                }
                $after = array_map(static fn (string $k) => end($rows)[$k], $t->key);
            }
            foreach (array_chunk($missing, $o['batch']) as $chunk) {
                $inserted += $tgt->insert($t, $chunk, 'ignore', false);
                $this->throttle($o);
            }
        }
        if ($inserted > 0) {
            $this->emit(['event' => 'reconciled', 'table' => $t->name, 'inserted' => $inserted]);
        }

        return $inserted;
    }

    /**
     * @param array<string,mixed> $ck
     * @param array<string,mixed> $o
     */
    private function finalize(array &$ck, array $o, StoreEndpoint $src, StoreEndpoint $tgt): void
    {
        $since = $ck['since'] !== null ? (int) $ck['since'] : (int) $ck['bulk_start'];
        $daily = HotTables::get('rmm_metric_daily');
        $this->copyRange($daily, $o, $src, $tgt, 'overwrite', $this->utc($this->floor($since, 86400) - 3 * 86400));
        $state = HotTables::get('rmm_metric_rollup_state');
        $this->copyRange($state, $o, $src, $tgt, 'overwrite', null);
        foreach (HotTables::all() as $t) {
            if ($t->kind !== HotTable::LEDGER) {
                continue;
            }
            $events = HotTables::get((string) $t->ledgerOf);
            $counts = $tgt->countsByDevice($events);
            $after = null;
            $n = 0;
            while (($rows = $src->readBatch($t, $after, $o['batch'])) !== []) {
                foreach ($rows as &$r) {
                    $r['row_count'] = $counts[(int) $r['device_id']] ?? 0;
                }
                unset($r);
                $tgt->insert($t, $rows, 'overwrite', false);
                $after = array_map(static fn (string $k) => end($rows)[$k], $t->key);
                $n += count($rows);
                $this->throttle($o);
            }
            $this->emit(['event' => 'ledger', 'table' => $t->name, 'devices' => $n]);
        }
    }

    // ------------------------------------------------------------------ verification

    /**
     * @param array<string,mixed> $ck
     * @param array<string,mixed> $o
     */
    private function verifyWithRepair(int $runId, array &$ck, array $o, StoreEndpoint $src, StoreEndpoint $tgt): void
    {
        $problems = [];
        for ($attempt = 1; $attempt <= $o['verify_attempts']; ++$attempt) {
            $this->sleepSeconds($o['verify_lag_s']);
            [$report, $problems] = $this->verify($ck, $o, $src, $tgt);
            $faults = (int) ($this->runs->get($runId)['dual_faults'] ?? 0);
            if ($faults > (int) ($ck['faults_seen'] ?? 0)) {
                $problems[] = ['table' => '*', 'what' => 'dual_faults', 'detail' => 'writes to the second store failed since the last repair pass'];
            }
            $ck['summary'] = $report;
            $this->emit(['event' => 'verify', 'attempt' => $attempt, 'ok' => $problems === [], 'problems' => $problems]);
            if ($problems === []) {
                return;
            }
            if ($attempt < $o['verify_attempts']) {
                $ck['faults_seen'] = $faults;
                $this->repairWindow($ck, $o, $src, $tgt);
                $this->finalize($ck, $o, $src, $tgt);
            }
        }
        $this->runs->update($runId, ['summary' => ['problems' => $problems, 'tables' => $ck['summary'] ?? []]]);
        throw new MigrationException('Verification found differences between the two stores (' . count($problems) . '): ' . json_encode(array_slice($problems, 0, 5)) . '. Nothing was switched; dual-write is still on, so no data is lost. '
            . 'Fix the cause and resume (--resume), or abort (--abort).');
    }

    /**
     * @param array<string,mixed> $ck
     * @param array<string,mixed> $o
     * @return array{0:array<string,mixed>,1:list<array<string,mixed>>} [per-table report, problems]
     */
    private function verify(array $ck, array $o, StoreEndpoint $src, StoreEndpoint $tgt): array
    {
        $v = $this->now() - $o['verify_lag_s'];
        $from = $ck['since'] !== null ? $this->utc((int) $ck['since']) : null;
        $report = [];
        $problems = [];
        foreach (HotTables::all() as $t) {
            if ($t->kind === HotTable::STATE) {
                continue;
            }
            $before = match ($t->name) {
                'rmm_metric_hourly' => $this->utc($this->floor($v, 3600)),
                'rmm_metric_daily' => $this->utc($this->floor($v, 86400)),
                default => $t->timeCol === null ? null : $this->utc($v),
            };
            $fromT = $t->kind === HotTable::LEDGER ? null : $from;
            if ($fromT !== null && $t->name === 'rmm_metric_hourly') {
                $fromT = $this->utc($this->floor((int) $ck['since'], 3600));
            } elseif ($fromT !== null && $t->name === 'rmm_metric_daily') {
                $fromT = $this->utc($this->floor((int) $ck['since'], 86400));
            }
            $a = $src->count($t, $before, $fromT);
            $b = $tgt->count($t, $before, $fromT);
            $report[$t->name] = ['source' => $a, 'target' => $b];
            if ($a !== $b) {
                $problems[] = ['table' => $t->name, 'what' => 'row_count', 'source' => $a, 'target' => $b];
            }
            if ($t->kind === HotTable::LEDGER) {
                $events = HotTables::get((string) $t->ledgerOf);
                $counts = $tgt->countsByDevice($events);
                $after = null;
                $seen = [];
                while (($rows = $tgt->readBatch($t, $after, 5000)) !== []) {
                    foreach ($rows as $r) {
                        $seen[(int) $r['device_id']] = true;
                        if ((int) $r['row_count'] !== ($counts[(int) $r['device_id']] ?? 0)) {
                            $problems[] = ['table' => $t->name, 'what' => 'ledger_vs_rows', 'device' => (int) $r['device_id'], 'ledger' => (int) $r['row_count'], 'rows' => $counts[(int) $r['device_id']] ?? 0];
                        }
                    }
                    $after = array_map(static fn (string $k) => end($rows)[$k], $t->key);
                }
            }
            if ($o['sample'] !== 0 && $t->deviceCol !== null && $t->kind !== HotTable::LEDGER) {
                array_push($problems, ...$this->sample($t, $before, $fromT, $o, $src, $tgt));
            }
        }

        return [$report, $problems];
    }

    /**
     * Checksum of the rows of some devices (or assets) in both stores.
     *
     * @param array<string,mixed> $o
     * @return list<array<string,mixed>>
     */
    private function sample(HotTable $t, ?string $before, ?string $from, array $o, StoreEndpoint $src, StoreEndpoint $tgt): array
    {
        $owners = $src->owners($t, 5000);
        if ($o['sample'] > 0 && count($owners) > $o['sample']) {
            usort($owners, static fn (int $x, int $y): int => crc32('s' . $x) <=> crc32('s' . $y));
            $owners = array_slice($owners, 0, $o['sample']);
        }
        $problems = [];
        foreach ($owners as $owner) {
            $a = $this->ownerRows($t, $src, $owner, $before, $from);
            $b = $this->ownerRows($t, $tgt, $owner, $before, $from);
            if (count($a) !== count($b) || Fingerprint::digest($t, $a) !== Fingerprint::digest($t, $b)) {
                $problems[] = ['table' => $t->name, 'what' => 'checksum', 'owner' => $owner, 'source_rows' => count($a), 'target_rows' => count($b)];
            }
        }

        return $problems;
    }

    /** @return list<array<string,mixed>> */
    private function ownerRows(HotTable $t, StoreEndpoint $ep, int $owner, ?string $before, ?string $from): array
    {
        $rows = $ep->readOwner($t, $owner, $before, self::MAX_SAMPLE_ROWS);
        if ($from !== null && $t->timeCol !== null) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (string) $r[$t->timeCol] >= $from));
        }

        return $rows;
    }

    // ------------------------------------------------------------------ cut-over

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $ck
     */
    private function cutover(int $runId, array $run, array $ck): void
    {
        $now = $this->now();
        $delta = $run['mode'] === 'delta';
        $days = (int) ($this->settings->limits()['rmm_store_rollback_days'] ?? 7);
        $this->sql->database()->transaction(function () use ($runId, $run, $now, $days, $delta): void {
            $this->settings->set(['data_store' => $run['to_store'], 'data_store_phase' => null]);
            $this->runs->update($runId, ['state' => MigrationRuns::CUTOVER, 'dual_write' => 0, 'cutover_at' => $this->utc($now), 'finished_at' => $this->utc($now),
                'rollback_until' => $delta ? null : $this->utc($now + $days * 86400)]);
            if ($delta) {
                $this->runs->update((int) $run['params']['rolls_back'], ['state' => MigrationRuns::ROLLED_BACK]);
            }
        });
        $this->note($delta ? 'rmm.store_migration.rollback' : 'rmm.store_migration.cutover', 'RMM data store ' . ($delta ? 'rolled back' : 'cut over') . ' to ' . $run['to_store'] . ' (run ' . $runId . ').'
            . ($delta ? '' : ' The ' . $run['from_store'] . ' store is kept untouched for ' . $days . ' day(s); remove it with the purge command.'));
        $this->emit(['event' => 'cutover', 'run' => $runId, 'store' => $run['to_store'], 'rollback_until' => $delta ? null : $this->utc($now + $days * 86400)]);
    }

    // ------------------------------------------------------------------ preflight

    /**
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    private function dryRun(StoreEndpoint $src, StoreEndpoint $tgt, array $o): array
    {
        $tables = [];
        $total = 0;
        foreach (HotTables::all() as $t) {
            $n = $src->count($t);
            $tables[$t->name] = ['source_rows' => $n, 'target_rows' => $tgt->count($t), 'batches' => (int) ceil($n / max(1, $o['batch']))];
            $total += $n;
        }
        $nonEmpty = array_keys(array_filter($tables, static fn (array $x): bool => $x['target_rows'] > 0));
        $out = ['ok' => $nonEmpty === [], 'dry_run' => true, 'from' => $o['from'], 'to' => $o['to'], 'tables' => $tables, 'total_rows' => $total, 'target_not_empty' => $nonEmpty];
        $this->emit(['event' => 'dry_run'] + $out);

        return $out;
    }

    private function assertKinds(string $from, string $to): void
    {
        if (!in_array($from, RmmDataStoreKind::ALL, true) || !in_array($to, RmmDataStoreKind::ALL, true) || $from === $to) {
            throw new MigrationException('Choose two different stores: mariadb and postgres.');
        }
    }

    private function assertTables(StoreEndpoint $src, StoreEndpoint $tgt): void
    {
        foreach (HotTables::all() as $t) {
            foreach ([$src, $tgt] as $ep) {
                if (!$ep->tableExists($t)) {
                    throw new MigrationException('The ' . $ep->kind() . ' store has no table ' . $t->name . '. ' . ($ep->kind() === 'postgres' ? 'Run the PostgreSQL migration (scripts/rmm-postgres.php migrate).' : 'Run the Core migrations.'));
                }
            }
        }
    }

    private function assertTargetEmpty(StoreEndpoint $tgt, string $to): void
    {
        $full = [];
        foreach (HotTables::all() as $t) {
            if ($t->kind !== HotTable::STATE && $tgt->count($t) > 0) {
                $full[] = $t->name;
            }
        }
        if ($full !== []) {
            throw new MigrationException('The ' . $to . ' store already holds history (' . implode(', ', array_slice($full, 0, 4)) . (count($full) > 4 ? ', ...' : '') . '). Copying into it could mix two histories. '
                . 'If it is left over from an earlier try or a rollback, empty it with the purge command first.');
        }
    }

    private function prepareSchema(bool $dryRun): void
    {
        $pg = $this->postgresConnection();
        $m = new PostgresMigrator($pg);
        if ($dryRun) {
            $st = $m->status();
            if (!$st['exists'] || $st['pending'] !== []) {
                throw new MigrationException('The PostgreSQL schema is missing or behind (pending: ' . implode(', ', $st['pending']) . '). Run scripts/rmm-postgres.php migrate first.');
            }

            return;
        }
        $m->migrate(PostgresMigrator::MODE_AUTO);
    }

    // ------------------------------------------------------------------ plumbing

    private function endpoint(string $kind): StoreEndpoint
    {
        return $kind === RmmDataStoreKind::MARIADB ? new MariaDbEndpoint($this->sql) : new PostgresEndpoint($this->postgresConnection());
    }

    private function postgresConnection(): PostgresConnectionInterface
    {
        if ($this->pgResolved === null) {
            $c = $this->postgres instanceof \Closure ? ($this->postgres)() : $this->postgres;
            $this->pgResolved = $c ?? throw new MigrationException('No PostgreSQL connection is configured. Save one with the data store settings (or pass it in the bootstrap file).');
        }

        return $this->pgResolved;
    }

    /**
     * @param array<string,mixed> $in
     * @return array{from:string,to:string,dry_run:bool,resume:bool,batch:int,pause_ms:int,sample:int,actor:string,settle_s:int,verify_lag_s:int,verify_attempts:int,cutover:bool}
     */
    private function options(array $in): array
    {
        $limits = $this->settings->limits();

        return [
            'from' => (string) ($in['from'] ?? $this->settings->dataStore()),
            'to' => (string) ($in['to'] ?? ''),
            'dry_run' => !empty($in['dry_run']),
            'resume' => !empty($in['resume']),
            'batch' => max(10, min(50000, (int) ($in['batch'] ?? $limits['rmm_store_migrate_batch'] ?? 2000))),
            'pause_ms' => max(0, (int) ($in['pause_ms'] ?? $limits['rmm_store_migrate_pause_ms'] ?? 100)),
            'sample' => (int) ($in['sample'] ?? 50),
            'actor' => (string) ($in['actor'] ?? ''),
            'settle_s' => max(0, (int) ($in['settle_s'] ?? 5)),
            'verify_lag_s' => max(0, (int) ($in['verify_lag_s'] ?? 10)),
            'verify_attempts' => max(1, (int) ($in['verify_attempts'] ?? 3)),
            'cutover' => !array_key_exists('cutover', $in) || (bool) $in['cutover'],
        ];
    }

    /**
     * Between two batches: the configured pause, and a longer one while the load shedder is active.
     *
     * @param array<string,mixed> $o
     */
    private function throttle(array $o): void
    {
        if ($o['pause_ms'] > 0) {
            ($this->sleep)($o['pause_ms'] * 1000);
        }
        for ($i = 0; $i < 12 && (int) ($this->settings->get(true)['shed_level'] ?? 0) > 0; ++$i) {
            $this->emit(['event' => 'throttled', 'reason' => 'the load shedder is active']);
            ($this->sleep)(5_000_000);
        }
    }

    private function sleepSeconds(int $s): void
    {
        if ($s > 0) {
            ($this->sleep)($s * 1_000_000);
        }
    }

    private function lock(): void
    {
        if ((int) $this->sql->val("SELECT GET_LOCK('rivetcore_rmm_store_migration', 0)") !== 1) {
            throw new MigrationException('Another process is running a data store migration right now.');
        }
        $this->lockHeld = true;
    }

    private function unlock(): void
    {
        if ($this->lockHeld) {
            $this->lockHeld = false;
            try {
                $this->sql->val("SELECT RELEASE_LOCK('rivetcore_rmm_store_migration')");
            } catch (\Throwable) {
            }
        }
    }

    private function now(): int
    {
        return $this->sql->clock()->now()->getTimestamp();
    }

    private function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function epoch(string $utc): int
    {
        return (int) strtotime($utc . ' UTC');
    }

    private function floor(int $ts, int $span): int
    {
        return $ts - $ts % $span;
    }

    /**
     * @param array<string,mixed> $event
     */
    private function emit(array $event): void
    {
        if ($this->progress !== null) {
            ($this->progress)($event + ['at' => $this->utc($this->now())]);
        }
    }

    private function note(string $action, string $text): void
    {
        try {
            $this->audit?->record($action, $text, 0, 0);
        } catch (\Throwable) {
            // an audit failure never stops a migration
        }
    }
}
