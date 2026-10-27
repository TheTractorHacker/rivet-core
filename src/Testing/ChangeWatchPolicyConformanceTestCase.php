<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\ChangeWatchPolicyInterface;

/**
 * Conformance kit for {@see ChangeWatchPolicyInterface}, the rule that decides what a device may be told to read (a registry key, a file or a folder) and what change records it may
 * send. This is a PRIVACY boundary: a policy that is too generous turns "watch this key" into "send me the password hashes". What every policy must do: allow the targets the edition
 * configured; answer a bool and never throw, whatever the text; answer the same to the same question; refuse the credential hives (SAM, SECURITY and the LSA key parts that hold the
 * boot key) in every spelling and under every control set; refuse a whole hive and its first level; refuse the hives it does not support; refuse a key that is malformed (empty
 * segments, `..`, slashes, padding); refuse private keys, shadow files, certificate stores, VPN and Wi-Fi secrets; refuse relative or traversing file paths and a whole system
 * folder; refuse Windows registry hive files; refuse a control character or NUL anywhere (never "clean it and go on"); refuse an unknown kind; and not change any of this because of
 * the client id it is given.
 *
 * Implement {@see policy()}, {@see allowedRegistryKey()} and {@see allowedFilePath()}: a registry key of a Run-style location and an absolute file path that the policy allows for client 7.
 *
 * @api
 */
abstract class ChangeWatchPolicyConformanceTestCase extends TestCase
{
    abstract protected function policy(): ChangeWatchPolicyInterface;

    /** A registry key the policy allows, with at least three segments, such as `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run`. */
    abstract protected function allowedRegistryKey(): string;

    /** An absolute file path the policy allows, such as `/etc/ssh/sshd_config`. */
    abstract protected function allowedFilePath(): string;

    private function allows(string $kind, string $target, int $client = 7): bool
    {
        // The interface says bool, so PHP itself refuses any other answer (a TypeError fails the test that asked).
        return $this->policy()->allows($kind, $target, $client);
    }

