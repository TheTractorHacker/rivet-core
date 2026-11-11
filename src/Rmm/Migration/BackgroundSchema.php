<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The tables of Background Mode, created by migration 0049. Every statement is CREATE TABLE IF NOT EXISTS with an explicit engine, charset and collation; nothing existing is
 * altered (`rmm_remote_sessions` belongs to the edition, ADR-002, so the mode of a session lives in `rmm_background_sessions`, keyed by the same session id the edition's log
 * carries in `meshcentral:session:<id>`). Do not edit a released statement, add a new migration instead.
 *
 * @internal
 */
final class BackgroundSchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement (no trailing semicolon) */
    public static function tables(): array
    {
        return [
            // One row. An empty policy_mode means "not configured", which denies every Background Mode session; an edition's updater sets its default (RivetIT `internal`, RivetMSP `per_client`).
            'rmm_background_settings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_background_settings` (
  `id` tinyint(4) NOT NULL DEFAULT 1,
  `policy_mode` varchar(12) NOT NULL DEFAULT '',
  `internal_group` varchar(100) NOT NULL DEFAULT '',
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            // Per-client policy (allow, notify, approve). No row = denied. A keyed side table: the edition's `clients` table is not edited.
            'rmm_client_remote_policy' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_client_remote_policy` (
  `client_id` int(11) NOT NULL,
  `background_policy` varchar(10) NOT NULL,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            // One row per Background Mode session that was allowed to start. Denied and failed attempts are audit rows only.
            'rmm_background_sessions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_background_sessions` (
  `session_id` char(16) NOT NULL,
  `device_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `asset_id` int(11) NOT NULL DEFAULT 0,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(100) NOT NULL DEFAULT '',
  `policy` varchar(24) NOT NULL DEFAULT '',
  `notice` varchar(10) NOT NULL DEFAULT 'none',
  `started_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  `end_reason` varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY (`session_id`),
  KEY `idx_device_started` (`device_id`,`started_at`),
  KEY `idx_user_started` (`user_id`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            // The single-use session-check tokens of the Mesh plugin. Only the SHA-256 of a token is stored, never the token.
            'rmm_background_tokens' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_background_tokens` (
  `token_hash` char(64) NOT NULL,
  `session_id` char(16) NOT NULL,
  `user_id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
}
