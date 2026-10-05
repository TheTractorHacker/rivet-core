<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

/**
 * A control no software can verify (a policy, a review, a test). A person records that they did it, with a note, and it
 * stays "current" until the review interval runs out.
 */
final readonly class ManualItem
{
    /**
     * @param array<string, list<string>> $controls indicative control references per framework
     * @param int $intervalDays how often it must be redone (365 = yearly)
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $category,
        public string $why,
        public array $controls,
        public int $intervalDays = 365,
    ) {
    }
}
