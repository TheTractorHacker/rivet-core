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
        $this->assertStringContainsString('function clearRecent()', $js, 'signing out clears the list');
        $this->assertStringContainsString('RECENT_TTL_MS', $js, 'CRR-20: and so does half an hour without use');
    }

    /** CRR-20: the list is cleared on every sign that the session ended, not only on a click of a link that says logout (the JS is run with node when it is installed). */
    public function testRecentsAreClearedOnEverySignOfASessionEnd(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is not installed');
        }
        $file = dirname(__DIR__, 2) . '/assets/ui/js/command_palette.js';
        $script = <<<'JS'
var fs = require('fs');
function run(navPresent, fire) {
    var store = {'rivet.palette.recent.v1': '[{"url":"/a"}]', 'rivet.palette.recent.v1.at': String(Date.now())};
    var handlers = {};
    var el = function (attrs) { return { closest: function () { return this; }, getAttribute: function (k) { return attrs[k] == null ? null : attrs[k]; } }; };
    global.window = {};
    global.navigator = {platform: 'Linux'};
    global.location = new URL('https://h.example/agent/x.php');
    global.sessionStorage = {getItem: function (k) { return k in store ? store[k] : null; }, setItem: function (k, v) { store[k] = String(v); }, removeItem: function (k) { delete store[k]; }};
    global.localStorage = {removeItem: function () {}};
    global.document = {readyState: 'complete', addEventListener: function (t, f) { (handlers[t] = handlers[t] || []).push(f); }, querySelector: function (q) { return q === '.navbar-vertical' && navPresent ? {} : null; },
        querySelectorAll: function () { return []; }, getElementById: function () { return null; }, createElement: function () { return {}; }, body: {}, documentElement: {}};
    delete global.window.__rivetPalette;
    new Function('window', 'document', 'sessionStorage', 'localStorage', 'navigator', 'location', fs.readFileSync(process.argv[1], 'utf8'))(global.window, global.document, global.sessionStorage, global.localStorage, global.navigator, global.location);
    if (fire) { (handlers[fire[0]] || []).forEach(function (h) { h({target: el(fire[1])}); }); }
    return 'rivet.palette.recent.v1' in store;
}
console.log(JSON.stringify({
    kept_on_an_app_page: run(true, null),
    kept_on_an_ordinary_link: run(true, ['click', {href: '/agent/tickets.php'}]),
    cleared_on_a_logout_link: !run(true, ['click', {href: '/agent/logout.php'}]),
    cleared_on_a_signout_link: !run(true, ['click', {href: '/session/sign-out'}]),
    cleared_on_a_logout_button: !run(true, ['click', {formaction: '/agent/logout.php'}]),
    cleared_on_a_logout_form: !run(true, ['submit', {action: '/agent/logout.php'}]),
    kept_on_another_form: run(true, ['submit', {action: '/agent/search.php'}]),
    cleared_on_a_page_without_the_app_navigation: !run(false, null)
}));
JS;
        $r = [];
        exec('node -e ' . escapeshellarg($script) . ' ' . escapeshellarg($file) . ' 2>&1', $r, $rc);
        $this->assertSame(0, $rc, implode("\n", $r));
        $out = json_decode(implode('', $r), true);
        $this->assertIsArray($out, implode("\n", $r));
        foreach ($out as $case => $ok) {
            $this->assertTrue($ok, $case);
        }
        $this->assertCount(8, $out);
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
