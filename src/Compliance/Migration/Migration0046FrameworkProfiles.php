<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Customizable compliance frameworks (#92 phases 1 and 2): custom profiles with their immutable versions and requirement rows, the profile the installation
 * has selected (one row) and its log, and restore-test evidence. Built-in profiles have no rows: they are derived from the constants in code.
 * Additive and idempotent (CREATE TABLE IF NOT EXISTS only); nothing existing is touched. No foreign keys, like every other Core table: the store deletes
 * the children of a profile itself, inside one transaction.
 *
 * @internal
 */
final class Migration0046FrameworkProfiles implements MigrationInterface
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

    public function id(): string
    {
        return '0046_compliance_framework_profiles';
    }

    public function up(DatabaseInterface $database): void
    {
        $o = self::TABLE_OPTIONS;
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `compliance_profiles` (
                `profile_id` int(11) NOT NULL AUTO_INCREMENT,
                `profile_key` varchar(48) NOT NULL,
                `based_on` varchar(48) DEFAULT NULL,
                `current_version` int(11) NOT NULL,
                `status` varchar(10) NOT NULL,
                `created_by` int(11) DEFAULT NULL,
                `created_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                `archived_at` datetime DEFAULT NULL,
                PRIMARY KEY (`profile_id`),
                UNIQUE KEY `uq_compliance_profiles_key` (`profile_key`)
            ) $o"
        );
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `compliance_profile_versions` (
                `version_id` int(11) NOT NULL AUTO_INCREMENT,
                `profile_id` int(11) NOT NULL,
                `version` int(11) NOT NULL,
                `label` varchar(120) NOT NULL,
                `description` text DEFAULT NULL,
                `content_hash` char(64) NOT NULL,
                `change_note` varchar(300) DEFAULT NULL,
                `changed_by` int(11) DEFAULT NULL,
                `changed_by_name` varchar(200) NOT NULL,
                `created_at` datetime NOT NULL,
                PRIMARY KEY (`version_id`),
                UNIQUE KEY `uq_compliance_profile_versions` (`profile_id`, `version`)
            ) $o"
        );
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `compliance_profile_requirements` (
                `requirement_id` int(11) NOT NULL AUTO_INCREMENT,
                `version_id` int(11) NOT NULL,
                `req_type` varchar(40) NOT NULL,
                `data_class` varchar(40) NOT NULL,
                `value_int` int(11) NOT NULL,
                `note` varchar(300) DEFAULT NULL,
                PRIMARY KEY (`requirement_id`),
                UNIQUE KEY `uq_compliance_profile_requirements` (`version_id`, `req_type`, `data_class`)
            ) $o"
        );
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `compliance_profile_selection` (
                `selection_id` tinyint(4) NOT NULL,
                `profile_key` varchar(48) NOT NULL,
                `selected_by` int(11) DEFAULT NULL,
                `selected_by_name` varchar(200) NOT NULL,
                `selected_at` datetime NOT NULL,
                PRIMARY KEY (`selection_id`)
            ) $o"
        );
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `compliance_profile_selection_log` (
                `log_id` int(11) NOT NULL AUTO_INCREMENT,
                `profile_key` varchar(48) DEFAULT NULL,
                `profile_version` int(11) DEFAULT NULL,
                `action` varchar(10) NOT NULL,
                `by_user` int(11) DEFAULT NULL,
                `by_name` varchar(200) NOT NULL,
                `at` datetime NOT NULL,
                PRIMARY KEY (`log_id`),
                KEY `idx_compliance_selection_log_key` (`profile_key`)
            ) $o"
        );
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `compliance_restore_tests` (
                `test_id` int(11) NOT NULL AUTO_INCREMENT,
                `tested_at` datetime NOT NULL,
                `recorded_at` datetime NOT NULL,
                `recorded_by` int(11) DEFAULT NULL,
                `recorded_by_name` varchar(200) NOT NULL,
                `result` varchar(10) NOT NULL,
                `stores` varchar(80) NOT NULL,
                `data_store` varchar(10) NOT NULL,
                `backup_taken_at` datetime DEFAULT NULL,
                `duration_s` int(11) DEFAULT NULL,
                `counts_json` longtext DEFAULT NULL,
                `verify_json` longtext DEFAULT NULL,
                `note` text DEFAULT NULL,
                PRIMARY KEY (`test_id`),
                KEY `idx_compliance_restore_tests_at` (`tested_at`)
            ) $o"
        );
    }
}
