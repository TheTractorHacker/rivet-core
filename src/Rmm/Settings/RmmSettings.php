<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Settings;

use RivetCore\Rmm\Contracts\RmmBridgeInterface;
use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Checks\CheckCatalog;
use RivetCore\Rmm\Checks\RmmChecks;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Support\Sql;

/**
 * The one-row endpoint_agent_settings table (never the edition's `settings`, which is at the row-size limit): read, validate and
 * update, the module switch columns of migration 0016 (master `enabled`, `features_json`, `limits_json`, `shed_level`,
 * `ingest_mode`, `max_devices`), the instance signing key (minted here, sealed with the edition's SecretBox) and the check
 * schedule delivered to agents. The row is cached per instance and the cache is dropped on every write.
 *
 * @api
 */
final class RmmSettings
{
    /** Columns {@see set()} may write. Key material is written only by {@see generateSigningKey()}. */
    public const WRITABLE = [
        'enabled', 'service_url', 'check_in_interval_s', 'collect_interval_s', 'offline_after_s', 'stale_after_s',
        'failure_debounce', 'recovery_debounce', 'retention_days', 'job_retention_days', 'job_output_max_bytes',
        'job_default_timeout_s', 'job_max_timeout_s', 'job_expiry_s', 'job_ack_timeout_s', 'job_max_attempts', 'enroll_max_ttl_h',
        'unmatched_policy', 'checks_json', 'mesh_enabled', 'mesh_url', 'mesh_domain', 'mesh_login_key_enc', 'mesh_account_template',
        'mesh_policy', 'mesh_token_ttl_s', 'coexistence_policy', 'integration_id', 'ca_pem',
        'features_json', 'limits_json', 'shed_level', 'ingest_mode', 'max_devices',
    ];

    /**
     * Server-side bounds for the intervals agents are told to use (design 13.3). The two floors are a DELIBERATE change from RivetIT's
     * original save handler (check-in 30 to 3600, collect 10 to 3600): they are capacity controls, because the check-in rate and the
     * sample rows per check-in are what a server pays for. Only a save clamps; a stored value below the new floor keeps working until
     * the settings are saved again (UPGRADING.md lists the difference).
     */
    public const CHECK_IN_INTERVAL_MIN_S = 60;
    public const CHECK_IN_INTERVAL_MAX_S = 3600;
    public const COLLECT_INTERVAL_MIN_S = 30;
    public const COLLECT_INTERVAL_MAX_S = 3600;
    /** MeshCentral login-token lifetime bounds, the same in {@see update()} and RmmAdmin::saveMesh() (RivetIT's original: 60 to 3600). */
    public const MESH_TOKEN_TTL_MIN_S = 60;
    public const MESH_TOKEN_TTL_MAX_S = 3600;
    public const MESH_TOKEN_TTL_DEFAULT_S = 300;

    /** Every feature a sub-switch may name (the first five are live in Phase 0; the rest are reserved for later phases). */
    public const FEATURES = ['monitoring', 'metrics', 'jobs', 'remote', 'updates', 'inventory_software', 'policies', 'scripts', 'patching', 'software', 'software_deploy', 'logs', 'change_monitor', 'reports', 'alerting', 'remote_tools', 'filetransfer'];
    /** What a NULL features_json means: today's behaviour. `remote` follows mesh_enabled. */
    public const LEGACY_FEATURES = ['monitoring', 'metrics', 'jobs', 'updates'];

    public const INGEST_MODES = ['sync', 'queued'];

