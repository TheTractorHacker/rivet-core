<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Compliance\CheckInterface;
use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Framework;
use RivetCore\Database\DatabaseInterface;

/** Is the structured audit trail on, and has it recorded anything recently? */
final class AuditTrailRecordingCheck implements CheckInterface
{
    public function __construct(private DatabaseInterface $database, private bool $recordingEnabled, private string $settingsPath)
    {
    }

    public function id(): string
    {
        return 'audit_trail_recording';
    }

    public function title(): string
    {
        return 'Audit trail is recording';
    }

    public function category(): string
    {
        return 'Logging and monitoring';
    }

    public function why(): string
    {
        return 'Security-relevant actions (sign-ins, permission and setting changes, exports) need a tamper-resistant record of who did what and when.';
    }

    public function controls(): array
    {
        return [
            Framework::ISO27001 => ['A.8.15', 'A.8.16'],
            Framework::SOC2 => ['CC7.2'],
            Framework::PCI => ['10.2.1'],
            Framework::HIPAA => ['164.312(b)'],
        ];
    }

    public function run(): CheckResult
    {
        if (!$this->recordingEnabled) {
            return CheckResult::fail('Audit recording is switched off.', null, $this->settingsPath);
        }
        $r = $this->database->fetchOne('SELECT COUNT(*) AS c, MAX(created_at) AS last FROM audit_events WHERE created_at >= (NOW() - INTERVAL 30 DAY)');
        $count = (int) ($r['c'] ?? 0);
        if ($count === 0) {
            return CheckResult::warn('Audit recording is on but nothing was recorded in the last 30 days.', 'This is normal on a brand-new install; otherwise check that the audit module is wired in.', $this->settingsPath, ['events_30d' => 0]);
        }

        return CheckResult::pass("Audit recording is on ($count events in the last 30 days).", null, ['events_30d' => $count]);
    }
}
