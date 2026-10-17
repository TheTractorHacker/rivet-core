<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Deploy;

/**
 * The wire and storage constants of RMM Phase 5 (software deployment): job types, capability flags, the strict syntax of everything that reaches an agent
 * and the vocabularies of the server tables. Like {@see \RivetCore\Rmm\Patch\PatchProtocol} they live apart from {@see \RivetCore\Rmm\RmmProtocol} (pinned by the
 * frozen-constants test): nothing here changes an existing value. Installed agents depend on the job and capability names.
 *
 * @api
 */
final class DeployProtocol
{
    // ---- job types (registry names, at most 20 characters) and the capability each needs (announced in the check-in)
    public const JOB_DETECT = 'software_detect';
    public const JOB_INSTALL = 'software_install';
    public const JOB_TYPES = [self::JOB_DETECT, self::JOB_INSTALL];
    public const CAP_DETECT = 'software:detect';
    public const CAP_INSTALL = 'software:install';

    /** The sub-switch in `features_json` (off by default; nothing in this phase runs, is scheduled or is stored while it is off). */
    public const FEATURE = 'software_deploy';

    // ---- syntax (anchored, `D` modifier: a trailing newline does not pass). The agent checks every one of them again.
    /** A Linux package name, never starting with a symbol, so it can never be an option. */
    public const PACKAGE_RE = '/^[a-z0-9][a-z0-9+._-]{0,99}$/D';
    /** A version to pin (apt `pkg=version`, dnf `pkg-version`). */
    public const PIN_RE = '/^[A-Za-z0-9][A-Za-z0-9.+~:_-]{0,63}$/D';
    /** A minimum version for detection. */
    public const MIN_RE = '/^[0-9][A-Za-z0-9.+~:_-]{0,63}$/D';
    public const PRODUCT_CODE_RE = '/^\{[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}\}$/D';
    public const SHA256_RE = '/^[0-9a-f]{64}$/D';
    /** Silent-install arguments: no quotes, no shell characters. The agent never uses a shell; this is the second wall. */
    public const ARGS_RE = '/^[A-Za-z0-9 \/\\\\_=.:,+-]{0,200}$/D';
    public const ARGS_MAX_TOKENS = 12;
    public const ARGS_MAX_TOKEN_CHARS = 80;
    public const KEY_RE = '/^[a-z0-9][a-z0-9._-]{0,39}$/D';
    public const URL_MAX = 600;
    public const SIZE_MAX = 1073741824;
    public const DETECT_ITEMS_MAX = 25;
    /** A job parameter is at most 1024 characters; the detection rules travel as one JSON text of at most this many. */
    public const RULES_MAX_CHARS = 1000;
    public const MAX_MINUTES_DEFAULT = 30;
    public const MAX_MINUTES_MAX = 120;
    public const NAME_MAX = 120;

    // ---- vocabularies
    public const ACTIONS = ['install', 'uninstall'];
    /** `dry_run` changes nothing; `run` does it. The default of every API and of the scheduler (until `software_live_install` is 1) is the dry run. */
    public const MODES = ['dry_run', 'run'];
    public const OSES = ['windows', 'linux'];
    public const FORMATS = ['apt', 'dnf', 'msi', 'exe'];
    public const DETECT_KINDS = ['package', 'product_code', 'uninstall_name'];
    public const SCOPES = ['global', 'client', 'site', 'group', 'tag', 'device'];
    public const RESULTS = ['would_install', 'would_uninstall', 'installed', 'uninstalled', 'already_installed', 'not_installed', 'failed', 'hash_mismatch', 'download_failed',
        'not_found', 'locked', 'needs_reboot', 'verify_failed'];
    /** Results that mean "what was asked for is now true". */
    public const OK_RESULTS = ['installed', 'uninstalled', 'already_installed', 'not_installed', 'needs_reboot'];
    public const DRY_RESULTS = ['would_install', 'would_uninstall'];
    public const FAILED_RESULTS = ['failed', 'hash_mismatch', 'download_failed', 'not_found', 'locked', 'verify_failed'];
    /** Status of one expected package on one device (the report). */
    public const STATES = ['compliant', 'scheduled', 'pending', 'failed', 'missing', 'present', 'unknown'];

    /** An installation that failed is tried again by the scheduler after this long, at most this many times. */
    public const RETRY_AFTER_S = 43200;
    public const MAX_ATTEMPTS = 3;

    public static function capabilityFor(string $jobType): ?string
    {
        return match ($jobType) {
            self::JOB_DETECT => self::CAP_DETECT,
            self::JOB_INSTALL => self::CAP_INSTALL,
            default => null,
        };
    }

    public static function isDeployJob(mixed $type): bool
    {
        return is_string($type) && in_array($type, self::JOB_TYPES, true);
    }

    /** Whether a format can run on that operating system. */
    public static function formatFits(string $fmt, string $os): bool
    {
        return match ($fmt) {
            'apt', 'dnf' => $os === 'linux',
            'msi', 'exe' => $os === 'windows',
            default => false,
        };
    }

    /**
     * Whether an argument string is acceptable: the pattern, at most {@see ARGS_MAX_TOKENS} tokens of at most {@see ARGS_MAX_TOKEN_CHARS} characters, separated by single spaces.
     */
    public static function argsOk(string $args): bool
    {
        if (preg_match(self::ARGS_RE, $args) !== 1) {
            return false;
        }
        if ($args === '') {
            return true;
        }
        $tokens = explode(' ', $args);
        if (count($tokens) > self::ARGS_MAX_TOKENS) {
            return false;
        }
        foreach ($tokens as $t) {
            if ($t === '' || strlen($t) > self::ARGS_MAX_TOKEN_CHARS) {
                return false;
            }
        }

        return true;
    }

    /** The visible name of an uninstall entry (Windows DisplayName): printable text, no control characters. */
    public static function displayNameOk(mixed $v): bool
    {
        return is_string($v) && $v !== '' && mb_strlen($v) <= self::NAME_MAX && preg_match('/^[^\x00-\x1f\x7f]+$/Du', $v) === 1 && mb_check_encoding($v, 'UTF-8');
    }

    /** Whether a detection triple is well formed for the operating system. */
    public static function detectOk(string $kind, mixed $value, mixed $min, string $os): bool
    {
        if (!in_array($kind, self::DETECT_KINDS, true) || !is_string($min) || ($min !== '' && preg_match(self::MIN_RE, $min) !== 1)) {
            return false;
        }

        return match ($kind) {
            'package' => $os === 'linux' && is_string($value) && preg_match(self::PACKAGE_RE, $value) === 1,
            'product_code' => $os === 'windows' && is_string($value) && preg_match(self::PRODUCT_CODE_RE, $value) === 1,
            default => $os === 'windows' && self::displayNameOk($value),
        };
    }
}
