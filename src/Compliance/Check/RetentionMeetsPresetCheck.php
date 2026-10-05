<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Compliance\CheckInterface;
use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Framework;
use RivetCore\Compliance\RetentionPolicy;

/** Is a compliance preset chosen, and do both retention horizons meet its floor? */
final class RetentionMeetsPresetCheck implements CheckInterface
{
    public function __construct(private string $profile, private int $logDays, private int $auditDays, private string $settingsPath)
    {
    }

    public function id(): string
    {
        return 'log_retention';
    }

    public function title(): string
    {
        return 'Log retention meets the chosen standard';
    }

    public function category(): string
    {
        return 'Logging and monitoring';
    }

    public function why(): string
    {
        return 'Standards expect logs to be kept long enough to investigate incidents (commonly 12 months, HIPAA documentation 6 years).';
    }

    public function controls(): array
    {
        return [
            Framework::ISO27001 => ['A.8.15'],
            Framework::SOC2 => ['CC7.2'],
            Framework::PCI => ['10.5.1'],
            Framework::HIPAA => ['164.316(b)(2)'],
        ];
    }

    public function run(): CheckResult
    {
        if (!RetentionPolicy::isValidProfile($this->profile) || $this->profile === 'none') {
            return CheckResult::warn('No compliance preset is selected.', 'Pick the standard you work to so retention is enforced automatically.', $this->settingsPath);
        }
        $m = ['profile' => $this->profile, 'log_days' => $this->logDays, 'audit_days' => $this->auditDays];
        if (RetentionPolicy::isBelowFloor($this->profile, $this->auditDays) || RetentionPolicy::isBelowFloor($this->profile, $this->logDays)) {
            return CheckResult::fail('Retention is below the preset minimum.', 'Audit: ' . RetentionPolicy::describeDays($this->auditDays) . ', logs: ' . RetentionPolicy::describeDays($this->logDays) . '.', $this->settingsPath, $m);
        }

        return CheckResult::pass('Audit and log retention meet the preset minimum.', 'Audit: ' . RetentionPolicy::describeDays($this->auditDays) . ', logs: ' . RetentionPolicy::describeDays($this->logDays) . '.', $m);
    }
}
