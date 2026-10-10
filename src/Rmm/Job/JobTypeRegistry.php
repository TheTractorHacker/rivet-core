<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Job;

/**
 * The job types the module knows. Phase 0 seeds the three that exist today with identical behaviour (Phase 2 adds `shell` and `python`): `powershell` (script,
 * ability run_script, or run_saved for a library script), `reboot` (always destructive, params.delay_s 5 to 3600 default 30) and
 * `collect` (re-collect inventory now). Adding a type later is a registration plus an agent handler, never a protocol change.
 *
 * @api
 */
final class JobTypeRegistry
{
    public const ABILITY_RUN_SAVED = 'rmm.job.run_saved';
    public const ABILITY_REBOOT = 'rmm.job.reboot';
    public const ABILITY_RUN_SCRIPT = 'rmm.job.run_script';

    /** @var array<string,JobType> */
    private array $types = [];

    public function __construct()
    {
    }

    /** The registry with the three seed types. */
    public static function withDefaults(): self
    {
        $r = new self();
        $r->register(new JobType('powershell', self::ABILITY_RUN_SCRIPT, false, ['windows'], true));
        // Phase 2: the same free-form script runner for the other platform (`shell` = bash, or POSIX sh when bash is missing) and for Python 3. The agent
        // runs a type only on the platforms it lists in its `job:<type>` capabilities.
        $r->register(new JobType('shell', self::ABILITY_RUN_SCRIPT, false, ['linux'], true));
        $r->register(new JobType('python', self::ABILITY_RUN_SCRIPT, false, ['linux'], true));
        $r->register(new JobType('reboot', self::ABILITY_REBOOT, true, ['windows', 'linux'], false, static function (array $params): array {
            $delay = $params['delay_s'] ?? 30;
            if (!is_int($delay) || $delay < 5 || $delay > 3600) {
                return [$params, 'A reboot delay (params.delay_s) must be 5 to 3600 seconds.'];
            }
            $params['delay_s'] = $delay;

            return [$params, null];
        }));
        $r->register(new JobType('collect', self::ABILITY_RUN_SAVED, false, ['windows', 'linux']));

        return $r;
    }

    public function register(JobType $type): void
    {
        if (strlen($type->type) < 1 || strlen($type->type) > 20 || preg_match('/^[a-z][a-z0-9_]*$/', $type->type) !== 1) {
            throw new \InvalidArgumentException('a job type is 1 to 20 characters of a-z, 0-9 and _');
        }
        $this->types[$type->type] = $type;
    }

    public function get(string $type): ?JobType
    {
        return $this->types[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->types);
    }
}
