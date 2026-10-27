<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Logs;

/**
 * Named presets for the Windows event-log collector: the security-relevant events an MSP wants without writing event ids by hand. A preset is a code constant; turning one on adds
 * ordinary `eventlog` sources to the log configuration of Windows devices (merged per channel, so eight presets are three sources), and the existing caps, retention, rate
 * budget, redaction, alert rules and `logs` switch apply unchanged. Security-log audit events carry Level 0, so these sources read every level (`levels` empty) and select by event id.
 *
 * The one preset that needs more than ids is repeated failed logons (4625): thousands of events during a password-spraying run would be stored one by one, so it is
 * AGGREGATED on the device (`aggregate`: one summary entry per window and per account or address, only when the count reaches `min`). An agent that did not announce
 * `logs:presets` is never offered an aggregated source (it would ship every event), so that preset is silently absent for it. The auditing itself must be on in Windows
 * (Advanced Audit Policy); a preset cannot create events Windows does not write. {@see \RivetCore\Rmm\Remote\RemoteSettings::agentLogConfig()}.
 *
 * @api
 */
final class SecurityLogPresets
{
    /** The most sources the presets add to one device's configuration (the agent reads at most 24 in all). */
    public const MAX_SOURCES = 4;

    /** Shown wherever presets are chosen: a preset reads events, it cannot make Windows write them. */
    public const AUDIT_POLICY_NOTE = 'A preset only reads events that Windows writes. If the matching Advanced Audit Policy subcategory is off on a device, that preset collects nothing from it. '
        . 'RivetIT does not read the audit policy of a device, so it cannot tell you which subcategories are on.';

    /**
     * @return array<string,array{title:string,description:string,platform:string,aggregate:bool,sources:list<array<string,mixed>>}>
     */
    public static function catalog(): array
    {
        $sec = static fn (array $ids, ?array $aggregate = null): array => ['kind' => 'eventlog', 'channel' => 'Security', 'levels' => [], 'event_ids' => $ids, 'contains' => []] + ($aggregate === null ? [] : ['aggregate' => $aggregate]);

        return [
            'win.sec.accounts' => ['title' => 'Account changes', 'platform' => 'windows', 'aggregate' => false,
                'description' => 'User account created (4720), enabled (4722), password reset (4724), disabled (4725) or deleted (4726).',
                'sources' => [$sec([4720, 4722, 4724, 4725, 4726])]],
            'win.sec.groups' => ['title' => 'Group membership changes', 'platform' => 'windows', 'aggregate' => false,
                'description' => 'A member added to or removed from a local (4732, 4733), global (4728, 4729) or universal (4756, 4757) security group.',
                'sources' => [$sec([4728, 4729, 4732, 4733, 4756, 4757])]],
            'win.sec.logon_failures' => ['title' => 'Repeated failed logons', 'platform' => 'windows', 'aggregate' => true,
                'description' => 'Failed logons (4625), summed up on the device: one entry per five minutes and account or address once it reaches five failures. Needs an agent that announces logs:presets.',
                'sources' => [$sec([4625], ['window_s' => 300, 'min' => 5, 'by' => ['TargetUserName', 'IpAddress']])]],
            'win.sec.log_cleared' => ['title' => 'Event log cleared', 'platform' => 'windows', 'aggregate' => false,
                'description' => 'The Security log was cleared (1102) or any other log was cleared (System 104).',
                'sources' => [$sec([1102]), ['kind' => 'eventlog', 'channel' => 'System', 'levels' => [], 'event_ids' => [104], 'contains' => []]]],
            'win.sec.scheduled_tasks' => ['title' => 'Scheduled task created or changed', 'platform' => 'windows', 'aggregate' => false,
                'description' => 'A scheduled task was created (4698) or updated (4702).',
                'sources' => [$sec([4698, 4702])]],
            'win.sec.new_service' => ['title' => 'New service installed', 'platform' => 'windows', 'aggregate' => false,
                'description' => 'A service was installed: System 7045, Security 4697.',
                'sources' => [$sec([4697]), ['kind' => 'eventlog', 'channel' => 'System', 'levels' => [], 'event_ids' => [7045], 'contains' => []]]],
            'win.sec.audit_policy' => ['title' => 'Audit policy changed', 'platform' => 'windows', 'aggregate' => false,
                'description' => 'The system audit policy was changed (4719): a common first step of hiding activity.',
                'sources' => [$sec([4719])]],
            'win.sec.devices' => ['title' => 'New device detected', 'platform' => 'windows', 'aggregate' => false,
                'description' => 'A new plug and play device was recognised (6416). Needs "Audit PNP Activity" in the audit policy.',
                'sources' => [$sec([6416])]],
        ];
    }

