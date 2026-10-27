<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Scripts\ParamSchema;
use RivetCore\Rmm\Starter\StarterPack;
use RivetCore\Tests\Support\StarterDocker;

/**
 * The real Linux proof: every bash script of the starter pack is RUN, as root, in a throwaway ubuntu:24.04 container, with the same variable definitions the
 * agent puts in front of a script (the parameter defaults, so every changing script is a dry run). Each must exit 0 and print something. The scripts that
 * take a free string are also fed hostile values and must refuse them (exit 2) without running them. Nothing of Windows is executed anywhere.
 *
 * Needs docker; skipped without it. The second pass installs packages (slow, needs a network) and runs only with `RIVETCORE_STARTER_PROOF_TOOLS=1`. `RIVETCORE_STARTER_PROOF_REPORT=/path/report.json` writes the per-script result table.
 */
final class StarterLinuxProofTest extends TestCase
{
    private const IMAGE = 'ubuntu:24.04';

    /** Values for the parameters that have no default. */
    private const SAMPLE = ['target' => '127.0.0.1', 'cert_file' => '/etc/os-release', 'unit_name' => 'ssh.service'];

    private const HOSTILE = ['-x', '--help', 'a b', 'a;touch /tmp/pwned', '$(touch /tmp/pwned)', '`touch /tmp/pwned`', "a\nb", '../../etc/passwd', 'etc/passwd', 'a|b', "'", '"', '*', '%s', ''];

    private string $dir = '';

    protected function setUp(): void
    {
        if (!StarterDocker::image(self::IMAGE)) {
            $this->markTestSkipped('Docker (or the ubuntu image) is not available.');
        }
        $this->dir = StarterDocker::tempDir('proof');
        mkdir($this->dir . '/out');
    }

    protected function tearDown(): void
    {
        StarterDocker::rmTree($this->dir);
    }

