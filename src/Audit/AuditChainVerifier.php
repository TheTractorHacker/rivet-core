<?php

declare(strict_types=1);

namespace RivetCore\Audit;

use RivetCore\Database\DatabaseException;
use RivetCore\Database\DatabaseInterface;

/**
 * Read-only check of the audit hash chain (ERR-04). Walks the chained rows oldest first in keyset batches (constant memory) and checks
 *  - every row's hash against its own fields and prev_hash (an edited row),
 *  - every prev_hash against the row before it (a deleted, inserted or reordered row),
 *  - the oldest kept row: its prev_hash is the genesis value or the anchor of a recorded retention prune (see AuditChainCheckpoints),
 *    so deleting old rows by hand is flagged while RetentionService pruning is not,
 *  - the newest row against audit_chain_head and, when given, against an off-database anchor (a deleted tail),
 *  - rows without a hash: those before the chain began are counted as legacy, those after it as a failure.
 *
 * What it cannot do: someone who can write the database AND (with a key) holds the key, or (without one) simply recomputes SHA-256,
 * can rewrite the whole chain, the head row and the checkpoints consistently. Only an anchor stored outside the database
 * (exportHead(), kept by the edition elsewhere) catches that, and only for rows older than the anchor.
 *
 * @api
 */
final class AuditChainVerifier
{
    public function __construct(private DatabaseInterface $database, private ?string $key = null, private int $batchSize = 1000)
    {
    }

