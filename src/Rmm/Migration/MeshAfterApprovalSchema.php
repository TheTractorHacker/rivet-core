<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The table of "install the Mesh agent after the device is approved" (1.0.0-rc.27, migration 0034): one row per device whose enrollment token carried the installer
 * option. The row only records the plan and the outcome; the pushed `mesh_install` job (and the device's own Mesh report) say whether the agent is installed. New table, nothing altered.
 *
 *  - state    waiting (not linked yet, or waiting for the agent to announce `job:mesh_install`) | queued | skipped | failed
 *  - reason   plain text for the device page; never a secret, never the Mesh address or group id
 *
 * @internal
 */
final class MeshAfterApprovalSchema
{
    /** @return array<string,string> */
    public static function tables(): array
    {
        return [
            'rmm_mesh_after_approval' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_mesh_after_approval` (
  `device_id` int(11) NOT NULL,
  `token_id` int(11) NOT NULL,
  `state` varchar(12) NOT NULL DEFAULT 'waiting',
  `reason` varchar(240) NOT NULL DEFAULT '',
  `job_id` varchar(36) DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `planned_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`device_id`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
}
