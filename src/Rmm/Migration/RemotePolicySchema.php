<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The table of the per-client remote-session policy, created by migration 0050. CREATE TABLE IF NOT EXISTS with an explicit engine, charset and collation; nothing existing
 * is altered. Do not edit a released statement, add a new migration instead.
 *
 * @internal
 */
final class RemotePolicySchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement (no trailing semicolon) */
    public static function tables(): array
    {
        return [
            // Per-client policy (allow, notify, approve). No row = no extra requirement. A keyed side table: the edition's `clients` table is not edited.
            'rmm_client_remote_policy' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_client_remote_policy` (
  `client_id` int(11) NOT NULL,
  `policy` varchar(10) NOT NULL,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
}