    /** @var array<string,array{0:int,1:int}> limits_json keys => [min, max]; unknown keys are refused */
    public const LIMIT_KEYS = [
        'max_checkins_per_min' => [0, 1000000],
        'retry_after_min_s' => [1, 3600],
        'retry_after_max_s' => [1, 3600],
        'shed_retry_min_s' => [1, 3600],
        'shed_retry_max_s' => [1, 3600],
        // Load shedding thresholds (Capacity\LoadShedder): queue backlog (pending rmm.ingest jobs), database probe latency in
        // milliseconds, check-ins in the last minute. 0 means that level never triggers on that signal; the others must not decrease from level 1 to 3.
        'shed_backlog_l1' => [0, 10000000],
        'shed_backlog_l2' => [0, 10000000],
        'shed_backlog_l3' => [0, 10000000],
        'shed_db_ms_l1' => [0, 60000],
        'shed_db_ms_l2' => [0, 60000],
        'shed_db_ms_l3' => [0, 60000],
        'shed_rate_per_min' => [0, 10000000],
        // Phase 1 history: days the per-check history ring keeps (0 = record none), the minimum seconds between two stored
        // results of one check that did not change status, and days the software change log is kept.
        'check_history_days' => [0, 365],
        'check_history_gap_s' => [60, 86400],
        'software_history_days' => [1, 3650],
        // Scale S3: when above 0, a check result that changes nothing (same status, detail and counters, same history bucket) does not refresh the
        // row's "last reported" stamp more often than this many seconds (0 = every check-in, the exact old behaviour).
        'check_touch_s' => [0, 3600],
        // Phase 2: a run on more devices than approval_bulk_threshold needs a second user (0 = never, the default), an approval request
        // lapses after approval_expiry_h hours, one scheduled run creates at most schedule_batch jobs per housekeeping pass (shed levels
        // divide it), and one bulk run may target at most bulk_run_max devices.
        'approval_bulk_threshold' => [0, 100000],
        'approval_expiry_h' => [1, 720],
        'schedule_batch' => [10, 5000],
        'bulk_run_max' => [1, 100000],
        // Scale S5 (storage): days the hourly and the daily metric rollups of the database sink are kept, time partitioning on (1) or off (0, the
        // batched DELETE pruning), the days one partition spans, and how many partitions are kept created ahead of now.
        'metric_hourly_days' => [1, 400],
        'metric_daily_days' => [1, 3650],
        'partitioning' => [0, 1],
        'partition_span_days' => [1, 7],
        'partition_ahead' => [1, 60],
        // Scale S6 (workers): most rmm.ingest jobs one drain() claims, and the most that may be running at once across all workers (0 = no limit).
        'ingest_batch' => [1, 200],
        'ingest_max_running' => [0, 10000],
        // Scale S8 (read path and herd control): seconds a cached fleet figure is fresh and the further seconds it may be served stale while one request
        // refreshes it (0 fresh = no caching); a token bucket per client for check-ins and enrollment (0 burst = off); the warm start ramp after the module is
        // switched back on or after a quiet gap (0 = off): seconds it lasts, the share of check-ins admitted at its start, and the quiet gap that counts as an outage.
        'cache_fresh_s' => [0, 3600],
        'cache_stale_s' => [0, 86400],
        'client_bucket_burst' => [0, 1000000],
        'client_bucket_per_min' => [0, 1000000],
        'warm_start_s' => [0, 7200],
        'warm_start_pct' => [1, 100],
        'warm_quiet_s' => [30, 86400],
        // Phase 4 (patching, `patching` switch): seconds between two patch scans of a device (jittered), days a critical or security update may be missing before the
        // compliance check warns (fails at twice that), most devices installing at once in the fleet and per client, whether the scheduler may do real installs
        // (0: dry runs only, the safe default), days of install history kept, and devices one scheduler pass works on (halved/quartered by load shedding).
        'patch_scan_interval_s' => [3600, 604800],
        'patch_overdue_days' => [1, 365],
        'patch_max_installs' => [1, 10000],
        'patch_max_installs_per_client' => [1, 1000],
        'patch_live_install' => [0, 1],
        'patch_history_days' => [30, 3650],
        'patch_batch' => [10, 5000],
        // Phase 5 (software deployment, `software_deploy` switch): seconds between two detections of a device (jittered), most devices installing or removing at once in
        // the fleet and per client, whether the scheduler may do real installs (0: dry runs only, the safe default), days of deployment history kept, and devices one
        // scheduler pass works on (halved/quartered by load shedding).
        'software_detect_interval_s' => [3600, 604800],
        'software_max_installs' => [1, 10000],
        'software_max_installs_per_client' => [1, 1000],
        'software_live_install' => [0, 1],
        'software_deploy_history_days' => [30, 3650],
        'software_batch' => [10, 5000],
    ];
    public const LIMIT_DEFAULTS = [
        'max_checkins_per_min' => 0,
        'retry_after_min_s' => 30,
        'retry_after_max_s' => 120,
        'shed_retry_min_s' => 60,
        'shed_retry_max_s' => 300,
        'shed_backlog_l1' => 500,
        'shed_backlog_l2' => 2000,
        'shed_backlog_l3' => 8000,
        'shed_db_ms_l1' => 100,
        'shed_db_ms_l2' => 300,
        'shed_db_ms_l3' => 1000,
        'shed_rate_per_min' => 0,
        'check_history_days' => 7,
        'check_history_gap_s' => 3600,
        'software_history_days' => 365,
        'check_touch_s' => 0,
        'approval_bulk_threshold' => 0,
        'approval_expiry_h' => 48,
        'schedule_batch' => 200,
        'bulk_run_max' => 1000,
        'metric_hourly_days' => 14,
        'metric_daily_days' => 400,
        'partitioning' => 0,
        'partition_span_days' => 1,
        'partition_ahead' => 7,
        'ingest_batch' => 50,
        'ingest_max_running' => 0,
        'cache_fresh_s' => 15,
        'cache_stale_s' => 300,
        'client_bucket_burst' => 0,
        'client_bucket_per_min' => 0,
        'warm_start_s' => 0,
        'warm_start_pct' => 10,
        'warm_quiet_s' => 120,
        'patch_scan_interval_s' => 86400,
        'patch_overdue_days' => 14,
        'patch_max_installs' => 25,
        'patch_max_installs_per_client' => 5,
        'patch_live_install' => 0,
        'patch_history_days' => 365,
        'patch_batch' => 100,
        'software_detect_interval_s' => 86400,
        'software_max_installs' => 25,
        'software_max_installs_per_client' => 5,
        'software_live_install' => 0,
        'software_deploy_history_days' => 365,
        'software_batch' => 100,
    ];

    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    /** @var (\Closure(): void)|null called after a write that touches a column the state file mirrors */
    private ?\Closure $stateListener = null;

