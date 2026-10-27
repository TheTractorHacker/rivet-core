<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

/**
 * The table of the Windows Installer (MSI) templates, created by migration 0025. A separate table on purpose: `endpoint_agent_binaries` is owned by the editions
 * and feeds the self-update releases (`is_current` per architecture, `endpoint_agent_releases.binary_id`), none of which an installer template may touch. The columns
 * mirror it (version and architecture identify a template, the SHA-256 and size are checked before every download, the file name is random) so
 * {@see \RivetCore\Rmm\Binaries\BinaryStore} treats both the same way. CREATE TABLE IF NOT EXISTS with an explicit engine, charset and collation; nothing
 * existing is altered. Do not edit a released statement, add a new migration instead.
 *
 * @internal
 */
final class MsiTemplateSchema
{
    /** @return array<string,string> table name => CREATE TABLE IF NOT EXISTS statement (no trailing semicolon) */
    public static function tables(): array
    {
        return [
            'endpoint_agent_msi_templates' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `endpoint_agent_msi_templates` (
  `template_id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(40) NOT NULL,
  `arch` varchar(10) NOT NULL,
  `sha256` char(64) NOT NULL,
  `size_bytes` bigint(20) NOT NULL DEFAULT 0,
  `storage_name` varchar(64) NOT NULL,
  `arch_source` varchar(10) NOT NULL DEFAULT 'declared',
  `uploaded_by` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`template_id`),
  UNIQUE KEY `uniq_version_arch` (`version`,`arch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,
        ];
    }
}