    /** @param list<string> $targets */
    private function assertAllRefused(string $kind, array $targets, string $why): void
    {
        foreach ($targets as $t) {
            $this->assertFalse($this->allows($kind, $t), "$why ($kind): " . json_encode($t, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }

    public function testTheConfiguredTargetsAreAllowed(): void
    {
        $this->assertTrue($this->allows('registry', $this->allowedRegistryKey()));
        $this->assertTrue($this->allows('registry', $this->allowedRegistryKey() . '\\Sub'), 'a key below an allowed key');
        $this->assertTrue($this->allows('file', $this->allowedFilePath()));
    }

    public function testTheSameQuestionGetsTheSameAnswer(): void
    {
        foreach ([['registry', $this->allowedRegistryKey()], ['file', $this->allowedFilePath()], ['registry', 'HKLM\\SAM\\SAM'], ['file', '/etc/shadow']] as [$k, $t]) {
            $answers = [];
            for ($i = 0; $i < 4; ++$i) {
                $answers[] = $this->allows($k, $t);
            }
            $this->assertCount(1, array_unique($answers, SORT_REGULAR), "asked four times, answered differently for $k $t");
        }
    }

    public function testCredentialHivesAreRefusedInEverySpellingAndUnderEveryControlSet(): void
    {
        $this->assertAllRefused('registry', [
            'HKLM\\SAM', 'HKLM\\SAM\\SAM', 'HKLM\\SAM\\SAM\\Domains\\Account\\Users', 'hklm\\sam\\sam', 'HKLM\\Sam\\Sam\\Domains', 'HKEY_LOCAL_MACHINE\\SAM\\SAM', 'hkey_local_machine\\sam\\sam\\domains',
            'HKLM\\SECURITY', 'HKLM\\SECURITY\\Policy\\Secrets', 'HKLM\\security\\policy\\secrets\\x', 'HKEY_LOCAL_MACHINE\\SECURITY\\Cache', 'HKEY_LOCAL_MACHINE\\Security\\SAM\\Domains',
            'HKLM\\SYSTEM\\CurrentControlSet\\Control\\Lsa\\JD', 'HKLM\\SYSTEM\\CurrentControlSet\\Control\\Lsa\\Skew1', 'HKLM\\SYSTEM\\CurrentControlSet\\Control\\Lsa\\GBG', 'HKLM\\SYSTEM\\CurrentControlSet\\Control\\Lsa\\Data',
            'HKLM\\system\\currentcontrolset\\control\\lsa\\jd', 'HKLM\\SYSTEM\\ControlSet001\\Control\\Lsa\\JD', 'HKLM\\SYSTEM\\ControlSet002\\Control\\Lsa\\Skew1', 'HKEY_LOCAL_MACHINE\\SYSTEM\\ControlSet001\\Control\\Lsa\\GBG',
            'HKLM\\SYSTEM\\CurrentControlSet\\Control\\Lsa\\Data\\x', 'HKLM\\BCD00000000\\Objects', 'HKLM\\SOFTWARE\\Acme\\SECURITY\\Secrets',
        ], 'a credential store');
    }

    public function testAWholeHiveOrItsFirstLevelIsRefused(): void
    {
        $this->assertAllRefused('registry', ['HKLM', 'HKLM\\', 'HKEY_LOCAL_MACHINE', 'HKLM\\SOFTWARE', 'HKLM\\SYSTEM', 'HKU', 'HKU\\S-1-5-18', 'HKEY_USERS', 'HKU\\*', 'HKLM\\SOFTWARE\\'], 'a whole hive or its first level');
    }

    public function testUnsupportedHivesAndMalformedKeysAreRefused(): void
    {
        $k = $this->allowedRegistryKey();
        $this->assertAllRefused('registry', [
            'HKCU\\Software\\Acme\\Agent', 'HKEY_CURRENT_USER\\Software\\Acme\\Agent', 'HKCR\\Acme\\Agent\\Shell', 'HKCC\\Software\\Acme\\Agent', 'HKEY_CLASSES_ROOT\\Acme\\Shell\\Open',
            '\\Registry\\Machine\\SAM', '\\Registry\\Machine\\SOFTWARE\\Acme\\Agent', '\\\\?\\HKLM\\SOFTWARE\\Acme\\Agent', 'Computer\\HKEY_LOCAL_MACHINE\\SOFTWARE\\Acme\\Agent', 'SOFTWARE\\Acme\\Agent\\Run',
            str_replace('\\', '\\\\', $k), $k . '\\', '\\' . $k, ' ' . $k, $k . ' ', str_replace('\\', '/', $k), 'HKLM\\SOFTWARE\\Acme\\..\\..\\SAM', 'HKLM\\SOFTWARE\\Acme\\..\\Agent', 'HKLM\\SOFTWARE\\.\\Acme\\Agent', 'HKLM\\SOFTWARE\\Acme.\\Agent', 'HKLM\\SOFTWARE\\' . str_repeat('A', 300) . '\\Agent',
            'HKLM\\SOFTWARE\\Acme\\ \\Agent', 'HKU\\notasid\\Software\\Acme', 'HKU\\..\\Software\\Acme\\X',
        ], 'a hive that is not supported, or a malformed key');
    }

    public function testCredentialFilesAndKeysAreRefused(): void
    {
        $this->assertAllRefused('file', [
            '/etc/shadow', '/etc/shadow-', '/etc/gshadow', '/etc/gshadow-', '/etc/security/opasswd', '/etc/ssh/ssh_host_rsa_key', '/etc/ssh/ssh_host_ed25519_key', '/etc/ssh/ssh_host_ecdsa_key',
            '/etc/ssl/private/site.key', '/etc/ssl/private', '/etc/ssl/private/', '/root/.ssh/id_rsa', '/root/.ssh/id_ed25519', '/home/bob/.ssh/id_rsa', '/home/bob/.ssh/id_ed25519',
            '/etc/letsencrypt/live/example.com/privkey.pem', '/etc/letsencrypt/archive/example.com/privkey1.pem', '/etc/wireguard/wg0.conf', '/etc/openvpn/client/client.key',
            '/etc/NetworkManager/system-connections/Home.nmconnection', '/etc/krb5.keytab', '/etc/ppp/chap-secrets', '/etc/mysql/debian.cnf', '/etc/samba/private/passdb.tdb',
            '/etc/apt/auth.conf', '/etc/apt/auth.conf.d/private.conf', '/etc/shadow.bak', '/etc/shadow~', '/etc/gshadow.old', '/etc/postfix/sasl_passwd', '/etc/samba/smbpasswd', '/etc/ppp/passwd.client', '/etc/wpa_supplicant/wpa_supplicant.conf', '/etc/sssd/sssd.conf', '/etc/ssl/foo_key', '/etc/foo/id_ed25519', '/etc/passwd-', '/etc/nginx/server.key', '/etc/pki/tls/private/host.key', '/etc/nginx/.htpasswd', '/root/.netrc', '/etc/ipsec.secrets',
        ], 'a credential file');
    }

    public function testRelativeAndTraversingFilePathsAndSystemFoldersAreRefused(): void
    {
        $f = $this->allowedFilePath();
        $this->assertAllRefused('file', [
            ltrim($f, '/'), './' . ltrim($f, '/'), $f . '/..', $f . '/', dirname($f) . '/./' . basename($f), dirname($f) . '//' . basename($f), str_replace('/', '\\', $f), dirname($f) . '/../' . basename(dirname($f)) . '/' . basename($f),
            '/etc/../root/.ssh/id_rsa', '/etc/../etc/shadow', '/etc/ssh/../../root/.ssh/id_rsa', '~root/.ssh/authorized_keys', 'file://' . $f, '/', '/etc', '/root', '/home', '/home/bob', '/proc/1/environ', '/proc/self/mem',
            '/dev/mem', '/sys/kernel/security', '/var/log/auth.log', '/home/bob/.bash_history', '/root/.bash_history', '/tmp/x', '/usr/bin/sudo', '', ' ' . $f, $f . ' ',
        ], 'a relative path, a traversal, or a place no configuration lives');
    }

    public function testRegistryHiveFilesAreNotFiles(): void
    {
        $this->assertAllRefused('file', [
            'C:\\Windows\\System32\\config\\SAM', 'C:\\Windows\\System32\\config\\SECURITY', 'C:\\Windows\\System32\\config\\SYSTEM', 'C:\\Windows\\System32\\config\\SOFTWARE', 'C:\\Windows\\System32\\config\\RegBack\\SAM',
            'c:\\windows\\system32\\config\\sam', 'C:\\Windows\\NTDS\\ntds.dit', 'C:\\Users\\bob\\NTUSER.DAT', 'C:\\Windows\\repair\\SAM', 'C:\\Windows\\System32\\drivers\\etc\\..\\..\\config\\SAM',
            'C:\\Windows\\System32\\drivers\\etc\\hosts\\..\\..\\..\\config\\SAM', 'C:/Windows/System32/config/SAM', 'C:\\', 'C:\\Windows', '\\\\server\\share\\x', '\\\\?\\C:\\Windows\\System32\\config\\SAM', 'C:\\Users\\bob\\key.pfx',
        ], 'a registry hive file, a certificate or a place no configuration lives');
    }

    public function testNulAndControlCharactersAreRefusedEverywhere(): void
    {
        $k = $this->allowedRegistryKey();
        $f = $this->allowedFilePath();
        foreach (["\0", "\n", "\r\n", "\t", "\x01", "\x7f", "\x1b[31m"] as $c) {
            $this->assertAllRefused('registry', [$k . $c, $c . $k, substr($k, 0, 20) . $c . substr($k, 20), 'HKLM\\SAM' . $c . '\\x'], 'a control character (never cleaned and accepted)');
            $this->assertAllRefused('file', [$f . $c, $c . $f, substr($f, 0, 6) . $c . substr($f, 6), '/etc/shadow' . $c], 'a control character (never cleaned and accepted)');
        }
    }

    public function testGarbageIsRefusedAndNeverThrows(): void
    {
        $garbage = ['', ' ', '\\', '/', '.', '..', '0', 'null', '{}', str_repeat('a', 5000), 'HKLM\\' . str_repeat('A\\', 3000), '/etc/' . str_repeat('a/', 3000), "\xff\xfe\xfd", "HKLM\\SOFTWARE\\\xff\\x", "/etc/\xff/x",
            "\u{202e}ledoR", 'ＨＫＬＭ\\ＳＯＦＴＷＡＲＥ\\Acme', '／ｅｔｃ／ｈｏｓｔｓ', "HKLM\\SOFTWARE\\Acme\u{200b}\\Agent", "/etc/ho\u{200b}sts"];
        foreach (['registry', 'file'] as $kind) {
            foreach ($garbage as $g) {
                $this->assertFalse($this->allows($kind, $g), "garbage must be refused ($kind): " . substr(json_encode($g, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '', 0, 60));
            }
        }
    }

    public function testAnUnknownKindIsRefused(): void
    {
        foreach (['', 'group', 'task', 'REGISTRY', 'File', 'registry ', ' file', 'file\0', 'registry,file', '*', 'any'] as $kind) {
            $this->assertFalse($this->allows($kind, $this->allowedRegistryKey()), "kind '$kind' with a registry key");
            $this->assertFalse($this->allows($kind, $this->allowedFilePath()), "kind '$kind' with a file path");
        }
        $this->assertFalse($this->allows('file', $this->allowedRegistryKey()), 'a registry key is not a file');
        $this->assertFalse($this->allows('registry', $this->allowedFilePath()), 'a file is not a registry key');
    }

    public function testTheClientIdNeverLoosensTheRules(): void
    {
        $bad = [['registry', 'HKLM\\SAM\\SAM'], ['registry', 'HKLM\\SECURITY\\Policy\\Secrets'], ['registry', 'HKLM\\SYSTEM\\CurrentControlSet\\Control\\Lsa\\JD'], ['registry', 'HKLM'], ['file', '/etc/shadow'],
            ['file', '/etc/ssh/ssh_host_rsa_key'], ['file', '/etc/../root/.ssh/id_rsa'], ['file', 'relative/path'], ['registry', "HKLM\\SOFTWARE\\Acme\0\\x"], ['file', 'C:\\Windows\\System32\\config\\SAM']];
        foreach ([0, 1, 7, 99999, PHP_INT_MAX, -1, PHP_INT_MIN] as $client) {
            foreach ($bad as [$kind, $t]) {
                $this->assertFalse($this->allows($kind, $t, $client), "client $client must not change the rules: $kind $t");
            }
        }
    }
}