    /** @var (\Closure(): void)|null called when the master switch goes from off to on (the warm start of {@see \RivetCore\Rmm\Capacity\HerdControl}) */
    private ?\Closure $enableListener = null;

    public function __construct(
        private readonly Sql $sql,
        private readonly SecretBoxInterface $box,
        private readonly RmmBridgeInterface $bridge,
        private readonly string $integrationName = RmmProtocol::DEFAULT_INTEGRATION_NAME,
        private readonly bool $allowInsecureHttp = false,
    ) {
    }

    /**
     * Register the callback that keeps the zero-database state file in step ({@see \RivetCore\Rmm\RmmState::sync()}). It runs after
     * every {@see set()} (and so after update/enable/disable) that writes enabled, features_json, limits_json, shed_level,
     * ingest_mode, mesh_enabled or max_devices. A failing listener never fails the write.
     *
     * @param \Closure(): void|null $listener
     */
    public function onStateChange(?\Closure $listener): void
    {
        $this->stateListener = $listener;
    }

    /** @return array<string,mixed> the settings row */
    public function get(bool $fresh = false): array
    {
        if ($this->cache === null || $fresh) {
            $row = $this->sql->one('SELECT * FROM endpoint_agent_settings WHERE id = 1');
            if ($row === null) {
                $this->sql->run('INSERT IGNORE INTO endpoint_agent_settings (id) VALUES (1)');
                $row = $this->sql->one('SELECT * FROM endpoint_agent_settings WHERE id = 1') ?? [];
            }
            $this->cache = $row;
        }

        return $this->cache;
    }

    public function refresh(): void
    {
        $this->cache = null;
    }

    public function enabled(): bool
    {
        return (int) ($this->get()['enabled'] ?? 0) === 1;
    }

