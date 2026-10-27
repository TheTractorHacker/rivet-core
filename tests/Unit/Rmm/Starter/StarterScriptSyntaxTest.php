<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\Starter;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Starter\StarterPack;
use RivetCore\Tests\Support\StarterDocker;

/**
 * Every script of the pack must parse: PowerShell through the real PowerShell parser in a container (and, always, through a structural fallback),
 * bash through `bash -n` and ShellCheck. Nothing here RUNS a Windows script; see StarterLinuxProofTest for what is executed.
 */
final class StarterScriptSyntaxTest extends TestCase
{
    /** @return array<string,string> file name => text, the Windows scripts plus the check scripts with their tokens filled in */
    private static function powershell(): array
    {
        $out = [];
        foreach (StarterPack::bundled()->scripts() as $s) {
            if ($s['language'] === 'powershell') {
                $out[$s['id'] . '.ps1'] = $s['body'];
            }
        }
        $dir = StarterPack::bundled()->directory() . '/checks';
        foreach (glob($dir . '/*.ps1') ?: [] as $f) {
            $out['check-' . basename($f)] = (string) preg_replace('/\{\{[a-z_]+\}\}/', '30', (string) file_get_contents($f));
        }

        return $out;
    }

    /** @return array<string,string> */
    private static function bash(): array
    {
        $out = [];
        foreach (StarterPack::bundled()->scripts() as $s) {
            if ($s['language'] === 'bash') {
                $out[$s['id'] . '.sh'] = $s['body'];
            }
        }
        foreach (glob(StarterPack::bundled()->directory() . '/checks/*.sh') ?: [] as $f) {
            $out['check-' . basename($f)] = (string) preg_replace('/\{\{[a-z_]+\}\}/', '30', (string) file_get_contents($f));
        }

        return $out;
    }

    public function testThereIsSomethingToCheck(): void
    {
        $this->assertGreaterThanOrEqual(35, count(self::powershell()));
        $this->assertGreaterThanOrEqual(25, count(self::bash()));
    }

    /** The always-on fallback: balanced brackets and terminated strings, comments and here-strings. It cannot prove a script parses; the container test does. */
    public function testPowerShellStructure(): void
    {
        foreach (self::powershell() as $name => $text) {
            $err = self::structure($text);
            $this->assertNull($err, "$name: $err");
        }
    }

    public function testPowerShellParsesInTheRealParser(): void
    {
        if (!StarterDocker::image('mcr.microsoft.com/powershell:latest')) {
            $this->markTestSkipped('Docker or the PowerShell image is not available: only the structural check ran.');
        }
        $dir = StarterDocker::tempDir('ps');
        try {
            foreach (self::powershell() as $name => $text) {
                file_put_contents("$dir/$name", $text);
            }
            chmod($dir, 0755);
            $cmd = '$bad=0; Get-ChildItem /w -Filter *.ps1 | ForEach-Object { $e=$null; [void][System.Management.Automation.Language.Parser]::ParseFile($_.FullName,[ref]$null,[ref]$e); if($e){ $bad++; $_.Name; $e | ForEach-Object { "  "+$_.Message+" line "+$_.Extent.StartLineNumber } } }; "parse errors in $bad file(s)"';
            [$code, $out] = StarterDocker::exec(['docker', 'run', '--rm', '-v', "$dir:/w:ro", 'mcr.microsoft.com/powershell:latest', 'pwsh', '-NoProfile', '-Command', $cmd], 300);
            $this->assertSame(0, $code, $out);
            $this->assertStringContainsString('parse errors in 0 file(s)', $out, $out);
        } finally {
            StarterDocker::rmTree($dir);
        }
    }

