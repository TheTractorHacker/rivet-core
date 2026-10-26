<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Profile\DataClass;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\Requirement;
use RivetCore\Compliance\Profile\RequirementsProviderInterface;
use RivetCore\Compliance\Profile\RequirementType;
use RivetCore\Contracts\ClockInterface;
use RivetCore\Rmm\Change\ChangeSettings;
use RivetCore\Rmm\Remote\RemoteSettings;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetCore\Rmm\Store\RmmDataStores;

/**
 * Is every hot RMM table of the ACTIVE data store kept at least as long as the profile asks? It reads the retention that is really applied, not what the
 * form says: the settings as the housekeeping job reads them (with the framework's floors when enforcement is wired), the partition retention the active
 * store reports through {@see \RivetCore\Rmm\Store\StoreMaintenance::tiers()}, and the metric store's own retention. It works the same on MariaDB and on
 * PostgreSQL because it only talks to the store interfaces.
 *
 * It also reports two honest caveats instead of a clean pass: the row caps of logs and change records (a store that is full deletes its oldest rows
 * before the retention is reached), and a store that could not be read. And it warns when the framework asks for retention but the floors are not wired
 * into the settings, because then the minimum is only checked, not enforced.
 *
 * @api
 */
final class DataStoreRetentionCheck extends AbstractProfileCheck
{
    /** Share of a row cap above which the cap is reported as limiting. */
    private const CAP_WARN = 0.95;

    public function __construct(
        RequirementsProviderInterface $profiles,
        ClockInterface $clock,
        private RmmDataStores $stores,
        private RmmSettings $settings,
        private RemoteSettings $remote,
        private ChangeSettings $change,
        string $settingsPath = '',
    ) {
        parent::__construct($profiles, $clock, $settingsPath);
    }

    public function id(): string
    {
        return 'data_store_retention';
    }

    public function title(): string
    {
        return 'RMM history is kept long enough';
    }

    public function category(): string
    {
        return 'Logging and monitoring';
    }

    public function why(): string
    {
        return 'Device logs, change records and check, patch and software history are evidence of what happened on managed systems; the framework sets how long each must be kept.';
    }

    public function controls(): array
    {
        return ['iso27001' => ['A.8.15'], 'soc2' => ['CC7.2'], 'pci' => ['10.5.1'], 'hipaa' => ['164.316(b)(2)(i)'], 'nist171' => ['3.3.1']];
    }

    public function requirementTypes(): array
    {
        return [RequirementType::RETENTION_MIN];
    }

    public function appliesTo(FrameworkProfile $profile): bool
    {
        return $this->required($profile) !== [];
    }

    /** @return list<Requirement> */
    private function required(FrameworkProfile $profile): array
    {
        return array_values(array_filter($profile->ofType(RequirementType::RETENTION_MIN), static fn (Requirement $r): bool => in_array($r->dataClass, DataClass::rmmHot(), true)));
    }

