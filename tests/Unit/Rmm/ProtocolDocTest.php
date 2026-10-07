<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\RmmProtocol;

/** docs/rmm/PROTOCOL.md carries blocks generated from the code and the recorded transcripts; they must not drift. */
final class ProtocolDocTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    public function testGeneratedBlocksAreCurrent(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root . '/scripts/rmm-protocol-doc.php') . ' --check 2>&1', $out, $code);
        $this->assertSame(0, $code, "docs/rmm/PROTOCOL.md is stale: run `php scripts/rmm-protocol-doc.php`\n" . implode("\n", $out));
    }

    public function testEveryFrozenConstantAndErrorCodeIsInTheDocument(): void
    {
        $doc = (string) file_get_contents($this->root . '/docs/rmm/PROTOCOL.md');
        foreach ((new \ReflectionClass(RmmProtocol::class))->getConstants() as $name => $_) {
            $this->assertStringContainsString('`' . $name . '`', $doc, "constant $name is missing from PROTOCOL.md");
        }
        foreach (['module_disabled', 'feature_disabled', 'device_limit', 'tls_required', 'token_in_url', 'rate_limited', 'unavailable', 'too_large'] as $code) {
            $this->assertStringContainsString('`' . $code . '`', $doc, "error code $code is not described");
        }
        // every error code the golden transcripts recorded is spelled out in the observed table
        foreach (glob($this->root . '/tests/Fixtures/rmm/golden/0*.json') ?: [] as $f) {
            foreach ((array) (json_decode((string) file_get_contents($f), true)['steps'] ?? []) as $s) {
                $code = $s['response']['body']['code'] ?? null;
                if (is_string($code)) {
                    $this->assertStringContainsString('`' . $code . '`', $doc);
                }
            }
        }
    }

    public function testTheDocumentSaysWhereTheVectorsAre(): void
    {
        $doc = (string) file_get_contents($this->root . '/docs/rmm/PROTOCOL.md');
        $sums = (string) file_get_contents($this->root . '/endpoint-agent/testdata/vectors/SHA256SUMS');
        foreach (explode("\n", trim($sums)) as $line) {
            [$hash] = preg_split('/\s+/', $line) ?: [''];
            $this->assertStringContainsString($hash, $doc, 'a vector file changed: regenerate PROTOCOL.md');
        }
    }
}