    /**
     * @param array<string,mixed> $values
     * @throws \InvalidArgumentException for a column that is not writable here
     */
    public function set(array $values): void
    {
        $sets = [];
        $params = [];
        foreach ($values as $k => $v) {
            if (!in_array($k, self::WRITABLE, true)) {
                throw new \InvalidArgumentException("unknown setting $k");
            }
            $sets[] = "`$k` = ?";
            $params[] = $v;
        }
        if ($sets !== []) {
            $this->sql->run('UPDATE endpoint_agent_settings SET ' . implode(', ', $sets) . ' WHERE id = 1', $params);
        }
        $this->cache = null;
        if ($this->stateListener !== null && array_intersect(array_keys($values), \RivetCore\Rmm\RmmState::MIRRORED_COLUMNS) !== []) {
            try {
                ($this->stateListener)();
            } catch (\Throwable $e) {
                error_log('rmm state file: ' . $e->getMessage());
            }
        }
    }

    /**
     * Validate an administrator's settings form (only the keys present are touched) and save it. Whole numbers are clamped to their
     * range, not refused; a bad URL, check schedule, feature or limit list refuses the whole save. Nothing is written on error.
     *
     * @param array<string,mixed> $in column => value (checks_json as JSON text, features_json/limits_json as JSON text or arrays)
     * @return list<string> error messages; empty when saved
     */
    public function update(array $in): array
    {
        $out = [];
        $err = [];
        $int = static function (string $k, int $min, int $max) use ($in, &$out): void {
            if (array_key_exists($k, $in) && is_numeric($in[$k])) {
                $out[$k] = max($min, min($max, (int) $in[$k]));
            }
        };
        if (array_key_exists('service_url', $in)) {
            $url = trim((string) (is_scalar($in['service_url']) ? $in['service_url'] : ''));
            if ($url !== '' && !self::serviceUrlOk($url, $this->allowInsecureHttp)) {
                $err[] = 'The service URL must be an https:// address that agents can reach.';
            }
            $out['service_url'] = $url;
        }
        $int('check_in_interval_s', self::CHECK_IN_INTERVAL_MIN_S, self::CHECK_IN_INTERVAL_MAX_S);
        $int('collect_interval_s', self::COLLECT_INTERVAL_MIN_S, self::COLLECT_INTERVAL_MAX_S);
        $int('offline_after_s', 60, 86400);
        $int('stale_after_s', 3600, 31536000);
        $int('failure_debounce', 1, 20);
        $int('recovery_debounce', 1, 20);
        $int('retention_days', 1, 400);
        $int('job_retention_days', 1, 3650);
        $int('job_output_max_bytes', 1024, 200000);
        $int('job_default_timeout_s', 5, 3600);
        $int('job_max_timeout_s', 30, 86400);
        $int('job_expiry_s', 60, 604800);
        $int('job_ack_timeout_s', 30, 3600);
        $int('job_max_attempts', 1, 10);
        $int('enroll_max_ttl_h', 1, 720);
        $int('max_devices', 0, 1000000);
        $int('mesh_token_ttl_s', self::MESH_TOKEN_TTL_MIN_S, self::MESH_TOKEN_TTL_MAX_S);
        if (array_key_exists('unmatched_policy', $in)) {
            $out['unmatched_policy'] = in_array($in['unmatched_policy'], ['approval', 'auto_create'], true) ? $in['unmatched_policy'] : 'approval';
        }
        if (array_key_exists('ingest_mode', $in)) {
            if (!in_array($in['ingest_mode'], self::INGEST_MODES, true)) {
                $err[] = 'The ingest mode must be sync or queued.';
            } else {
                $out['ingest_mode'] = $in['ingest_mode'];
            }
        }
        if (array_key_exists('checks_json', $in)) {
            $text = trim((string) (is_scalar($in['checks_json']) ? $in['checks_json'] : ''));
            if ($text === '') {
                $out['checks_json'] = null;
            } else {
                [$list, $e] = ChecksValidator::validate($text);
                if ($e !== null) {
                    $err[] = $e;
                } else {
                    $out['checks_json'] = json_encode(ChecksValidator::toWire($list));
                }
            }
        }
        if (array_key_exists('features_json', $in)) {
            [$f, $e] = self::validateFeatures($in['features_json']);
            if ($e !== null) {
                $err[] = $e;
            } else {
                $out['features_json'] = $f;
            }
        }
        if (array_key_exists('limits_json', $in)) {
            [$l, $e] = self::validateLimits($in['limits_json']);
            if ($e !== null) {
                $err[] = $e;
            } else {
                $out['limits_json'] = $l;
            }
        }
        if (array_key_exists('coexistence_policy', $in)) {
            $out['coexistence_policy'] = mb_substr(trim((string) (is_scalar($in['coexistence_policy']) ? $in['coexistence_policy'] : '')), 0, 4000);
        }
        if (array_key_exists('ca_pem', $in)) {
            // Already normalised by the installer service (InstallerService::normalizeCa); null clears it.
            $out['ca_pem'] = $in['ca_pem'] === null || $in['ca_pem'] === '' ? null : (string) (is_scalar($in['ca_pem']) ? $in['ca_pem'] : '');
        }
        if (array_key_exists('enabled', $in)) {
            $out['enabled'] = !empty($in['enabled']) ? 1 : 0;
        }
        if ($err !== []) {
            return $err;
        }
        if (($out['enabled'] ?? 0) === 1) {
            $this->enable();
        }
        $this->set($out);

        return [];
    }

