<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

/**
 * The wire and storage constants of the Phase 6 (remote tools) and Phase 8 (log collection) additions. They live apart from
 * {@see \RivetCore\Rmm\RmmProtocol} on purpose: that class is pinned by the frozen-constants test and the generated section of
 * docs/rmm/PROTOCOL.md; nothing here changes an existing value. Installed agents depend on every value below once released.
 *
 * @api
 */
final class RemoteProtocol
{
    // ---- job types (registry names, at most 20 characters)
    public const JOB_SERVICE_LIST = 'svc_list';
    public const JOB_SERVICE_CONTROL = 'svc_control';
    public const JOB_PROCESS_LIST = 'proc_list';
    public const JOB_PROCESS_KILL = 'proc_kill';
    public const JOB_FILE_GET = 'file_get';
    public const JOB_FILE_PUT = 'file_put';
    public const JOB_TYPES = [self::JOB_SERVICE_LIST, self::JOB_SERVICE_CONTROL, self::JOB_PROCESS_LIST, self::JOB_PROCESS_KILL, self::JOB_FILE_GET, self::JOB_FILE_PUT];

    /**
     * The pushed MeshCentral agent install and removal (1.0.0-rc.16). They are NOT part of the frozen {@see JOB_TYPES} list, so nothing that list pins moves; {@see isToolJob()}
     * is the question "is this one of the jobs only the remote-tools endpoints may queue and only `rmm.remote.manage` may read".
     */
    public const JOB_MESH_INSTALL = 'mesh_install';
    public const JOB_MESH_UNINSTALL = 'mesh_uninstall';
    public const MESH_JOB_TYPES = [self::JOB_MESH_INSTALL, self::JOB_MESH_UNINSTALL];
    /** The capability an agent announces when it can run each Mesh job (the ordinary `job:<type>` flag, which a Mesh job requires: an agent that announced nothing is never assumed to have it). */
    public const CAP_MESH_INSTALL = 'job:mesh_install';
    public const CAP_MESH_UNINSTALL = 'job:mesh_uninstall';

    // ---- capability flags an agent announces (same list as `job:<type>`)
    public const CAP_SERVICES = 'remote:services';
    public const CAP_PROCESSES = 'remote:processes';
    public const CAP_FILES = 'remote:files';
    public const CAP_LOGS = 'logs:collect';

    // ---- sub-switches (RmmSettings::FEATURES)
    public const FEATURE_REMOTE_TOOLS = 'remote_tools';
    public const FEATURE_FILE_TRANSFER = 'filetransfer';
    public const FEATURE_LOGS = 'logs';

    // ---- service and process manager
    public const SERVICE_ACTIONS = ['start', 'stop', 'restart', 'enable', 'disable', 'manual'];
    /** Actions that interrupt something a user may be relying on: a technician must confirm them and the job is offered at most once. */
    public const SERVICE_DESTRUCTIVE = ['stop', 'restart', 'disable'];
    public const SERVICE_NAME_RE = '/^[A-Za-z0-9_][A-Za-z0-9_.@$:-]{0,199}$/';
    public const PROCESS_NAME_RE = '/^[A-Za-z0-9 _.()+@#~\[\]-]{1,128}$/';
    public const PID_MIN = 2;
    public const PID_MAX = 4194304;
    public const PROCESS_LIST_MAX = 500;

    // ---- file transfer
    public const TRANSFER_CHUNK_MAX = 1048576;
    public const TRANSFER_PATH_MAX = 1000;
    public const TRANSFER_ALLOW_MAX = 900;
    public const TRANSFER_TOKEN_RE = '/^[0-9a-f]{64}$/';
    public const TRANSFER_RATE = [600, 60];
    public const TRANSFER_STATES = ['awaiting_content', 'queued', 'receiving', 'delivering', 'complete', 'failed', 'expired', 'deleted'];
    /** The largest file an agent will receive or send whatever the server allows (also the upper bound of the admin setting). */
    public const TRANSFER_HARD_MAX_BYTES = 536870912;

    // ---- logs
    public const LOG_BATCH_MAX = 200;
    public const LOG_MESSAGE_MAX = 1024;
    public const LOG_BODY_MAX = 262144;
    public const LOG_RATE = [30, 60];
    /** Severity names, worst first; stored as 1 to 5. */
    public const LOG_LEVELS = ['critical', 'error', 'warning', 'info', 'debug'];
    public const LOG_SOURCE_MAX = 100;
    public const LOG_CHECK_PREFIX = 'log.rule_';

    /** Wire names of the extra error codes. */
    public const ERROR_CODES = ['feature_disabled', 'not_configured', 'confirmation_required', 'too_large', 'conflict'];

    /** The capability a job type needs on the device (null: none beyond `job:<type>`). */
    public static function capabilityFor(string $jobType): ?string
    {
        return match ($jobType) {
            self::JOB_SERVICE_LIST, self::JOB_SERVICE_CONTROL => self::CAP_SERVICES,
            self::JOB_PROCESS_LIST, self::JOB_PROCESS_KILL => self::CAP_PROCESSES,
            self::JOB_FILE_GET, self::JOB_FILE_PUT => self::CAP_FILES,
            self::JOB_MESH_INSTALL => self::CAP_MESH_INSTALL,
            self::JOB_MESH_UNINSTALL => self::CAP_MESH_UNINSTALL,
            default => null,
        };
    }

    /** True for the remote tool jobs and the Mesh agent jobs: queued only through their own endpoints, output readable only with `rmm.remote.manage`. */
    public static function isToolJob(mixed $type): bool
    {
        return is_string($type) && (in_array($type, self::JOB_TYPES, true) || in_array($type, self::MESH_JOB_TYPES, true));
    }

    public static function levelNumber(string $name): ?int
    {
        $i = array_search($name, self::LOG_LEVELS, true);

        return $i === false ? null : $i + 1;
    }

    public static function levelName(int $n): string
    {
        return self::LOG_LEVELS[$n - 1] ?? 'info';
    }

    /**
     * A service name the manager may act on, normalised. Linux: a systemd unit, `.service` appended when it has no suffix, any other suffix refused.
     * Windows: the service short name. Never an option (no leading dash or dot), never a space, quote, slash or control character.
     */
    public static function serviceName(mixed $v, string $os): ?string
    {
        if (!is_string($v) || preg_match(self::SERVICE_NAME_RE, $v) !== 1) {
            return null;
        }
        if ($os === 'linux') {
            if (str_ends_with($v, '.service')) {
                return strlen($v) > 8 ? $v : null;
            }

            return str_contains($v, '.') && preg_match('/\.(socket|timer|target|path|mount|slice|scope|swap|device|automount)$/', $v) === 1 ? null : $v . '.service';
        }

        return $v;
    }

    public static function processName(mixed $v): ?string
    {
        if (!is_string($v) || preg_match(self::PROCESS_NAME_RE, $v) !== 1 || trim($v) === '' || trim($v) !== $v) {
            return null;
        }

        return $v;
    }

    public static function pid(mixed $v): ?int
    {
        if (is_string($v) && preg_match('/^[1-9][0-9]{0,7}$/', $v) === 1) {
            $v = (int) $v;
        }
        if (!is_int($v) || $v < self::PID_MIN || $v > self::PID_MAX) {
            return null;
        }

        return $v;
    }
}
