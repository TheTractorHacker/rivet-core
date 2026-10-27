<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * `endpoint_agent_devices.device_automation_disabled` (1.0.0-rc.31): one per-device switch for a device another
 * tool already manages (a second RMM/patch agent, commonly left behind after a partial migration) so RivetCore's
 * own automation never fights it. ON means:
 *   - {@see \RivetCore\Rmm\Scripts\TargetResolver} excludes the device from every scheduled-script target
 *     (device, tag, group, client, site, policy or "all" alike) and from a manual run-on-target bulk action.
 *   - {@see \RivetCore\Rmm\Patch\PatchScheduler::queueInstalls()} never queues a patch install for it.
 * Both left OFF by this migration (default 0): nothing changes for an existing device until an admin turns it
 * on. Deliberately narrow: patch SCANNING/compliance evaluation, monitoring/alerting, software inventory and a
 * technician's deliberate single-device "Run script now" or manual patch install are unaffected - this is a
 * scheduler-only kill switch, not a device-wide automation pause.
 *
 * @internal
 */
final class Migration0039AutomationDisabled implements MigrationInterface
{
    public function id(): string
    {
        return '0039_rmm_automation_disabled';
    }

    public function up(DatabaseInterface $database): void
    {
        if (!Migration0015EndpointAgentConverge::hasColumn($database, 'endpoint_agent_devices', 'device_automation_disabled')) {
            $database->execute("ALTER TABLE `endpoint_agent_devices` ADD COLUMN `device_automation_disabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `retired_at`");
        }
    }
}