    public static function serviceUrlOk(string $url, bool $allowInsecureHttp): bool
    {
        $p = parse_url($url);
        if ($p === false || empty($p['host']) || isset($p['user']) || strlen($url) > 500) {
            return false;
        }
        $scheme = $p['scheme'] ?? '';

        return $scheme === 'https' || ($allowInsecureHttp && $scheme === 'http');
    }

    // ------------------------------------------------------------------ the module switch

    /**
     * Turn the module on: mint the instance signing key the first time and make sure the synthetic RMM integration row exists
     * (asset_rmm_links.integration_id points at it, so devices appear in the edition's RMM views).
     */
    /** @param \Closure(): void|null $listener */
    public function onEnable(?\Closure $listener): void
    {
        $this->enableListener = $listener;
    }

    public function enable(): void
    {
        $cfg = $this->get(true);
        $wasOff = (int) ($cfg['enabled'] ?? 0) !== 1;
        if ((string) $cfg['signing_public_key'] === '' || empty($cfg['signing_private_key_enc'])) {
            $this->generateSigningKey();
        }
        $this->integrationId();
        $this->set(['enabled' => 1]);
        if ($wasOff && $this->enableListener !== null) {
            try {
                ($this->enableListener)();
            } catch (\Throwable) {
                // a warm start that cannot be recorded is simply not in force
            }
        }
    }

    /** Switch the master off. Nothing is deleted. */
    public function disable(): void
    {
        $this->set(['enabled' => 0]);
    }

    /**
     * Effective sub-switches. NULL features_json is "legacy defaults" (monitoring, metrics, jobs, updates on; remote follows
     * mesh_enabled; everything newer off); a stored list names exactly the features that are on.
     *
     * @return array<string,bool>
     */
    public function features(): array
    {
        $cfg = $this->get();
        $raw = $cfg['features_json'] ?? null;
        $stored = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $out = array_fill_keys(self::FEATURES, false);
        if (!is_array($stored) || array_is_list($stored)) {
            foreach (self::LEGACY_FEATURES as $f) {
                $out[$f] = true;
            }
            $out['remote'] = (int) ($cfg['mesh_enabled'] ?? 0) === 1;

            return $out;
        }
        foreach (self::FEATURES as $f) {
            $out[$f] = !empty($stored[$f]);
        }

        return $out;
    }

    public function featureOn(string $feature): bool
    {
        return $this->features()[$feature] ?? false;
    }

    /**
     * Capacity limits with their defaults filled in.
     *
     * @return array<string,int>
     */
    public function limits(): array
    {
        $raw = $this->get()['limits_json'] ?? null;
        $stored = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $out = self::LIMIT_DEFAULTS;
        if (is_array($stored)) {
            foreach (self::LIMIT_KEYS as $k => [$min, $max]) {
                if (isset($stored[$k]) && is_int($stored[$k]) && $stored[$k] >= $min && $stored[$k] <= $max) {
                    $out[$k] = $stored[$k];
                }
            }
        }

        return $out;
    }

