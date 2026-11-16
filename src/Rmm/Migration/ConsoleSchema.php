<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The tables of the Rivet Console redeem and events service, created by migration 0051. Every statement is CREATE TABLE IF NOT EXISTS with an explicit engine, charset and
 * collation; nothing existing is altered. Do not edit a released statement, add a new migration instead.
 *
 * Nothing here stores a secret: a redeem code and a report token are kept only as their SHA-256, the cookie is never stored, and the client address only as a salted hash.
 *
 * @internal
 */
final class ConsoleSchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement (no trailing semicolon) */
    public static function tables(): array
    {
        return [
            // One row. No row, or an empty public_url, means "not configured": a console launch is then refused (409 not_configured).
            'rmm_console_settings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_console_settings` (
  `id` tinyint(4) NOT NULL DEFAULT 1,
  `public_url` varchar(255) NOT NULL DEFAULT '',
  `min_version` varchar(20) NOT NULL DEFAULT '',
  `log_file_paths` tinyint(1) NOT NULL DEFAULT 0,
  `cookie_once` tinyint(1) NOT NULL DEFAULT 1,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            // The single-use redeem codes. Only the SHA-256 of a code is stored. Valid 60 seconds from creation, redeemed by one conditional UPDATE.
            'rmm_console_codes' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_console_codes` (
  `code_hash` char(64) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(100) NOT NULL DEFAULT '',
  `device_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `asset_id` int(11) NOT NULL DEFAULT 0,
  `node_id` varchar(150) NOT NULL,
  `kind` varchar(12) NOT NULL,
  `mode` varchar(12) NOT NULL DEFAULT 'standard',
  `session_id` char(16) NOT NULL,
  `ip_hash` char(64) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`code_hash`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_device` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
            // One row per redeemed code: the session and its report token (only the SHA-256 of the token). Sessions never hold the cookie.
            'rmm_console_sessions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_console_sessions` (
  `session_id` char(16) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(100) NOT NULL DEFAULT '',
  `device_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `asset_id` int(11) NOT NULL DEFAULT 0,
  `node_id` varchar(150) NOT NULL,
  `kind` varchar(12) NOT NULL,
  `started_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  `token_expires_at` datetime NOT NULL,
  `hard_expires_at` datetime NOT NULL,
  `events_count` int(11) NOT NULL DEFAULT 0,
  `truncated` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `uniq_token` (`token_hash`),
  KEY `idx_started` (`started_at`),
  KEY `idx_device` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
}
