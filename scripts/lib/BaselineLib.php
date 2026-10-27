<?php

declare(strict_types=1);

/**
 * Shared by scripts/baseline-check.php and scripts/baseline-map.php: loads docs/security/baseline.json and renders the two generated
 * documents (docs/security/CONTROL_MAPPING.md and the control table inside docs/security/BASELINE.md) so neither can drift from the list.
 */
final class BaselineLib
{
    public const STATUSES = ['enforced', 'partial', 'not-applicable-to-Core', 'edition-responsibility', 'gap'];
    public const BEGIN = '<!-- BEGIN GENERATED CONTROLS (scripts/baseline-map.php) -->';
    public const END = '<!-- END GENERATED CONTROLS -->';

    /** SOC 2 Trust Services Criteria (2017, revised points of focus 2022) used by the list. */
    public const SOC2 = [
        'CC3.2' => 'Identifies and analyzes risk', 'CC3.4' => 'Identifies and assesses changes', 'CC4.1' => 'Performs ongoing and separate evaluations',
        'CC6.1' => 'Logical access security software, infrastructure and architectures', 'CC6.2' => 'Registers and authorizes users before credentials are issued',
        'CC6.3' => 'Role-based access and least privilege', 'CC6.6' => 'Protects against threats from outside the system boundary',
        'CC6.7' => 'Restricts transmission, movement and removal of information', 'CC6.8' => 'Prevents or detects unauthorized or malicious software',
        'CC7.1' => 'Detects configuration changes and vulnerabilities', 'CC7.2' => 'Monitors components for anomalies', 'CC7.3' => 'Evaluates security events',
        'CC7.4' => 'Responds to security incidents', 'CC8.1' => 'Authorizes, tests and approves changes', 'CC9.2' => 'Assesses and manages vendor and partner risk',
        'A1.2' => 'Environmental protections, backup and recovery infrastructure', 'A1.3' => 'Tests recovery plan procedures',
        'C1.1' => 'Identifies and maintains confidential information', 'C1.2' => 'Disposes of confidential information',
        'P1.1' => 'Provides notice about privacy practices', 'PI1.2' => 'Inputs are complete and accurate',
    ];

    /** ISO/IEC 27001:2022 Annex A controls used by the list. */
    public const ISO = [
        'A.5.8' => 'Information security in project management', 'A.5.15' => 'Access control', 'A.5.17' => 'Authentication information', 'A.5.18' => 'Access rights',
        'A.5.21' => 'Managing information security in the ICT supply chain', 'A.5.24' => 'Information security incident management planning and preparation',
        'A.5.34' => 'Privacy and protection of PII', 'A.5.36' => 'Compliance with policies, rules and standards for information security',
        'A.8.2' => 'Privileged access rights', 'A.8.3' => 'Information access restriction', 'A.8.4' => 'Access to source code', 'A.8.5' => 'Secure authentication',
        'A.8.7' => 'Protection against malware', 'A.8.8' => 'Management of technical vulnerabilities', 'A.8.9' => 'Configuration management',
        'A.8.10' => 'Information deletion', 'A.8.11' => 'Data masking', 'A.8.12' => 'Data leakage prevention', 'A.8.13' => 'Information backup',
        'A.8.15' => 'Logging', 'A.8.16' => 'Monitoring activities', 'A.8.17' => 'Clock synchronization', 'A.8.19' => 'Installation of software on operational systems',
        'A.8.20' => 'Networks security', 'A.8.22' => 'Segregation of networks', 'A.8.24' => 'Use of cryptography', 'A.8.26' => 'Application security requirements',
        'A.8.27' => 'Secure system architecture and engineering principles', 'A.8.28' => 'Secure coding', 'A.8.29' => 'Security testing in development and acceptance',
        'A.8.32' => 'Change management',
    ];

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string,mixed> */
    public static function load(): array
    {
        // BASELINE_JSON lets tests point the checker at a deliberately broken copy.
        $raw = file_get_contents((string) (getenv('BASELINE_JSON') ?: self::root() . '/docs/security/baseline.json'));
        if ($raw === false) {
            throw new RuntimeException('docs/security/baseline.json is missing');
        }

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $b */
    public static function counts(array $b): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        foreach ($b['controls'] as $c) {
            $out[$c['status']]++;
        }

        return $out;
    }

