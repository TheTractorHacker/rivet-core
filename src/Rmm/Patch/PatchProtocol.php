<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Patch;

/**
 * The wire and storage constants of RMM Phase 4 (patch management): job types, capability flags, the strict syntax of an update id and the
 * vocabularies of the server tables. They live apart from {@see \RivetCore\Rmm\RmmProtocol} (pinned by the frozen-constants test) like
 * {@see \RivetCore\Rmm\Remote\RemoteProtocol}: nothing here changes an existing value. Installed agents depend on the job and capability names.
 *
 * @api
 */
final class PatchProtocol
{
    // ---- job types (registry names, at most 20 characters) and the capability each needs (announced in the check-in)
    public const JOB_SCAN = 'patch_scan';
    public const JOB_INSTALL = 'patch_install';
    /** Windows Update control (1.0.0-rc.65): sets or restores the Windows Update POLICY registry values the agent owns. Windows only, destructive (offered once). */
    public const JOB_CONFIGURE = 'patch_configure';
    public const JOB_TYPES = [self::JOB_SCAN, self::JOB_INSTALL];
    /** Every patch job type including Windows Update control ({@see JOB_TYPES} keeps its released value). */
    public const ALL_JOB_TYPES = [self::JOB_SCAN, self::JOB_INSTALL, self::JOB_CONFIGURE];
    public const CAP_SCAN = 'patch:scan';
    public const CAP_INSTALL = 'patch:install';
    public const CAP_CONFIGURE = 'patch:configure';

    /**
     * The `type` column of rmm_patch_jobs is varchar(14) and `patch_configure` has 15 characters (no migration may widen it), so the ledger rows of this feature
     * use these short names. The wire job type and the capability keep their long names.
     */
    public const LEDGER_CONFIGURE = 'configure';
    /** One durable row per device (job_id = {@see wuStateRowId()}, applied at creation, never pruned): the last Windows Update policy state the device reported. */
    public const LEDGER_WU_STATE = 'wu_state';

    /** The sub-switch in `features_json` (already listed in RmmSettings::FEATURES since Phase 0; off by default). */
    public const FEATURE = 'patching';

    // ---- update ids
    /** Windows: the Windows Update UpdateID (a GUID, lower case). */
    public const WINDOWS_ID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    /** Linux: a package name, optionally `:arch` (apt) or `.arch` (dnf, covered by the character set). Never starts with a symbol, so it can never be an option. */
    public const PACKAGE_ID_RE = '/^[a-z0-9][a-z0-9+._-]{0,99}(:[a-z0-9-]{1,20})?$/D';
    /** The most ids one install job carries (the parameter is a comma list of at most {@see IDS_MAX_CHARS} characters). */
    public const IDS_MAX = 40;
    public const IDS_MAX_CHARS = 1000;
    public const MAX_MINUTES_DEFAULT = 60;
    public const MAX_MINUTES_MAX = 240;

    // ---- vocabularies
    public const MODES = ['dry_run', 'install'];
    /** Worst first. */
    public const KINDS = ['critical', 'security', 'important', 'optional', 'driver', 'other'];
    public const SEVERITIES = ['critical', 'important', 'moderate', 'low', 'unspecified'];
    public const CATEGORIES = ['security', 'critical', 'important', 'definition', 'feature', 'driver', 'optional', 'other'];
    /** Status of one update on one device. */
    public const STATUSES = ['available', 'approved', 'declined', 'installed', 'failed'];
    public const REBOOT_POLICIES = ['never', 'if_required_defer', 'if_required_now'];
    public const APPROVAL_MODES = ['manual', 'auto'];
    public const WINDOW_MODES = ['none', 'maintenance', 'daily'];
    public const SCAN_STATES = ['ok', 'partial', 'failed', 'never'];
    public const WU_STATES = ['running', 'stopped', 'disabled', 'n/a'];
    public const MANAGERS = ['wua', 'apt', 'dnf', 'yum', 'zypper'];
    public const RESULTS = ['would_install', 'installed', 'failed', 'skipped', 'not_applicable', 'eula_required', 'not_found', 'locked', 'needs_reboot'];
    /** Results that count as "the update is now installed". */
    public const OK_RESULTS = ['installed', 'needs_reboot'];
    /** Results that count as a failed installation. */
    public const FAILED_RESULTS = ['failed', 'eula_required', 'not_found', 'locked'];

    /** Check keys of the alert engine (Phase 3). */
    public const CHECK_COMPLIANCE = 'patch.compliance';
    public const CHECK_INSTALL_FAILED = 'patch.install_failed';
    /** Windows only: the device runs a feature version newer than the one its policy holds it at. */
    public const CHECK_FEATURE_VERSION = 'patch.feature_version';

    // ---- Windows Update control (policy keys, see PatchPolicySettings)
    public const WU_MODES = ['leave', 'managed'];
    /** A named feature version: two digits, H1 or H2 (22H2, 23H2, 24H2, 25H2, 26H2). Nothing else, never a free string. */
    public const FEATURE_VERSION_RE = '/^[0-9]{2}H[12]$/D';
    public const FEATURE_TARGET_NONE = 'none';
    public const FEATURE_TARGET_HOLD = 'hold';
    public const DEFER_DAYS_MAX = 30;
    public const CONFIGURE_MODES = ['dry_run', 'apply'];
    /** Outcomes of one `patch_configure` job. */
    public const CONFIGURE_OUTCOMES = ['unchanged', 'changed', 'restored', 'would_change', 'would_restore', 'externally_managed', 'cannot_hold'];
    /** Why a device is managed by something else. */
    public const EXTERNAL_SOURCES = ['wsus', 'gpo', 'mdm', 'changed_since'];

    /** The capability a job type needs on the device (an agent that announced nothing is never assumed to have it). */
    public static function capabilityFor(string $jobType): ?string
    {
        return match ($jobType) {
            self::JOB_SCAN => self::CAP_SCAN,
            self::JOB_INSTALL => self::CAP_INSTALL,
            self::JOB_CONFIGURE => self::CAP_CONFIGURE,
            default => null,
        };
    }

    /** The 36 character primary key of the durable Windows Update state row of a device (rmm_patch_jobs.job_id). */
    public static function wuStateRowId(int $deviceId): string
    {
        return 'wustate-' . str_pad((string) $deviceId, 28, '0', STR_PAD_LEFT);
    }

    /** Whether `$v` is a named feature version such as 25H2. */
    public static function validFeatureVersion(mixed $v): bool
    {
        return is_string($v) && preg_match(self::FEATURE_VERSION_RE, $v) === 1;
    }

    /** Orders two feature versions: negative when a is older than b. Both must be valid ({@see validFeatureVersion()}). */
    public static function compareFeatureVersions(string $a, string $b): int
    {
        return [(int) substr($a, 0, 2), (int) substr($a, 3, 1)] <=> [(int) substr($b, 0, 2), (int) substr($b, 3, 1)];
    }

    public static function isPatchJob(mixed $type): bool
    {
        return is_string($type) && in_array($type, self::ALL_JOB_TYPES, true);
    }

    /** Whether `$id` is a valid update id for a device of that operating system. */
    public static function validId(mixed $id, string $os): bool
    {
        if (!is_string($id)) {
            return false;
        }

        return $os === 'windows' ? preg_match(self::WINDOWS_ID_RE, $id) === 1 : preg_match(self::PACKAGE_ID_RE, $id) === 1;
    }
}
