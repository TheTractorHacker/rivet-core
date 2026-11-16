<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * The server side of the signed contracts of the deep-scan fixes (CORE-DS-16, 20, 26). Additive and idempotent; with nothing set, every response is byte-identical to before.
 *
 *  - `endpoint_agent_settings.signed_config` (default 0): when 1 the check-in and enrollment responses announce `config.signed_config: 1` and bind every signed check to the
 *    device (`device_id` inside the signed item). Off by default so the wire does not change until the administrator knows the agents carry the matching build.
 *  - `endpoint_agent_settings.release_rollover_json` (nullable): the signed release-key rollover message the release owner published, relayed as `release_rollover`.
 *  - `rmm_agent_release_sigs`: the offline release signature of each stored agent package (os, arch, signature), uploaded with the binary and relayed as `release_signature`.
 *
 * @internal
 */
final class Migration0054SignedContracts implements MigrationInterface
{
    public function id(): string
    {
        return '0054_rmm_signed_contracts';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (['signed_config' => 'tinyint(1) NOT NULL DEFAULT 0', 'release_rollover_json' => 'text DEFAULT NULL'] as $col => $ddl) {
            if (!Migration0015EndpointAgentConverge::hasColumn($database, 'endpoint_agent_settings', $col)) {
                $database->execute("ALTER TABLE `endpoint_agent_settings` ADD COLUMN `$col` $ddl");
            }
        }
        $database->execute('CREATE TABLE IF NOT EXISTS `rmm_agent_release_sigs` (
  `binary_id` int(11) NOT NULL,
  `os` varchar(8) NOT NULL,
  `arch` varchar(8) NOT NULL,
  `release_signature` varchar(128) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`binary_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
    }
}
