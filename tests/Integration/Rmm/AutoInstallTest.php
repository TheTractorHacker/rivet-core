<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\AutoInstall\AutoInstallService;
use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Deploy\ArgsTemplate;
use RivetCore\Rmm\Http\RmmRequest;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\DeployTestCase;
use RivetCore\Tests\Support\RmmHarness;

/** A request body that counts the bytes read from it (it serves endless NUL bytes). */
final class CountingStream
{
    public static int $read = 0;
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        self::$read += $count;

        return str_repeat("\0", min($count, 4096));
    }

    public function stream_eof(): bool
    {
        return self::$read > 4096;
    }

    /** @return array<string,int> */
    public function stream_stat(): array
    {
        return [];
    }
}

/** 1.0.0-rc.26: install apps when the agent installs. Policies, vendor parameters, ordering, idempotency, the rules around live installs, two-person, hosted files. */
final class AutoInstallTest extends DeployTestCase
{
    private const ACCT = 'ACCTsecret7712';
    private const ORG = 'ORGsecret9931';
    private const TPL = '/ACCT_ID={{account_key}} /ORG_KEY={{org_key}} /S';

    protected function makeHarness(): RmmHarness
    {
        $view = [RmmAbility::DEVICE_VIEW, RmmAbility::SOFTWARE_VIEW];
        $tech = [...$view, RmmAbility::SOFTWARE_MANAGE, RmmAbility::JOB_RUN_SAVED];

        return new RmmHarness(null, null, ['allow_linux' => true], false, null, new AllowUsersPolicy([
            1 => true,
            10 => [...$tech, RmmAbility::TOKEN_MANAGE],                                      // may issue installers and manage the catalog, but not deploy
            11 => [...$tech, RmmAbility::SOFTWARE_DEPLOY, RmmAbility::TOKEN_MANAGE],         // may also tick apps
            12 => [...$view, RmmAbility::SOFTWARE_DEPLOY, RmmAbility::JOB_APPROVE],
            13 => $view,
            16 => true,
        ]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('countingstream', stream_get_wrappers(), true)) {
            stream_wrapper_register('countingstream', CountingStream::class);
        }
        $this->assertSame([], $this->h->module->settings()->update(['service_url' => 'https://rmm.example.com']));
        $this->allowHost();
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string,mixed> $over */
    private function exe(int $pkg, string $tpl = self::TPL, array $over = []): int
    {
        [$c, $j] = $this->api('POST', ['software_packages', (string) $pkg, 'versions'], $this->admin, $over + ['version' => '1.0', 'os' => 'windows', 'fmt' => 'exe', 'url' => 'https://' . self::HOST . '/edr/setup.exe',
            'sha256' => self::SHA, 'size_bytes' => 4096, 'silent_args' => $tpl, 'detect_kind' => 'uninstall_name', 'detect_value' => 'Acme EDR']);
        $this->assertSame(201, $c, json_encode($j));

        return (int) $j['version_id'];
    }

    private function param(int $pkg, string $name, string $value, string $scope = 'global', int $id = 0, ?object $by = null): int
    {
        return $this->api('PUT', ['software_params', (string) $pkg], $by ?? $this->admin, ['name' => $name, 'value' => $value, 'scope' => $scope, 'scope_id' => $id])[0];
    }

    private function auto(int $pkg, array $in, ?object $by = null): array
    {
        return $this->api('PUT', ['software_autoinstall', (string) $pkg], $by ?? $this->admin, $in);
    }

    /** An exe package with the Huntress-shaped template and both parameters set. */
    private function edr(string $name = 'Acme EDR'): int
    {
        $p = $this->package($name);
        $this->exe($p);
        $this->assertSame(200, $this->param($p, 'account_key', self::ACCT));
        $this->assertSame(200, $this->param($p, 'org_key', self::ORG));

        return $p;
    }

    /** @return array{0:int,1:string} */
    private function enrollNew(string $name, ?string $plainToken = null, string $os = 'windows'): array
    {
        $this->h->asset(['name' => $name, 'serial' => "SER-$name", 'client_id' => $this->h->clientA]);
        $dev = $this->h::device(['serial' => "SER-$name", 'hostname' => $name, 'os' => $os]);
        [$c, , $j] = $this->h->enroll($plainToken ?? $this->h->token($this->h->clientA), $dev);
        $this->assertSame(201, $c);
        [$c] = $this->h->checkin((string) $j['device_token'], ['capabilities' => self::caps($os), 'platform' => $os]);
        $this->assertSame(200, $c);

        return [(int) $j['device_id'], (string) $j['device_token']];
    }

    /** @param list<int> $apps */
    private function installerToken(array $apps, ?object $by = null): string
    {
        if ((int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version = '1.0.0'") === 0) {
            $this->h->publishBinary('1.0.0', 'amd64', 8192, true);
        }
        $r = $this->h->module->admin()->deploymentCommands($by ?? $this->deployer, $this->h->clientA, 0, 'stable', 24, 5, 'tick', 'amd64', false, 'exe', $apps);

        return $r->ok ? (string) $r->data['token_plain'] : 'ERR:' . $r->message;
    }

    /** @return array<string,mixed>|null */
    private function jobOf(string $token, int $dev, string $type = 'software_install'): ?array
    {
        $jobs = array_values(array_filter($this->offered($token), static fn (array $j): bool => $j['type'] === $type));

        return $jobs[0] ?? null;
    }

    private function finish(string $token, array $job, string $result = 'installed'): void
    {
        $this->report($token, $job, in_array($result, ['installed', 'already_installed', 'would_install', 'needs_reboot'], true) ? 'succeeded' : 'failed',
            self::installOut($result, ['fmt' => 'exe', 'mode' => $job['params']['mode'] ?? 'run']), in_array($result, ['installed', 'would_install', 'needs_reboot'], true) ? 0 : 1);
    }

    /** @return list<array<string,mixed>> */
    private function apps(int $dev, ?object $who = null): array
    {
        [$c, $j] = $this->api('GET', [(string) $dev, 'autoinstall'], $who ?? $this->viewer);
        $this->assertSame(200, $c, json_encode($j));

        return $j['apps'];
    }

    private function dump(): string
    {
        $out = '';
        foreach (['endpoint_agent_jobs', 'rmm_job_extra', 'rmm_package_params', 'rmm_package_history', 'rmm_package_jobs', 'rmm_device_apps', 'rmm_package_versions', 'rmm_installer_apps', 'rmm_hosted_grants'] as $t) {
            $out .= json_encode($this->h->rows("SELECT * FROM `$t`")) . "\n";
        }

        return $out . json_encode($this->h->audit->records());
    }

    // ------------------------------------------------------------------ templates

    public function testTheArgumentTemplateIsTheAllowList(): void
    {
        $p = $this->package();
        $bad = ['a whole token' => '{{org_key}}', 'not after = or :' => '/ORG_KEY {{org_key}}', 'upper case name' => '/K={{Org}}', 'bad name chars' => '/K={{a-b}}', 'unclosed' => '/K={{org_key',
            'stray brace' => '/K={{a}} }', 'quote' => '/K="{{a}}"', 'shell' => '/K={{a}};whoami', 'too many words' => str_repeat('/x=1 ', 13), 'empty name' => '/K={{}}', 'nested' => '/K={{{{a}}}}',
            'nine placeholders' => '/A={{a}} /B={{b}} /C={{c}} /D={{d}} /E={{e}} /F={{f}} /G={{g}} /H={{h}} /I={{i}}'];
        foreach ($bad as $why => $tpl) {
            [$c] = $this->api('POST', ['software_packages', (string) $p, 'versions'], $this->admin, ['version' => '1.0', 'os' => 'windows', 'fmt' => 'exe', 'url' => 'https://' . self::HOST . '/s.exe',
                'sha256' => self::SHA, 'size_bytes' => 100, 'silent_args' => $tpl, 'detect_kind' => 'uninstall_name', 'detect_value' => 'X']);
            $this->assertSame(422, $c, $why);
        }
        $this->exe($p, '/S /v=1.2 --server={{server_url}}');
        $this->assertSame(['server_url'], $this->api('GET', ['software_params', (string) $p], $this->viewer)[1]['declared']);
        $this->assertSame(['a'], ArgsTemplate::names('/K={{a}} /L={{a}}'));
        $this->assertNull(ArgsTemplate::render('/K={{a}}', ['a' => 'a b']), 'a space in a value is refused');
        $this->assertNull(ArgsTemplate::render('/K={{a}}', ['a' => 'x"y']));
        $this->assertNull(ArgsTemplate::render('/K={{a}}', ['a' => 'x;y']));
        $this->assertNull(ArgsTemplate::render('/K={{a}}', ['a' => str_repeat('x', 129)]));
        $this->assertNull(ArgsTemplate::render('/K={{a}}', []));
        $this->assertNull(ArgsTemplate::render('/K={{a}}', ['a' => '{{b}}']), 'a value cannot carry a placeholder');
        $this->assertSame('/K=ab-1:2/3 /S', ArgsTemplate::render('/K={{a}} /S', ['a' => 'ab-1:2/3']));
    }

    public function testAParameterNeedsADeclaredNameAndAGoodValue(): void
    {
        $p = $this->edr();
        $this->assertSame(422, $this->param($p, 'not_declared', 'abcd'));
        foreach (['', 'has space', 'q"uote', 'semi;colon', 'new' . "\n" . 'line', '$(x)', '`x`', str_repeat('a', 129), 'back\\slash'] as $v) {
            $this->assertSame(422, $this->param($p, 'org_key', $v), json_encode($v));
        }
        $this->assertSame(404, $this->param($p, 'org_key', 'ok123', 'client', 0), 'a client scope needs a client');
        $this->assertSame(422, $this->param($p, 'org_key', 'ok123', 'global', 5), 'global has no id');
        $this->assertSame(422, $this->param($p, 'org_key', 'ok123', 'planet', 1));
        $this->assertSame(200, $this->param($p, 'org_key', 'ClientOrg55', 'client', $this->h->clientA));
    }

    // ------------------------------------------------------------------ sealing

    public function testVendorValuesAreSealedAndOnlyTheDeviceEverSeesThem(): void
    {
        $p = $this->edr();
        $this->assertSame(200, $this->param($p, 'org_key', 'CLIENTorg4488', 'client', $this->h->clientA), 'a client override wins over the global value');
        $this->assertSame(200, $this->auto($p, ['scope' => 'all'])[0]);
        [$dev, $tok] = $this->enrollNew('W-SEAL');
        $this->limits(['software_live_install' => 1]);
        $this->pass();
        // the job row, the sidecar, the ledger, the audit trail, the API: none holds a value in clear
        $row = $this->h->rows("SELECT params_json FROM endpoint_agent_jobs WHERE device_id = $dev AND type = 'software_install'");
        $this->assertCount(1, $row);
        $this->assertStringContainsString('[redacted:secret]', $row[0]['params_json']);
        $everything = $this->dump();
        foreach ([self::ACCT, 'CLIENTorg4488', self::ORG] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
            $this->assertStringNotContainsString(base64_encode($secret), $everything);
        }
        foreach ([['GET', ['software_params', (string) $p]], ['GET', ['software_autoinstall']], ['GET', ['software_packages', (string) $p]], ['GET', [(string) $dev, 'autoinstall']]] as [$m, $seg]) {
            $body = json_encode($this->api($m, $seg, $this->deployer)[1]);
            $this->assertStringNotContainsString(self::ACCT, $body);
            $this->assertStringNotContainsString('CLIENTorg4488', $body);
            $this->assertStringNotContainsString(self::ORG, $body);
        }
        // the device gets the real, rendered arguments
        $job = $this->jobOf($tok, $dev);
        $this->assertNotNull($job);
        $this->assertSame('/ACCT_ID=' . self::ACCT . ' /ORG_KEY=CLIENTorg4488 /S', $job['params']['args']);
        $this->assertSame('run', $job['params']['mode']);
        // an agent that echoes its arguments back does not put them into the stored output; the sealed copy is wiped
        $this->report($tok, $job, 'succeeded', self::installOut('installed', ['fmt' => 'exe', 'detail' => 'ran with ' . $job['params']['args']]));
        $this->assertStringNotContainsString(self::ACCT, $this->dump());
        $this->assertStringNotContainsString('CLIENTorg4488', $this->dump());
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM rmm_job_extra WHERE device_id = $dev AND secret_params_enc IS NOT NULL"), 'the sealed values are wiped with the final result');
        $this->assertSame('installed', $this->apps($dev)[0]['status']);
        // a sealed value that was stored is not the plaintext
        $this->assertStringNotContainsString(self::ACCT, (string) $this->h->one('SELECT GROUP_CONCAT(value_enc) FROM rmm_package_params'));
    }

    public function testMissingParametersAreNamedNotShown(): void
    {
        $p = $this->package('Half set');
        $this->exe($p);
        $this->assertSame(200, $this->param($p, 'account_key', self::ACCT));
        $this->assertSame(200, $this->auto($p, ['scope' => 'all'])[0]);
        [$dev] = $this->enrollNew('W-MISS');
        $this->limits(['software_live_install' => 1]);
        $this->pass();
        $a = $this->apps($dev)[0];
        $this->assertSame('failed', $a['status']);
        $this->assertStringContainsString('org_key', $a['reason']);
        $this->assertStringNotContainsString(self::ACCT, json_encode($a));
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id = $dev AND type = 'software_install'"));
        // and an installer cannot be issued with it ticked until the value exists
        $tok = $this->installerToken([$p]);
        $this->assertStringStartsWith('ERR:', $tok);
        $this->assertStringContainsString('org_key', $tok);
    }

    // ------------------------------------------------------------------ policy, order, idempotency

    public function testScopesDecideWhichNewDevicesGetWhat(): void
    {
        $all = $this->edr('All');
        $b = $this->edr('OnlyB');
        $lin = $this->package('Lin');
        $this->apt($lin, 'rivetit-dummy');
        $this->assertSame(200, $this->auto($all, ['scope' => 'all'])[0]);
        $this->assertSame(200, $this->auto($b, ['scope' => 'clients', 'clients' => [$this->h->clientB]])[0]);
        $this->assertSame(200, $this->auto($lin, ['scope' => 'os', 'os' => 'linux'])[0]);
        $this->assertSame(422, $this->auto($b, ['scope' => 'clients', 'clients' => [999999]])[0], 'a client must exist');
        $this->assertSame(422, $this->auto($b, ['scope' => 'os'])[0] === 200 ? 200 : 422, 'sanity: os scope needs os (already set on another package)');
        [$w] = $this->enrollNew('W-SCOPE');
        $this->assertSame(['All'], array_column($this->apps($w), 'name'), 'a Windows device of client A: the "all" app only');
        [$l] = $this->enrollNew('L-SCOPE', null, 'linux');
        $this->assertEqualsCanonicalizing(['All', 'Lin'], array_column($this->apps($l), 'name'));
        $sel = $this->api('GET', ['software_autoinstall', 'selectable'], $this->viewer, null, ['client_id' => (string) $this->h->clientA, 'os' => 'windows']);
        $this->assertSame(200, $sel[0]);
        $byName = array_column($sel[1]['apps'], null, 'name');
        $this->assertTrue($byName['All']['auto']);
        $this->assertFalse($byName['OnlyB']['auto']);
        $this->assertArrayNotHasKey('Lin', $byName, 'no Windows version');
        $selB = $this->api('GET', ['software_autoinstall', 'selectable'], $this->viewer, null, ['client_id' => (string) $this->h->clientB]);
        $this->assertTrue(array_column($selB[1]['apps'], null, 'name')['OnlyB']['auto']);
    }

    public function testAppsInstallOneAtATimeInTheOrderGivenAndARequiredFailureStopsTheRest(): void
    {
        $sec = $this->edr('Security');
        $bak = $this->edr('Backup');
        $mon = $this->edr('Monitor');
        $this->assertSame(200, $this->auto($bak, ['scope' => 'all', 'install_order' => 30])[0]);
        $this->assertSame(200, $this->auto($sec, ['scope' => 'all', 'install_order' => 10, 'required' => true])[0]);
        $this->assertSame(200, $this->auto($mon, ['scope' => 'all', 'install_order' => 20])[0]);
        $this->limits(['software_live_install' => 1]);
        [$dev, $tok] = $this->enrollNew('W-ORDER');
        $this->assertSame(['Security', 'Monitor', 'Backup'], array_column($this->apps($dev), 'name'));
        $seen = [];
        foreach (['Security', 'Monitor', 'Backup'] as $expected) {
            $this->pass();
            $jobs = array_values(array_filter($this->offered($tok), static fn (array $j): bool => $j['type'] === 'software_install'));
            $this->assertCount(1, $jobs, 'one install at a time');
            $seen[] = (string) $this->h->one("SELECT p.name FROM rmm_package_jobs j JOIN rmm_packages p ON p.package_id = j.package_id WHERE j.job_id = '{$jobs[0]['job_id']}'");
            $this->finish($tok, $jobs[0]);
        }
        $this->assertSame(['Security', 'Monitor', 'Backup'], $seen);
        $this->assertSame(['installed', 'installed', 'installed'], array_column($this->apps($dev), 'status'));

        // a required app that fails stops the later ones; a best-effort failure does not
        [$d2, $t2] = $this->enrollNew('W-REQ');
        $this->pass();
        $this->finish($t2, $this->jobOf($t2, $d2), 'failed');
        $this->pass();
        $st = array_column($this->apps($d2), null, 'name');
        $this->assertSame('failed', $st['Security']['status']);
        $this->assertSame('skipped', $st['Monitor']['status']);
        $this->assertStringContainsString('Security', $st['Monitor']['reason']);
        $this->assertSame('skipped', $st['Backup']['status']);
        $this->assertNull($this->jobOf($t2, $d2), 'nothing more is sent after a required failure');

        $this->assertSame(200, $this->auto($sec, ['required' => false])[0]);
        [$d3, $t3] = $this->enrollNew('W-BEST');
        $this->pass();
        $this->finish($t3, $this->jobOf($t3, $d3), 'failed');
        $this->pass();
        $this->assertNotNull($this->jobOf($t3, $d3), 'a best-effort app that failed does not stop the next one');
    }

    public function testReEnrollingOrPlanningAgainNeverInstallsTwice(): void
    {
        $p = $this->edr();
        $this->assertSame(200, $this->auto($p, ['scope' => 'all'])[0]);
        $this->limits(['software_live_install' => 1]);
        $this->h->asset(['name' => 'W-IDEM', 'serial' => 'SER-IDEM', 'client_id' => $this->h->clientA]);
        $dev = $this->h::device(['serial' => 'SER-IDEM', 'hostname' => 'W-IDEM']);
        $tokenStr = $this->h->token($this->h->clientA);
        [$c, , $j] = $this->h->enroll($tokenStr, $dev);
        $this->assertSame(201, $c);
        $id = (int) $j['device_id'];
        $this->h->checkin((string) $j['device_token'], ['capabilities' => self::caps(), 'platform' => 'windows']);
        $this->pass();
        $this->pass();
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id = $id AND type = 'software_install'"));
        [$c, , $j2] = $this->h->enroll($tokenStr, $dev);   // the same install id again: a re-enrollment
        $this->assertSame(201, $c);
        $this->assertSame($id, (int) $j2['device_id']);
        $this->assertSame(0, $this->h->module->autoInstall()->planDevice($this->h->module->devices()->find($id) ?? [], null, 'enrolled'), 'planning again plans nothing');
        $this->pass();
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id = $id AND type = 'software_install'"));
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM rmm_device_apps WHERE device_id = $id"));
        // a policy added later does not reach a device that is already enrolled
        $q = $this->edr('Later');
        $this->assertSame(200, $this->auto($q, ['scope' => 'all'])[0]);
        $this->h->enroll($tokenStr, $dev);
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM rmm_device_apps WHERE device_id = $id"), 'only a NEW device gets the policies');
    }

    public function testNothingHappensWhileTheSwitchIsOff(): void
    {
        $p = $this->edr();
        $this->assertSame(200, $this->auto($p, ['scope' => 'all'])[0]);
        $this->assertSame([], $this->h->module->settings()->update(['features_json' => ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true, 'software_deploy' => false]]));
        [$dev] = $this->enrollNew('W-OFF');
        $this->pass();
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM rmm_device_apps WHERE device_id = $dev"));
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id = $dev AND type = 'software_install'"));
        $this->assertStringStartsWith('ERR:', $this->installerToken([$p]), 'ticking apps is refused while deployment is off');
        $this->assertSame(409, $this->auto($p, ['scope' => 'off'])[0]);
        $this->assertSame(409, $this->param($p, 'org_key', 'zzzzzz'));
    }

    // ------------------------------------------------------------------ live-install rules, ticked apps, two-person

    public function testPolicyAppsAreDryRunsUntilLiveInstallsAreOnButATickedAppIsExplicitApproval(): void
    {
        $pol = $this->edr('Policy');
        $this->assertSame(200, $this->auto($pol, ['scope' => 'all'])[0]);
        $tick = $this->edr('Ticked');
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_package_auto WHERE package_id = ' . $tick));
        $this->assertSame(200, $this->auto($tick, ['scope' => 'off', 'install_order' => 5])[0], 'an order can be set before the policy is on');
        $plain = $this->installerToken([$tick]);
        $this->assertStringNotContainsString('ERR:', $plain);
        $this->assertStringContainsString('Installer Apps Chosen', json_encode($this->h->audit->records()));
        $this->assertStringContainsString('Deployer', json_encode($this->h->audit->records()), 'who ticked what is on record');
        [$dev, $tok] = $this->enrollNew('W-TICK', $plain);
        $names = array_column($this->apps($dev), null, 'name');
        $this->assertSame('chosen', $names['Ticked']['source']);
        $this->assertSame(11, $names['Ticked']['selected_by']);
        $this->assertSame('auto', $names['Policy']['source']);
        $this->pass();   // live installs are off (the default)
        $job = $this->jobOf($tok, $dev);
        $this->assertNotNull($job);
        $this->assertSame('run', $job['params']['mode'], 'the ticked app runs for real');
        $this->assertSame('Ticked', (string) $this->h->one("SELECT p.name FROM rmm_package_jobs j JOIN rmm_packages p ON p.package_id = j.package_id WHERE j.job_id = '{$job['job_id']}'"));
        $this->finish($tok, $job);
        $this->pass();
        $job2 = $this->jobOf($tok, $dev);
        $this->assertSame('dry_run', $job2['params']['mode'], 'the policy app is a dry run while software_live_install is 0');
        $this->finish($tok, $job2, 'would_install');
        $st = array_column($this->apps($dev), null, 'name');
        $this->assertSame('installed', $st['Ticked']['status']);
        $this->assertSame('skipped', $st['Policy']['status']);
        $this->assertStringContainsString('dry run', $st['Policy']['reason']);
    }

    public function testATickNeedsTheDeployAbilityAndAnExistingEnabledPackage(): void
    {
        $p = $this->edr();
        $this->assertStringStartsWith('ERR:', $this->installerToken([$p], $this->tech), 'manage without deploy cannot tick');
        $this->assertStringStartsWith('ERR:', $this->installerToken([999999]));
        $this->assertSame(200, $this->api('PATCH', ['software_packages', (string) $p], $this->admin, ['enabled' => false])[0]);
        $this->assertStringStartsWith('ERR:', $this->installerToken([$p]));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_installer_apps'));
    }

    public function testATwoPersonPackageStillWaitsForASecondPerson(): void
    {
        $p = $this->package('Two', true);
        $this->exe($p);
        $this->param($p, 'account_key', self::ACCT);
        $this->param($p, 'org_key', self::ORG);
        $plain = $this->installerToken([$p]);
        [$dev, $tok] = $this->enrollNew('W-2P', $plain);
        $this->pass();
        $this->assertNull($this->jobOf($tok, $dev), 'no job before the approval');
        $a = $this->apps($dev)[0];
        $this->assertSame('queued', $a['status']);
        $this->assertStringContainsString('second person', $a['reason']);
        $ap = (int) $this->h->one("SELECT approval_id FROM rmm_approvals WHERE kind = 'software' ORDER BY approval_id DESC LIMIT 1");
        $this->assertSame(11, (int) $this->h->one("SELECT requested_by FROM rmm_approvals WHERE approval_id = $ap"), 'asked in the name of the technician who ticked it');
        $this->pass();
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM rmm_approvals WHERE kind = 'software'"), 'asked once');
        [$c] = $this->api('POST', ['approvals', (string) $ap, 'approve'], $this->deployer);
        $this->assertSame(403, $c, 'nobody approves their own');
        [$c, $j] = $this->api('POST', ['approvals', (string) $ap, 'approve'], $this->second);
        $this->assertSame(200, $c, json_encode($j));
        $job = $this->jobOf($tok, $dev);
        $this->assertNotNull($job);
        $this->finish($tok, $job);
        $this->assertSame('installed', $this->apps($dev)[0]['status']);
        // a rejection ends the wait
        [$d2, $t2] = $this->enrollNew('W-2P-B', $this->installerToken([$p]));
        $this->pass();
        $ap2 = (int) $this->h->one("SELECT approval_id FROM rmm_approvals WHERE kind = 'software' ORDER BY approval_id DESC LIMIT 1");
        $this->assertSame(200, $this->api('POST', ['approvals', (string) $ap2, 'reject'], $this->second)[0]);
        $this->assertSame('skipped', $this->apps($d2)[0]['status']);
    }

    // ------------------------------------------------------------------ authorization

    /** rc.31 review F6: a client-restricted reader does not learn other clients' ids from the policy targets or the parameter scopes. */
    public function testAClientRestrictedReaderSeesOnlyTheirOwnClientsInPoliciesAndParameterScopes(): void
    {
        $p = $this->edr();
        $a = $this->h->clientA;
        $b = $this->h->clientB;
        $this->assertSame(200, $this->auto($p, ['scope' => 'clients', 'clients' => [['client_id' => $a, 'location_id' => 0], ['client_id' => $b, 'location_id' => 0]]], $this->deployer)[0]);
        $this->assertSame(200, $this->param($p, 'org_key', 'for-a', 'client', $a, $this->admin));
        $this->assertSame(200, $this->param($p, 'org_key', 'for-b', 'client', $b, $this->admin));
        $this->assertSame(200, $this->param($p, 'org_key', 'for-loc', 'location', 777, $this->admin));
        $find = fn (array $j): array => array_values(array_filter($j['packages'], static fn (array $x): bool => $x['package_id'] === $p))[0];
        // an unrestricted reader sees everything
        $all = $find($this->api('GET', ['software_autoinstall'], $this->viewer)[1]);
        $this->assertSame([$a, $b], array_column($all['auto']['clients'], 'client_id'));
        $this->assertEqualsCanonicalizing(['client:' . $a, 'client:' . $b, 'location:777'], array_map(static fn (array $r): string => $r['scope'] . ':' . $r['scope_id'], array_filter($all['params']['set'], static fn (array $r): bool => $r['name'] === 'org_key' && $r['scope'] !== 'global')));
        // the restricted reader sees client B only
        [$c, $j] = $this->api('GET', ['software_autoinstall'], $this->scoped);
        $this->assertSame(200, $c);
        $mine = $find($j);
        $this->assertSame([$b], array_column($mine['auto']['clients'], 'client_id'));
        $scopes = array_map(static fn (array $r): string => $r['scope'] . ':' . $r['scope_id'], $mine['params']['set']);
        $this->assertContains('client:' . $b, $scopes);
        $this->assertNotContains('client:' . $a, $scopes);
        $this->assertNotContains('location:777', $scopes, 'a location does not say which client it belongs to');
        $one = $this->api('GET', ['software_params', (string) $p], $this->scoped)[1];
        $this->assertNotContains('client:' . $a, array_map(static fn (array $r): string => $r['scope'] . ':' . $r['scope_id'], $one['set']));
    }

    public function testWhoMayDoWhat(): void
    {
        $p = $this->edr();
        $this->assertSame(403, $this->auto($p, ['scope' => 'all'], $this->tech)[0], 'manage cannot set an auto-install policy');
        $this->assertSame(403, $this->auto($p, ['scope' => 'all'], $this->viewer)[0]);
        $this->assertSame(403, $this->auto($p, ['scope' => 'all'], $this->scoped)[0], 'a client-restricted user cannot change what happens fleet-wide');
        $this->assertSame(200, $this->auto($p, ['scope' => 'all'], $this->deployer)[0]);
        $this->assertSame(403, $this->param($p, 'org_key', 'abcdef', 'global', 0, $this->viewer));
        $this->assertSame(403, $this->param($p, 'org_key', 'abcdef', 'global', 0, $this->scoped));
        $this->assertSame(200, $this->param($p, 'org_key', 'abcdef', 'global', 0, $this->tech));
        $this->assertSame(200, $this->param($p, 'org_key', 'bclient', 'client', $this->h->clientB, $this->scoped), 'a restricted user may set the value for their own client');
        $this->assertSame(404, $this->param($p, 'org_key', 'aclient', 'client', $this->h->clientA, $this->scoped), 'and not for another');
        $this->assertSame(200, $this->api('GET', ['software_autoinstall'], $this->viewer)[0]);
        [$dev] = $this->enrollNew('W-AUTHZ');
        $this->assertSame(404, $this->api('GET', [(string) $dev, 'autoinstall'], $this->scoped)[0], 'a device of another client is a plain 404');
        $this->assertSame(403, $this->api('GET', [(string) $dev, 'autoinstall'], new \RivetCore\Rmm\Authz\RmmPrincipal(99, 'Nobody'))[0]);
        $this->assertSame(422, $this->auto($p, ['scope' => 'everything'], $this->deployer)[0]);
        $this->assertSame(422, $this->auto($p, ['scope' => 'all', 'install_order' => 0], $this->deployer)[0]);
        $this->assertSame(422, $this->auto($p, ['scope' => 'all', 'surprise' => 1], $this->deployer)[0]);
    }

    // ------------------------------------------------------------------ hosted files

    private function upload(string $bytes, string $name = 'setup.exe'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ai_');
        file_put_contents($tmp, $bytes);
        try {
            $r = $this->h->module->autoInstallActions()->uploadFile($this->tech === null ? $this->admin : $this->admin, $tmp, $name);
        } finally {
            @unlink($tmp);
        }

        return [$r->http, $r->data];
    }

    /** rc.31 review F5: the upload route checks who may upload before it reads, let alone stores, one byte of the body. */
    public function testAnUploadByACallerWhoMayNotUploadIsRefusedWithoutReadingTheBody(): void
    {
        CountingStream::$read = 0;
        foreach ([[$this->viewer, 403], [$this->scoped, 403]] as [$who, $status]) {
            $stream = fopen('countingstream://body', 'rb');
            $req = new RmmRequest('POST', 'endpoint_devices', ['software_files'], [], ['x-filename' => 'setup.exe'], '127.0.0.1', 'test-agent', true, 50_000_000, $stream);
            $r = $this->h->module->autoInstallApi()->fleet($req, $who, ['software_files']);
            $this->assertSame($status, $r->status, (string) $r->body);
            $this->assertSame(0, CountingStream::$read, 'nothing was read from the request body');
        }
        $this->assertSame([], $this->h->module->hostedFiles()->files(), 'and nothing was stored');
        // a caller who may upload still gets the body read (whatever then becomes of it: here the stream is not an installer)
        $stream = fopen('countingstream://body', 'rb');
        $req = new RmmRequest('POST', 'endpoint_devices', ['software_files'], [], ['x-filename' => 'setup.exe'], '127.0.0.1', 'test-agent', true, 4096, $stream);
        $r = $this->h->module->autoInstallApi()->fleet($req, $this->admin, ['software_files']);
        $this->assertNotSame(403, $r->status, (string) $r->body);
        $this->assertGreaterThan(0, CountingStream::$read);
    }

    public function testAHostedInstallerIsServedOnlyToTheJobThatWasBuiltForIt(): void
    {
        $exe = "MZ" . random_bytes(5000);
        [$c, $f] = $this->upload($exe, '../../etc/passwd.exe');
        $this->assertSame(201, $c, json_encode($f));
        $this->assertSame(hash('sha256', $exe), $f['sha256']);
        $this->assertSame('passwd.exe', $f['filename'], 'the name is display only and cannot traverse');
        $this->assertSame(422, $this->upload('just text, not an installer ' . str_repeat('x', 2000))[0]);
        $this->assertSame(422, $this->upload('MZ')[0], 'too small');
        $p = $this->package('Hosted');
        [$c, $v] = $this->api('POST', ['software_packages', (string) $p, 'versions'], $this->admin, ['version' => '3.0', 'os' => 'windows', 'fmt' => 'exe', 'hosted_file_id' => $f['file_id'],
            'silent_args' => '/S', 'detect_kind' => 'uninstall_name', 'detect_value' => 'Hosted App']);
        $this->assertSame(201, $c, json_encode($v));
        $this->assertSame(hash('sha256', $exe), $v['sha256']);
        $this->assertSame('', $v['url']);
        $this->assertSame(422, $this->api('POST', ['software_packages', (string) $p, 'versions'], $this->admin, ['version' => '3.1', 'os' => 'windows', 'fmt' => 'exe', 'hosted_file_id' => $f['file_id'],
            'url' => 'https://' . self::HOST . '/x.exe', 'silent_args' => '/S', 'detect_kind' => 'uninstall_name', 'detect_value' => 'Hosted App'])[0], 'a hosted version has no address of its own');
        $this->assertSame(422, $this->api('POST', ['software_packages', (string) $p, 'versions'], $this->admin, ['version' => '3.2', 'os' => 'windows', 'fmt' => 'exe', 'hosted_file_id' => 99999, 'silent_args' => '/S',
            'detect_kind' => 'uninstall_name', 'detect_value' => 'X'])[0]);
        $this->assertSame(409, $this->api('DELETE', ['software_files', (string) $f['file_id']], $this->admin)[0], 'in use');
        $this->limits(['software_live_install' => 1]);
        $this->assertSame(200, $this->auto($p, ['scope' => 'all'])[0]);
        [$dev, $tok] = $this->enrollNew('W-HOST');
        $this->pass();
        $job = $this->jobOf($tok, $dev);
        $this->assertNotNull($job);
        $url = (string) $job['params']['url'];
        $this->assertMatchesRegularExpression('#^https://rmm\.example\.com/api/v1/agent_software/' . $job['job_id'] . '/[0-9a-f]{48}$#', $url);
        $secret = substr($url, -48);
        $this->assertStringNotContainsString($secret, $this->dump(), 'the secret in the address is sealed, only its hash is stored');
        $api = $this->h->module->hostedFileApi(static fn (): bool => true);
        $get = fn (array $seg, string $method = 'GET'): \RivetCore\Rmm\Http\RmmResponse => $api->handle(new RmmRequest($method, 'agent_software', $seg, [], [], '127.0.0.1', 'agent', true, null, null));
        $ok = $get([$job['job_id'], $secret]);
        $this->assertSame(200, $ok->status);
        $this->assertSame(hash('sha256', $exe), $ok->file->sha256 ?? hash_file('sha256', $ok->file->path ?? ''));
        foreach ([[$job['job_id'], str_repeat('0', 48)], [$job['job_id'], strtoupper($secret)], [$job['job_id'], '../' . substr($secret, 3)], ['../' . $job['job_id'], $secret],
            [JobUuid::other(), $secret], [$job['job_id']], [$job['job_id'], $secret, 'x']] as $seg) {
            $this->assertSame(404, $get($seg)->status, json_encode($seg));
        }
        $this->assertSame(405, $get([$job['job_id'], $secret], 'POST')->status);
        $insecure = new RmmRequest('GET', 'agent_software', [$job['job_id'], $secret], [], [], '127.0.0.1', 'agent', false, null, null);
        $strict = new \RivetCore\Rmm\Http\HostedFileApi($this->h->module->hostedFiles(), $this->h->module->settings(), static fn (): bool => true, false);
        $this->assertSame(426, $strict->handle($insecure)->status);
        $this->finish($tok, $job);
        $this->assertSame(404, $get([$job['job_id'], $secret])->status, 'the address stops working when the job is over');
        $this->assertGreaterThanOrEqual(1, $this->h->module->hostedFiles()->pruneGrants());
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM rmm_hosted_grants WHERE job_id = '{$job['job_id']}'"));
    }

    public function testAHostedFileThatChangedOnDiskIsNeverServed(): void
    {
        [, $f] = $this->upload('MZ' . random_bytes(3000));
        $p = $this->package('Hosted2');
        $this->api('POST', ['software_packages', (string) $p, 'versions'], $this->admin, ['version' => '1', 'os' => 'windows', 'fmt' => 'exe', 'hosted_file_id' => $f['file_id'], 'silent_args' => '/S', 'detect_kind' => 'uninstall_name', 'detect_value' => 'H2']);
        $this->limits(['software_live_install' => 1]);
        $this->auto($p, ['scope' => 'all']);
        [$dev, $tok] = $this->enrollNew('W-TAMPER');
        $this->pass();
        $job = $this->jobOf($tok, $dev);
        $secret = substr((string) $job['params']['url'], -48);
        $name = (string) $this->h->one('SELECT storage_name FROM rmm_hosted_files LIMIT 1');
        $path = $this->h->binaryDir . '/hosted/hosted_' . $name . '.bin';
        $this->assertFileExists($path);
        file_put_contents($path, 'MZ' . str_repeat('X', 3000));
        $api = $this->h->module->hostedFileApi(static fn (): bool => true);
        $this->assertSame(404, $api->handle(new RmmRequest('GET', 'agent_software', [$job['job_id'], $secret], [], [], '127.0.0.1', 'a', true, null, null))->status);
    }

    // ------------------------------------------------------------------ templates

    public function testStarterTemplatesAreDataOnlyAndGoThroughTheSameChecks(): void
    {
        [$c, $j] = $this->api('GET', ['software_templates'], $this->viewer);
        $this->assertSame(200, $c);
        $keys = array_column($j['templates'], 'key');
        $this->assertContains('huntress_agent', $keys);
        $this->assertContains('comet_backup_windows', $keys);
        $this->assertContains('custom_exe', $keys);
        foreach ($j['templates'] as $t) {
            $this->assertFalse($t['verified'], $t['key'] . ' is unverified');
            $this->assertStringContainsString('UNVERIFIED', implode(' ', $t['notes']));
            $this->assertSame(0, preg_match('#https?://[a-z0-9-]+\\.[a-z]{2,}#i', json_encode($t)), 'no vendor address is hard-coded');
            if ($t['fmt'] === 'exe') {
                $this->assertNull(ArgsTemplate::validate($t['silent_args']), $t['key'] . ' passes the argument rules');
            }
        }
        $in = ['name' => 'Huntress', 'version' => '2026.1', 'url' => 'https://' . self::HOST . '/huntress/setup.exe', 'sha256' => self::SHA, 'size_bytes' => 5000, 'detect_kind' => 'uninstall_name', 'detect_value' => 'Huntress Agent'];
        $this->assertSame(404, $this->api('POST', ['software_templates', 'nope'], $this->admin, $in)[0]);
        $this->assertSame(403, $this->api('POST', ['software_templates', 'huntress_agent'], $this->viewer, $in)[0]);
        $this->assertSame(422, $this->api('POST', ['software_templates', 'huntress_agent'], $this->admin, ['sha256' => 'bad'] + $in)[0]);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM rmm_packages WHERE name = 'Huntress'"), 'a refused version leaves no package behind');
        [$c, $r] = $this->api('POST', ['software_templates', 'huntress_agent'], $this->admin, $in);
        $this->assertSame(201, $c, json_encode($r));
        $this->assertSame(['account_key', 'org_key'], $r['parameters_to_set']);
        $this->assertSame('/ACCT_ID={{account_key}} /ORG_KEY={{org_key}} /S', $r['version']['silent_args']);
        $this->assertSame(['account_key', 'org_key'], $this->api('GET', ['software_params', (string) $r['package_id']], $this->viewer)[1]['declared']);
        // a package that is deleted takes its policy, parameters and plans with it
        $this->assertSame(200, $this->param((int) $r['package_id'], 'org_key', 'abcdef'));
        $this->assertSame(200, $this->auto((int) $r['package_id'], ['scope' => 'all'])[0]);
        $this->assertSame(200, $this->api('DELETE', ['software_packages', (string) $r['package_id']], $this->admin)[0]);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_package_params') + (int) $this->h->one('SELECT COUNT(*) FROM rmm_package_auto'));
    }

    // ------------------------------------------------------------------ migration

    public function testTheMigrationOnlyAddsEmptyTablesAndRunsTwice(): void
    {
        $names = array_keys(\RivetCore\Rmm\Migration\AutoInstallSchema::tables());
        $this->assertCount(8, $names);
        (new \RivetCore\Rmm\Migration\Migration0033AutoInstall())->up($this->h->db);
        foreach ($names as $n) {
            $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$n' AND ENGINE = 'InnoDB' AND TABLE_COLLATION = 'utf8mb4_general_ci'"), $n);
        }
        $this->assertSame(AutoInstallService::STATUSES, ['queued', 'installing', 'installed', 'failed', 'skipped']);
    }
}

/** A well-formed job id that is not any job. */
final class JobUuid
{
    public static function other(): string
    {
        return \RivetCore\Rmm\Job\JobService::uuid();
    }
}