    protected function evaluate(FrameworkProfile $profile): CheckResult
    {
        $kind = $this->stores->kind();
        $storeName = $kind === RmmDataStoreKind::POSTGRES ? 'PostgreSQL' : 'MariaDB';
        $limits = $this->settings->limits();
        $explicit = $this->settings->explicitLimits();
        $remote = $this->remote->limits();
        $change = $this->change->limits();

        $tiers = null;
        $unreadable = [];
        try {
            $tiers = $this->stores->maintenance()->tiers($limits, $explicit)['tables'];
        } catch (\Throwable) {
            $unreadable[] = 'the partition retention of the ' . $storeName . ' store';
        }

        $lines = ['Evidence: RMM data store is ' . $storeName . '.'];
        $below = [];
        $caveats = [];
        $m = ['rmm_data_store' => $kind];
        foreach ($this->required($profile) as $req) {
            $class = (string) $req->dataClass;
            $need = $req->value;
            $label = DataClass::label($class);
            $have = match ($class) {
                DataClass::RMM_LOGS => $remote['logs_retention_days'] ?? null,
                DataClass::CHANGE_RECORDS => $change['change_retention_days'] ?? null,
                DataClass::CHECK_HISTORY => $this->partitioned($limits['check_history_days'], $tiers['endpoint_agent_check_history']['retention_days'] ?? null),
                DataClass::SOFTWARE_HISTORY => $limits['software_history_days'] ?? null,
                DataClass::PATCH_HISTORY => $limits['patch_history_days'] ?? null,
                DataClass::DEPLOYMENT_HISTORY => $limits['software_deploy_history_days'] ?? null,
                DataClass::METRICS_HOURLY => $explicit['metric_hourly_days'] ?? $this->stores->metrics()?->retentionDays(),
                DataClass::METRICS_DAILY => $explicit['metric_daily_days'] ?? $this->stores->metrics()?->dailyRetentionDays(),
                default => null,
            };
            $m['required_' . $class] = $need;
            if ($have === null) {
                $caveats[] = $label . ' (kept outside the RMM store, cannot be read here)';
                $lines[] = 'Evidence: ' . $label . ' is not kept in the RMM data store of this installation; the requirement of ' . $need . ' days cannot be verified here.';
                continue;
            }
            $m['effective_' . $class] = $have;
            if ($have < $need) {
                $below[] = $label . ' ' . $have . ' < ' . $need . ' days';
                $lines[] = 'Not met: ' . $label . ' are kept ' . $have . ' days; the framework requires ' . $need . '.';
            } else {
                $lines[] = 'Met: ' . $label . ' are kept ' . $have . ' days (required ' . $need . ').';
            }
            foreach ($this->evidence($class, $remote, $change, $unreadable) as $e) {
                $lines[] = $e['line'];
                if ($e['caveat'] !== null) {
                    $caveats[] = $e['caveat'];
                }
            }
        }
        if ($below !== []) {
            return CheckResult::fail('Retention is below the framework minimum: ' . implode('; ', $below) . '.', self::lines($lines), $this->settingsPath ?: null, $m);
        }
        if ($this->settings->retentionFloors() === null) {
            $caveats[] = 'the framework minimums are not enforced by the settings in this installation (they can still be lowered)';
        }
        foreach ($unreadable as $u) {
            $caveats[] = $u . ' could not be read';
        }
        if ($caveats !== []) {
            foreach ($caveats as $c) {
                $lines[] = 'Caveat: ' . $c . '.';
            }

            return CheckResult::warn('Retention meets the minimum, with caveats: ' . implode('; ', $caveats) . '.', self::lines($lines), $this->settingsPath ?: null, $m);
        }

        return CheckResult::pass('Every RMM history kept for this framework meets its minimum retention.', self::lines($lines), $m);
    }

    /** The shorter of the setting and what the store reports for a partitioned table, so a partition job that drops earlier shows up. 0 (record none) stays 0. */
    private function partitioned(?int $setting, mixed $store): ?int
    {
        if ($setting === null) {
            return null;
        }
        if ($setting <= 0 || !is_int($store)) {
            return max(0, $setting);
        }

        return min($setting, $store);
    }

    /**
     * Facts about the real rows behind a class: how old the oldest is, and whether a row cap is about to delete rows before the retention is reached.
     *
     * @param array<string,int> $remote
     * @param array<string,int> $change
     * @param list<string> $unreadable
     * @return list<array{line:string, caveat:?string}>
     */
    private function evidence(string $class, array $remote, array $change, array &$unreadable): array
    {
        $out = [];
        try {
            if ($class === DataClass::RMM_LOGS) {
                $oldest = $this->stores->logs()->oldestEventAt();
                if ($oldest !== null) {
                    $out[] = ['line' => 'Evidence: the oldest stored log event is ' . self::span($this->ageSeconds(new \DateTimeImmutable($oldest, new \DateTimeZone('UTC')))) . ' old.', 'caveat' => null];
                }
                $cap = (int) ($remote['logs_fleet_max_rows'] ?? 0);
                $rows = $this->stores->logs()->fleetRowCount();
                if ($cap > 0 && $rows >= (int) floor($cap * self::CAP_WARN)) {
                    $out[] = ['line' => 'Evidence: the log store holds ' . $rows . ' of at most ' . $cap . ' rows.', 'caveat' => 'the log row cap (' . $cap . ') is nearly reached, so the oldest logs are deleted before the retention period ends; raise the cap'];
                }
            } elseif ($class === DataClass::CHANGE_RECORDS) {
                $cap = (int) ($change['change_fleet_max_rows'] ?? 0);
                $rows = $this->stores->changes()->fleetRowCount();
                if ($cap > 0 && $rows >= (int) floor($cap * self::CAP_WARN)) {
                    $out[] = ['line' => 'Evidence: the change store holds ' . $rows . ' of at most ' . $cap . ' rows.', 'caveat' => 'the change record row cap (' . $cap . ') is nearly reached, so the oldest records are deleted before the retention period ends; raise the cap'];
                }
            }
        } catch (\Throwable) {
            $unreadable[] = 'the ' . strtolower(DataClass::label($class)) . ' in the RMM store';
        }

        return $out;
    }
}
