<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** CORE-DS-45 / CORE-DS-46: the command palette's URL guard and its "recent" store (the JS is run with node when it is installed). */
final class PaletteJsTest extends TestCase
{
    private static function js(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/assets/ui/js/command_palette.js');
    }

    public function testRecentsNeverGoToLocalStorageAndOnlyPagesAreKept(): void
    {
        $js = self::js();
        $this->assertSame(0, substr_count($js, 'localStorage.setItem'), 'nothing is written to the origin-wide store');
        $this->assertSame(0, substr_count($js, 'localStorage.getItem'));
        $this->assertStringContainsString("localStorage.removeItem(RECENT_KEY)", $js, 'what an earlier version left there is removed');
        $this->assertStringContainsString("if (it.type !== 'page' || it.search) { return; }", $js, 'record titles and typed queries are not remembered');
        $this->assertStringContainsString("type: 'page', search: true, title: 'Search everything for", $js, 'CR22: the full-search row is marked');
        $this->assertMatchesRegularExpression('/logout\\/i\\.test\\(a\\.getAttribute.*sessionStorage\\.removeItem\\(RECENT_KEY\\)/s', $js, 'signing out clears the list');
    }

    public function testASameOriginPathNeverStartsWithTwoSlashes(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is not installed');
        }
        foreach (['command_palette.js' => 'sameOrigin', 'webhook_wizard.js' => 'sameOriginPath'] as $file => $fn) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/ui/js/' . $file);
            $this->assertSame(1, preg_match('/^\s*function ' . $fn . '\(.*?^\s{2,4}\}\s*$/ms', $src, $m), $fn);
            $script = "var location = new URL('https://h.example/agent/x.php');\n" . $m[0] . "\n"
                . "var out = ['/.//evil.example/x', '/a/..//evil.example/x?q=1#f', '//evil.example/x', 'https://h.example//evil.example/y', '/ok/path?a=1', 'https://other.example/x', 'javascript:alert(1)'].map(function (h) { return [h, " . ($fn === 'sameOrigin' ? 'sameOrigin(h)' : 'sameOriginPath(h, null)') . "]; });\n"
                . "console.log(JSON.stringify(out));";
            $r = [];
            exec('node -e ' . escapeshellarg($script) . ' 2>&1', $r, $rc);
            $this->assertSame(0, $rc, implode("\n", $r));
            $map = json_decode(implode('', $r), true);
            $this->assertIsArray($map);
            foreach ($map as [$in, $out]) {
                if ($out !== null) {
                    $this->assertDoesNotMatchRegularExpression('#^//#', $out, "$file: $in -> $out");
                    $this->assertStringStartsWith('/', $out);
                }
            }
            $byInput = array_column($map, 1, 0);
            $this->assertSame('/ok/path?a=1', $byInput['/ok/path?a=1']);
            $this->assertNull($byInput['https://other.example/x']);
            $this->assertNull($byInput['javascript:alert(1)']);
            $this->assertSame('/evil.example/x', $byInput['/.//evil.example/x']);
        }
    }
}