    /** The lines the agent generates before a script ({@see \RivetCore\Rmm\Scripts\ParamSchema} validates, the agent defines). @param array<string,string|int|bool> $values */
    private static function prelude(array $values, array $schema): string
    {
        $types = array_column($schema, 'type', 'name');
        $out = '';
        foreach ($values as $name => $v) {
            $out .= in_array($types[$name], ['string', 'choice'], true) ? "$name=\"\${RIVETIT_PARAM_$name-}\"\n" : $name . '=' . (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v) . "\n";
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,string|int|bool> $values
     */
    private function stage(string $id, array $s, array $values): void
    {
        file_put_contents("{$this->dir}/$id.sh", self::prelude($values, $s['params']) . $s['body']);
        $env = '';
        foreach ($values as $k => $v) {
            $env .= "export RIVETIT_PARAM_$k=" . escapeshellarg(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v) . "\n";
        }
        file_put_contents("{$this->dir}/$id.env", $env);
    }

    /** @return array<string,array{rc:int,out:string}> */
    private function runAll(bool $withTools = false): array
    {
        file_put_contents($this->dir . '/run.sh', <<<'SH'
            #!/bin/bash
            for f in /w/*.sh; do
              id=$(basename "$f" .sh)
              [ "$id" = run ] && continue
              ( env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME=/root bash -c ". /w/$id.env; exec timeout 240 bash /w/$id.sh" > "/w/out/$id.out" 2>&1; echo $? > "/w/out/$id.rc" )
            done
            [ -e /tmp/pwned ] && echo "PWNED" > /w/out/_pwned
            exit 0
            SH);
        chmod($this->dir, 0777);
        chmod($this->dir . '/out', 0777);
        $cmd = ['bash', '/w/run.sh'];
        if ($withTools) {
            // The tools a real server has, so the scripts take their real paths. Exit 77 = the packages could not be installed (no network): skip.
            $cmd = ['bash', '-c', 'export DEBIAN_FRONTEND=noninteractive; apt-get update -qq >/dev/null 2>&1 && apt-get install -y -qq --no-install-recommends iproute2 procps iputils-ping dnsutils traceroute openssh-server openssl smartmontools cron ufw nftables net-tools sudo >/dev/null 2>&1 || exit 77; openssl req -x509 -newkey rsa:2048 -nodes -keyout /tmp/rivet-test-key.pem -out /tmp/rivet-test-cert.pem -days 60 -subj /CN=rivet-test >/dev/null 2>&1; exec bash /w/run.sh'];
        }
        [$code, $log] = StarterDocker::exec(array_merge(['docker', 'run', '--rm', '-v', "{$this->dir}:/w", self::IMAGE], $cmd), 1500);
        if ($withTools && $code === 77) {
            $this->markTestSkipped('The container could not install the common tools (no network).');
        }
        $this->assertSame(0, $code, $log);
        $this->assertFileDoesNotExist($this->dir . '/out/_pwned', 'a hostile value was executed');
        $res = [];
        foreach (glob($this->dir . '/out/*.rc') ?: [] as $rc) {
            $id = basename($rc, '.rc');
            $res[$id] = ['rc' => (int) trim((string) file_get_contents($rc)), 'out' => (string) file_get_contents($this->dir . "/out/$id.out")];
        }

        return $res;
    }

    public function testEveryLinuxScriptRunsCleanlyWithItsDefaults(): void
    {
        $this->proveAll(false, 'RIVETCORE_STARTER_PROOF_REPORT');
    }

    /** The same run in a container that has ss, ip, ping, dig, traceroute, sshd, openssl, smartctl, cron, ufw and nft, so the real code paths run. */
    public function testEveryLinuxScriptRunsCleanlyWithCommonToolsInstalled(): void
    {
        $this->proveAll(true, 'RIVETCORE_STARTER_PROOF_REPORT_TOOLS');
    }

    private function proveAll(bool $withTools, string $reportEnv): void
    {
        if ($withTools && !getenv('RIVETCORE_STARTER_PROOF_TOOLS')) {
            $this->markTestSkipped('Set RIVETCORE_STARTER_PROOF_TOOLS=1 to run the pass that installs packages in the container (about 3 minutes).');
        }
        $scripts = [];
        foreach (StarterPack::bundled()->scripts() as $s) {
            if ($s['language'] !== 'bash') {
                continue;
            }
            $given = [];
            foreach ($s['params'] as $p) {
                if (!array_key_exists('default', $p) && isset(self::SAMPLE[$p['name']])) {
                    $given[$p['name']] = $withTools && $p['name'] === 'cert_file' ? '/tmp/rivet-test-cert.pem' : self::SAMPLE[$p['name']];
                }
            }
            [$values, , $err] = ParamSchema::validate($s['params'], $given);
            $this->assertNull($err, $s['id'] . ': ' . $err);
            /** @var array<string,string|int|bool> $values */
            $this->stage($s['id'], $s, $values);
            $scripts[$s['id']] = $s;
        }
        $this->assertGreaterThanOrEqual(25, count($scripts));

        $res = $this->runAll($withTools);
        $report = [];
        foreach ($scripts as $id => $s) {
            $this->assertArrayHasKey($id, $res, "$id did not run");
            $r = $res[$id];
            $report[$id] = ['risk' => $s['risk'], 'exit' => $r['rc'], 'output_bytes' => strlen($r['out']), 'not_available' => str_contains($r['out'], 'NOT AVAILABLE'), 'dry_run' => str_contains($r['out'], '[DRY-RUN]')];
            $this->assertSame(0, $r['rc'], "$id exited {$r['rc']}:\n" . substr($r['out'], 0, 1500));
            $this->assertNotSame('', trim($r['out']), "$id printed nothing");
            $this->assertStringStartsWith('==', ltrim($r['out']), "$id should start with its title line");
            $this->assertLessThan(60000, strlen($r['out']), "$id printed too much");
            $this->assertDoesNotMatchRegularExpression('/command not found|syntax error|unbound variable/i', $r['out'], "$id leaked a shell error:\n" . substr($r['out'], 0, 1500));
            if ($s['risk'] !== 'read_only') {
                $this->assertMatchesRegularExpression('/\[DRY-RUN\]|NOT AVAILABLE|\[SKIP\]/', $r['out'], "$id changes the machine, so by default it may only report or skip:\n" . substr($r['out'], 0, 800));
                $this->assertStringNotContainsString('[DONE]', $r['out'], "$id acted on a dry run");
            }
        }
        if (($path = getenv($reportEnv)) !== false && $path !== '') {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT));
        }
    }

    public function testStringParametersRefuseHostileValuesWithoutRunningThem(): void
    {
        $staged = 0;
        $ids = [];
        foreach (StarterPack::bundled()->scripts() as $s) {
            if ($s['language'] !== 'bash') {
                continue;
            }
            foreach ($s['params'] as $p) {
                if ($p['type'] !== 'string') {
                    continue;
                }
                foreach (self::HOSTILE as $i => $bad) {
                    if ($bad === '' && !empty($p['required']) === false) {
                        continue;   // empty = "not supplied": the default applies
                    }
                    $values = [];
                    foreach ($s['params'] as $q) {
                        if ($q['name'] === $p['name']) {
                            $values[$q['name']] = $bad;
                        } elseif (array_key_exists('default', $q)) {
                            $values[$q['name']] = $q['default'];
                        } elseif (isset(self::SAMPLE[$q['name']])) {
                            $values[$q['name']] = self::SAMPLE[$q['name']];
                        }
                    }
                    $id = $s['id'] . '__' . $p['name'] . '__' . $i;
                    $this->stage($id, $s, $values);
                    $ids[$id] = [$s['id'], $bad];
                    ++$staged;
                }
            }
        }
        $this->assertGreaterThan(40, $staged, 'there are string parameters to attack');
        $res = $this->runAll();
        foreach ($ids as $id => [$script, $bad]) {
            $this->assertArrayHasKey($id, $res);
            $this->assertSame(2, $res[$id]['rc'], "$script accepted the hostile value " . json_encode($bad) . " (exit {$res[$id]['rc']}):\n" . substr($res[$id]['out'], 0, 600));
        }
    }

    public function testAGoodStringValueIsAccepted(): void
    {
        $s = StarterPack::bundled()->script('lin-network-diagnostics');
        $this->assertNotNull($s);
        $this->stage('good', $s, ['target' => 'localhost', 'count' => 1]);
        $res = $this->runAll();
        $this->assertSame(0, $res['good']['rc'], $res['good']['out']);
        $this->assertStringContainsString('localhost', $res['good']['out']);
    }
}
