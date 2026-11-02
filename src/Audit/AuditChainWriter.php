<?php

declare(strict_types=1);

namespace RivetCore\Audit;

use RivetCore\Database\DatabaseInterface;

/**
 * Appends one audit row to the hash chain (ERR-04). Used by AuditService when it is given a writer; an edition that inserts into
 * audit_events with its own SQL is not chained.
 *
 * Concurrency: one transaction does   SELECT ... FROM audit_chain_head WHERE id = 1 FOR UPDATE ; INSERT the row ; read it back ;
 * UPDATE its prev_hash/row_hash ; UPDATE the head ; COMMIT.   The row lock on the single head row makes writers take turns, so two
 * requests cannot read the same prev_hash and fork the chain, and rows are chained in commit order. Chosen over GET_LOCK() because the
 * lock belongs to the transaction (released on commit, rollback or a dropped connection - no leaked named lock, works through
 * connection poolers) and the insert, the hash and the head move together or not at all. The cost is that audit writes are serialised
 * for the length of one short transaction; an edition that calls AuditService from inside a long outer transaction holds the head lock
 * until that transaction ends, so log after the commit where possible.
 *
 * Never loses an event: if the chain cannot be extended (migration not applied yet, key changed, lock timeout) the row is still
 * inserted, unchained, and the verifier reports it as "unchained after the chain started".
 *
 * @api
 */
final class AuditChainWriter
{
    private bool $unavailable = false;

    /** @param string|null $key HMAC key (see AuditChainHasher::deriveKey); null = plain SHA-256 */
    public function __construct(private DatabaseInterface $database, private ?string $key = null)
    {
    }

    /**
     * Insert the row described by $insertSql/$params (an INSERT INTO audit_events) and chain it.
     *
     * @param list<mixed> $params
     * @return int the new audit_id
     * @throws \Throwable when the chain could not be extended; nothing was inserted then (the transaction rolled back)
     */
    public function append(string $insertSql, array $params): int
    {
        if ($this->unavailable) {
            throw new \RuntimeException('Audit chain tables are not installed.');
        }
        try {
            return $this->database->transaction(function () use ($insertSql, $params): int {
                $head = $this->lockHead();
                $mode = AuditChainHasher::mode($this->key);
                $tag = AuditChainHasher::keyTag($this->key);
                if ($head['head_audit_id'] !== null && ($head['hash_mode'] !== $mode || $head['key_fp'] !== $tag)) {
                    throw new \RuntimeException('The audit chain was started with a different key or mode.');
                }

                $id = $this->database->execute($insertSql, $params)->insertId;
                if ($id === null || $id < 1) {
                    throw new \RuntimeException('Audit insert returned no id.');
                }
                $row = $this->database->fetchOne('SELECT * FROM audit_events WHERE audit_id = ?', [$id]);
                if ($row === null) {
                    throw new \RuntimeException('Audit row not readable after insert.');
                }
                $prev = (string) $head['head_hash'];
                $hash = AuditChainHasher::rowHash($row, $prev, $this->key);
                $this->database->execute('UPDATE audit_events SET prev_hash = ?, row_hash = ? WHERE audit_id = ?', [$prev, $hash, $id]);
                $this->database->execute(
                    'UPDATE audit_chain_head SET head_audit_id = ?, head_hash = ?, hash_mode = ?, key_fp = ?, updated_at = NOW() WHERE id = 1',
                    [$id, $hash, $mode, $tag]
                );

                return $id;
            });
        } catch (\Throwable $e) {
            // Missing table or column (1146 / 1054): stop trying for this object; a transient error (lock wait, deadlock) is retried next time.
            if (in_array((int) $e->getCode(), [1146, 1054], true) || str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), 'Unknown column')) {
                $this->unavailable = true;
            }
            throw $e;
        }
    }

    /** @return array{head_audit_id:?int,head_hash:string,hash_mode:string,key_fp:string} */
    private function lockHead(): array
    {
        $sql = 'SELECT head_audit_id, head_hash, hash_mode, key_fp FROM audit_chain_head WHERE id = 1 FOR UPDATE';
        $r = $this->database->fetchOne($sql);
        if ($r === null) {
            // The migration seeds this row; recreate it if it was removed (a lost head is also what the verifier would flag).
            $this->database->execute('INSERT IGNORE INTO audit_chain_head (id, head_hash) VALUES (1, ?)', [AuditChainHasher::GENESIS]);
            $r = $this->database->fetchOne($sql);
        }
        if ($r === null) {
            throw new \RuntimeException('audit_chain_head is not readable.');
        }

        return [
            'head_audit_id' => $r['head_audit_id'] === null ? null : (int) $r['head_audit_id'],
            'head_hash' => (string) $r['head_hash'],
            'hash_mode' => (string) $r['hash_mode'],
            'key_fp' => (string) $r['key_fp'],
        ];
    }
}