    public function testBashParsesAndPassesShellCheck(): void
    {
        $dir = StarterDocker::tempDir('sh');
        try {
            foreach (self::bash() as $name => $text) {
                file_put_contents("$dir/$name", $text);
                [$code, $out] = StarterDocker::exec(['bash', '-n', "$dir/$name"], 30);
                $this->assertSame(0, $code, "bash -n $name: $out");
            }
            $files = array_keys(self::bash());
            [$have] = StarterDocker::exec(['sh', '-c', 'command -v shellcheck >/dev/null'], 10);
            if ($have === 0) {
                $cmd = array_merge(['shellcheck', '-s', 'bash', '-S', 'warning'], array_map(static fn (string $f): string => "$dir/$f", $files));
            } elseif (StarterDocker::image('koalaman/shellcheck:stable')) {
                chmod($dir, 0755);
                $cmd = array_merge(['docker', 'run', '--rm', '-v', "$dir:/mnt:ro", 'koalaman/shellcheck:stable', '-s', 'bash', '-S', 'warning'], array_map(static fn (string $f): string => "/mnt/$f", $files));
            } else {
                $this->markTestSkipped('Neither shellcheck nor docker is available: only bash -n ran.');
            }
            [$code, $out] = StarterDocker::exec($cmd, 300);
            $this->assertSame(0, $code, "shellcheck:\n$out");
        } finally {
            StarterDocker::rmTree($dir);
        }
    }

    /** Returns a message, or null when the text is balanced. */
    private static function structure(string $t): ?string
    {
        $stack = [];
        $pairs = ['(' => ')', '{' => '}', '[' => ']'];
        $n = strlen($t);
        $line = 1;
        for ($i = 0; $i < $n; ++$i) {
            $c = $t[$i];
            if ($c === "\n") {
                ++$line;
                continue;
            }
            if ($c === '<' && ($t[$i + 1] ?? '') === '#') {
                $e = strpos($t, '#>', $i + 2);
                if ($e === false) {
                    return "unterminated block comment at line $line";
                }
                $line += substr_count($t, "\n", $i, $e - $i);
                $i = $e + 1;
                continue;
            }
            if ($c === '#' && ($i === 0 || preg_match('/[\s;({]/', $t[$i - 1]) === 1)) {
                $e = strpos($t, "\n", $i);
                $i = $e === false ? $n : $e - 1;
                continue;
            }
            if (($c === '@') && in_array($t[$i + 1] ?? '', ['"', "'"], true) && preg_match('/\G[ \t]*\r?\n/', $t, $m, 0, $i + 2) === 1) {
                $q = $t[$i + 1];
                $e = strpos($t, "\n$q@", $i);
                if ($e === false) {
                    return "unterminated here-string at line $line";
                }
                $line += substr_count($t, "\n", $i, $e + 1 - $i);
                $i = $e + 2;
                continue;
            }
            if ($c === "'") {
                for (++$i; $i < $n; ++$i) {
                    if ($t[$i] === "'") {
                        if (($t[$i + 1] ?? '') === "'") {
                            ++$i;
                            continue;
                        }
                        break;
                    }
                    $line += $t[$i] === "\n" ? 1 : 0;
                }
                if ($i >= $n) {
                    return "unterminated single-quoted string near line $line";
                }
                continue;
            }
            if ($c === '"') {
                for (++$i; $i < $n; ++$i) {
                    if ($t[$i] === '`') {
                        ++$i;
                        continue;
                    }
                    if ($t[$i] === '"') {
                        if (($t[$i + 1] ?? '') === '"') {
                            ++$i;
                            continue;
                        }
                        break;
                    }
                    $line += $t[$i] === "\n" ? 1 : 0;
                }
                if ($i >= $n) {
                    return "unterminated double-quoted string near line $line";
                }
                continue;
            }
            if ($c === '`') {
                ++$i;
                continue;
            }
            if (isset($pairs[$c])) {
                $stack[] = [$pairs[$c], $line];
            } elseif ($c === ')' || $c === '}' || $c === ']') {
                $top = array_pop($stack);
                if ($top === null || $top[0] !== $c) {
                    return "unbalanced $c at line $line";
                }
            }
        }

        return $stack === [] ? null : 'unclosed ' . $stack[count($stack) - 1][0] . ' opened at line ' . $stack[count($stack) - 1][1];
    }
}
