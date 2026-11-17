<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * `rmm_hardening_settings` (one row, id 1): the switches of the deep-scan fixes. A table of its own, not columns of `endpoint_agent_settings`, because the ten original
 * `endpoint_agent_*` tables are part of the frozen schema and the golden transcripts of the device protocol. Every column defaults to "off", so an install keeps its behaviour until
 * somebody sets one. Additive and idempotent.
 *
 *  - `mesh_token_ttl_exact` (CORE-DS-39): honour `mesh_token_ttl_s` exactly, the 300 s default included (the default otherwise keeps the legacy ~58 minute MeshCentral window).
 *  - `signed_config` (CORE-DS-16, 20): announce `config.signed_config: 1`, bind signed checks to the device and sign the installer payload.
 *  - `release_rollover_json` (CORE-DS-26): the signed release-key rollover message the release owner published, relayed to agents.
 *
 * @internal
 */
final class Migration0053MeshTokenExact implements MigrationInterface
{
    public function id(): string
    {
        return '0053_rmm_mesh_token_exact';
    }

    public function up(DatabaseInterface $database): void
    {
        $database->execute('CREATE TABLE IF NOT EXISTS `rmm_hardening_settings` (
  `id` tinyint(4) NOT NULL DEFAULT 1,
  `mesh_token_ttl_exact` tinyint(1) NOT NULL DEFAULT 0,
  `signed_config` tinyint(1) NOT NULL DEFAULT 0,
  `release_rollover_json` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $database->execute('INSERT IGNORE INTO `rmm_hardening_settings` (`id`) VALUES (1)');
    }
}
