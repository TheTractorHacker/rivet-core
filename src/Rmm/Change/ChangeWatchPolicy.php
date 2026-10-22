<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Change;

use RivetCore\Rmm\Contracts\ChangeWatchPolicyInterface;

/**
 * Core's default {@see ChangeWatchPolicyInterface}: what a custom watch (and a record a device sends) may point at.
 *
 * Registry: HKLM and HKU only, at least three segments deep (never a whole hive or its first level), no empty or `..` segment, never SAM, SECURITY, the boot configuration or
 * the LSA key parts that hold the boot key (`Lsa\JD`, `Skew1`, `GBG`, `Data`), in any spelling of the hive or of the control set. Files: an absolute path strictly below one of a
 * short list of configuration roots (Linux: `/etc`, the systemd unit folders, the cron spool, `.ssh/authorized_keys`; Windows: the drivers\etc and GroupPolicy folders), never
 * a path with `.` or `..`, a double separator or a control character, and never a shadow file, a private key, a certificate store, a keytab, a VPN or Wi-Fi secret, or a
 * history file. A subclass or the constructor argument can ADD file roots (a code decision, never data); nothing here can remove a denial.
 *
 * @api
 */
class ChangeWatchPolicy implements ChangeWatchPolicyInterface
{
    /** Linux roots a watch may sit below (the path must be strictly deeper than the root). */
    public const LINUX_ROOTS = ['/etc', '/usr/lib/systemd', '/usr/local/lib/systemd', '/lib/systemd', '/var/spool/cron', '/root/.ssh'];
    /** Windows roots, compared without regard to case; `X:` is any drive letter. */
    public const WINDOWS_ROOTS = ['\\Windows\\System32\\drivers\\etc', '\\Windows\\System32\\GroupPolicy', '\\Windows\\System32\\GroupPolicyUsers'];
    private const MAX_LEN = 300;

    /** @param list<string> $extraFileRoots absolute folders to allow in addition (a code decision of the edition or a test); each must itself be safe, see the kit */
    public function __construct(private readonly array $extraFileRoots = [])
    {
    }

    public function allows(string $kind, string $target, int $clientId): bool
    {
        // No control character, no invisible or format character (a zero-width space makes a lookalike), no padding: a name is judged exactly as it is, never cleaned first.
        if ($target === '' || strlen($target) > self::MAX_LEN || !mb_check_encoding($target, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]|[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $target) === 1
            || preg_match('/^[\s\p{Z}]|[\s\p{Z}]$/u', $target) === 1) {
            return false;
        }

        return match ($kind) {
            'registry' => self::registryAllowed($target),
            'file' => $this->fileAllowed($target),
            default => false,
        };
    }

    /** Hive spelling to the short form, or null. */
    private static function hive(string $s): ?string
    {
        return match (strtoupper($s)) {
            'HKLM', 'HKEY_LOCAL_MACHINE' => 'HKLM',
            'HKU', 'HKEY_USERS' => 'HKU',
            default => null,
        };
    }

    public static function registryAllowed(string $key): bool
    {
        if (str_contains($key, '/')) {
            return false;
        }
        $seg = explode('\\', $key);
        if (count($seg) < 3) {
            return false;   // a whole hive, or its first level
        }
        foreach ($seg as $s) {
            if ($s === '' || $s === '.' || $s === '..' || str_ends_with($s, '.') || self::padded($s)) {
                return false;
            }
        }
        $hive = self::hive($seg[0]);
        if ($hive === null) {
            return false;
        }
        $up = array_map('strtoupper', $seg);
        if ($hive === 'HKU') {
            // a loaded user hive by SID, the default profile, or every loaded user (`*`, expanded by the agent)
            if (!($up[1] === '*' || $up[1] === '.DEFAULT' || preg_match('/^S-1-[0-9-]{1,80}$/D', $up[1]) === 1)) {
                return false;
            }

            return !self::deniedUnderUsers($up);
        }
        if ($up[1] === 'SAM' || $up[1] === 'SECURITY' || str_starts_with($up[1], 'BCD0') || $up[1] === 'HARDWARE') {
            return false;
        }
        if ($up[1] !== 'SOFTWARE' && $up[1] !== 'SYSTEM') {
            return false;
        }
        if ($up[1] === 'SYSTEM') {
            // the control set is spelled CurrentControlSet or ControlSetNNN
            if (!(preg_match('/^(?:CURRENTCONTROLSET|CONTROLSET\d{3})$/D', $up[2]) === 1)) {
                return false;
            }
            if (($up[3] ?? '') === 'CONTROL' && ($up[4] ?? '') === 'LSA' && in_array($up[5] ?? '', ['JD', 'SKEW1', 'GBG', 'DATA'], true)) {
                return false;
            }
        }
        foreach (array_slice($up, 2) as $s) {
            if (in_array($s, ['SECRETS', 'SAM', 'SECURITY'], true)) {
                return false;   // a key named like a credential store anywhere below, e.g. a Policy\Secrets copy
            }
        }

        return true;
    }