    /**
     * @param array{id:int,hash:string}|null $anchor a head previously taken with exportHead() and stored outside the database
     */
    public function verify(?array $anchor = null): AuditChainResult
    {
        try {
            return $this->run($anchor);
        } catch (DatabaseException $e) {
            if (in_array((int) $e->getCode(), [1146, 1054], true) || str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), 'Unknown column')) {
                return new AuditChainResult(AuditChainResult::NOT_INSTALLED, reason: 'The audit chain tables are not installed; run Core migration 0048_audit_hash_chain.');
            }
            throw $e;
        }
    }

    /**
     * The newest chained row, for storing OFF the database (a file on another host, a log pipeline, a ticket). Keep several over time:
     * pass any of them to verify() later. Null when nothing is chained yet.
     *
     * @return array{id:int,hash:string,mode:string,exported_at:string}|null
     */
    public function exportHead(): ?array
    {
        $row = $this->database->fetchOne('SELECT audit_id, row_hash FROM audit_events WHERE row_hash IS NOT NULL ORDER BY audit_id DESC LIMIT 1');
        if ($row === null) {
            return null;
        }

        return ['id' => (int) $row['audit_id'], 'hash' => (string) $row['row_hash'], 'mode' => AuditChainHasher::mode($this->key), 'exported_at' => gmdate('Y-m-d\TH:i:s\Z')];
    }

    /** @param array{id:int,hash:string}|null $anchor */
    private function run(?array $anchor): AuditChainResult
    {
        // The head is read first: a writer commits the row and the head together, so every row up to the head is visible to the walk.
        $head = $this->database->fetchOne('SELECT head_audit_id, head_hash, hash_mode, key_fp FROM audit_chain_head WHERE id = 1');
        $anyChained = (int) ($this->database->fetchOne('SELECT COUNT(*) AS c FROM (SELECT 1 FROM audit_events WHERE row_hash IS NOT NULL LIMIT 1) t')['c'] ?? 0) > 0;
        $headId = $head !== null && $head['head_audit_id'] !== null ? (int) $head['head_audit_id'] : null;
        $headHash = $head !== null ? (string) $head['head_hash'] : AuditChainHasher::GENESIS;
        $key = $this->key !== null && $this->key !== '' ? $this->key : null;

        if ($head !== null && $head['hash_mode'] !== '') {
            if ($head['hash_mode'] === 'hmac' && $key === null) {
                return new AuditChainResult(AuditChainResult::KEY_UNAVAILABLE, reason: 'The chain was started with a key and none was supplied.');
            }
            if ($head['hash_mode'] !== AuditChainHasher::mode($key) || $head['key_fp'] !== AuditChainHasher::keyTag($key)) {
                return new AuditChainResult(AuditChainResult::KEY_MISMATCH, reason: 'The key (or mode) differs from the one that started the chain; supply the original key. This is not reported as tampering.');
            }
        }

        $chained = 0;
        $firstId = null;
        $prev = null;
        $lastId = 0;
        $lastHash = null;
        $after = 0;
        do {
            $rows = $this->database->fetchAll(
                'SELECT * FROM audit_events WHERE row_hash IS NOT NULL AND audit_id > ? ORDER BY audit_id ASC LIMIT ' . max(1, $this->batchSize),
                [$after]
            );
            foreach ($rows as $row) {
                $id = (int) $row['audit_id'];
                $after = $id;
                if ($firstId === null) {
                    $firstId = $id;
                    $p = (string) ($row['prev_hash'] ?? '');
                    if ($p !== AuditChainHasher::GENESIS && !$this->isCheckpointAnchor($p)) {
                        return new AuditChainResult(AuditChainResult::BROKEN, $chained, firstBrokenId: $id, reason: "Audit row #$id: the rows before it were removed without a recorded retention prune (its prev_hash is neither the chain start nor a prune checkpoint).", firstChainedId: $id);
                    }
                } elseif (!hash_equals((string) $prev, (string) ($row['prev_hash'] ?? ''))) {
                    return new AuditChainResult(AuditChainResult::BROKEN, $chained, firstBrokenId: $id, reason: "Audit row #$id: its prev_hash does not match the row before it (a row was removed, inserted or reordered).", firstChainedId: $firstId);
                }
                if (!hash_equals(AuditChainHasher::rowHash($row, (string) ($row['prev_hash'] ?? ''), $key), (string) $row['row_hash'])) {
                    return new AuditChainResult(AuditChainResult::BROKEN, $chained, firstBrokenId: $id, reason: "Audit row #$id: its contents no longer match its hash (the row was edited).", firstChainedId: $firstId);
                }
                $prev = (string) $row['row_hash'];
                $lastId = $id;
                $lastHash = $prev;
                $chained++;
            }
        } while (count($rows) >= max(1, $this->batchSize));

        if ($chained === 0) {
            if ($anyChained) {
                return new AuditChainResult(AuditChainResult::BROKEN, reason: 'Chained rows exist but could not be walked.');
            }
            if ($headId !== null && !$this->isCheckpointAnchor($headHash)) {
                return new AuditChainResult(AuditChainResult::BROKEN, firstBrokenId: $headId, reason: "The chain head points at row #$headId but no chained row is left and no retention prune accounts for it (the rows were deleted).");
            }
            $legacy = (int) ($this->database->fetchOne('SELECT COUNT(*) AS c FROM audit_events WHERE row_hash IS NULL')['c'] ?? 0);

            return new AuditChainResult(AuditChainResult::EMPTY, legacyUnchained: $legacy, headId: $headId, headHash: $headId === null ? null : $headHash, reason: $headId === null ? 'The chain has not started: no row has been chained yet.' : 'Every chained row was pruned by retention.');
        }

        // Tail: the head recorded by the writers must be the row we ended on (or an older one still in place), never a row that is gone.
        if ($headId !== null) {
            if ($lastId < $headId) {
                return new AuditChainResult(AuditChainResult::BROKEN, $chained, firstBrokenId: $headId, reason: "Rows at the end of the chain were removed (the chain head is row #$headId, the newest chained row is #$lastId).", firstChainedId: $firstId);
            }
            $at = $this->database->fetchOne('SELECT row_hash FROM audit_events WHERE audit_id = ?', [$headId]);
            if ($at === null) {
                if (!$this->isCheckpointAnchor($headHash)) {
                    return new AuditChainResult(AuditChainResult::BROKEN, $chained, firstBrokenId: $headId, reason: "The row the chain head points at (#$headId) is gone and no retention prune accounts for it.", firstChainedId: $firstId);
                }
            } elseif (!hash_equals($headHash, (string) $at['row_hash'])) {
                return new AuditChainResult(AuditChainResult::BROKEN, $chained, firstBrokenId: $headId, reason: "Row #$headId no longer has the hash recorded in the chain head.", firstChainedId: $firstId);
            }
        } else {
            return new AuditChainResult(AuditChainResult::BROKEN, $chained, reason: 'Chained rows exist but the chain head record says none were written (the head was reset).', firstChainedId: $firstId);
        }

        $anchorState = 'none';
        if ($anchor !== null) {
            $aid = (int) $anchor['id'];
            $ahash = (string) $anchor['hash'];
            if ($aid > $lastId) {
                return new AuditChainResult(AuditChainResult::BROKEN, $chained, firstBrokenId: $aid, reason: "The external anchor is row #$aid but the newest chained row is #$lastId (rows at the end were removed).", firstChainedId: $firstId, anchor: 'none');
            }
            $at = $this->database->fetchOne('SELECT row_hash FROM audit_events WHERE audit_id = ?', [$aid]);
            if ($at !== null) {
                if (!hash_equals($ahash, (string) $at['row_hash'])) {
                    return new AuditChainResult(AuditChainResult::BROKEN, $chained, firstBrokenId: $aid, reason: "Row #$aid does not match the external anchor (the chain was rewritten).", firstChainedId: $firstId);
                }
                $anchorState = 'verified';
            } elseif ($aid >= (int) $firstId) {
                return new AuditChainResult(AuditChainResult::BROKEN, $chained, firstBrokenId: $aid, reason: "Row #$aid named by the external anchor is missing from inside the chain.", firstChainedId: $firstId);
            } else {
                $anchorState = 'predates_kept_rows';
            }
        }

        $legacy = (int) ($this->database->fetchOne('SELECT COUNT(*) AS c FROM audit_events WHERE row_hash IS NULL AND audit_id < ?', [$firstId])['c'] ?? 0);
        $late = $this->database->fetchOne('SELECT COUNT(*) AS c, MIN(audit_id) AS first FROM audit_events WHERE row_hash IS NULL AND audit_id > ?', [$firstId]);
        $lateCount = (int) ($late['c'] ?? 0);
        if ($lateCount > 0) {
            $fid = (int) $late['first'];

            return new AuditChainResult(AuditChainResult::UNCHAINED_ROWS, $chained, $legacy, $lateCount, $fid, "$lateCount audit row(s) after the chain started carry no hash (first: #$fid): written outside the chain, or while the chain was unavailable.", $firstId, $lastId, $lastHash, $anchorState);
        }

        return new AuditChainResult(AuditChainResult::OK, $chained, $legacy, 0, null, '', $firstId, $lastId, $lastHash, $anchorState);
    }

    private function isCheckpointAnchor(string $hash): bool
    {
        return $hash !== '' && $this->database->fetchOne('SELECT 1 AS x FROM audit_chain_checkpoint WHERE anchor_hash = ? LIMIT 1', [$hash]) !== null;
    }
}
