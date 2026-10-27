<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;

/** Every PHP sample in the docs runs, and so do the runnable examples under docs/examples. Needs a scratch database. */
final class DocSamplesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    protected function setUp(): void
    {
        if (!getenv('RIVETCORE_TEST_DB_NAME')) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
    }

    /** @return array{0:int,1:string} */
    private function php(array $args): array
    {
        $p = proc_open([PHP_BINARY, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::ROOT);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return [proc_close($p), $out];
    }

    public function testEveryDocSampleRuns(): void
    {
        [$rc, $out] = $this->php(['scripts/check-doc-samples.php']);
        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('0 failed', $out);
    }

    public function testTheMinimalEditionRuns(): void
    {
        [$rc, $out] = $this->php(['docs/examples/minimal-edition.php']);
        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('webhook: ok=true, signed=yes', $out);
        $this->assertStringContainsString('lock without Redis: held=true degraded=true', $out);
        $this->assertStringEndsWith("done\n", $out);
    }

    public function testTheExampleAdapterPassesTheConformanceKit(): void
    {
        [$rc, $out] = $this->php(['vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', 'docs/examples/ExampleAdapterConformanceTest.php']);
        $this->assertSame(0, $rc, $out);
    }
}
