<?php

declare(strict_types=1);

namespace RivetCore\Audit;

use RivetCore\Database\DatabaseInterface;

/**
 * Lets the audit hash chain survive legitimate pruning (ERR-04). When RetentionService deletes the oldest audit rows, the first row that
 * is kept still holds, in its prev_hash, the hash of the last row that was deleted. record() stores that value as a checkpoint
 * "anchor"; the verifier accepts an oldest-kept row whose prev_hash is the genesis value or a recorded anchor. Rows removed any other
 * way leave a prev_hash that no checkpoint accounts for, which the verifier reports.
 *
 * Honest limit: the checkpoint table lives in the same database, so whoever can edit audit_events can also add a checkpoint. It makes
 * pruning explicit and accidental or partial deletion visible; it does not stop a database administrator (use an off-database anchor).
 *
 * @api
 */
final class AuditChainCheckpoints
{
    /**
     * Call after audit rows were deleted from the OLD end. Does nothing when no row was chained, or when nothing chained was removed.
     * Safe to call when the chain tables do not exist (returns false). The head row is locked while the anchor is read, so a
     * concurrent writer cannot change which row is the oldest kept one.
     *
     * @return bool true when a checkpoint was written
     */
    public static function record(DatabaseInterface $database, int $rowsPruned): bool
    {
        if ($rowsPruned < 1) {
            return false;
        }
        try {
            return (bool) $database->transaction(static function () use ($database, $rowsPruned): bool {
                $head = $database->fetchOne('SELECT head_audit_id, head_hash FROM audit_chain_head WHERE id = 1 FOR UPDATE');
                if ($head === null || ($head['head_audit_id'] ?? null) === null) {
                    return false; // the chain never started: nothing chained can have been pruned
                }
                $first = $database->fetchOne('SELECT prev_hash FROM audit_events WHERE row_hash IS NOT NULL ORDER BY audit_id ASC LIMIT 1');
                $anchor = $first !== null ? (string) $first['prev_hash'] : (string) $head['head_hash'];
                if ($anchor === '' || $anchor === AuditChainHasher::GENESIS) {
                    return false; // the oldest chained row is still there
                }
                if ($database->fetchOne('SELECT 1 AS x FROM audit_chain_checkpoint WHERE anchor_hash = ? LIMIT 1', [$anchor]) !== null) {
                    return false; // already recorded
                }
                $database->execute('INSERT INTO audit_chain_checkpoint (anchor_hash, rows_pruned) VALUES (?, ?)', [$anchor, $rowsPruned]);

                return true;
            });
        } catch (\Throwable) {
            return false; // never let bookkeeping fail a prune; the verifier then reports the gap
        }
    }
}
