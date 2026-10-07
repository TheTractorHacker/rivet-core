<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Binaries\BinaryStore;
use RivetCore\Rmm\Installer\InstallerDownload;
use RivetCore\Rmm\Installer\InstallerService;
use RivetCore\Rmm\Mesh\MeshService;
use RivetCore\Rmm\RmmProtocol;

/** Port of the pure parts of RivetIT tests/endpoint_agent_deploy_unit.php: names, quoting, snippets, CA text, size and version syntax. */
final class InstallerSnippetsTest extends TestCase
{
    public function testSlugAndFileNamesAreHeaderSafe(): void
    {
        $this->assertSame('acme-corp', InstallerDownload::slug('Acme Corp'));
        $this->assertNotSame('', InstallerDownload::slug('  --Müller & Söhne--  '));
        $this->assertSame('department', InstallerDownload::slug('日本'));
        $this->assertSame('department', InstallerDownload::slug(''));
        $this->assertLessThanOrEqual(40, strlen(InstallerDownload::slug(str_repeat('abc ', 50))));
        $this->assertSame('RivetIT-Agent-Setup-', RmmProtocol::INSTALLER_NAME_PREFIX);
    }

    public function testPowerShellQuoting(): void
    {
        $this->assertSame("'it''s'", InstallerService::psQuote("it's"));
        $this->assertSame("'a\$b`c\"d'", InstallerService::psQuote('a$b`c"d'), '$ ` and " are inert inside single quotes');
        foreach (["a\nb", "a\0b", "a\rb", "a\x7fb"] as $bad) {
            try {
                InstallerService::psQuote($bad);
                $this->fail('control character accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPowerShellSnippet(): void
    {
        $token = 'rvte1.aaaaaaaaaaaa.' . str_repeat('b', 40);
        $snip = InstallerService::powershellSnippet("https://h.example/it'x", $token, 'amd64', "Evil'; Remove-Item C:\\ #\n");
        $this->assertStringContainsString("\$Server = 'https://h.example/it''x'", $snip);
        $this->assertStringNotContainsString("Remove-Item C:\\ #\n", $snip, 'the client name appears only sanitised, in a one-line comment');
        $this->assertLessThanOrEqual(2, substr_count($snip, "\n# "));
        foreach (["'setup', '--silent'", '/api/v1/agent_installer', 'ExitCode -ne 0', 'Remove-Item -LiteralPath $Exe', "\$Token  = '$token'"] as $needle) {
            $this->assertStringContainsString($needle, $snip);
        }
        $this->assertStringNotContainsString('?token', $snip, 'the token never travels in a URL');
        $this->assertStringContainsString("\$Arch   = 'arm64'", InstallerService::powershellSnippet('https://h', $token, 'arm64', 'x'));
        $this->assertStringContainsString("\$Arch   = 'amd64'", InstallerService::powershellSnippet('https://h', $token, 'riscv', 'x'), 'an unknown architecture falls back to amd64');
    }

    public function testShellQuotingAndTheLinuxSnippet(): void
    {
        $this->assertSame("'it'\\''s'", InstallerService::shQuote("it's"));
        $this->assertSame("'\$(rm -rf /)'", InstallerService::shQuote('$(rm -rf /)'), 'command substitution is inert inside single quotes');
        $this->assertSame("'a\nb'", InstallerService::shQuote("a\nb", true));
        foreach ([["a\nb", false], ["a\0b", true], ["a\rb", true]] as [$bad, $nl]) {
            try {
                InstallerService::shQuote($bad, $nl);
                $this->fail('control character accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $token = 'rvte1.aaaaaaaaaaaa.' . str_repeat('b', 40);
        $s = InstallerService::linuxSnippet("https://rmm.example.com/", $token, 'arm64', "Evil\n; reboot");
        $this->assertStringContainsString('install-linux.sh --server \'https://rmm.example.com\' --token-file "$T"', $s);
        $this->assertStringContainsString("printf '%s' '$token' > \"\$T\"", $s, 'the token goes through a file, not a command line');
        $this->assertStringContainsString('trap \'rm -f "$T"\' EXIT', $s);
        $this->assertStringContainsString('rivetit-agent-linux-arm64.tar.gz', $s);
        $this->assertSame(1, substr_count($s, "\n# RivetIT") + (int) str_starts_with($s, '# RivetIT'), 'one header comment, the client name flattened');
        $this->assertStringNotContainsString('--ca', $s);
        $pem = "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n";
        $withCa = InstallerService::linuxSnippet('https://h', $token, 'amd64', 'x', $pem);
        $this->assertStringContainsString('--ca "$C"', $withCa);
        $this->assertStringContainsString('trap \'rm -f "$T" "$C"\' EXIT', $withCa);
        $this->assertStringContainsString("BEGIN CERTIFICATE", $withCa);
    }

    public function testCaCertificateNormalisation(): void
    {
        [$none, $e] = InstallerService::normalizeCa('   ');
        $this->assertNull($none);
        $this->assertNull($e, 'empty clears the setting');
        foreach (['hello', "-----BEGIN CERTIFICATE-----\n!!!!\n-----END CERTIFICATE-----", str_repeat('A', 9000)] as $bad) {
            [$x, $e] = InstallerService::normalizeCa($bad);
            $this->assertNull($x);
            $this->assertNotNull($e);
        }
        $k = openssl_pkey_new(['private_key_bits' => 2048]);
        $this->assertNotFalse($k);
        $csr = openssl_csr_new(['commonName' => 'Test CA'], $k);
        $cert = openssl_csr_sign($csr, null, $k, 30);
        openssl_x509_export($cert, $pem);
        [$x, $e] = InstallerService::normalizeCa("  \n" . $pem . "\n junk after \n");
        $this->assertNull($e);
        $this->assertNotNull($x);
        $this->assertStringNotContainsString('junk', $x);
        $this->assertStringEndsWith("\n", $x);
        [$many, $e] = InstallerService::normalizeCa(str_repeat($pem . "\n", 6));
        $this->assertNull($many, 'at most five certificates');
        $this->assertNotNull($e);
    }

    public function testSizeAndVersionSyntax(): void
    {
        $this->assertSame(8388608, BinaryStore::iniBytes('8M'));
        $this->assertSame(2147483648, BinaryStore::iniBytes('2G'));
        $this->assertSame(524288, BinaryStore::iniBytes('512K'));
        $this->assertSame(0, BinaryStore::iniBytes('-1'));
        $this->assertSame(123, BinaryStore::iniBytes('123'));
        $this->assertSame('1.5 MiB', BinaryStore::human(1572864));
        $this->assertSame('2 KiB', BinaryStore::human(2048));
        $this->assertSame('12 B', BinaryStore::human(12));
        $this->assertSame(64 * 1024 * 1024, RmmProtocol::BINARY_DEFAULT_MAX_BYTES);
        $ok = static fn (string $v): bool => preg_match(RmmProtocol::BINARY_VERSION_RE, $v) === 1;
        foreach (['1.2.3', '1.2.3-rc1', '10.0.100+build.5'] as $v) {
            $this->assertTrue($ok($v), $v);
        }
        foreach (['1.2', 'v1.2.3', '1.2.3 ', "1.2.3\n", '../1.2.3', '1.2.3/../x'] as $v) {
            $this->assertFalse($ok($v), json_encode($v));
        }
    }

    public function testMeshUrlAndNodeIdSyntax(): void
    {
        $this->assertTrue(MeshService::validNodeId('node//' . str_repeat('A', 20)));
        $this->assertFalse(MeshService::validNodeId('node//short'));
        $this->assertFalse(MeshService::validNodeId("node//" . str_repeat('A', 20) . "\n"));
        $this->assertFalse(MeshService::validNodeId('garbage; DROP TABLE x'));
        $this->assertNull(MeshService::normalizeUrlWith('https://host/path?x=1', false));
        $this->assertSame('https://host/mesh', MeshService::normalizeUrlWith(' https://host/mesh/ ', false));
        $this->assertNull(MeshService::normalizeUrlWith('https://ho st', false));
        $this->assertNull(MeshService::normalizeUrlWith('https://host/' . str_repeat('a', 500), false));
    }
}
