<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

/** @api */
interface AttestationProviderInterface
{
    /**
     * The most recent review of each item, keyed by item id: the review recorded last wins (also when two share a date), an
     * item that was never reviewed is absent, and every entry has exactly the four keys below, dates as Y-m-d strings or null.
     * Checked by Testing\AttestationProviderConformanceTestCase.
     *
     * @return array<string, array{reviewed_on:string, next_due_on:?string, reviewer_name:string, note:?string}>
     */
    public function latestPerItem(): array;
}
