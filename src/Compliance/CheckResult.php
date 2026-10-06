<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

/** The outcome of one automatic check.
 *
 * @api
 */
final readonly class CheckResult
{
    /**
     * @param array<string, scalar|null> $metrics the raw numbers behind the verdict (shown in reports)
     */
    public function __construct(
        public Status $status,
        public string $summary,
        public ?string $detail = null,
        public ?string $fixPath = null,
        public array $metrics = [],
    ) {
    }

    /** @param array<string, scalar|null> $metrics */
    public static function pass(string $summary, ?string $detail = null, array $metrics = []): self
    {
        return new self(Status::Pass, $summary, $detail, null, $metrics);
    }

    /** @param array<string, scalar|null> $metrics */
    public static function warn(string $summary, ?string $detail = null, ?string $fixPath = null, array $metrics = []): self
    {
        return new self(Status::Warn, $summary, $detail, $fixPath, $metrics);
    }

    /** @param array<string, scalar|null> $metrics */
    public static function fail(string $summary, ?string $detail = null, ?string $fixPath = null, array $metrics = []): self
    {
        return new self(Status::Fail, $summary, $detail, $fixPath, $metrics);
    }

    public static function notApplicable(string $summary, ?string $detail = null): self
    {
        return new self(Status::NotApplicable, $summary, $detail);
    }

    public static function error(string $summary): self
    {
        return new self(Status::Error, $summary);
    }
}
