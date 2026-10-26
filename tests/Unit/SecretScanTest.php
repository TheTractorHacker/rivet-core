<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/secret-scan.php';
require_once dirname(__DIR__, 2) . '/scripts/secret-scan-sync.php';

/**
 * The secret scanner itself, the guarantee that src/ and docs/ carry no secret shape, and the allowlist discipline.
 * Positive samples are assembled from fragments at run time so this file is not a fixture of the thing it tests.
 */
final class SecretScanTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return iterable<string,array{0:string,1:string}> [line, expected rule id] */
    public static function positives(): iterable
    {
        $rnd = static fn(int $n, string $alphabet): string => substr(str_repeat($alphabet, (int) ceil($n / strlen($alphabet))), 0, $n);
        $mixed = 'q8Zr3KdLw0XvB7nTc5YhJm2PsUa9GfEo';   // varied, no run of one character
        yield 'private key block' => ['pem = "-----BEGIN ' . 'RSA PRIVATE KEY-----\nMIIEo' . $rnd(80, $mixed) . '"', 'private-key-block'];
        yield 'aws access key id' => ['id=AK' . 'IA' . '7Q2ZM4K9XW3BTR6D', 'aws-access-key-id'];
        yield 'github token' => ['t=gh' . 'p_' . $rnd(36, $mixed), 'github-token'];
        yield 'github fine grained' => ['t=github_' . 'pat_' . $rnd(60, $mixed), 'github-fine-grained-pat'];
        yield 'slack token' => ['t=xo' . 'xb-123456789012-' . $rnd(20, $mixed), 'slack-token'];
        yield 'stripe key' => ['k=sk' . '_live_' . $rnd(24, $mixed), 'stripe-key'];
        yield 'google api key' => ['k=AI' . 'za' . $rnd(35, $mixed), 'google-api-key'];
        yield 'jwt' => ['a=ey' . 'J' . $rnd(20, $mixed) . '.ey' . 'J' . $rnd(20, $mixed) . '.' . $rnd(30, $mixed), 'jwt'];
        yield 'url with password' => ['dsn = "postgres://app:' . 'hX7' . 'qK2m9Zt@db.internal:5432/app"', 'url-with-password'];
        yield 'secret assignment' => ['$config_api_secret = "' . $rnd(40, $mixed) . '";', 'secret-assignment'];
        yield 'hex key blob' => ['signing_key: "' . bin2hex(hash('sha256', 'not-a-real-key', true)) . '"', 'hex-key-blob'];
        yield 'base64 key blob' => ["secret_key = '" . base64_encode(hash('sha256', 'another-fixture', true)) . "'", 'base64-key-blob'];
    }

    #[DataProvider('positives')]
    public function testEveryRuleFiresOnItsShape(string $line, string $rule): void
    {
        $hits = secret_scan_line($line);
        $this->assertContains($rule, array_column($hits, 'rule'), $line === '' ? '' : "rule $rule did not fire");
    }

    /** @return iterable<string,array{0:string}> */
    public static function negatives(): iterable
    {
        yield 'documented AWS example' => ['id=AKIAIOSFODNN7EXAMPLE'];
        yield 'template variable' => ['$token = "${SOME_TOKEN_VARIABLE_FROM_ENV_1234}";'];
        yield 'placeholder word' => ['secret: "your-secret-goes-here-please-1234"'];
        yield 'repeated characters' => ['api_key = "' . str_repeat('a', 40) . '"'];
        yield 'url without password' => ['https://user@example.com/path'];
        yield 'url with variable password' => ['postgres://app:${DB_PASSWORD}@db/app'];
        yield 'public key' => ['"public_key": "' . base64_encode(hash('sha256', 'pub', true)) . '"'];
        yield 'plain sentence' => ['The secret to a good tool is that the key stays in the vault.'];
        yield 'pem header only' => ['-----BEGIN ' . 'PRIVATE KEY----- appears in the log'];
        yield 'sha256 of file next to path' => ['sha256sum build/artifact-linux-amd64.tar.gz'];
    }

    #[DataProvider('negatives')]
    public function testOrdinaryTextAndPlaceholdersAreNotFindings(string $line): void
    {
        $this->assertSame([], secret_scan_line($line));
    }

    public function testAHitNeverCarriesTheValue(): void
    {
        $value = 'gh' . 'p_q8Zr3KdLw0XvB7nTc5YhJm2PsUa9GfEoq8Zr';
        $hits = secret_scan_line('t=' . $value);
        $this->assertNotEmpty($hits);
        $this->assertSame(['col', 'fp', 'len', 'rule'], $this->sortedKeys($hits[0]));
        $this->assertStringNotContainsString($value, (string) json_encode($hits));
        $this->assertSame(4, strlen($hits[0]['fp']));
        $this->assertSame(strlen($value), $hits[0]['len']);
    }

    /** @param array<string,mixed> $a @return list<string> */
    private function sortedKeys(array $a): array
    {
        $k = array_keys($a);
        sort($k);
        return $k;
    }

    public function testSrcAndDocsCarryNoSecretShape(): void
    {
        $allow = secret_scan_load_allowlist(self::root() . '/secret-scan-allowlist.json');
        $found = [];
        foreach (['src', 'docs'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile() || $file->isLink() || filesize($file->getPathname()) > 2_000_000) {
                    continue;
                }
                $rel = substr($file->getPathname(), strlen(self::root()) + 1);
                $fh = fopen($file->getPathname(), 'rb');
                for ($n = 1; $fh && ($line = fgets($fh)) !== false; $n++) {
                    foreach (secret_scan_line($line) as $h) {
                        if (!secret_scan_allowed($allow, $rel, $h['rule'])) {
                            $found[] = sprintf('%s:%d [%s] len=%d fp=%s', $rel, $n, $h['rule'], $h['len'], $h['fp']);
                        }
                    }
                }
                if ($fh) {
                    fclose($fh);
                }
            }
        }
        $this->assertSame([], $found, "secret-shaped values in src/ or docs/ (values are not printed):\n" . implode("\n", $found));
    }

    public function testTheWholeTrackedTreeIsCleanAgainstTheAllowlist(): void
    {
        if (!is_dir(self::root() . '/.git') && !is_file(self::root() . '/.git')) {
            $this->markTestSkipped('not a git checkout');
        }
        $out = [];
        exec('cd ' . escapeshellarg(self::root()) . ' && php scripts/secret-scan.php 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
    }

    public function testAllowlistEntriesMustJustifyThemselves(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'ssa');
        file_put_contents($f, json_encode(['rules' => [['path' => 'a/b.php', 'reason' => 'fixture']]]));
        try {
            $this->expectException(\RuntimeException::class);
            secret_scan_load_allowlist($f);
        } finally {
            unlink($f);
        }
    }

    public function testAllowlistIsPathScopedAndRuleScoped(): void
    {
        $allow = ['rules' => [['path' => 'tests/Fixtures/**', 'reason' => 'x', 'rules' => ['jwt']], ['path' => 'a/*.json', 'reason' => 'x']]];
        $this->assertTrue(secret_scan_allowed($allow, 'tests/Fixtures/deep/er/f.json', 'jwt'));
        $this->assertFalse(secret_scan_allowed($allow, 'tests/Fixtures/f.json', 'github-token'), 'another rule in the same file is still a finding');
        $this->assertFalse(secret_scan_allowed($allow, 'src/Foo.php', 'jwt'), 'a path outside the entry is still a finding');
        $this->assertTrue(secret_scan_allowed($allow, 'a/x.json', 'github-token'));
        $this->assertFalse(secret_scan_allowed($allow, 'a/b/x.json', 'github-token'), '* does not cross a directory');
        $this->assertFalse(secret_scan_allowed($allow, 'xa/x.json', 'github-token'), 'the glob is anchored at the repository root');
    }

    public function testNoAllowlistEntryCoversSrc(): void
    {
        $allow = secret_scan_load_allowlist(self::root() . '/secret-scan-allowlist.json');
        foreach ($allow['rules'] as $r) {
            $this->assertStringStartsNotWith('src/', $r['path'] . '', 'production code must never be allowlisted: ' . $r['path']);
        }
    }

    public function testGeneratedScannerConfigsAreUpToDate(): void
    {
        $out = secret_scan_render(self::root() . '/secret-scan-allowlist.json');
        $this->assertSame($out['gitleaks'], (string) file_get_contents(self::root() . '/.gitleaks.toml'), '.gitleaks.toml drifted: php scripts/secret-scan-sync.php');
        $this->assertSame($out['semgrep'], (string) file_get_contents(self::root() . '/.semgrepignore'), '.semgrepignore drifted: php scripts/secret-scan-sync.php');
    }
}
