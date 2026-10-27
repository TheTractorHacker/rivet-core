<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Rmm\Change\ChangeWatchPolicy;
use RivetCore\Rmm\Contracts\ChangeWatchPolicyInterface;

/**
 * Core's real policy with ONE deliberate flaw each (the harness target of the watch policy mutants). Null = the correct policy. Every flaw is a mistake a hand-written
 * "is this safe to read" check really makes: one spelling of a hive forgotten, a case-sensitive comparison, a prefix test that a `..` walks around, input cleaned and accepted.
 */
final class FlawedChangeWatchPolicy implements ChangeWatchPolicyInterface
{
    private int $calls = 0;
    private readonly ChangeWatchPolicy $real;

    public function __construct(private readonly ?string $flaw = null)
    {
        $this->real = new ChangeWatchPolicy();
    }

    public function allows(string $kind, string $target, int $clientId): bool
    {
        ++$this->calls;
        $real = fn (string $k, string $t): bool => $this->real->allows($k, $t, $clientId);
        $isReg = $kind === 'registry';
        switch ($this->flaw) {
            case 'case_sensitive_hives':   // only the exact upper-case spelling of SAM / SECURITY is recognised
                if ($isReg && preg_match('/^(?:HKLM|HKEY_LOCAL_MACHINE)\\\\(?:SAM|SECURITY)(?:\\\\|$)/', $target) === 1) {
                    return false;
                }

                return $isReg && preg_match('/^(?:HKLM|HKEY_LOCAL_MACHINE)\\\\(?:sam|security)(?:\\\\|$)/i', $target) === 1 ? str_contains($target, '\\') && count(explode('\\', $target)) >= 3 : $real($kind, $target);
            case 'long_hive_name':   // HKEY_LOCAL_MACHINE\SAM is not recognised as the SAM hive
                return $isReg && preg_match('/^HKEY_LOCAL_MACHINE\\\\(?:SAM|SECURITY)(?:\\\\|$)/i', $target) === 1 ? count(explode('\\', $target)) >= 3 : $real($kind, $target);
            case 'whole_hive':   // a hive or its first level is fine
                return $isReg && preg_match('/^(?:HKLM|HKEY_LOCAL_MACHINE|HKU|HKEY_USERS)(?:\\\\(?:SOFTWARE|SYSTEM|\*|S-1-5-18)?)?$/', $target) === 1 || $real($kind, $target);
            case 'allows_hkcu':
                return $isReg && preg_match('/^(?:HKCU|HKEY_CURRENT_USER|HKCR|HKEY_CLASSES_ROOT|HKCC)\\\\[^\\\\\x00-\x1f]+\\\\[^\\\\\x00-\x1f]+/', $target) === 1 || $real($kind, $target);
            case 'collapses_backslashes':   // HKLM\\SOFTWARE\\x is read as HKLM\SOFTWARE\x
                return $real($kind, $isReg ? (string) preg_replace('/\\\\+/', '\\', $target) : $target);
            case 'trims_input':
                return $real($kind, trim($target));
            case 'lsa_boot_key':   // the four LSA key parts that hold the boot key are fine
                return $isReg && preg_match('/\\\\Lsa\\\\(?:JD|Skew1|GBG|Data)(?:\\\\|$)/i', $target) === 1 ? count(explode('\\', $target)) >= 3 && !preg_match('/\\\\(?:SAM|SECURITY)\\\\/i', $target) : $real($kind, $target);
            case 'only_current_control_set':   // ControlSet001 / ControlSet002 are not recognised
                return $isReg && preg_match('/\\\\ControlSet\d{3}\\\\Control\\\\Lsa\\\\(?:JD|Skew1|GBG|Data)(?:\\\\|$)/i', $target) === 1 ? true : $real($kind, $target);
            case 'allows_bcd':
                return $isReg && preg_match('/^HKLM\\\\BCD00000000(?:\\\\|$)/i', $target) === 1 ? count(explode('\\', $target)) >= 3 : $real($kind, $target);
            case 'security_substring_only_at_top':   // a key named SECURITY\Secrets deeper in the tree is fine
                return $isReg && preg_match('/^HKLM\\\\SOFTWARE\\\\.+\\\\(?:SECURITY|SAM|Secrets)(?:\\\\|$)/i', $target) === 1 ? true : $real($kind, $target);
            case 'allows_shadow':
                return $kind === 'file' && preg_match('#^/etc/g?shadow-?$|^/etc/security/opasswd$#', $target) === 1 ? true : $real($kind, $target);
            case 'allows_host_keys':
                return $kind === 'file' && preg_match('#^/etc/ssh/ssh_host_[a-z0-9]+_key$#', $target) === 1 ? true : $real($kind, $target);
            case 'allows_private_dirs':
                return $kind === 'file' && preg_match('#^/etc/(?:ssl|pki/tls)/private/?$#', $target) === 1 ? true : $real($kind, $target);
            case 'allows_user_keys':
                return $kind === 'file' && preg_match('#^/(?:root|home/[^/]+)/\.ssh/id_[a-z0-9]+$#', $target) === 1 ? true : $real($kind, $target);
            case 'allows_vpn_and_wifi':
                return $kind === 'file' && preg_match('#^/etc/(?:wireguard|openvpn|NetworkManager/system-connections)/#', $target) === 1 ? true : $real($kind, $target);
            case 'allows_secret_names':   // only the folder is checked, never the file's own name
                return $kind === 'file' && preg_match('#^/etc/(?:nginx|apt|letsencrypt/live/[^/]+|ppp|mysql|samba/private|apt/auth.conf.d|pki/tls/private)/[^/]*(?:\.key|\.pem|auth\.conf|chap-secrets|debian\.cnf|passdb\.tdb|\.htpasswd|private\.conf)$#', $target) === 1 || $kind === 'file' && in_array($target, ['/etc/krb5.keytab', '/etc/ipsec.secrets', '/root/.netrc'], true) ? true : $real($kind, $target);
            case 'prefix_check_only':   // "starts with /etc/" is the whole test, so a .. walks out of it
                return $kind === 'file' && str_starts_with($target, '/etc/') && !str_contains($target, "\0") && preg_match('#^/etc/(?:g?shadow|ssh/ssh_host)#', $target) !== 1 ? true : $real($kind, $target);
            case 'relative_ok':
                return $kind === 'file' && preg_match('#^(?:\./)?etc/[a-z]+(?:/[a-z_.]+)?$#', $target) === 1 ? true : $real($kind, $target);
            case 'allows_system_folders':   // /etc, /root, /home and friends as a whole
                return $kind === 'file' && in_array($target, ['/etc', '/root', '/home', '/home/bob', '/'], true) ? true : $real($kind, $target);
            case 'trailing_slash_ok':
                return $kind === 'file' && str_ends_with($target, '/') && strlen($target) > 1 ? $real($kind, rtrim($target, '/')) : $real($kind, $target);
            case 'double_slash_ok':
                return $real($kind, $kind === 'file' ? (string) preg_replace('#/+#', '/', $target) : $target);
            case 'proc_and_logs':
                return $kind === 'file' && preg_match('#^/(?:proc/[^/]+/(?:environ|mem)|var/log/auth\.log|dev/mem|tmp/x|home/bob/\.bash_history|root/\.bash_history)$#', $target) === 1 ? true : $real($kind, $target);
            case 'allows_windows_hives':
                return $kind === 'file' && preg_match('/^[A-Za-z]:\\\\Windows\\\\(?:System32\\\\config|NTDS|repair)\\\\(?:RegBack\\\\)?[A-Za-z.]+$/i', $target) === 1 || $kind === 'file' && preg_match('/^C:\\\\Users\\\\bob\\\\(?:NTUSER\.DAT|key\.pfx)$/i', $target) === 1 ? true : $real($kind, $target);
            case 'windows_traversal':
                return $kind === 'file' && str_contains($target, '\\drivers\\etc\\') && str_contains($target, '..') && !str_contains($target, "\0") ? true : $real($kind, $target);
            case 'forward_slashes_windows':
                return $kind === 'file' && preg_match('#^C:/Windows/System32/config/#i', $target) === 1 ? true : $real($kind, $target);
            case 'drive_root_ok':
                return $kind === 'file' && in_array($target, ['C:\\', 'C:\\Windows', '\\\\server\\share\\x', '\\\\?\\C:\\Windows\\System32\\config\\SAM'], true) ? true : $real($kind, $target);
            case 'strips_controls':   // the control characters are removed and the rest is judged
                return $real($kind, (string) preg_replace('/[\x00-\x1f\x7f]/', '', $target));
            case 'strips_nul_only':
                return $real($kind, str_replace("\0", '', $target));
            case 'unknown_kind_is_file':
                return $kind !== 'registry' ? $real('file', $target) : $real($kind, $target);
            case 'kind_case_insensitive':
                return $real(strtolower(trim($kind)), $target);
            case 'kinds_interchangeable':   // a registry key is accepted as a file and the reverse
                return $real($kind, $target) || $real($kind === 'registry' ? 'file' : 'registry', $target);
            case 'throws_on_garbage':
                if ($target === '' || strlen($target) > 2000 || !mb_check_encoding($target, 'UTF-8')) {
                    throw new \InvalidArgumentException('cannot parse');
                }

                return $real($kind, $target);
            case 'allows_fullwidth':
                $folded = function_exists('mb_convert_kana') ? mb_convert_kana($target, 'as', 'UTF-8') : $target;

                return $folded !== $target && mb_check_encoding($target, 'UTF-8') ? $real($kind, $folded) : $real($kind, $target);
            case 'allows_zero_width':
                return $real($kind, str_replace("\u{200b}", '', $target));
            case 'flips':
                return $this->calls % 3 === 0 ? !$real($kind, $target) : $real($kind, $target);
            case 'client_elevates':
                return $clientId > 1000 || $clientId < 0 ? true : $real($kind, $target);
            case 'returns_int':
                return $real($kind, $target) ? 1 : 0;   // @phpstan-ignore-line deliberately not a bool
        }

        return $real($kind, $target);
    }
}
