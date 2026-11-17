<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Deploy\DeployProtocol;

/** CORE-DS-35: the server refuses the display names the agent's ValidatePrintableName refuses (anything Go's unicode.IsPrint rejects). */
final class DeployDisplayNameTest extends TestCase
{
    public function testNamesTheAgentRefusesAreRefusedHere(): void
    {
        foreach (["Acme\u{00a0}Agent", "Acme\u{00ad}Agent", "Acme\u{200b}Agent", "Acme\u{200e}Agent", "Acme\u{2028}Agent", "Acme\u{0085}Agent", "Acme\u{3000}Agent", "Acme\tAgent", "Acme\u{202e}Agent", "Acme\u{feff}Agent"] as $bad) {
            $this->assertFalse(DeployProtocol::displayNameOk($bad), bin2hex($bad));
        }
    }

    public function testOrdinaryNamesStillPass(): void
    {
        foreach (['Acme Agent 2.4', 'Microsoft Visual C++ 2015-2022 Redistributable (x64)', 'Zoom Workplace (64-bit)', 'Übersicht für Ärzte', '日本語のソフト', 'Foo & Bar™ 5.0', 'a/b\\c'] as $ok) {
            $this->assertTrue(DeployProtocol::displayNameOk($ok), $ok);
        }
        $this->assertFalse(DeployProtocol::displayNameOk(''));
        $this->assertFalse(DeployProtocol::displayNameOk(str_repeat('a', DeployProtocol::NAME_MAX + 1)));
    }
}
