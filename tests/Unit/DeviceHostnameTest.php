<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Enrollment\DeviceValidator;

/** Deep scan IT-DS-19/42: a host name chosen by a device carries no markup into the device and asset records. */
final class DeviceHostnameTest extends TestCase
{
    public function testMarkupCharactersAreReplacedAndFormatCharactersDropped(): void
    {
        $this->assertSame('_img src=x onerror=alert(1)_', DeviceValidator::cleanHostname('<img src=x onerror=alert(1)>'));
        $this->assertSame('a_ onmouseover=_x_', DeviceValidator::cleanHostname('a" onmouseover="x"'));
        $this->assertSame('PC_name', DeviceValidator::cleanHostname('PC`name'));
        $this->assertSame('PC_name', DeviceValidator::cleanHostname('PC\\name'));
        $this->assertSame('PCgpj.exe', DeviceValidator::cleanHostname("PC\u{202e}gpj.exe"), 'a right-to-left override is dropped');
        $this->assertSame('abc', DeviceValidator::cleanHostname("a\u{200b}b\u{2028}c"));
        $this->assertSame('a b', DeviceValidator::cleanHostname("a\x00\x1fb"), 'control characters still become a space (cleanText)');
        $this->assertNull(DeviceValidator::cleanHostname("\u{200b}"));
        $this->assertNull(DeviceValidator::cleanHostname(['x']));
        $this->assertSame(200, mb_strlen((string) DeviceValidator::cleanHostname(str_repeat('a', 300))));
    }

    public function testRealComputerNamesAreUntouched(): void
    {
        foreach (['DESKTOP-4F2K9QL', 'srv-web01.corp.example.com', 'Büro-PC', "Bob's-Laptop", 'R&D-12', 'win_build.07', '日本語PC', 'host name with spaces', 'A!B@C#D$E%F^(G){H}~I'] as $ok) {
            $this->assertSame($ok, DeviceValidator::cleanHostname($ok), $ok);
        }
    }
}
