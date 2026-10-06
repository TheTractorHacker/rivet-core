<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

/**
 * The manual checklist an MSP works through for a customer: things a person must do and sign off, each tagged to the frameworks it
 * helps evidence. Control references are indicative starting points, not a mapping reviewed by an assessor.
 *
 * @api
 */
final class ClientChecklist
{
    /** @return list<ManualItem> */
    public static function items(): array
    {
        $m = static fn (string $id, string $title, string $cat, string $why, array $controls, int $days = 365) => new ManualItem($id, $title, $cat, $why, $controls, $days);
        $I = Framework::ISO27001;
        $S = Framework::SOC2;
        $P = Framework::PCI;
        $H = Framework::HIPAA;
        $N = Framework::NIST171;

        $items = [
            $m('policy_review', 'Security policy written, approved and reviewed', 'Governance', 'A management-approved policy sets direction for everything else.', [$I => ['A.5.1'], $S => ['CC1.1', 'CC5.3'], $P => ['12.1.1', '12.1.2'], $H => ['164.316(b)(1)']]),
            $m('risk_assessment', 'Risk assessment performed', 'Governance', 'Risks to data and systems are identified, ranked and treated.', [$I => ['Clause 6.1.2'], $S => ['CC3.2'], $P => ['12.3.1'], $H => ['164.308(a)(1)(ii)(A)']]),
            $m('asset_inventory', 'Inventory of systems and data', 'Governance', 'You cannot protect what you do not know about.', [$I => ['A.5.9'], $S => ['CC6.1'], $P => ['12.5.1'], $H => ['164.310(d)(1)']]),
            $m('access_review', 'User access review', 'Access control', 'Every account and its permissions are confirmed against current roles.', [$I => ['A.5.18'], $S => ['CC6.2', 'CC6.3'], $P => ['7.2.4'], $H => ['164.308(a)(4)(ii)(C)']], 90),
            $m('mfa_enforced', 'Multi-factor authentication enforced (email, remote access, admin accounts)', 'Access control', 'Passwords alone are the most common way in.', [$I => ['A.8.5'], $S => ['CC6.1'], $P => ['8.4.2'], $H => ['164.312(d)']]),
            $m('offboarding_check', 'Leaver process verified', 'Access control', 'Access of departed staff is removed promptly.', [$I => ['A.6.5'], $S => ['CC6.2'], $P => ['8.2.5'], $H => ['164.308(a)(3)(ii)(C)']], 180),
            $m('endpoint_protection', 'Endpoint protection and patching in place', 'Vulnerability management', 'Devices run supported software, current patches and malware protection.', [$I => ['A.8.7', 'A.8.8'], $S => ['CC7.1'], $P => ['5.2.1', '6.3.3'], $H => ['164.308(a)(5)(ii)(B)']], 180),
            $m('vuln_scan_pentest', 'Vulnerability scan or penetration test', 'Vulnerability management', 'Weaknesses are found by someone other than the people who built the system.', [$I => ['A.8.8', 'A.8.29'], $S => ['CC7.1'], $P => ['11.3.1', '11.4.1'], $H => ['164.308(a)(8)']]),
            $m('logging_retention', 'Logging enabled and retained', 'Logging and monitoring', 'Security-relevant events are recorded and kept long enough to investigate.', [$I => ['A.8.15'], $S => ['CC7.2'], $P => ['10.2.1', '10.5.1'], $H => ['164.312(b)']]),
            $m('encryption_in_place', 'Data encrypted in transit and at rest', 'Cryptography', 'Stolen disks and intercepted traffic do not expose data.', [$I => ['A.8.24'], $S => ['CC6.7'], $P => ['3.5.1', '4.2.1'], $H => ['164.312(a)(2)(iv)', '164.312(e)(1)']]),
            $m('backup_restore_test', 'Backup restore test', 'Resilience', 'A backup is only proven when a restore has worked.', [$I => ['A.8.13'], $S => ['A1.3'], $P => ['12.10.1'], $H => ['164.308(a)(7)(ii)(D)']], 180),
            $m('dr_bcp_test', 'Disaster recovery and continuity plan tested', 'Resilience', 'The plan for keeping or restoring service has been exercised.', [$I => ['A.5.30'], $S => ['A1.3'], $P => ['12.10.2'], $H => ['164.308(a)(7)(ii)(B)']]),
            $m('incident_response_test', 'Incident response plan tested', 'Incident management', 'A documented plan, roles and a tabletop or live exercise.', [$I => ['A.5.24', 'A.5.26'], $S => ['CC7.3', 'CC7.4'], $P => ['12.10.2'], $H => ['164.308(a)(6)']]),
            $m('security_awareness', 'Security awareness training delivered', 'People', 'Everyone with access has been trained and the record is kept.', [$I => ['A.6.3'], $S => ['CC1.4', 'CC2.2'], $P => ['12.6.1'], $H => ['164.308(a)(5)']]),
            $m('supplier_review', 'Supplier and sub-processor review', 'Governance', 'Vendors with access to data are assessed and under agreement.', [$I => ['A.5.19', 'A.5.22'], $S => ['CC9.2'], $P => ['12.8.1'], $H => ['164.308(b)(1)']]),
            $m('physical_security', 'Physical security of offices and equipment', 'Physical', 'Who can physically reach systems and records is controlled.', [$I => ['A.7.1', 'A.7.2'], $S => ['CC6.4'], $P => ['9.2.1'], $H => ['164.310(a)(1)']]),
            $m('data_classification', 'Data classification and handling rules', 'Governance', 'Data is labelled by sensitivity with matching handling and disposal rules.', [$I => ['A.5.12', 'A.5.13'], $S => ['C1.1'], $P => ['3.2.1'], $H => ['164.310(d)(1)']]),
            $m('baa_in_place', 'Business associate agreements signed', 'Governance', 'HIPAA requires a written agreement with every business associate that touches patient data.', [$H => ['164.308(b)(1)', '164.502(e)']]),
            $m('pci_scope_validation', 'Cardholder-data scope and self-assessment (SAQ) completed', 'Governance', 'Where card data lives is documented and the right self-assessment filed.', [$P => ['12.5.2', 'SAQ']]),
            $m('isms_scope_soa', 'Management-system scope and statement of applicability', 'Governance', 'ISO 27001 requires a defined scope and a statement of which controls apply.', [$I => ['Clause 4.3', 'Clause 6.1.3']]),
            $m('soc2_system_description', 'System description and boundaries documented', 'Governance', 'SOC 2 reports describe the system, its boundaries and its commitments.', [$S => ['CC2.1', 'DC 200']]),
            $m('cui_scope', 'CUI / FCI scope and data flows identified', 'Governance', 'Federal contract information and controlled unclassified information are located, marked and bounded before anything else is scoped.', [$N => ['3.1.3', '3.8.1', '3.8.4']]),
            $m('ssp_poam', 'System security plan and plan of action maintained', 'Governance', 'NIST 800-171 and CMMC require a current SSP and a POA&M for every gap.', [$N => ['3.12.2', '3.12.4']]),
            $m('cmmc_self_assessment', 'CMMC self-assessment or C3PAO readiness completed', 'Governance', 'The 110 practices are scored and the result recorded (and submitted where the contract requires it).', [$N => ['3.12.1']], 365),
        ];

        return array_map(static fn (ManualItem $i) => new ManualItem($i->id, $i->title, $i->category, $i->why, Nist171Map::apply($i->id, $i->controls), $i->intervalDays), $items);
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(static fn (ManualItem $i) => $i->id, self::items());
    }

    /**
     * Only the items that help evidence at least one of the chosen frameworks, with their references trimmed to those frameworks.
     *
     * @param list<string> $frameworks
     * @return list<ManualItem>
     */
    public static function forFrameworks(array $frameworks): array
    {
        $out = [];
        foreach (self::items() as $i) {
            $controls = array_intersect_key($i->controls, array_flip($frameworks));
            if ($controls !== []) {
                $out[] = new ManualItem($i->id, $i->title, $i->category, $i->why, $controls, $i->intervalDays);
            }
        }

        return $out;
    }
}