    /** @param array<string,mixed> $b */
    public static function renderTable(array $b): string
    {
        $n = self::counts($b);
        $o = self::BEGIN . "\n\n";
        $o .= '**' . count($b['controls']) . ' controls:** ' . implode(', ', array_map(static fn ($s) => "{$n[$s]} {$s}", self::STATUSES)) . ".\n\n";
        $area = null;
        foreach ($b['controls'] as $c) {
            if ($c['area'] !== $area) {
                $area = $c['area'];
                $o .= "### {$area}\n\n| Id | Control | ASVS 4.0.3 | Status | How it is enforced | Proof |\n|---|---|---|---|---|---|\n";
            }
            $proof = $c['tests'] === [] ? 'none' : implode('<br>', array_map(static fn ($t) => '`' . $t . '`', $c['tests']));
            $how = str_replace('|', '/', $c['how']) . ($c['note'] !== '' ? ' **Note:** ' . str_replace('|', '/', $c['note']) : '');
            $o .= "| {$c['id']} | " . str_replace('|', '/', $c['title']) . ' | ' . implode(', ', $c['asvs']) . " | {$c['status']} | {$how} | {$proof} |\n";
        }
        $o .= "\n### Known gaps\n\n| Id | Gap | Detail |\n|---|---|---|\n";
        foreach ($b['gaps'] as $g) {
            $o .= "| {$g['id']} | {$g['title']} | {$g['detail']} |\n";
        }

        return $o . "\n" . self::END;
    }

    /** @param array<string,mixed> $b */
    public static function renderMapping(array $b): string
    {
        $o = "# Control mapping: SOC 2 Trust Services Criteria and ISO/IEC 27001:2022 Annex A\n\n"
            . "GENERATED by `php scripts/baseline-map.php` from `docs/security/baseline.json`. Do not edit by hand; `scripts/baseline-check.php` fails when this file is stale.\n\n"
            . "**What this is:** for each criterion, the baseline controls (and their honest status) that bear on it. It is a self-assessed mapping. It is not a SOC 2 report, not an ISO 27001 certificate, and no auditor has reviewed it. See [CLAIMS.md](CLAIMS.md) for what may be said publicly.\n\n"
            . "Criteria with no control are listed as unmapped on purpose: most SOC 2 and ISO controls are organisational (policies, HR, vendor management, physical security) and are outside a software library.\n\n";
        foreach (['soc2' => ['SOC 2 Trust Services Criteria', self::SOC2], 'iso27001' => ['ISO/IEC 27001:2022 Annex A', self::ISO]] as $key => [$title, $names]) {
            $map = [];
            foreach ($b['controls'] as $c) {
                foreach ($c[$key] as $ref) {
                    $map[$ref][] = $c;
                }
            }
            uksort($map, static fn ($a, $b2) => strnatcmp($a, $b2));
            $o .= "## {$title}\n\n| Criterion | Name | Controls (status) |\n|---|---|---|\n";
            foreach ($map as $ref => $cs) {
                $o .= "| {$ref} | " . ($names[$ref] ?? '?') . ' | ' . implode('<br>', array_map(static fn ($c) => "{$c['id']} {$c['title']} ({$c['status']})", $cs)) . " |\n";
            }
            $unmapped = array_values(array_diff(array_keys($names), array_keys($map)));
            $o .= $unmapped === [] ? '' : "\nListed in this file's vocabulary but with no control: " . implode(', ', $unmapped) . ".\n";
            $o .= "\n";
        }
        $n = self::counts($b);
        $o .= "## Status counts\n\n| Status | Controls |\n|---|---|\n";
        foreach (self::STATUSES as $s) {
            $o .= "| {$s} | {$n[$s]} |\n";
        }

        return $o;
    }

    public static function spliceTable(string $doc, string $table): ?string
    {
        $a = strpos($doc, self::BEGIN);
        $z = strpos($doc, self::END);
        if ($a === false || $z === false || $z < $a) {
            return null;
        }

        return substr($doc, 0, $a) . $table . substr($doc, $z + strlen(self::END));
    }
}
