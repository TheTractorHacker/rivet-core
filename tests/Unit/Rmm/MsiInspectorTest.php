<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Binaries\MsiInspector;
use RivetCore\Rmm\Installer\InstallerStamp;
use RivetCore\Tests\Support\OleFile;
use RivetCore\Tests\Support\RmmHarness;

/** The MSI template validation: the OLE signature, the size window, "already stamped", and the platform read from the summary information. */
final class MsiInspectorTest extends TestCase
{
    private const CAP = 1048576;
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rmm_msi_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function put(string $bytes, string $name = 'a.msi'): string
    {
        file_put_contents($this->dir . '/' . $name, $bytes);

        return $this->dir . '/' . $name;
    }

    public function testTheRealWixlBuiltTemplateIsRecognisedAndItsPlatformRead(): void
    {
        $real = dirname(__DIR__, 2) . '/Fixtures/rmm/msi/template-x64.msi';
        $s = MsiInspector::summary($real);
        $this->assertSame(['x64;1033', 'x64', 'amd64', 'RivetIT endpoint agent', null], [$s['template'], $s['platform'], $s['arch'], $s['subject'], $s['note']]);
        $i = MsiInspector::inspect($real, 'amd64', self::CAP);
        $this->assertIsArray($i, is_string($i) ? $i : '');
        $this->assertSame(['amd64', 'summary', 'x64;1033', 11264, hash_file('sha256', $real)], [$i['arch'], $i['arch_source'], $i['template'], $i['size'], $i['sha256']]);
        $this->assertSame('The package says it is for amd64 (x64;1033), not arm64.', MsiInspector::inspect($real, 'arm64', self::CAP));
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function templates(): iterable
    {
        yield 'x64' => ['x64;1033', 'amd64', 'amd64'];
        yield 'Arm64' => ['Arm64;1033', 'arm64', 'arm64'];
        yield 'upper case' => ['X64;1033', 'amd64', 'amd64'];
        yield 'no language' => ['x64', 'amd64', 'amd64'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('templates')]
    public function testThePlatformComesFromTheSummaryInformation(string $template, string $declared, string $expected): void
    {
        $f = $this->put(OleFile::build([7 => $template, 3 => 'x']));
        $i = MsiInspector::inspect($f, $declared, self::CAP);
        $this->assertIsArray($i, is_string($i) ? $i : '');
        $this->assertSame([$expected, 'summary'], [$i['arch'], $i['arch_source']]);
    }

    public function testAPlatformTheAgentIsNotBuiltForIsRefused(): void
    {
        foreach (['Intel;1033' => 'Intel', 'Intel64;1033' => 'intel64'] as $t => $platform) {
            $r = MsiInspector::inspect($this->put(OleFile::build([7 => $t])), 'amd64', self::CAP);
            $this->assertIsString($r);
            $this->assertStringContainsString('platform "' . strtolower($platform) . '"', $r);
        }
    }

    public function testADeclaredArchitectureThatContradictsTheSummaryIsRefused(): void
    {
        $r = MsiInspector::inspect($this->put(OleFile::build([7 => 'x64;1033'])), 'arm64', self::CAP);
        $this->assertSame('The package says it is for amd64 (x64;1033), not arm64.', $r);
    }

    public function testAnUnreadableSummaryFallsBackToTheDeclaredArchitectureAndSaysSo(): void
    {
        // an OLE file whose directory has no summary stream at all (garbage after the signature)
        $bytes = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 9000);
        $i = MsiInspector::inspect($this->put($bytes), 'arm64', self::CAP);
        $this->assertIsArray($i);
        $this->assertSame(['arm64', 'declared', null], [$i['arch'], $i['arch_source'], $i['template']]);
        // a summary without a Template property is the same case
        $i = MsiInspector::inspect($this->put(OleFile::build([3 => 'only a subject'])), 'amd64', self::CAP);
        $this->assertSame(['amd64', 'declared'], [$i['arch'], $i['arch_source']]);
    }

    public function testRefusals(): void
    {
        $ok = OleFile::build([7 => 'x64;1033']);
        $this->assertSame('The architecture must be amd64 or arm64.', MsiInspector::inspect($this->put($ok), 'x86', self::CAP));
        $this->assertSame('The file could not be read.', MsiInspector::inspect($this->dir . '/missing.msi', 'amd64', self::CAP));
        $this->assertStringContainsString('too small', (string) MsiInspector::inspect($this->put(substr($ok, 0, 4000)), 'amd64', self::CAP));
        $this->assertStringContainsString('larger than the', (string) MsiInspector::inspect($this->put($ok), 'amd64', 8192));
        $this->assertStringContainsString('not a Windows Installer package', (string) MsiInspector::inspect($this->put(str_repeat('x', 9000)), 'amd64', self::CAP));
        $this->assertStringContainsString('not an MSI', (string) MsiInspector::inspect($this->put(RmmHarness::fakePe(0x8664, 9000, 'a')), 'amd64', self::CAP));
        $this->assertStringContainsString('not an MSI', (string) MsiInspector::inspect($this->put("\x7fELF" . str_repeat("\0", 9000)), 'amd64', self::CAP));
        $stamped = $ok . InstallerStamp::trailer('{"a":1}');
        $this->assertStringContainsString('already carries an installer footer', (string) MsiInspector::inspect($this->put($stamped), 'amd64', self::CAP));
    }

    public function testACorruptCompoundFileNeverThrowsOrLoops(): void
    {
        $good = OleFile::build([7 => 'x64;1033']);
        // every single-byte corruption of the header and of the first sectors either still parses or falls back; none throws, hangs or reads outside the file
        for ($i = 0; $i < 1600; $i += 7) {
            $bad = $good;
            $bad[$i] = chr((ord($bad[$i]) + 91) & 0xFF);
            $r = MsiInspector::summary($this->put($bad, 'c.msi'));
            $this->assertArrayHasKey('note', $r);
            $inspected = MsiInspector::inspect($this->put($bad, 'c.msi'), 'amd64', self::CAP);
            $this->assertTrue(is_array($inspected) || is_string($inspected));
        }
        // a FAT that loops back on itself
        $loop = substr_replace($good, pack('V', 3), 512 + 4 * 3, 4);
        $s = MsiInspector::summary($this->put($loop));
        $this->assertNull($s['platform']);
        $this->assertStringContainsString('broken sector chain', (string) $s['note']);
    }
}
