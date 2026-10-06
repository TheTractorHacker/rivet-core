<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

/**
 * NIST SP 800-171 Rev 2 requirement numbers (the basis of CMMC Level 2) for the checks and checklist items that already exist, so one
 * mapping serves every edition. References are indicative starting points, not a mapping reviewed by an assessor; CMMC Level 2
 * practices correspond one to one to the 110 requirements. Rev 3 renumbers them.
 *
 * @api
 */
final class Nist171Map
{
    public const MAP = [
        // automatic checks
        'audit_trail_recording' => ['3.3.1', '3.3.2'],
        'log_retention' => ['3.3.1'],
        'mfa_coverage' => ['3.5.3'],
        'admin_mfa' => ['3.5.3', '3.1.5'],
        'admin_count' => ['3.1.5'],
        'session_lifetime' => ['3.1.10', '3.1.11'],
        'https_only' => ['3.13.8', '3.13.11'],
        'vault_key' => ['3.13.10', '3.13.16'],
        'backups' => ['3.8.9'],
        'credential_rotation' => ['3.5.7'],
        'api_keys' => ['3.1.1', '3.5.2'],
        'dormant_agents' => ['3.5.6', '3.1.1'],
        'schema_current' => ['3.14.1'],
        'training_overdue' => ['3.2.1', '3.2.2'],
        // manual items (installation checklist)
        'security_policy_review' => ['3.12.4'],
        'key_management_review' => ['3.13.10'],
        // manual items shared by the installation and customer checklists
        'risk_assessment' => ['3.11.1'],
        'access_review' => ['3.1.1', '3.1.5'],
        'offboarding_check' => ['3.9.2'],
        'backup_restore_test' => ['3.8.9'],
        'dr_bcp_test' => ['3.6.1'],
        'incident_response_test' => ['3.6.1', '3.6.3'],
        'vuln_scan_pentest' => ['3.11.2', '3.11.3'],
        'security_awareness' => ['3.2.1', '3.2.2', '3.2.3'],
        'physical_security' => ['3.10.1', '3.10.2'],
        'data_classification' => ['3.1.3', '3.8.4'],
        // customer checklist
        'policy_review' => ['3.12.4'],
        'asset_inventory' => ['3.4.1'],
        'mfa_enforced' => ['3.5.3'],
        'endpoint_protection' => ['3.14.1', '3.14.2', '3.14.4'],
        'logging_retention' => ['3.3.1', '3.3.2'],
        'encryption_in_place' => ['3.13.8', '3.13.11', '3.13.16'],
    ];

    /**
     * @param array<string, list<string>> $controls
     * @return array<string, list<string>> the controls plus this item's NIST references, if it has any and none were given
     */
    public static function apply(string $itemId, array $controls): array
    {
        if (!isset($controls[Framework::NIST171]) && isset(self::MAP[$itemId])) {
            $controls[Framework::NIST171] = self::MAP[$itemId];
        }

        return $controls;
    }
}
