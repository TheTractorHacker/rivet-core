<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Compliance\CheckInterface;
use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\RequirementsProviderInterface;
use RivetCore\Contracts\ClockInterface;

/**
 * Shared shape of the checks driven by the selected framework profile: with no profile, or a profile that does not ask for this, the check is "not
 * applicable" (left out of the score) so an install that has not opted in sees no change in its results. Otherwise {@see evaluate()} judges the requirement.
 *
 * The detail text of a result is a list of evidence lines, one per line, each starting "Met:", "Not met:", "Evidence:" or "Caveat:" so a report reads as
 * requirement, status, evidence.
 *
 * @internal
 */
abstract class AbstractProfileCheck implements CheckInterface
{
    public function __construct(protected RequirementsProviderInterface $profiles, protected ClockInterface $clock, protected string $settingsPath = '')
    {
    }

    /** @return list<string> the {@see \RivetCore\Compliance\Profile\RequirementType} ids this check evaluates; it runs when the profile has any of them */
    abstract public function requirementTypes(): array;

    /** Does the profile ask for what this check judges? The default: it has a requirement of one of {@see requirementTypes()}. */
    public function appliesTo(FrameworkProfile $profile): bool
    {
        foreach ($this->requirementTypes() as $type) {
            if ($profile->has($type)) {
                return true;
            }
        }

        return false;
    }

    abstract protected function evaluate(FrameworkProfile $profile): CheckResult;

    final public function run(): CheckResult
    {
        $profile = $this->profiles->selectedProfile();
        if ($profile === null) {
            return CheckResult::notApplicable('No compliance framework is selected.');
        }
        if ($this->appliesTo($profile)) {
            return $this->evaluate($profile);
        }

        return CheckResult::notApplicable('The selected framework (' . $profile->label . ') does not require this.');
    }

    /** @param list<string> $lines */
    protected static function lines(array $lines): string
    {
        return implode("\n", $lines);
    }

    protected function ageSeconds(\DateTimeImmutable $at): int
    {
        return $this->clock->now()->getTimestamp() - $at->getTimestamp();
    }

    protected static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC';
    }

    /** "3 days 4 hours", "5 hours 10 minutes", "1 hour", "12 minutes". */
    protected static function span(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $unit = static fn (int $n, string $word): string => $n . ' ' . $word . ($n === 1 ? '' : 's');
        if ($seconds >= 2 * 86400) {
            $h = intdiv($seconds % 86400, 3600);

            return $unit(intdiv($seconds, 86400), 'day') . ($h > 0 ? ' ' . $unit($h, 'hour') : '');
        }
        if ($seconds >= 3600) {
            $m = intdiv($seconds % 3600, 60);

            return $unit(intdiv($seconds, 3600), 'hour') . ($m > 0 ? ' ' . $unit($m, 'minute') : '');
        }

        return $unit(max(1, intdiv($seconds, 60)), 'minute');
    }

    /** The indicative control references shared by every backup check, keyed by framework. */
    protected const BACKUP_CONTROLS = [
        'iso27001' => ['A.8.13'],
        'soc2' => ['A1.2'],
        'hipaa' => ['164.308(a)(7)(ii)(A)'],
    ];
}
