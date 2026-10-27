<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Profile;

/**
 * The kinds of requirement a profile can hold. Each one is evaluated by one check ({@see \RivetCore\Compliance\Check}). The ids are stored in profiles and
 * exported files: never rename or reuse one. A new type is additive; an older Core refuses a file that uses it (strict import).
 *
 * `value` says what the number means: `days`, `hours`, or `flag` (a yes/no requirement: the value is always 1 and is left out of exported files).
 * `needs_class` lists the data classes the type may be attached to (empty list: the type takes no data class).
 *
 * @api
 */
final class RequirementType
{
    public const RETENTION_MIN = 'retention_min';
    public const BACKUP_RPO_MAX = 'backup_rpo_max';
    public const BACKUP_COVERS_ALL_STORES = 'backup_covers_all_stores';
    public const BACKUP_OFFSITE = 'backup_offsite';
    public const BACKUP_ENCRYPTED = 'backup_encrypted';
    public const RESTORE_TEST_MAX_AGE = 'restore_test_max_age';
    public const TAMPER_EVIDENCE = 'tamper_evidence';
    /** Windows security event-log presets ({@see \RivetCore\Rmm\Logs\SecurityLogPresets::complianceBaseline()}) are collected. A yes/no requirement. */
    public const LOG_PRESETS_BASELINE = 'log_presets_baseline';

    public const UNIT_DAYS = 'days';
    public const UNIT_HOURS = 'hours';
    public const UNIT_FLAG = 'flag';

    /**
     * @var array<string, array{label:string, unit:string, min:int, max:int, classes:list<string>}>
     */
    public const ALL = [
        self::RETENTION_MIN => ['label' => 'Minimum retention', 'unit' => self::UNIT_DAYS, 'min' => 1, 'max' => DataClass::MAX_DAYS, 'classes' => []],
        self::BACKUP_RPO_MAX => ['label' => 'Maximum time between backups (RPO)', 'unit' => self::UNIT_HOURS, 'min' => 1, 'max' => 8760, 'classes' => []],
        self::BACKUP_COVERS_ALL_STORES => ['label' => 'Backups cover every data store', 'unit' => self::UNIT_FLAG, 'min' => 1, 'max' => 1, 'classes' => []],
        self::BACKUP_OFFSITE => ['label' => 'A backup copy is stored off the server', 'unit' => self::UNIT_FLAG, 'min' => 1, 'max' => 1, 'classes' => []],
        self::BACKUP_ENCRYPTED => ['label' => 'Backups are encrypted', 'unit' => self::UNIT_FLAG, 'min' => 1, 'max' => 1, 'classes' => []],
        self::RESTORE_TEST_MAX_AGE => ['label' => 'Restore tested within N days', 'unit' => self::UNIT_DAYS, 'min' => 1, 'max' => 3650, 'classes' => []],
        self::TAMPER_EVIDENCE => ['label' => 'Tamper evidence', 'unit' => self::UNIT_FLAG, 'min' => 1, 'max' => 1, 'classes' => [DataClass::AUDIT_EVENTS, DataClass::RMM_LOGS, DataClass::CHANGE_RECORDS]],
        self::LOG_PRESETS_BASELINE => ['label' => 'Security event-log presets are collected', 'unit' => self::UNIT_FLAG, 'min' => 1, 'max' => 1, 'classes' => []],
    ];

    /** Types that take a data class. retention_min takes any class; the others are decided by `classes`. */
    public static function takesClass(string $type): bool
    {
        return $type === self::RETENTION_MIN || (self::ALL[$type]['classes'] ?? []) !== [];
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::ALL);
    }

    public static function isValid(string $type): bool
    {
        return isset(self::ALL[$type]);
    }

    public static function label(string $type): string
    {
        return self::ALL[$type]['label'] ?? $type;
    }

    public static function unit(string $type): string
    {
        return self::ALL[$type]['unit'] ?? self::UNIT_FLAG;
    }

    public static function allowsClass(string $type, string $class): bool
    {
        if (!DataClass::isValid($class)) {
            return false;
        }

        return $type === self::RETENTION_MIN || in_array($class, self::ALL[$type]['classes'] ?? [], true);
    }
}
