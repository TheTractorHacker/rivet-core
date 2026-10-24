<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The daily budget ledger (1.0.0-rc.38, migration 0041): one row per (scope, subject, UTC day) counting what a lower-trust principal has used that day. Two scopes
 * use it today: `panel_chat` (a RivetPanel device's chat posts and bytes, {@see \RivetCore\Panel\PanelService::postChat()}) and `mesh_events` (the audit rows and webhook
 * events one device may cause through its Mesh reports, {@see \RivetCore\Rmm\Mesh\MeshAgentService}). New table, nothing altered; rows older than a week are deleted
 * whenever a day's first row is written, so it never grows.
 *
 *  - scope       a short constant naming the budget
 *  - subject_id  the device id
 *  - day         the UTC calendar day
 *  - used_count  what was charged (posts, events)
 *  - used_bytes  bytes charged (0 where the scope does not weigh bytes)
 *  - flagged     1 once the first refusal of the day was audited, so a flood writes one audit row, not thousands
 *
 * @internal
 */
final class BudgetSchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS */
    public static function tables(): array
    {
        return [
            'rmm_daily_budget' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_daily_budget` (
  `scope` varchar(24) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `day` date NOT NULL,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `used_bytes` bigint(20) NOT NULL DEFAULT 0,
  `flagged` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`scope`,`subject_id`,`day`),
  KEY `idx_day` (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
}
