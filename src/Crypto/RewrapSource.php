<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * An edition's view of one encrypted column (or one kind of encrypted value). The Rewrapper walks it in pages and writes back through
 * compare-and-set, so it is safe against concurrent edits and can be interrupted at any point.
 *
 * Contract (checked by {@see \RivetCore\Testing\RewrapSourceConformanceTestCase}):
 * - next() returns rows in a stable order, strictly after the row whose id is the cursor, at most $limit, an empty list at the end.
 *   Ids are opaque strings; the order is the source's own (for an integer key, numeric order), so a cursor never skips or repeats rows.
 * - next() has no side effects and returns the current stored value each time.
 * - replace() is atomic compare-and-set: it writes $replacement only if the stored value still equals $expected, and returns whether it did.
 *   Typical SQL: `UPDATE t SET col = ? WHERE id = ? AND col = ?` and `affected_rows === 1`.
 * - the item context is the exact AAD the value was (or will be) sealed with.
 *
 * @api
 */
interface RewrapSource
{
    /** Stable name of this source; it keys the saved progress, for example "settings" or "vault.credentials.password". */
    public function name(): string;

    /** @return list<RewrapItem> */
    public function next(?string $afterId, int $limit): array;

    public function replace(string $id, string $expected, string $replacement): bool;
}
