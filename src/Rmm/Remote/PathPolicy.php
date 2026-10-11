<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

/**
 * The server's half of the file-transfer path policy: a path is accepted only when it is absolute, free of traversal, under one of the
 * administrator's allowed folders (whole path elements, never a string prefix) and not on the deny list. The agent repeats every one of these
 * checks on its own (and resolves symbolic links, which only the device can do), so a hostile or buggy server cannot widen what a device
 * hands out. The deny list wins over the allow list.
 *
 * @api
 */
final class PathPolicy
{
    /** Refusal codes, stable: they appear in API answers and in the agent's `path_denied:<code>` detail. */
    public const NOT_ABSOLUTE = 'not_absolute';
    public const TRAVERSAL = 'traversal';
    public const NOT_ALLOWED = 'not_allowed';
    public const DENY_LIST = 'deny_list';
    public const NO_ALLOWED_ROOTS = 'no_allowed_roots';
    public const INVALID = 'invalid';

    private const LINUX_DENY_PREFIX = ['/proc', '/sys', '/dev', '/run/secrets', '/root/.ssh', '/etc/sudoers.d', '/etc/rivetit', '/etc/rivetit-agent', '/var/lib/rivetit', '/var/lib/rivetit-agent', '/etc/ssl/private'];
    private const LINUX_DENY_EXACT = ['/etc/shadow', '/etc/gshadow', '/etc/sudoers', '/etc/security/opasswd', '/etc/shadow-', '/etc/gshadow-'];
    private const DENY_NAME_RE = '/^(id_rsa.*|id_ed25519.*|id_ecdsa.*|id_dsa.*|.*\.(pem|key|p12|pfx|kdbx|keystore|jks)|ssh_host_.*|ntds\.dit|pagefile\.sys|hiberfil\.sys|swapfile\.sys)$/i';
    private const DENY_ELEMENT = ['.ssh', '.gnupg'];

    /**
     * @param list<string> $roots the allowed folders for this platform
     * @return string|null a refusal code, or null when the path is acceptable
     */
    public static function check(string $path, string $os, array $roots): ?string
    {
        $windows = $os === 'windows';
        if ($path === '' || strlen($path) > RemoteProtocol::TRANSFER_PATH_MAX || preg_match('/[\x00-\x1f\x7f]/', $path) === 1 || !mb_check_encoding($path, 'UTF-8')) {
            return self::INVALID;
        }
        $elements = self::elements($path, $windows);
        if ($elements === null) {
            return self::NOT_ABSOLUTE;
        }
        foreach ($elements as $i => $e) {
            if ($e === '..' || $e === '.' || $e === '') {
                return self::TRAVERSAL;
            }
            if ($windows && $i > 0 && (str_contains($e, ':') || preg_match('/[<>"|?*]/', $e) === 1 || preg_match('/[. ]$/', $e) === 1 || str_contains($e, '~'))) {
                return self::INVALID;   // alternate data streams, wildcards, trailing dots and 8.3 short names
            }
        }
        if ($roots === []) {
            return self::NO_ALLOWED_ROOTS;
        }
        $under = false;
        foreach ($roots as $root) {
            $r = self::elements($root, $windows);
            if ($r !== null && $r !== [] && self::isUnder($elements, $r, $windows)) {
                $under = true;
                break;
            }
        }
        if (!$under) {
            return self::NOT_ALLOWED;
        }

        return self::denied($elements, $windows) ? self::DENY_LIST : null;
    }

