<?php

declare(strict_types=1);

namespace RivetCore\Audit;

/** One page of audit events (newest first). Each row has `metadata` decoded (array, or null when absent/invalid JSON).
 *
 * @api
 */
final class AuditPage
{
    /** @param list<array<string,mixed>> $rows */
    public function __construct(
        public readonly array $rows,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pages,
        public readonly int $perPage,
    ) {
    }
}
