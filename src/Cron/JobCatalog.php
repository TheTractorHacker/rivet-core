<?php

declare(strict_types=1);

namespace RivetCore\Cron;

/**
 * Plain-language facts about an edition's cron scripts, and whether the admin UI may start each one. A script
 * that sends real email, writes to outside systems, or needs arguments is not startable from the UI: it keeps
 * running on its schedule, and the reason is shown instead of a button. The entries are the edition's data.
 *
 * @api
 */
final class JobCatalog
{
    /**
     * @param array<string, array{label:string, description:string, run_now:bool, note:string, dir?:string}> $entries keyed by script file name; dir defaults to cron
     * @param list<string> $needsArguments scripts that can only run from a schedule line that supplies arguments
     */
    public function __construct(private array $entries, private array $needsArguments = [])
    {
    }

    /** @return array<string, array{label:string, description:string, run_now:bool, note:string, dir?:string}> */
    public function all(): array
    {
        return $this->entries;
    }

    /** Directory (under the app root) a catalog script lives in: cron or scripts. */
    public function dir(string $scriptFile): string
    {
        return $this->entries[$scriptFile]['dir'] ?? 'cron';
    }

    public function describe(string $scriptFile): array
    {
        return $this->entries[$scriptFile] ?? ['label' => $scriptFile, 'description' => 'Custom or unrecognized job.', 'run_now' => false, 'note' => 'Not in the known job list, so it cannot be started from here.'];
    }

    public function needsArguments(string $scriptFile): bool
    {
        return in_array($scriptFile, $this->needsArguments, true);
    }
}
