<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Tests\Support\TestRedis;

/**
 * The T1 golden HTTP transcripts of the RivetIT endpoint agent API (10 files: disabled, TLS, enrollment errors and flows, check-in,
 * jobs, update download, installer download, rate limits), replayed through RivetCore\Rmm\Http\DeviceApi on a scratch database behind a
 * real `php -S` server (scripts/rmm-golden/replay-core.php). Bodies, status codes, header sets and the masked table snapshots must be
 * IDENTICAL to what the original code produced. Needs RIVETCORE_TEST_DB_* (scratch) and a throwaway Redis (RIVETCORE_TEST_REDIS_PORT):
 * the per-device 429 buckets are rate-limited through Core's Redis RateLimiter.
 */
final class GoldenReplayTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../scripts/rmm-golden/replay-core.php';

    protected function setUp(): void
    {
        if (!getenv('RIVETCORE_TEST_DB_NAME') || !str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
            $this->markTestSkipped('A scratch database (RIVETCORE_TEST_DB_NAME) is required.');
        }
        if (!TestRedis::available() || (new TestRedis())->client() === null) {
            $this->markTestSkipped('RIVETCORE_TEST_REDIS_PORT (a throwaway Redis) is required for the per-device rate-limit transcripts.');
        }
        try {
            (new TestRedis())->client()?->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('The throwaway Redis does not answer.');
        }
    }

    /** @return array{0:int,1:string} */
    private function replay(string ...$args): array
    {
        $p = proc_open([PHP_BINARY, self::SCRIPT, 'replay', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        $this->assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

        return [proc_close($p), $out];
    }

    public function testTheGoldenTranscriptsReplayIdentically(): void
    {
        [$code, $out] = $this->replay();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('replay identical (10 files)', $out);
        $this->assertStringNotContainsString('DIFF in', $out);
    }

    public function testAnAlteredTranscriptIsDetected(): void
    {
        $dir = sys_get_temp_dir() . '/rmm_golden_altered_' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach (glob(dirname(__DIR__, 2) . '/Fixtures/rmm/golden/*.json') ?: [] as $f) {
            $text = (string) file_get_contents($f);
            if (basename($f) === '05-checkin.json') {
                $text = preg_replace('/"consecutive_failures": 3/', '"consecutive_failures": 4', $text, 1, $n) ?? $text;
                $this->assertSame(1, $n, 'the control file must contain what it alters');
            }
            file_put_contents($dir . '/' . basename($f), $text);
        }
        [$code, $out] = $this->replay('--dir=' . $dir);
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('DIFF in 05-checkin.json', $out);
    }
}