    /**
     * Only the limits an administrator stored (valid ones), without the defaults filled in. For a setting that has an older home elsewhere (the retention
     * of the database metric sink is a constructor argument of the edition) the stored value wins and the absence of one means "keep what the edition set".
     *
     * @return array<string,int>
     */
    public function explicitLimits(): array
    {
        $raw = $this->get()['limits_json'] ?? null;
        $stored = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $out = [];
        if (is_array($stored)) {
            foreach (self::LIMIT_KEYS as $k => [$min, $max]) {
                if (isset($stored[$k]) && is_int($stored[$k]) && $stored[$k] >= $min && $stored[$k] <= $max) {
                    $out[$k] = $stored[$k];
                }
            }
        }

        return $out;
    }

    /**
     * @return array{0:?string,1:?string} [normalised JSON text or null for "legacy defaults", error]
     */
    public static function validateFeatures(mixed $in): array
    {
        if ($in === null || $in === '') {
            return [null, null];
        }
        $d = is_string($in) ? json_decode($in, true) : $in;
        if (!is_array($d) || ($d !== [] && array_is_list($d))) {
            return [null, 'Features must be a JSON object of feature name to true or false.'];
        }
        $out = [];
        foreach ($d as $k => $v) {
            if (!in_array($k, self::FEATURES, true)) {
                return [null, 'Unknown feature ' . (is_string($k) ? $k : (string) $k) . '.'];
            }
            if (!is_bool($v)) {
                return [null, "Feature $k must be true or false."];
            }
            $out[$k] = $v;
        }

        return [(string) json_encode($out === [] ? new \stdClass() : $out), null];
    }

    /**
     * @return array{0:?string,1:?string} [normalised JSON text or null for "defaults", error]
     */
    public static function validateLimits(mixed $in): array
    {
        if ($in === null || $in === '') {
            return [null, null];
        }
        $d = is_string($in) ? json_decode($in, true) : $in;
        if (!is_array($d) || ($d !== [] && array_is_list($d))) {
            return [null, 'Limits must be a JSON object of limit name to whole number.'];
        }
        $out = [];
        foreach ($d as $k => $v) {
            $k = (string) $k;
            if (!isset(self::LIMIT_KEYS[$k])) {
                return [null, "Unknown limit $k."];
            }
            [$min, $max] = self::LIMIT_KEYS[$k];
            if (!is_int($v) || $v < $min || $v > $max) {
                return [null, "Limit $k must be a whole number from $min to $max."];
            }
            $out[$k] = $v;
        }
        $lo = $out['retry_after_min_s'] ?? self::LIMIT_DEFAULTS['retry_after_min_s'];
        $hi = $out['retry_after_max_s'] ?? self::LIMIT_DEFAULTS['retry_after_max_s'];
        $slo = $out['shed_retry_min_s'] ?? self::LIMIT_DEFAULTS['shed_retry_min_s'];
        $shi = $out['shed_retry_max_s'] ?? self::LIMIT_DEFAULTS['shed_retry_max_s'];
        if ($lo > $hi || $slo > $shi) {
            return [null, 'A retry-after minimum must not exceed its maximum.'];
        }
        foreach (['shed_backlog', 'shed_db_ms'] as $family) {
            $t = [];
            foreach (['l1', 'l2', 'l3'] as $l) {
                $t[] = $out["{$family}_$l"] ?? self::LIMIT_DEFAULTS["{$family}_$l"];
            }
            $t = array_values(array_filter($t, static fn (int $v): bool => $v > 0));   // 0 = that level never triggers on this signal
            if ($t !== [] && $t !== (function (array $x): array { sort($x); return $x; })($t)) {
                return [null, 'The load-shedding thresholds must not decrease from level 1 to level 3.'];
            }
        }

        return [(string) json_encode($out === [] ? new \stdClass() : $out), null];
    }

    // ------------------------------------------------------------------ signing key and integration

    /** Mint a new instance signing key (agents must re-enroll to trust it). @return string the new key id */
    public function generateSigningKey(): string
    {
        $k = Signer::generateStoredKey($this->box);
        $this->sql->run('UPDATE endpoint_agent_settings SET signing_key_id = ?, signing_public_key = ?, signing_private_key_enc = ?, signing_key_created_at = ? WHERE id = 1',
            [$k['signing_key_id'], $k['signing_public_key'], $k['signing_private_key_enc'], $this->sql->utcNow()]);
        $this->cache = null;

        return $k['signing_key_id'];
    }