    /**
     * A folder the administrator may allow: absolute, no traversal, not the root of the file system itself.
     */
    public static function validRoot(string $root, string $os): bool
    {
        $windows = $os === 'windows';
        if ($root === '' || strlen($root) > 400 || preg_match('/[\x00-\x1f\x7f]/', $root) === 1 || !mb_check_encoding($root, 'UTF-8')) {
            return false;
        }
        $e = self::elements($root, $windows);
        if ($e === null || $e === []) {
            return false;
        }
        foreach ($e as $i => $x) {
            if ($x === '..' || $x === '.' || $x === '' || ($windows && $i > 0 && (str_contains($x, ':') || preg_match('/[<>"|?*]/', $x) === 1))) {
                return false;
            }
        }
        // At least two levels (/var/log, C:\Data): a whole drive, /etc, /root or /home is never a transfer root. The deny list still applies below any root.
        if (count($e) < 2 || self::denied($e, $windows)) {
            return false;
        }

        return true;
    }

    /**
     * True when a path element list is under (or equal to) a root element list.
     *
     * @param list<string> $path
     * @param list<string> $root
     */
    private static function isUnder(array $path, array $root, bool $ci): bool
    {
        if (count($path) < count($root)) {
            return false;
        }
        foreach ($root as $i => $e) {
            if ($ci ? strcasecmp($path[$i], $e) !== 0 : $path[$i] !== $e) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>|null the path elements, or null when the path is not absolute for the platform
     */
    private static function elements(string $path, bool $windows): ?array
    {
        if ($windows) {
            if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
                return null;   // a drive-letter path only; UNC (\\server\share) and device (\\.\ ) paths are not offered
            }
            $rest = substr($path, 3);
            $parts = preg_split('/[\\\\\/]+/', rtrim($rest, '\\/'));
            $parts = $parts === false ? [] : $parts;

            return $rest === '' ? [strtoupper($path[0]) . ':'] : array_merge([strtoupper($path[0]) . ':'], $parts);
        }
        if ($path[0] !== '/') {
            return null;
        }
        if (str_contains($path, '//')) {
            return [''];   // an empty element is refused as traversal by the caller
        }
        $rest = trim($path, '/');

        return $rest === '' ? [] : explode('/', $rest);
    }

    /** @param list<string> $e */
    private static function denied(array $e, bool $windows): bool
    {
        $name = $e === [] ? '' : $e[count($e) - 1];
        if (preg_match(self::DENY_NAME_RE, $name) === 1) {
            return true;
        }
        foreach ($e as $part) {
            if (in_array(strtolower($part), self::DENY_ELEMENT, true)) {
                return true;
            }
        }
        if ($windows) {
            // <drive>:\Windows\System32\config, \Windows\NTDS, \ProgramData\RivetIT and the agent's own folder
            $lower = array_map('strtolower', array_slice($e, 1));
            foreach ([['windows', 'system32', 'config'], ['windows', 'ntds'], ['windows', 'repair'], ['programdata', 'rivetit'], ['programdata', 'rivetit-agent']] as $deny) {
                if (self::isUnder($lower, $deny, false)) {
                    return true;
                }
            }

            return false;
        }
        $joined = '/' . implode('/', $e);
        if (in_array($joined, self::LINUX_DENY_EXACT, true)) {
            return true;
        }
        foreach (self::LINUX_DENY_PREFIX as $p) {
            if ($joined === $p || str_starts_with($joined, $p . '/')) {
                return true;
            }
        }
        if (str_starts_with($joined, '/etc/ssh/ssh_host_')) {
            return true;
        }

        return false;
    }

    /** A plain-language sentence for a refusal code. */
    public static function explain(string $code): string
    {
        return match ($code) {
            self::NOT_ABSOLUTE => 'Give the full path of the file, starting at the root of the disk (for example /var/log/syslog or C:\\Logs\\app.log).',
            self::TRAVERSAL => 'The path must not contain .. or empty parts.',
            self::NOT_ALLOWED => 'That file is not inside a folder an administrator allowed for file transfer.',
            self::DENY_LIST => 'That file is on the never-transfer list (password databases, private keys, system credential stores and the agent\'s own files).',
            self::NO_ALLOWED_ROOTS => 'No folder is allowed for file transfer yet. An administrator must add at least one folder first.',
            default => 'That path is not valid.',
        };
    }
}
