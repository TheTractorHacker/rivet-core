<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/** The baseline checker must pass on the real list and fail on each kind of rot (mutation tests). */
final class BaselineCheckTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    /** @return array{0:int,1:string} */
    private function runScript(string $script, ?string $json = null, array $args = []): array
    {
        $env = $json === null ? [] : ['BASELINE_JSON' => $json];
        $cmd = array_merge([PHP_BINARY, $this->root . '/scripts/' . $script], $args);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $env + (array) getenv());
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($p), $out];
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mutate */
    private function mutated(callable $mutate): string
    {
        $b = json_decode((string) file_get_contents($this->root . '/docs/security/baseline.json'), true);
        $f = tempnam(sys_get_temp_dir(), 'bl');
        file_put_contents($f, json_encode($mutate($b)));

        return $f;
    }

    public function testRealBaselinePassesAndMappingIsCurrent(): void
    {
        [$code, $out] = $this->runScript('baseline-check.php');
        $this->assertSame(0, $code, $out);
        [$code, $out] = $this->runScript('baseline-map.php', null, ['--check']);
        $this->assertSame(0, $code, $out);
    }

    public function testSecurityPolicyExists(): void
    {
        $this->assertFileExists($this->root . '/SECURITY.md');
    }

    public function testEnforcedControlWithoutATestFails(): void
    {
        $f = $this->mutated(function (array $b) {
            $b['controls'][0]['status'] = 'enforced';
            $b['controls'][0]['tests'] = [];

            return $b;
        });
        [$code, $out] = $this->runScript('baseline-check.php', $f);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('status enforced but no test', $out);
    }

    public function testMissingFileMissingTestMissingMethodAndMissingCiJobFail(): void
    {
        $f = $this->mutated(function (array $b) {
            $b['controls'][0]['files'][] = 'src/Nope.php';
            $b['controls'][0]['tests'] = ['tests/Unit/Nope.php', 'tests/Unit/AuditServiceTest.php::testNothing', 'ci:no-such-job'];

            return $b;
        });
        [$code, $out] = $this->runScript('baseline-check.php', $f);
        $this->assertSame(1, $code);
        foreach (['file does not exist: src/Nope.php', 'test file does not exist', 'test method not found', 'CI job not found'] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
    }

    public function testUnknownMappingIdUndefinedGapAndStaleMappingFail(): void
    {
        $f = $this->mutated(function (array $b) {
            $b['controls'][0]['soc2'] = ['CC99.9'];
            $b['controls'][0]['note'] = 'see GAP-99';
            $b['controls'][1]['title'] = 'changed title so the generated docs are stale';

            return $b;
        });
        [$code, $out] = $this->runScript('baseline-check.php', $f);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('unknown SOC 2 criterion', $out);
        $this->assertStringContainsString('undefined GAP-99', $out);

        $g = $this->mutated(function (array $b) {
            $b['controls'][1]['title'] = 'changed title so the generated docs are stale';

            return $b;
        });
        [$code, $out] = $this->runScript('baseline-check.php', $g);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('is stale', $out);
    }

    public function testNoEnforcedControlClaimsMoreThanItsTestsAndGapsListNoTests(): void
    {
        $b = json_decode((string) file_get_contents($this->root . '/docs/security/baseline.json'), true);
        foreach ($b['controls'] as $c) {
            if ($c['status'] === 'gap') {
                $this->assertSame([], $c['tests'], $c['id']);
            }
            if ($c['status'] === 'enforced') {
                $this->assertNotSame([], $c['tests'], $c['id']);
            }
        }
    }
}
