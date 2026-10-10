<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

use RivetCore\Audit\AuditService;
use RivetCore\Contracts\ClockInterface;
use RivetCore\Support\SystemClock;

/**
 * Resumable batch re-encryption over an edition's {@see RewrapSource}.
 *
 * - Every write is a compare-and-set, so a row edited meanwhile is counted as a conflict and left to the next pass.
 * - A value that cannot be read is counted and left exactly as it was; the run goes on (or stops with `stopOnError`).
 * - Progress (cursor and counters) is saved after each batch, so a killed run continues where it stopped. Re-running is idempotent:
 *   values already on the current key are skipped.
 * - A dry run does the reading and verification and writes nothing, not even progress.
 * - With an {@see AuditService}, one `crypto.rewrap` event is logged per run (counts and ids only, never values).
 *
 * @api
 */
final class Rewrapper
{
    public const AUDIT_EVENT = 'crypto.rewrap';

    public function __construct(
        private RewrapStateStore $states,
        private ?AuditService $audit = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function run(RewrapSource $source, Reencryptor $reencryptor, ?RewrapOptions $options = null): RewrapReport
    {
        $options ??= new RewrapOptions();
        $job = $source->name();
        $clock = $this->clock ?? new SystemClock();
        $state = new RewrapState($job);
        if ($options->resume && !$options->dryRun) {
            $saved = $this->states->load($job);
            if ($saved !== null && $saved->status === RewrapState::RUNNING) {
                $state = $saved;
            }
        }
        $cursor = $state->cursor;
        $totals = ['scanned' => $state->scanned, 'rewrapped' => $state->rewrapped, 'skipped' => $state->skipped, 'conflicts' => $state->conflicts, 'failed' => $state->failed];
        $failedIds = [];
        $completed = false;
        $stopped = false;
        $batches = 0;
        $seen = 0;
        $started = microtime(true);

        while (true) {
            $items = $source->next($cursor, $options->batchSize);
            if ($items === []) {
                $completed = true;
                break;
            }
            foreach ($items as $item) {
                $totals['scanned']++;
                $seen++;
                $failedBefore = $totals['failed'];
                $this->handle($item, $source, $reencryptor, $options, $totals, $failedIds);
                if ($options->stopOnError && $totals['failed'] > $failedBefore) {
                    $stopped = true; // the cursor stays before the unreadable row, so a resume retries it once it is fixed
                    break;
                }
                $cursor = $item->id;
            }
            $batches++;
            if (!$options->dryRun) {
                $this->states->save($state->with($totals + ['cursor' => $cursor, 'status' => RewrapState::RUNNING, 'updatedAt' => $clock->now()->format('c')]));
            }
            if ($stopped
                || ($options->maxBatches !== null && $batches >= $options->maxBatches)
                || ($options->maxItems !== null && $seen >= $options->maxItems)
                || ($options->deadlineSeconds !== null && microtime(true) - $started >= $options->deadlineSeconds)) {
                break;
            }
        }

        if ($completed && !$options->dryRun) {
            $this->states->save($state->with($totals + ['cursor' => null, 'status' => RewrapState::COMPLETE, 'updatedAt' => $clock->now()->format('c')]));
        }
        $report = new RewrapReport($job, $options->dryRun, $completed, $totals['scanned'], $totals['rewrapped'], $totals['skipped'], $totals['conflicts'], $totals['failed'], $completed ? null : $cursor, array_slice($failedIds, 0, 100));
        $this->audit($report, $options);

        return $report;
    }

    /**
     * @param array<string,int> $totals
     * @param list<string> $failedIds
     */
    private function handle(RewrapItem $item, RewrapSource $source, Reencryptor $reencryptor, RewrapOptions $options, array &$totals, array &$failedIds): void
    {
        if ($item->ciphertext === '') {
            $totals['skipped']++;

            return;
        }
        try {
            $new = $reencryptor->reencrypt($item->ciphertext, $item->context);
        } catch (CryptoException) {
            $totals['failed']++;
            $failedIds[] = $item->id;

            return;
        }
        if ($new === null || $new === $item->ciphertext) {
            $totals['skipped']++;

            return;
        }
        if ($options->dryRun) {
            $totals['rewrapped']++;

            return;
        }
        if ($source->replace($item->id, $item->ciphertext, $new)) {
            $totals['rewrapped']++;
        } else {
            $totals['conflicts']++;
        }
    }

    private function audit(RewrapReport $r, RewrapOptions $options): void
    {
        if ($this->audit === null) {
            return;
        }
        $action = $r->dryRun ? 'dry_run' : ($r->completed ? 'complete' : 'partial');
        try {
            $this->audit->log(
                self::AUDIT_EVENT,
                $options->actorUserId,
                'rewrap_job',
                $r->job,
                $action,
                sprintf('Rewrap %s: %d scanned, %d rewrapped, %d skipped, %d conflicts, %d failed', $r->job, $r->scanned, $r->rewrapped, $r->skipped, $r->conflicts, $r->failed),
                ['scanned' => $r->scanned, 'rewrapped' => $r->rewrapped, 'skipped' => $r->skipped, 'conflicts' => $r->conflicts, 'failed' => $r->failed, 'failed_ids' => array_slice($r->failedIds, 0, 20), 'completed' => $r->completed],
            );
        } catch (\Throwable) {
            // The audit trail is best effort here; the rewrap itself has already been committed row by row.
        }
    }
}
