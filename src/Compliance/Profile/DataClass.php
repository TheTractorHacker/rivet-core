<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Profile;

/**
 * The classes of data a compliance profile can set requirements for. The ids are stored in profiles and exported files: never rename or reuse one.
 *
 * `scope` says who owns the data: `control` (Core's own tables: the audit trail), `rmm_hot` (the hot RMM tables, in whichever store is active) or `backup`
 * (the backup set the edition produces). For `rmm_hot` classes `source` and `key` name the retention setting that governs the class
 * (`rmm` = RmmSettings limits, `remote` = RemoteSettings limits, `change` = ChangeSettings limits); {@see \RivetCore\Rmm\Settings\RetentionClasses} binds them.
 *
 * @api
 */
final class DataClass
{
    public const AUDIT_EVENTS = 'audit_events';
    public const RMM_LOGS = 'rmm_logs';
    public const CHANGE_RECORDS = 'change_records';
    public const CHECK_HISTORY = 'check_history';
    public const SOFTWARE_HISTORY = 'software_history';
    public const PATCH_HISTORY = 'patch_history';
    public const DEPLOYMENT_HISTORY = 'deployment_history';
    public const METRICS_HOURLY = 'metrics_hourly';
    public const METRICS_DAILY = 'metrics_daily';
    public const BACKUPS = 'backups';

    public const SCOPE_CONTROL = 'control';
    public const SCOPE_RMM_HOT = 'rmm_hot';
    public const SCOPE_BACKUP = 'backup';

    public const SOURCE_RMM = 'rmm';
    public const SOURCE_REMOTE = 'remote';
    public const SOURCE_CHANGE = 'change';

    /**
     * @var array<string, array{label:string, scope:string, source:?string, key:?string, tables:list<string>}>
     */
    public const ALL = [
        self::AUDIT_EVENTS => ['label' => 'Audit events', 'scope' => self::SCOPE_CONTROL, 'source' => null, 'key' => null, 'tables' => ['audit_events']],
        self::RMM_LOGS => ['label' => 'RMM logs', 'scope' => self::SCOPE_RMM_HOT, 'source' => self::SOURCE_REMOTE, 'key' => 'logs_retention_days', 'tables' => ['endpoint_agent_log_events']],
        self::CHANGE_RECORDS => ['label' => 'Change records', 'scope' => self::SCOPE_RMM_HOT, 'source' => self::SOURCE_CHANGE, 'key' => 'change_retention_days', 'tables' => ['endpoint_agent_change_records']],
        self::CHECK_HISTORY => ['label' => 'Check history', 'scope' => self::SCOPE_RMM_HOT, 'source' => self::SOURCE_RMM, 'key' => 'check_history_days', 'tables' => ['endpoint_agent_check_history']],
        self::SOFTWARE_HISTORY => ['label' => 'Software history', 'scope' => self::SCOPE_RMM_HOT, 'source' => self::SOURCE_RMM, 'key' => 'software_history_days', 'tables' => ['rmm_software_history']],
        self::PATCH_HISTORY => ['label' => 'Patch history', 'scope' => self::SCOPE_RMM_HOT, 'source' => self::SOURCE_RMM, 'key' => 'patch_history_days', 'tables' => ['rmm_patch_history']],
        self::DEPLOYMENT_HISTORY => ['label' => 'Software deployment history', 'scope' => self::SCOPE_RMM_HOT, 'source' => self::SOURCE_RMM, 'key' => 'software_deploy_history_days', 'tables' => ['rmm_package_history']],
        self::METRICS_HOURLY => ['label' => 'Metrics (hourly)', 'scope' => self::SCOPE_RMM_HOT, 'source' => self::SOURCE_RMM, 'key' => 'metric_hourly_days', 'tables' => ['rmm_metric_hourly']],
        self::METRICS_DAILY => ['label' => 'Metrics (daily)', 'scope' => self::SCOPE_RMM_HOT, 'source' => self::SOURCE_RMM, 'key' => 'metric_daily_days', 'tables' => ['rmm_metric_daily']],
        self::BACKUPS => ['label' => 'Backups', 'scope' => self::SCOPE_BACKUP, 'source' => null, 'key' => null, 'tables' => []],
    ];

    /** Longest minimum a profile may ask for: 100 years. */
    public const MAX_DAYS = 36500;

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::ALL);
    }

    public static function isValid(string $id): bool
    {
        return isset(self::ALL[$id]);
    }

    public static function label(string $id): string
    {
        return self::ALL[$id]['label'] ?? $id;
    }

    /** @return list<string> the classes stored in the hot RMM store */
    public static function rmmHot(): array
    {
        return array_keys(array_filter(self::ALL, static fn (array $c): bool => $c['scope'] === self::SCOPE_RMM_HOT));
    }
}
