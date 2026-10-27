<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\RequirementsProviderInterface;
use RivetCore\Compliance\Profile\RequirementType;
use RivetCore\Contracts\ClockInterface;
use RivetCore\Rmm\Logs\SecurityLogPresets;
use RivetCore\Rmm\Remote\RemoteSettings;
use RivetCore\Rmm\Settings\RmmSettings;

/**
 * Are the Windows security event-log presets of the compliance baseline ({@see SecurityLogPresets::complianceBaseline()}) collected? It is an installation-wide
 * fact (the presets are one setting for every Windows device), read from the settings the agents are really given: the `logs` switch and the saved presets.
 *
 * What it cannot see: whether Windows writes the events. A preset reads events; it cannot create them, and the agent protocol does not report the audit policy of a
 * device, so a pass always carries that caveat. It is a known limitation, not something this check pretends to verify.
 *
 * @api
 */
final class SecurityLogPresetsCheck extends AbstractProfileCheck
{
    public function __construct(RequirementsProviderInterface $profiles, ClockInterface $clock, private RemoteSettings $remote, private RmmSettings $settings, string $settingsPath = '')
    {
        parent::__construct($profiles, $clock, $settingsPath);
    }

    public function id(): string
    {
        return 'security_log_presets';
    }

    public function title(): string
    {
        return 'Security events are collected from Windows devices';
    }

    public function category(): string
    {
        return 'Logging and monitoring';
    }

    public function why(): string
    {
        return 'Account and group changes, failed logons, cleared logs, new services and tasks and audit-policy changes are the events an investigation needs; they must be collected centrally, not only on a device that may be compromised.';
    }

    public function controls(): array
    {
        return ['iso27001' => ['A.8.15'], 'soc2' => ['CC7.2'], 'pci' => ['10.2'], 'hipaa' => ['164.312(b)'], 'nist171' => ['3.3.1', '3.3.2']];
    }

    public function requirementTypes(): array
    {
        return [RequirementType::LOG_PRESETS_BASELINE];
    }

    protected function evaluate(FrameworkProfile $profile): CheckResult
    {
        $want = SecurityLogPresets::complianceBaseline();
        $have = $this->remote->logPresets();
        $missing = array_values(array_diff($want, $have));
        $logsOn = $this->settings->featureOn('logs');
        $m = ['logs_on' => $logsOn, 'presets_wanted' => count($want), 'presets_enabled' => count($want) - count($missing), 'presets_chosen' => $this->remote->logPresetsChosen()];
        $fix = $this->settingsPath !== '' ? $this->settingsPath : null;
        $caveat = 'Caveat: a preset only reads events Windows writes; the matching Advanced Audit Policy subcategory must be on, and this system does not read the audit policy of a device.';
        if (!$logsOn) {
            return CheckResult::fail('Log collection is off, so no security events are collected.', self::lines([
                'Not met: the log collection switch is off.',
                ...($missing === [] ? [] : ['Evidence: ' . count($missing) . ' of ' . count($want) . ' baseline presets are not saved.']),
                'Remediation: turn log collection on, then ' . ($missing === [] ? 'keep' : 'apply') . ' the compliance baseline presets.',
                $caveat,
            ]), $fix, $m);
        }
        if ($missing !== []) {
            return CheckResult::fail('Not every security event preset of the compliance baseline is enabled.', self::lines([
                'Not met: ' . count($missing) . ' of ' . count($want) . ' baseline presets are not enabled.',
                ...array_map(static fn (string $id): string => 'Remediation: ' . SecurityLogPresets::remediation($id) . '.', $missing),
                $caveat,
            ]), $fix, $m);
        }

        return CheckResult::pass('All ' . count($want) . ' security event presets of the compliance baseline are enabled.', self::lines([
            'Met: log collection is on and every baseline preset is enabled (' . implode(', ', $want) . ').',
            $caveat,
        ]), $m);
    }
}
