<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Binaries\BinaryInspector;
use RivetCore\Rmm\Installer\InstallerStamp;
use RivetCore\Tests\Support\RmmHarness;

/** Header-level binary validation with no database, no module and no Reflection. */
final class BinaryInspectorTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bin_insp_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private function put(string $bytes): string
    {
        $p = $this->dir . '/f_' . bin2hex(random_bytes(3));
        file_put_contents($p, $bytes);

        return $p;
    }

    public function testDetectDescribesPeAndElfAndRefusesTheRest(): void
    {
        $pe = BinaryInspector::detect($this->put(RmmHarness::fakePe(0x8664, 4096, 'a')));
        $this->assertIsArray($pe);
        $this->assertSame(['pe', 0x8664, 'amd64', false, 4096], [$pe['format'], $pe['machine'], $pe['arch'], $pe['dll'], $pe['size']]);
        $this->assertSame('arm64', BinaryInspector::detect($this->put(RmmHarness::fakePe(0xAA64, 4096, 'a')))['arch'] ?? null);
        $x86 = BinaryInspector::detect($this->put(RmmHarness::fakePe(0x014C, 4096, 'a')));
        $this->assertIsArray($x86);
        $this->assertNull($x86['arch'], 'x86 is a PE the agent is not built for');

        $elf = "\x7fELF\x02\x01\x01" . str_repeat("\0", 11) . pack('v', 62) . str_repeat("\0", 64);
        $d = BinaryInspector::detect($this->put($elf));
        $this->assertIsArray($d);
        $this->assertSame(['elf', 'amd64'], [$d['format'], $d['arch']]);
        $this->assertIsString(BinaryInspector::detect($this->put("\x7fELF\x01\x01\x01" . str_repeat("\0", 80))), '32-bit ELF refused');
        $this->assertIsString(BinaryInspector::detect($this->put(str_repeat('text ', 100))));
        $this->assertSame('The file could not be read.', BinaryInspector::detect($this->dir . '/missing'));
    }

    public function testInspectValidatesSizeArchitectureDllAndStamp(): void
    {
        $max = 1_000_000;
        $ok = BinaryInspector::inspect($this->put(RmmHarness::fakePe(0x8664, 4096, 'a')), 'amd64', $max);
        $this->assertSame(['sha256' => hash('sha256', RmmHarness::fakePe(0x8664, 4096, 'a')), 'size' => 4096], $ok);
        $this->assertSame('The architecture must be amd64 or arm64.', BinaryInspector::inspect($this->put('x'), 'x86', $max));
        $this->assertStringContainsString('0xAA64', (string) BinaryInspector::inspect($this->put(RmmHarness::fakePe(0xAA64, 4096, 'a')), 'amd64', $max));
        $this->assertStringContainsString('larger than the', (string) BinaryInspector::inspect($this->put(RmmHarness::fakePe(0x8664, 4096, 'a')), 'amd64', 2048));
        $this->assertSame('The file is too small to be a Windows executable.', BinaryInspector::inspect($this->put('MZ'), 'amd64', $max));
        $dll = substr_replace(RmmHarness::fakePe(0x8664, 4096, 'a'), pack('v', 0x2022), 128 + 22, 2);
        $this->assertSame('This is a DLL, not an executable.', BinaryInspector::inspect($this->put($dll), 'amd64', $max));
        $elf = "\x7fELF\x02\x01\x01" . str_repeat("\0", 11) . pack('v', 62) . str_repeat("\0", 2048);
        $this->assertStringContainsString('Linux (ELF)', (string) BinaryInspector::inspect($this->put($elf), 'amd64', $max));
        $stamped = RmmHarness::fakePe(0x8664, 4096, 'a') . InstallerStamp::trailer('{"x":1}');
        $this->assertStringContainsString('installer footer', (string) BinaryInspector::inspect($this->put($stamped), 'amd64', $max));
    }
}
