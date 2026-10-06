<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

/**
 * One automatic, read-only check. A check never changes anything and never throws to its caller: the assessor turns an
 * exception into an "error" result for that check only.
 *
 * @api
 */
interface CheckInterface
{
    /** Stable id, e.g. "mfa_coverage". Used in snapshots and reports; never reuse or rename. */
    public function id(): string;

    public function title(): string;

    /** Grouping heading, e.g. "Access control". */
    public function category(): string;

    /** One or two plain sentences on why this matters. */
    public function why(): string;

    /**
     * Indicative control references per framework, e.g. ['iso27001' => ['A.8.5'], 'pci' => ['8.4.2']].
     *
     * @return array<string, list<string>>
     */
    public function controls(): array;

    public function run(): CheckResult;
}
