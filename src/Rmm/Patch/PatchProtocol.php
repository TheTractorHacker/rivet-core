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
    public const JOB_TYPES = [self::JOB_SCAN, self::JOB_INSTALL];
    public const CAP_SCAN = 'patch:scan';
    public const CAP_INSTALL = 'patch:install';

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

    /** The capability a job type needs on the device (an agent that announced nothing is never assumed to have it). */
    public static function capabilityFor(string $jobType): ?string
    {
        return match ($jobType) {
            self::JOB_SCAN => self::CAP_SCAN,
            self::JOB_INSTALL => self::CAP_INSTALL,
            default => null,
        };
    }

    public static function isPatchJob(mixed $type): bool
    {
        return is_string($type) && in_array($type, self::JOB_TYPES, true);
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