    /**
     * @return array{0:string,1:string,2:string} [secret key base64, public key base64, key id]
     * @throws \RuntimeException when no usable key is stored
     */
    public function signingKey(): array
    {
        $cfg = $this->get();
        $sec = Signer::openSecretKey($this->box, (string) ($cfg['signing_private_key_enc'] ?? ''));
        if ((string) $cfg['signing_public_key'] === '') {
            throw new \RuntimeException('endpoint agent signing key is not available');
        }

        return [$sec, (string) $cfg['signing_public_key'], (string) $cfg['signing_key_id']];
    }

    /** The synthetic integration row id, created on first use through the edition's bridge. */
    public function integrationId(): int
    {
        $id = (int) ($this->get()['integration_id'] ?? 0);
        if ($id > 0 && $this->bridge->integrationExists($id, RmmProtocol::INTEGRATION_TYPE)) {
            return $id;
        }
        $id = $this->bridge->ensureIntegration(RmmProtocol::INTEGRATION_TYPE, $this->integrationName);
        $this->set(['integration_id' => $id]);

        return $id;
    }

    // ------------------------------------------------------------------ checks delivered to agents

    /** @return list<array<string,mixed>> */
    public static function defaultChecks(): array
    {
        return [
            ['key' => 'disk_c', 'type' => 'disk', 'params' => ['mount' => 'C:', 'warn_pct' => 85, 'fail_pct' => 95], 'interval_s' => 300],
            ['key' => 'pending_reboot', 'type' => 'pending_reboot', 'params' => [], 'interval_s' => 3600],
            ['key' => 'svc_eventlog', 'type' => 'service', 'params' => ['name' => 'EventLog', 'expect' => 'running'], 'interval_s' => 300],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function checks(): array
    {
        $raw = (string) ($this->get()['checks_json'] ?? '');
        $list = $raw === '' ? null : json_decode($raw, true);
        if (!is_array($list) || !array_is_list($list)) {
            return self::defaultChecks();
        }

        /** @var list<array<string,mixed>> $list */
        return $list;
    }

    /** True when the configured check list contains a type the original agent does not know (Phase 3). */
    public function hasExtendedChecks(): bool
    {
        foreach ($this->checks() as $c) {
            if (!CheckCatalog::isLegacy((string) ($c['type'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check definitions as delivered to the agent, each signed (script checks execute code on the endpoint).
     *
     * A Phase 3 check type is included only when $caps (the device's announced capabilities) lists `check:<type>`; the four original types
     * are always included. With no capabilities (enrollment, an old agent) the list is therefore exactly what it always was.
     *
     * With $os ('windows' or 'linux', the device's OS) a check limited to the other OS is not delivered (rc.24); the signature covers each
     * delivered entry, including its `os` member when it has one.
     *
     * @param list<string>|null $caps
     * @return list<array<string,mixed>>
     */
    public function signedChecks(?array $caps = null, ?string $os = null): array
    {
        return $this->signCheckList($this->checks(), $caps, $os);
    }

    /**
     * Sign any list of check definitions exactly as {@see signedChecks()} does (the policy engine hands in the per-device list).
     *
     * @param list<array<string,mixed>> $checks
     * @param list<string>|null $caps
     * @return list<array<string,mixed>>
     */
    public function signCheckList(array $checks, ?array $caps = null, ?string $os = null): array
    {
        [$sec] = $this->signingKey();
        $out = [];
        foreach ($checks as $c) {
            if (!CheckCatalog::offeredTo((string) $c['type'], $caps)) {
                continue;
            }
            if ($os !== null && !RmmChecks::appliesTo($c, $os)) {
                continue;
            }
            $item = [
                'key' => (string) $c['key'],
                'type' => (string) $c['type'],
                'params' => $c['params'] ?? new \stdClass(),
                'interval_s' => (int) $c['interval_s'],
            ];
            if (is_array($item['params']) && $item['params'] === []) {
                $item['params'] = new \stdClass();
            }
            $target = RmmChecks::osOf($c);
            if ($target !== null) {
                $item['os'] = $target;
            }
            $item['signature'] = Signer::sign(Signer::checkMessage($item), $sec);
            $out[] = $item;
        }

        return $out;
    }
}