    /**
     * The presets recommended for compliance: everything except `win.sec.devices`, which needs an extra audit subcategory ("Audit PNP Activity") that most baselines
     * leave off. Nothing is switched on by this list: an edition pre-ticks it for an install that has never chosen, and
     * {@see \RivetCore\Rmm\Remote\RemoteSettings::applyComplianceBaseline()} stores it on request.
     *
     * @return list<string>
     */
    public static function complianceBaseline(): array
    {
        return ['win.sec.accounts', 'win.sec.groups', 'win.sec.logon_failures', 'win.sec.log_cleared', 'win.sec.scheduled_tasks', 'win.sec.new_service', 'win.sec.audit_policy'];
    }

    /**
     * The control references each preset supports, as "Framework reference" strings (indicative, for a badge or a report; not a certification claim).
     *
     * @return array<string,list<string>>
     */
    public static function frameworkReferences(): array
    {
        $log = ['HIPAA 164.312(b)', 'PCI DSS 10.2', 'CIS Controls 8.2', 'NIST 800-171 3.3.1', 'SOC 2 CC7.2', 'ISO 27001 A.8.15', 'NIST CSF DE.CM'];

        return [
            'win.sec.accounts' => [...$log, 'NIST 800-171 3.3.2', 'PCI DSS 10.2.1.5'],
            'win.sec.groups' => [...$log, 'NIST 800-171 3.3.2', 'PCI DSS 10.2.1.5'],
            'win.sec.logon_failures' => [...$log, 'CIS Controls 8.5', 'NIST 800-171 3.3.2', 'PCI DSS 10.2.1.4'],
            'win.sec.log_cleared' => [...$log, 'CIS Controls 8.5', 'PCI DSS 10.2.1.6', 'PCI DSS 10.3.2'],
            'win.sec.scheduled_tasks' => [...$log, 'CIS Controls 8.5', 'NIST 800-171 3.3.2'],
            'win.sec.new_service' => [...$log, 'CIS Controls 8.5', 'NIST 800-171 3.3.2'],
            'win.sec.audit_policy' => [...$log, 'PCI DSS 10.2.1.6'],
            'win.sec.devices' => ['CIS Controls 8.2', 'NIST CSF DE.CM'],
        ];
    }

    /**
     * What Windows must audit for a preset to see anything. A preset reads events; it cannot make Windows write them. Keyed by preset id.
     *
     * @return array<string,string>
     */
    public static function auditPolicyNeeds(): array
    {
        return [
            'win.sec.accounts' => 'Audit User Account Management (Success)',
            'win.sec.groups' => 'Audit Security Group Management (Success)',
            'win.sec.logon_failures' => 'Audit Logon (Failure)',
            'win.sec.log_cleared' => 'nothing extra (Windows writes 1102 and 104 by default)',
            'win.sec.scheduled_tasks' => 'Audit Other Object Access Events (Success)',
            'win.sec.new_service' => 'Audit Security System Extension (Success) for 4697; System 7045 needs nothing extra',
            'win.sec.audit_policy' => 'Audit Audit Policy Change (Success)',
            'win.sec.devices' => 'Audit PNP Activity (Success)',
        ];
    }

    /** The one-line remediation for a preset that is not enabled, for example "Enable preset win.sec.accounts (needs Windows audit policy: Audit User Account Management (Success))". */
    public static function remediation(string $id): string
    {
        return 'Enable preset ' . $id . ' (needs Windows audit policy: ' . (self::auditPolicyNeeds()[$id] ?? 'see the preset description') . ')';
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * The sources the presets add for a device: non-aggregated sources merged per channel (ids united, sorted, at most 50), aggregated sources kept apart and only when
     * the device can aggregate.
     *
     * @param list<string> $enabled
     * @return list<array<string,mixed>>
     */
    public static function sourcesFor(array $enabled, bool $canAggregate): array
    {
        $byChannel = [];
        $agg = [];
        foreach (self::catalog() as $id => $p) {
            if (!in_array($id, $enabled, true)) {
                continue;
            }
            foreach ($p['sources'] as $s) {
                if (isset($s['aggregate'])) {
                    if ($canAggregate) {
                        $agg[] = $s;
                    }
                    continue;
                }
                $ch = (string) $s['channel'];
                $byChannel[$ch] = array_values(array_unique([...($byChannel[$ch] ?? []), ...$s['event_ids']]));
            }
        }
        $out = [];
        ksort($byChannel);
        foreach ($byChannel as $ch => $ids) {
            sort($ids);
            $out[] = ['kind' => 'eventlog', 'channel' => $ch, 'levels' => [], 'event_ids' => array_slice($ids, 0, 50), 'contains' => []];
        }

        return array_slice([...$out, ...$agg], 0, self::MAX_SOURCES);
    }
}
