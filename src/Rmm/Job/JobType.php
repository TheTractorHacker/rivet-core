<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Job;

/**
 * One entry of the job-type registry: what a type is called on the wire, which ability a technician needs to queue it, whether it
 * is destructive by default, the platforms it runs on, its parameter validator, its default timeout and how its output is kept.
 *
 * @api
 */
final class JobType
{
    /**
     * @param string $type wire name (endpoint_agent_jobs.type, at most 20 characters)
     * @param string $ability RmmAbility value a technician needs ("rmm.job.run_script" ...); the saved-script variant is decided by the caller
     * @param bool $destructive destructive by default: offered at most once, never auto-retried
     * @param list<string> $platforms informational in Phase 0 (the agent refuses what it cannot run); used by capability negotiation later
     * @param bool $requiresScript a script body is required (PowerShell); other types never carry one
     * @param (\Closure(array<string,mixed>):array{0:array<string,mixed>,1:?string})|null $paramValidator receives the params, returns [normalised params, error]
     * @param int|null $defaultTimeoutS null means the instance default (job_default_timeout_s)
     * @param bool $keepOutput false for types whose output is never stored
     */
    public function __construct(
        public readonly string $type,
        public readonly string $ability,
        public readonly bool $destructive = false,
        public readonly array $platforms = ['windows'],
        public readonly bool $requiresScript = false,
        public readonly ?\Closure $paramValidator = null,
        public readonly ?int $defaultTimeoutS = null,
        public readonly bool $keepOutput = true,
    ) {
    }
}
