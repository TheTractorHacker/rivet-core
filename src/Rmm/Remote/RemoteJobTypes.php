<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Job\JobType;
use RivetCore\Rmm\Job\JobTypeRegistry;

/**
 * The Phase 6 job types and their STRICT parameter allow-lists. A validator keeps only the keys it knows, in a fixed order, with fixed types:
 * an unknown key, a value of the wrong type, a name outside its character set or a number outside its range refuses the whole job. Nothing here
 * ever builds a command string; the agent turns these values into an argv list and re-validates all of them itself.
 *
 * @api
 */
final class RemoteJobTypes
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public static function register(JobTypeRegistry $r): void
    {
        $manage = RmmAbility::REMOTE_MANAGE;
        $both = ['windows', 'linux'];
        $r->register(new JobType(RemoteProtocol::JOB_SERVICE_LIST, $manage, false, $both, false, self::serviceList(...), 60, true, RemoteProtocol::CAP_SERVICES));
        $r->register(new JobType(RemoteProtocol::JOB_SERVICE_CONTROL, $manage, false, $both, false, self::serviceControl(...), 90, true, RemoteProtocol::CAP_SERVICES));
        $r->register(new JobType(RemoteProtocol::JOB_PROCESS_LIST, $manage, false, $both, false, self::processList(...), 60, true, RemoteProtocol::CAP_PROCESSES));
        $r->register(new JobType(RemoteProtocol::JOB_PROCESS_KILL, $manage, true, $both, false, self::processKill(...), 60, true, RemoteProtocol::CAP_PROCESSES));
        $r->register(new JobType(RemoteProtocol::JOB_FILE_GET, $manage, false, $both, false, self::fileGet(...), 900, true, RemoteProtocol::CAP_FILES));
        $r->register(new JobType(RemoteProtocol::JOB_FILE_PUT, RmmAbility::ADMIN, true, $both, false, self::filePut(...), 900, true, RemoteProtocol::CAP_FILES));
    }

    /**
     * @param array<string,mixed> $p
     * @return array{0:array<string,mixed>,1:?string}
     */
    public static function serviceList(array $p): array
    {
        return $p === [] ? [[], null] : [$p, 'Listing services takes no parameters.'];
    }

    /**
     * @param array<string,mixed> $p
     * @return array{0:array<string,mixed>,1:?string}
     */
    public static function serviceControl(array $p): array
    {
        if (array_diff(array_keys($p), ['name', 'action']) !== []) {
            return [$p, 'Only a service name and an action are accepted.'];
        }
        $name = $p['name'] ?? null;
        $action = $p['action'] ?? null;
        if (!is_string($name) || preg_match(RemoteProtocol::SERVICE_NAME_RE, $name) !== 1) {
            return [$p, 'That is not a valid service name (letters, digits and _ . @ $ : - only, no spaces, not starting with a symbol).'];
        }
        if (!is_string($action) || !in_array($action, RemoteProtocol::SERVICE_ACTIONS, true)) {
            return [$p, 'The action must be one of: ' . implode(', ', RemoteProtocol::SERVICE_ACTIONS) . '.'];
        }

        return [['name' => $name, 'action' => $action], null];
    }

    /**
     * @param array<string,mixed> $p
     * @return array{0:array<string,mixed>,1:?string}
     */
    public static function processList(array $p): array
    {
        if (array_diff(array_keys($p), ['limit', 'sort']) !== []) {
            return [$p, 'Only limit and sort are accepted.'];
        }
        $limit = $p['limit'] ?? 200;
        $sort = $p['sort'] ?? 'cpu';
        if (!is_int($limit) || $limit < 1 || $limit > RemoteProtocol::PROCESS_LIST_MAX) {
            return [$p, 'limit must be 1 to ' . RemoteProtocol::PROCESS_LIST_MAX . '.'];
        }
        if (!is_string($sort) || !in_array($sort, ['cpu', 'mem'], true)) {
            return [$p, 'sort must be cpu or mem.'];
        }

        return [['limit' => $limit, 'sort' => $sort], null];
    }

    /**
     * @param array<string,mixed> $p
     * @return array{0:array<string,mixed>,1:?string}
     */
    public static function processKill(array $p): array
    {
        if (array_diff(array_keys($p), ['pid', 'name', 'force']) !== []) {
            return [$p, 'Only pid, name and force are accepted.'];
        }
        $pid = $p['pid'] ?? null;
        if (!is_int($pid) || RemoteProtocol::pid($pid) === null) {
            return [$p, 'pid must be a whole number from ' . RemoteProtocol::PID_MIN . ' to ' . RemoteProtocol::PID_MAX . '.'];
        }
        if (RemoteProtocol::processName($p['name'] ?? null) === null) {
            return [$p, 'The process name (as listed) is required and may only contain letters, digits, spaces and . _ ( ) + @ # ~ [ ] -.'];
        }
        $force = $p['force'] ?? false;
        if (!is_bool($force)) {
            return [$p, 'force must be true or false.'];
        }

        return [['pid' => $pid, 'name' => $p['name'], 'force' => $force], null];
    }

    /**
     * @param array<string,mixed> $p
     * @return array{0:array<string,mixed>,1:?string}
     */
    public static function fileGet(array $p): array
    {
        if (array_diff(array_keys($p), ['transfer_id', 'token', 'path', 'max_bytes', 'allow', 'chunk']) !== []) {
            return [$p, 'Unexpected file download parameter.'];
        }
        if (($e = self::common($p)) !== null) {
            return [$p, $e];
        }
        $max = $p['max_bytes'] ?? null;
        if (!is_int($max) || $max < 1 || $max > RemoteProtocol::TRANSFER_HARD_MAX_BYTES) {
            return [$p, 'max_bytes is out of range.'];
        }
        $chunk = $p['chunk'] ?? RemoteProtocol::TRANSFER_CHUNK_MAX;
        if (!is_int($chunk) || $chunk < 1024 || $chunk > RemoteProtocol::TRANSFER_CHUNK_MAX) {
            return [$p, 'chunk is out of range.'];
        }

        return [['transfer_id' => $p['transfer_id'], 'token' => $p['token'], 'path' => $p['path'], 'max_bytes' => $max, 'allow' => $p['allow'], 'chunk' => $chunk], null];
    }

    /**
     * @param array<string,mixed> $p
     * @return array{0:array<string,mixed>,1:?string}
     */
    public static function filePut(array $p): array
    {
        if (array_diff(array_keys($p), ['transfer_id', 'token', 'path', 'size', 'sha256', 'overwrite', 'allow', 'mode']) !== []) {
            return [$p, 'Unexpected file upload parameter.'];
        }
        if (($e = self::common($p)) !== null) {
            return [$p, $e];
        }
        $size = $p['size'] ?? null;
        if (!is_int($size) || $size < 0 || $size > RemoteProtocol::TRANSFER_HARD_MAX_BYTES) {
            return [$p, 'size is out of range.'];
        }
        if (!is_string($p['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $p['sha256']) !== 1) {
            return [$p, 'sha256 must be 64 lower-case hex characters.'];
        }
        $over = $p['overwrite'] ?? false;
        if (!is_bool($over)) {
            return [$p, 'overwrite must be true or false.'];
        }
        $mode = $p['mode'] ?? '0640';
        if (!is_string($mode) || preg_match('/^0?[0-7]{3}$/', $mode) !== 1) {
            return [$p, 'mode must be an octal file mode such as 0640.'];
        }

        return [['transfer_id' => $p['transfer_id'], 'token' => $p['token'], 'path' => $p['path'], 'size' => $size, 'sha256' => $p['sha256'], 'overwrite' => $over,
            'allow' => $p['allow'], 'mode' => strlen($mode) === 3 ? '0' . $mode : $mode], null];
    }

    /** @param array<string,mixed> $p */
    private static function common(array $p): ?string
    {
        if (!is_string($p['transfer_id'] ?? null) || preg_match(self::UUID_RE, $p['transfer_id']) !== 1) {
            return 'transfer_id must be a UUID.';
        }
        if (!is_string($p['token'] ?? null) || preg_match(RemoteProtocol::TRANSFER_TOKEN_RE, $p['token']) !== 1) {
            return 'token must be 64 lower-case hex characters.';
        }
        $path = $p['path'] ?? null;
        if (!is_string($path) || $path === '' || strlen($path) > RemoteProtocol::TRANSFER_PATH_MAX || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            return 'path is missing or not valid.';
        }
        $allow = $p['allow'] ?? null;
        if (!is_string($allow) || strlen($allow) > RemoteProtocol::TRANSFER_PATH_MAX || preg_match('/[\x00-\x09\x0b-\x1f\x7f]/', $allow) === 1) {
            return 'allow is not valid.';
        }

        return null;
    }
}
