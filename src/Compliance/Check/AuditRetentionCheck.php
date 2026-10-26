<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Profile\DataClass;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\RequirementsProviderInterface;
use RivetCore\Compliance\Profile\RequirementType;
use RivetCore\Contracts\ClockInterface;

/**
 * Are audit events kept at least as long as the profile's minimum? The edition tells it how long it keeps them: pass the days it hands to
 * {@see \RivetCore\Retention\RetentionService::prune()} (0 = kept without limit), after raising them with {@see \RivetCore\Compliance\Profile\RetentionFloors::effectiveDays()}.
 * Use this instead of {@see RetentionMeetsPresetCheck} when a framework profile is selected; the older check keeps working for the five presets.
 *
 * @api
 */
final class AuditRetentionCheck extends AbstractProfileCheck
{
    /** @param \Closure(): int $auditDays days audit events are kept; 0 or less = without limit */
    public function __construct(RequirementsProviderInterface $profiles, ClockInterface $clock, private \Closure $auditDays, string $settingsPath = '')
    {
        parent::__construct($profiles, $clock, $settingsPath);
    }

    public function id(): string
    {
        return 'audit_retention';
    }

    public function title(): string
    {
        return 'Audit events are kept long enough';
    }

    public function category(): string
    {
        return 'Logging and monitoring';
    }

    public function why(): string
    {
        return 'Standards and contracts expect the record of who did what to be available for months or years after the event.';
    }

    public function controls(): array
    {
        return ['iso27001' => ['A.8.15'], 'soc2' => ['CC7.2'], 'pci' => ['10.5.1'], 'hipaa' => ['164.316(b)(2)(i)'], 'nist171' => ['3.3.1']];
    }

    public function requirementTypes(): array
    {
        return [RequirementType::RETENTION_MIN];
    }

    public function appliesTo(FrameworkProfile $profile): bool
    {
        return $profile->requirement(RequirementType::RETENTION_MIN, DataClass::AUDIT_EVENTS) !== null;
    }

    protected function evaluate(FrameworkProfile $profile): CheckResult
    {
        $need = $profile->retentionMin(DataClass::AUDIT_EVENTS);
        $days = ($this->auditDays)();
        $m = ['required_days' => $need, 'audit_days' => max(0, $days)];
        if ($days <= 0) {
            return CheckResult::pass('Audit events are kept without a time limit.', 'Met: no deletion of audit events is configured (requirement ' . $need . ' days).', $m);
        }
        if ($days >= $need) {
            return CheckResult::pass('Audit events are kept ' . $days . ' days (required ' . $need . ').', 'Met: audit events are kept ' . $days . ' days.', $m);
        }

        return CheckResult::fail('Audit events are kept ' . $days . ' days; the framework requires ' . $need . '.', 'Not met: audit events older than ' . $days . ' days are deleted.', $this->settingsPath ?: null, $m);
    }
}