    /** @param list<string> $up upper-cased segments */
    private static function deniedUnderUsers(array $up): bool
    {
        foreach ($up as $s) {
            if (in_array($s, ['SECRETS', 'PROTECTED STORAGE SYSTEM PROVIDER', 'CREDENTIALS'], true)) {
                return true;
            }
        }

        return false;
    }

    /** A segment with a space (of any kind) at either end. */
    private static function padded(string $s): bool
    {
        return preg_match('/^[\s\p{Z}]|[\s\p{Z}]$/u', $s) === 1;
    }

    private function fileAllowed(string $path): bool
    {
        if (preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            return self::windowsFileAllowed($path);
        }

        return $this->linuxFileAllowed($path);
    }

    private function linuxFileAllowed(string $path): bool
    {
        if ($path[0] !== '/' || str_contains($path, '\\') || str_ends_with($path, '/') || str_contains($path, '//')) {
            return false;
        }
        $seg = explode('/', substr($path, 1));
        foreach ($seg as $s) {
            if ($s === '' || $s === '.' || $s === '..' || str_ends_with($s, '.')) {
                return false;
            }
        }
        $ok = false;
        foreach ([...self::LINUX_ROOTS, ...$this->extraFileRoots] as $root) {
            if (str_starts_with($path, rtrim($root, '/') . '/') || ($path === $root && $root !== '/etc')) {
                $ok = true;   // a folder of its own may be watched as a whole; /etc itself may not
                break;
            }
        }
        if (!$ok && preg_match('#^/home/[A-Za-z0-9._-]{1,64}/\.ssh/authorized_keys2?$#D', $path) === 1) {
            $ok = true;
        }

        return $ok && !self::linuxDenied($path);
    }

    public static function linuxDenied(string $path): bool
    {
        $lower = strtolower($path);
        $base = strtolower(basename($path));
        if (preg_match('#^/etc/(?:shadow|gshadow)-?(?:/|$)#', $lower) === 1 || str_starts_with($lower, '/etc/security/opasswd')) {
            return true;
        }
        foreach (['/ssl/private', '/letsencrypt', '/networkmanager/system-connections', '/wireguard', '/openvpn', '/ppp/chap-secrets', '/ppp/pap-secrets', '/samba/private',
            '/stunnel', '/davfs2/secrets', '/apt/auth.conf', '/krb5.keytab', '/mysql/debian.cnf', '/ipsec.secrets', '/ipsec.d/private', '/etc/ssh/ssh_host_',
            '/etc/rancher/k3s/k3s.yaml', '/etc/kubernetes/', '/.kube/'] as $needle) {   // rc.31 (review F15): cluster credentials
            if (str_contains($lower, $needle) && !($needle === '/etc/ssh/ssh_host_' && str_ends_with($lower, '.pub'))) {
                return true;   // a host's public key is not a secret
            }
        }
        if (preg_match('#/private(?:/|$)#', $lower) === 1) {
            return true;
        }
        if (preg_match('/^(?:id_(?:rsa|dsa|ecdsa|ed25519)(?:_sk)?|\.netrc|\.pgpass|\.htpasswd|\.git-credentials|credentials|secrets?(?:\..*)?|tokens?|api_?keys?|kubeconfig|admin\.conf|[^\/]*\.token)$/', $base) === 1) {
            return true;
        }
        if (preg_match('/\.(?:key|pem|p12|pfx|jks|keystore|keytab|kdbx|gpg|asc|secret|secrets)$/', $base) === 1 && !str_ends_with($base, '.pub')) {
            return true;
        }
        if (str_ends_with($base, '_history') || str_contains($base, 'password') || str_contains($base, 'secret')) {
            return true;
        }
        // shadow, shadow-, shadow.bak, gshadow~; sasl_passwd, smbpasswd, passwd.client (only /etc/passwd itself is a passwd file); keys by name; Wi-Fi and SSSD secrets
        if (str_starts_with($base, 'shadow') || str_starts_with($base, 'gshadow') || str_starts_with($base, 'id_') || str_ends_with($base, '_key')
            || str_contains($base, 'wpa_supplicant') || str_contains($base, 'sssd.conf') || str_contains($base, 'krb5.conf.d')) {
            return true;
        }

        return str_contains($base, 'passwd') && $base !== 'passwd';
    }

    private static function windowsFileAllowed(string $path): bool
    {
        if (str_contains($path, '/')) {
            return false;
        }
        $rest = substr($path, 2);   // starts with a backslash
        $seg = explode('\\', substr($rest, 1));
        foreach ($seg as $s) {
            if ($s === '' || $s === '.' || $s === '..' || str_ends_with($s, '.') || self::padded($s) || str_contains($s, ':')) {
                return false;
            }
        }
        $lower = strtolower($rest);
        $ok = false;
        foreach (self::WINDOWS_ROOTS as $root) {
            if (str_starts_with($lower, strtolower($root) . '\\')) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return false;
        }
        foreach ($seg as $s) {
            if (in_array(strtolower($s), ['config', 'ntds', 'sam', 'security', 'system'], true)) {
                return false;   // the registry hive files live in ...\config; a folder of that name is never a configuration watch
            }
        }

        return preg_match('/\.(?:pfx|p12|key|pem|kdbx)$/', $lower) !== 1;
    }
}
