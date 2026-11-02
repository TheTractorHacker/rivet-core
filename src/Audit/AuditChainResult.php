<?php

declare(strict_types=1);

namespace RivetCore\Audit;

/**
 * Outcome of AuditChainVerifier::verify(). `$ok` is true only when nothing is wrong: status 'ok' (chained rows verified) or 'empty'
 * (the chain has not started yet, so there is nothing to verify - say so in the UI rather than showing a green tick).
 *
 * status: ok | empty | broken | unchained_rows | key_unavailable | key_mismatch | not_installed
 *
 * @api
 */
final readonly class AuditChainResult
{
    public const OK = 'ok';
    public const EMPTY = 'empty';
    public const BROKEN = 'broken';
    public const UNCHAINED_ROWS = 'unchained_rows';
    public const KEY_UNAVAILABLE = 'key_unavailable';
    public const KEY_MISMATCH = 'key_mismatch';
    public const NOT_INSTALLED = 'not_installed';

    /** True for status ok or empty only. */
    public bool $ok;

    public function __construct(
        public string $status,
        /** Chained rows whose link and hash were checked. */
        public int $chained = 0,
        /** Rows from before the chain started (no hash, older than the first chained row): not covered, reported not hidden. */
        public int $legacyUnchained = 0,
        /** Rows with no hash that sit after the first chained row: inserted outside the chain or while it was unavailable. */
        public int $unchainedAfterStart = 0,
        /** audit_id of the first row that fails, or of the first unchained row after the start. */
        public ?int $firstBrokenId = null,
        public string $reason = '',
        public ?int $firstChainedId = null,
        public ?int $headId = null,
        public ?string $headHash = null,
        /** none (no external anchor given) | verified | predates_kept_rows (the anchored row was pruned, so it cannot be checked) */
        public string $anchor = 'none',
    ) {
        $this->ok = $status === self::OK || $status === self::EMPTY;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'status' => $this->status,
            'chained' => $this->chained,
            'legacy_unchained' => $this->legacyUnchained,
            'unchained_after_start' => $this->unchainedAfterStart,
            'first_broken_id' => $this->firstBrokenId,
            'reason' => $this->reason,
            'first_chained_id' => $this->firstChainedId,
            'head_id' => $this->headId,
            'head_hash' => $this->headHash,
            'anchor' => $this->anchor,
        ];
    }
}
