<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../scripts/ui-assets-lib.php';
require_once __DIR__ . '/../../scripts/lib/CssChunker.php';
require_once __DIR__ . '/../../scripts/lib/CssCascade.php';

/**
 * The shared design layer (assets/ui, docs/ui/DESIGN_ASSETS.md): the manifest must describe the files exactly, the installer must
 * produce copies the drift check accepts, and the drift check must notice every kind of difference.
 */
final class UiAssetsTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rivet-ui-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/css', 0777, true);
        mkdir($this->tmp . '/js', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/{css,js}/{*,.[!.]*}', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp . '/css');
        @rmdir($this->tmp . '/js');
        @rmdir($this->tmp);
    }

    /** @return array{int, string} exit code and combined output */
    private function script(string $script, string ...$args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../scripts/' . $script);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        exec($cmd . ' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function testCoreAssetsMatchTheirManifest(): void
    {
        $root = ui_assets_root();
        $manifest = ui_assets_manifest($root . '/MANIFEST.json');
        self::assertSame([], ui_assets_verify_core($manifest, $root));
        self::assertNotEmpty($manifest['files']);
    }

    /**
     * The one deliberate deviation from RivetIT's bytes (docs/ui/DESIGN_ASSETS.md, "Deviations from RivetIT"): accessible muted text.
     * Put RivetIT's value back and the file must hash to what the verified split of RivetIT 26.10.68's itflow_design.css produced
     * (RivetIT original d619b495...; the split is proven by scripts/ui-css-split.php verify, which applies the same exception).
     */
    public function testMutedTextColourIsTheOnlyDeviationInDesignCss(): void
    {
        $css = (string) file_get_contents(ui_assets_root() . '/css/itflow_design.css');
        self::assertSame(1, substr_count($css, "  --if-muted: #4b5d64;\n"), 'light-theme token is the accessible value');
        self::assertSame(0, substr_count($css, '--if-muted: #5d6f76'), 'RivetIT value is gone from the declaration');
        $asRivetIt = str_replace("  --if-muted: #4b5d64;\n", "  --if-muted: #5d6f76;\n", $css);
        self::assertSame(self::DESIGN_CSS_RIVETIT_SPLIT_SHA256, hash('sha256', $asRivetIt));
        // Exactly one line differs (same line count, one changed line).
        self::assertSame(substr_count($css, "\n"), substr_count($asRivetIt, "\n"));
    }

    private const DESIGN_CSS_RIVETIT_SPLIT_SHA256 = '8aaf3030446237b59c7e63a605b8c1abe0688178f997b8de12ed8f142047b33c';

    /** SHA-256 of RivetIT 26.10.68's js/<file>; Core's copy must be byte identical (the wizard: after reversing its reviewed deviation). */
    private const RIVETIT_JS_SHA256 = [
        'chart_theme.js' => '070865bb4ceb8e29db408bec92dbc683c8ff350fb77f308b504c30ea0632e7ea',
        'icon_picker.js' => '3dde00674bb3970314aac1c5ffea56461a888ef0f0dba492d3fbba25668e7d0b',
        'date_range_picker.js' => 'fd0445c9ca151dfbb968c1d262f5a4ea5ebf5e37e6d127425de8960b63700d0e',
        'event_picker.js' => '4b5da886f94f68343265942092b8dec4dacf440c3f4c7fb21bcd7245b2a04855',
        'webhook_guides.js' => '7744477f9ea686efe58122dd4bf8021eec898f00e0ecf5a8213a78815e745cd5',
        'webhook_wizard.js' => '36aa8dd76ef5fda6ab8131d4fa5b2e80a86086f33d420867757c9b91f497a756',
    ];

    public function testSharedScriptsAreRivetItsBytes(): void
    {
        foreach (self::RIVETIT_JS_SHA256 as $file => $sha) {
            $js = (string) file_get_contents(ui_assets_root() . '/js/' . $file);
            if ($file === 'webhook_wizard.js') {
                $js = $this->reverseWizardDeviation($js);
            }
            self::assertSame($sha, hash('sha256', $js), $file);
        }
        // Edition-owned on purpose (docs/ui/DESIGN_ASSETS.md): Core has no server half for it.
        self::assertFileDoesNotExist(ui_assets_root() . '/js/event_rules.js');
    }

    /**
     * The webhook wizard's only change: the two page names it linked to are read from optional attributes and fall back to the same
     * literals. The fallbacks must be the original literals, and nothing else may differ.
     */
    public function testWebhookWizardDeviationFallsBackToTheOriginalLiterals(): void
    {
        $js = (string) file_get_contents(ui_assets_root() . '/js/webhook_wizard.js');
        self::assertStringContainsString("root.getAttribute('data-wz-networks') || 'settings_webhooks.php#internal-networks'", $js);
        self::assertStringContainsString("root.getAttribute('data-wz-edit') || 'webhook_edit.php'", $js);
        self::assertSame(0, substr_count($js, "'webhook_edit.php?id='"), 'no other hard-coded edit page left');
        self::assertSame(1, substr_count($js, "'settings_webhooks.php#internal-networks'"), 'only the fallback names the networks page');
    }

    private function reverseWizardDeviation(string $js): string
    {
        $pairs = [
            ["  var networksUrl = root.getAttribute('data-wz-networks') || 'settings_webhooks.php#internal-networks';\n  var editUrl = root.getAttribute('data-wz-edit') || 'webhook_edit.php';\n", ''],
            ['a.href = networksUrl;', "a.href = 'settings_webhooks.php#internal-networks';"],
            ["dl.href = editUrl + '?id=' +", "dl.href = 'webhook_edit.php?id=' +"],
            ["'', editUrl + '?id=' +", "'', 'webhook_edit.php?id=' +"],
        ];
        foreach ($pairs as [$from, $to]) {
            self::assertSame(1, substr_count($js, $from), $from);
            $js = str_replace($from, $to, $js);
        }
        return $js;
    }

    public function testSelfCheckScriptPasses(): void
    {
        [$code, $out] = $this->script('check-ui-assets.php');
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('OK', $out);
    }

    public function testInstallThenCheckThenDetectDrift(): void
    {
        [$code, $out] = $this->script('install-ui-assets.php', '--css=' . $this->tmp . '/css', '--js=' . $this->tmp . '/js');
        self::assertSame(0, $code, $out);
        self::assertFileExists($this->tmp . '/css/' . UI_ASSETS_RECEIPT);
        self::assertFileExists($this->tmp . '/css/itflow_design.css');
        self::assertFileDoesNotExist($this->tmp . '/css/itflow_design.edition.css', 'edition overrides are never installed');
        foreach (['icon_picker.js', 'date_range_picker.js', 'event_picker.js', 'webhook_wizard.js', 'webhook_guides.js', 'chart_theme.js'] as $js) {
            self::assertFileExists($this->tmp . '/js/' . $js);
        }
        self::assertFileDoesNotExist($this->tmp . '/js/event_rules.js');

        [$code, $out] = $this->script('check-ui-assets.php', '--css=' . $this->tmp . '/css', '--js=' . $this->tmp . '/js');
        self::assertSame(0, $code, $out);

        // A modified shared file, a missing one and an edition's own file: the first two fail, the third is ignored.
        file_put_contents($this->tmp . '/css/itflow_custom.css', "\n/* local tweak */", FILE_APPEND);
        unlink($this->tmp . '/css/itflow_motion.css');
        file_put_contents($this->tmp . '/css/itflow_kb.css', '/* edition sheet */');
        [$code, $out] = $this->script('check-ui-assets.php', '--css=' . $this->tmp . '/css');
        self::assertSame(1, $code);
        self::assertStringContainsString('css/itflow_custom.css', $out);
        self::assertStringContainsString('missing: css/itflow_motion.css', $out);
        self::assertStringNotContainsString('itflow_kb.css', $out);

        // Re-installing repairs it.
        [$code] = $this->script('install-ui-assets.php', '--css=' . $this->tmp . '/css');
        self::assertSame(0, $code);
        [$code] = $this->script('check-ui-assets.php', '--css=' . $this->tmp . '/css');
        self::assertSame(0, $code);
    }

    public function testDryRunWritesNothing(): void
    {
        [$code, $out] = $this->script('install-ui-assets.php', '--css=' . $this->tmp . '/css', '--dry-run');
        self::assertSame(0, $code, $out);
        self::assertSame([], glob($this->tmp . '/css/*') ?: []);
    }

    public function testExpectedVersionMismatchFails(): void
    {
        [$code, $out] = $this->script('check-ui-assets.php', '--expect-version=0.0.0-nope');
        self::assertSame(1, $code);
        self::assertStringContainsString('expected 0.0.0-nope', $out);
    }

    public function testManifestDriftIsCaught(): void
    {
        $root = ui_assets_root();
        $manifest = ui_assets_manifest($root . '/MANIFEST.json');
        $first = array_key_first($manifest['files']);
        self::assertNotNull($first);

        $wrongHash = $manifest;
        $wrongHash['files'][$first]['sha256'] = str_repeat('a', 64);
        self::assertNotSame([], ui_assets_verify_core($wrongHash, $root));

        $missingEntry = $manifest;
        unset($missingEntry['files'][$first]);
        $problems = ui_assets_verify_core($missingEntry, $root);
        self::assertNotSame([], $problems);
        self::assertStringContainsString('not in the manifest', implode("\n", $problems));

        $notOrdered = $manifest;
        $notOrdered['load_order'] = [];
        self::assertStringContainsString('load_order', implode("\n", ui_assets_verify_core($notOrdered, $root)));
    }

    public function testManifestPathsCannotEscapeTheAssetDirectory(): void
    {
        foreach (['css/../../etc/passwd', '../css/x.css', 'css/sub/x.css', '/etc/x.css', 'css/.hidden.css', 'css/x.php', 'other/x.css'] as $bad) {
            self::assertFalse(ui_assets_valid_path($bad), $bad);
        }
        self::assertTrue(ui_assets_valid_path('css/itflow.bind-tabler.css'));
        self::assertTrue(ui_assets_valid_path('js/chart_theme.js'));

        $path = $this->tmp . '/bad.json';
        file_put_contents($path, json_encode(['schema' => 1, 'core_version' => 'x', 'files' => ['css/../x.css' => ['sha256' => str_repeat('0', 64)]], 'load_order' => []]));
        $this->expectException(\RuntimeException::class);
        ui_assets_manifest($path);
    }

    public function testLoadOrderPutsTheSeamFilesInTheDocumentedOrder(): void
    {
        $m = ui_assets_manifest(ui_assets_root() . '/MANIFEST.json');
        $order = array_column($m['load_order'], 'file');
        $pos = static fn(string $f): int => (int) array_search($f, $order, true);
        self::assertLessThan($pos('css/itflow.bind-tabler.css'), $pos('css/itflow.shim-bs4.css'));
        self::assertLessThan($pos('css/itflow.bind-tabler.css'), $pos('css/itflow.shim-adminlte.css'));
        self::assertLessThan($pos('css/itflow_design.css'), $pos('css/itflow_custom.css'));
        self::assertGreaterThan($pos('css/itflow_custom.css'), $pos('css/itflow.compat-color.css'));
        self::assertGreaterThan($pos('css/itflow_design.css'), $pos('css/itflow.compat-color.css'));
        self::assertGreaterThan($pos('css/itflow.bind-tabler.css'), $pos('inline <style>'));
        // Each edition override sits right after the shared file it was cut from.
        self::assertSame($pos('css/itflow_custom.css') + 1, $pos('css/itflow_custom.edition.css'));
        self::assertSame($pos('css/itflow_design.css') + 1, $pos('css/itflow_design.edition.css'));
    }

    public function testSharedFilesCarryNoEditionSelectors(): void
    {
        foreach (['css/itflow_custom.css', 'css/itflow_design.css'] as $rel) {
            $css = (string) file_get_contents(ui_assets_root() . '/' . $rel);
            foreach (\CssChunker::chunks($css) as $chunk) {
                self::assertSame(0, preg_match('/(?<![\w-])(\.client-portal-nav|\.portal-[\w-]+|\.kb-[\w-]+|\.login-box-branding)/', $chunk['selector']), "$rel line {$chunk['start_line']}: {$chunk['selector']}");
            }
        }
    }

    public function testChunkerIsLossless(): void
    {
        $css = "/* a } */\n@charset \"utf-8\";\n.a, .b:not(.c) { color: red; content: \"}\"; }\n\n@media (max-width: 1px) {\n  .x { top: 0 }\n}\n/* tail */\n";
        $chunks = \CssChunker::chunks($css);
        self::assertSame($css, implode('', array_column($chunks, 'text')));
        self::assertSame(['@charset "utf-8";', '.a, .b:not(.c)', '@media (max-width: 1px)', ''], array_column($chunks, 'selector'));
        $this->expectException(\RuntimeException::class);
        \CssChunker::chunks('.a { color: red;');
    }

    public function testCascadeCheckReportsOnlyOrderSensitivePairs(): void
    {
        $rule = static fn(string $sel, string $body): array => \CssCascade::rules(\CssChunker::chunks("$sel { $body }")[0]);

        // Same property, equal specificity, same element: order decides, so it is reported.
        self::assertNotSame([], \CssCascade::conflicts($rule('.nav-x .brand', 'color: red'), $rule('.nav-x .brand', 'color: blue')));
        self::assertNotSame([], \CssCascade::conflicts($rule('.a', 'margin-left: 1px'), $rule('.a', 'margin: 0')), 'shorthand versus longhand');
        // A more specific rule wins whatever the order: not reported.
        self::assertSame([], \CssCascade::conflicts($rule('.a.b', 'color: red'), $rule('.a', 'color: blue')));
        // Different property, different tag, different pseudo-element: not reported.
        self::assertSame([], \CssCascade::conflicts($rule('.a', 'color: red'), $rule('.a', 'margin: 0')));
        self::assertSame([], \CssCascade::conflicts($rule('p.a', 'color: red'), $rule('li.a', 'color: blue')));
        self::assertSame([], \CssCascade::conflicts($rule('.a img', 'height: 1px'), $rule('.a.b::before', 'height: 2px')));
        // !important only competes with !important.
        self::assertSame([], \CssCascade::conflicts($rule('.a', 'color: red !important'), $rule('.a', 'color: blue')));
        // Rules inside @media are flattened.
        $media = \CssCascade::rules(\CssChunker::chunks('@media (min-width: 1px) { .a { color: red } }')[0]);
        self::assertNotSame([], \CssCascade::conflicts($media, $rule('.a', 'color: blue')));
    }

    public function testSpecificity(): void
    {
        self::assertSame([0, 1, 0], \CssCascade::specificity('.a'));
        self::assertSame([1, 1, 1], \CssCascade::specificity('div#x.a'));
        self::assertSame([0, 2, 1], \CssCascade::specificity('.a .b::before'));
        self::assertSame([0, 1, 0], \CssCascade::specificity(':where(.a, .b) .c'));
        self::assertSame([0, 2, 0], \CssCascade::specificity(':not(.a.b)'));
        self::assertSame([0, 2, 1], \CssCascade::specificity('a:hover:focus'));
    }
}
