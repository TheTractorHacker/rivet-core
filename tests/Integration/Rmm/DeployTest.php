<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\RmmEvent;
use RivetCore\Tests\Support\DeployTestCase;

/** Phase 5: the package catalog, assignments, manual runs, results, approvals and the authorization of every route, end to end through the REST API and the device endpoints. */
final class DeployTest extends DeployTestCase
{
    private int $dev = 0;
    private string $tok = '';

    protected function setUp(): void
    {
        parent::setUp();
        [$this->dev, $this->tok] = $this->linked('W1');
    }

    private function d(): string
    {
        return (string) $this->dev;
    }

    // ------------------------------------------------------------------ catalog

    public function testAVersionIsCheckedWhenSavedAndNeverEditedAfterwards(): void
    {
        $pkg = $this->package();
        $ok = ['version' => '1.0.0', 'os' => 'windows', 'fmt' => 'msi', 'url' => 'https://' . self::HOST . '/a.msi', 'sha256' => self::SHA, 'size_bytes' => 100, 'product_code' => self::CODE];
        $post = fn (array $over, array $drop = []) => $this->api('POST', ['software_packages', (string) $pkg, 'versions'], $this->admin, array_diff_key($over + $ok, array_flip($drop)));
        $this->assertSame(422, $post([])[0], 'the host is not on the list yet');
        $this->allowHost();
        $bad = [
            'http url' => ['url' => 'http://' . self::HOST . '/a.msi'],
            'unlisted host' => ['url' => 'https://other.example.com/a.msi'],
            'ip host' => ['url' => 'https://127.0.0.1/a.msi'],
            'userinfo' => ['url' => 'https://x@' . self::HOST . '/a.msi'],
            'port' => ['url' => 'https://' . self::HOST . ':8443/a.msi'],
            'fragment' => ['url' => 'https://' . self::HOST . '/a.msi#x'],
            'query string (a token would be readable in every job record)' => ['url' => 'https://' . self::HOST . '/a.msi?sig=SECRET'],
            'lookalike' => ['url' => 'https://' . self::HOST . '.evil.example/a.msi'],
            'no hash' => [[], ['sha256']],
            'short hash' => ['sha256' => 'abc'],
            'non hex hash' => ['sha256' => str_repeat('g', 64)],
            'no size' => [[], ['size_bytes']],
            'huge size' => ['size_bytes' => 5000000000],
            'string size' => ['size_bytes' => '100'],
            'no product code' => [[], ['product_code']],
            'bad product code' => ['product_code' => '12345678-ABCD-1234-ABCD-1234567890AB'],
            'args on an msi' => ['silent_args' => '/S'],
            'package name on windows' => ['pkg_name' => 'x'],
            'unknown field' => ['extra' => 1],
            'apt on windows' => ['fmt' => 'apt'],
            'bad label' => ['version' => "1.0\n"],
        ];
        foreach ($bad as $why => $args) {
            $r = $post($args[0] ?? $args, $args[1] ?? []);
            $this->assertSame(422, $r[0], "$why: " . json_encode($r[1]));
        }
        [$c, $j] = $post([]);
        $this->assertSame(201, $c, json_encode($j));
        $vid = (int) $j['version_id'];
        $this->assertSame(strtoupper(self::CODE), $j['product_code']);
        $this->assertSame(422, $post([])[0], 'the same version again is refused: add a new one');
        // nothing but "retired" can change, through the API or at all
        $this->assertSame(422, $this->api('PATCH', ['software_packages', (string) $pkg, 'versions', (string) $vid], $this->admin, ['sha256' => str_repeat('b', 64)])[0]);
        $this->assertSame(422, $this->api('PATCH', ['software_packages', (string) $pkg, 'versions', (string) $vid], $this->admin, ['retired' => true, 'url' => 'https://' . self::HOST . '/b.msi'])[0]);
        $this->assertSame(200, $this->api('PATCH', ['software_packages', (string) $pkg, 'versions', (string) $vid], $this->admin, ['retired' => true])[0]);
        $this->assertSame(1, (int) $this->h->one("SELECT retired FROM rmm_package_versions WHERE version_id = $vid"));
        $this->assertSame(self::SHA, $this->h->one("SELECT sha256 FROM rmm_package_versions WHERE version_id = $vid"));
    }

    public function testExeNeedsADetectionRuleAndSafeArguments(): void
    {
        $pkg = $this->package('Exe thing');
        $this->allowHost();
        $exe = ['version' => '9.1', 'os' => 'windows', 'fmt' => 'exe', 'url' => 'https://' . self::HOST . '/s.exe', 'sha256' => self::SHA, 'size_bytes' => 5000, 'silent_args' => '/S /norestart',
            'detect_kind' => 'uninstall_name', 'detect_value' => 'Exe thing', 'detect_min' => '9.1'];
        $post = fn (array $over, array $drop = []) => $this->api('POST', ['software_packages', (string) $pkg, 'versions'], $this->admin, array_diff_key($over + $exe, array_flip($drop)));
        foreach ([['silent_args' => '/S "quoted"'], ['silent_args' => '/S & calc'], ['silent_args' => '/S; rm'], ['silent_args' => '/S|x'], ['silent_args' => '/S $(x)'], ['silent_args' => "/S\n/x"], ['silent_args' => '/S  /x'],
            ['silent_args' => implode(' ', array_fill(0, 13, '/x'))], ['silent_args' => str_repeat('a', 81)], ['product_code' => self::CODE], ['detect_kind' => 'package'], ['detect_kind' => 'nope']] as $over) {
            $this->assertSame(422, $post($over)[0], json_encode($over));
        }
        $this->assertSame(422, $post([], ['detect_kind', 'detect_value', 'detect_min'])[0], 'no detection rule');
        $this->assertSame(201, $post([])[0]);
        $this->assertSame(201, $post(['version' => '9.2', 'silent_args' => '', 'detect_kind' => 'product_code', 'detect_value' => self::CODE, 'detect_min' => ''])[0]);
    }

    public function testALinuxVersionNamesAPackageAndNothingElse(): void
    {
        $pkg = $this->package('Dummy');
        $post = fn (array $b) => $this->api('POST', ['software_packages', (string) $pkg, 'versions'], $this->admin, $b + ['version' => '1.5', 'os' => 'linux', 'fmt' => 'apt']);
        foreach ([[], ['pkg_name' => 'Bad Name'], ['pkg_name' => '-oAPT::Foo'], ['pkg_name' => 'a b'], ['pkg_name' => 'x;touch /w/pwned'], ['pkg_name' => '$(id)'], ['pkg_name' => "pkg\n"], ['pkg_name' => str_repeat('a', 101)],
            ['pkg_name' => 'ok', 'url' => 'https://' . self::HOST . '/x.deb'], ['pkg_name' => 'ok', 'sha256' => self::SHA], ['pkg_name' => 'ok', 'silent_args' => '/S'], ['pkg_name' => 'ok', 'fmt' => 'msi'],
            ['pkg_name' => 'ok', 'pin_version' => 'yes'], ['pkg_name' => 'ok', 'pin_version' => true, 'version' => 'has space']] as $b) {
            $this->assertSame(422, $post($b)[0], json_encode($b));
        }
        [$c, $j] = $post(['pkg_name' => 'rivetit-dummy', 'pin_version' => true]);
        $this->assertSame(201, $c, json_encode($j));
        $this->assertSame(['package', 'rivetit-dummy', '1.5'], [$j['detect_kind'], $j['detect_value'], $j['detect_min']]);
    }

    public function testRemovingAHostStopsNewJobsEvenForAnAssignedVersion(): void
    {
        $pkg = $this->package();
        $v = $this->msi($pkg);
        $this->announceCaps();
        [$c, $j] = $this->api('POST', [$this->d(), 'deployments', 'run'], $this->deployer, ['package_id' => $pkg]);
        $this->assertSame(202, $c, json_encode($j));
        $this->assertSame(200, $this->api('DELETE', ['software_hosts', self::HOST], $this->admin)[0]);
        $this->cancelAll();
        [$c, $j] = $this->api('POST', [$this->d(), 'deployments', 'run'], $this->deployer, ['package_id' => $pkg]);
        $this->assertSame(422, $c, json_encode($j));
        $this->assertStringContainsString('no longer passes', (string) $j['error']);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = 'software_install' AND state = 'queued'"));
        $this->assertGreaterThan(0, $v);
    }

    private function announceCaps(): void
    {
        $this->h->checkin($this->tok, ['capabilities' => self::caps('windows'), 'platform' => 'windows']);
    }

    private function cancelAll(): void
    {
        $this->h->q("UPDATE endpoint_agent_jobs SET state = 'cancelled' WHERE state = 'queued'");
    }

    // ------------------------------------------------------------------ switch and capability

    public function testWithTheSwitchOffReadsAnswerAndWritesAreRefused(): void
    {
        $this->switchOn([]);
        $this->assertFalse($this->api('GET', ['software_packages'], $this->viewer)[1]['enabled']);
        $this->assertFalse($this->api('GET', [$this->d(), 'deployments'], $this->viewer)[1]['enabled']);
        foreach ([['POST', ['software_packages'], ['name' => 'x']], ['POST', ['software_hosts'], ['host' => 'a.example.com']], ['POST', ['software_assignments'], ['package_id' => 1, 'scope_type' => 'global']],
            ['POST', [$this->d(), 'deployments', 'run'], ['package_id' => 1]], ['POST', [$this->d(), 'deployments', 'detect'], null]] as [$m, $seg, $body]) {
            [$c, $j] = $this->api($m, $seg, $this->admin, $body);
            $this->assertSame([409, 'feature_disabled'], [$c, $j['code']], implode('/', $seg));
        }
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_packages'));
        $r = $this->pass();
        $this->assertSame(0, array_sum($r), 'a pass with the switch off does nothing: ' . json_encode($r));
    }

    public function testAnAgentThatNeverAnnouncedSoftwareIsNeverOfferedAJob(): void
    {
        [$old, $oldTok] = $this->linked('OLD', null, 'windows', self::caps('windows', false));
        $pkg = $this->package();
        $this->msi($pkg);
        [$c, $j] = $this->api('POST', [(string) $old, 'deployments', 'run'], $this->deployer, ['package_id' => $pkg]);
        $this->assertSame([409, 'unsupported'], [$c, $j['code']]);
        $this->assertSame([], $this->offered($oldTok));
        // and the generic job endpoint refuses the types outright, for anyone
        foreach (['software_install', 'software_detect'] as $type) {
            [$c, $j] = $this->api('POST', [$this->d(), 'jobs'], $this->admin, ['type' => $type, 'params' => ['action' => 'install']]);
            $this->assertSame(422, $c, json_encode($j));
        }
    }

    // ------------------------------------------------------------------ one run

    public function testARunIsADryRunUnlessItSaysRunAndConfirmsAndTheJobCarriesOnlyCatalogValues(): void
    {
        $pkg = $this->package();
        $vid = $this->msi($pkg);
        [$c, $j] = $this->api('POST', [$this->d(), 'deployments', 'run'], $this->tech, ['package_id' => $pkg]);
        $this->assertSame([202, 'dry_run'], [$c, $j['mode']], json_encode($j));
        $job = $this->offeredOne($this->tok, 'software_install');
        $p = (array) $job['params'];
        $this->assertSame(['action' => 'install', 'mode' => 'dry_run', 'fmt' => 'msi', 'url' => 'https://' . self::HOST . '/acme/2.4.1/acme.msi', 'sha256' => self::SHA, 'size' => 1048576,
            'product_code' => strtoupper(self::CODE), 'detect_kind' => 'product_code', 'detect_value' => strtoupper(self::CODE), 'detect_min' => '2.4.1', 'max_minutes' => 30], $p);
        $this->assertNotEmpty($job['signature'] ?? null, 'the job is signed');
        $this->report($this->tok, $job, 'succeeded', self::installOut('would_install', ['mode' => 'dry_run', 'detail' => 'not_downloaded']));
        // a dry run changes no state
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_package_installs WHERE installed IS NOT NULL'));
        $this->assertSame('would_install', $this->h->one('SELECT result FROM rmm_package_history'));
        // a real run: the technician lacks the grant, the deployer must confirm, and extra body keys are not parameters
        $this->assertSame(403, $this->api('POST', [$this->d(), 'deployments', 'run'], $this->tech, ['package_id' => $pkg, 'mode' => 'run', 'confirm' => true])[0]);
        [$c, $j] = $this->api('POST', [$this->d(), 'deployments', 'run'], $this->deployer, ['package_id' => $pkg, 'mode' => 'run']);
        $this->assertSame([422, 'confirmation_required'], [$c, $j['code']]);
        [$c, $j] = $this->api('POST', [$this->d(), 'deployments', 'run'], $this->deployer, ['package_id' => $pkg, 'mode' => 'run', 'confirm' => true, 'url' => 'https://evil.example/x.msi', 'args' => '/x', 'sha256' => str_repeat('0', 64)]);
        $this->assertSame(202, $c, json_encode($j));
        $job = $this->offeredOne($this->tok, 'software_install');
        $this->assertSame(['run', 'https://' . self::HOST . '/acme/2.4.1/acme.msi', self::SHA], [((array) $job['params'])['mode'], ((array) $job['params'])['url'], ((array) $job['params'])['sha256']], 'a caller can not smuggle an address or a hash');
        $this->assertSame(11, (int) $this->h->one("SELECT created_by FROM rmm_package_jobs WHERE mode = 'run'"));
        $this->assertGreaterThan(0, $vid);
        // one install at a time per device
        [$c, $j] = $this->api('POST', [$this->d(), 'deployments', 'run'], $this->deployer, ['package_id' => $pkg, 'mode' => 'run', 'confirm' => true]);
        $this->assertSame([200, 'already_queued'], [$c, $j['skipped']]);
    }

    public function testTheResultOfARealInstallIsAppliedAndEventsAreEmitted(): void
    {
        $pkg = $this->package();
        $this->msi($pkg);
        $this->api('POST', [$this->d(), 'deployments', 'run'], $this->deployer, ['package_id' => $pkg, 'mode' => 'run', 'confirm' => true]);
        $job = $this->offeredOne($this->tok, 'software_install');
        $this->report($this->tok, $job, 'succeeded', self::installOut('installed', ['version' => '2.4.1', 'reboot_required' => true]));
        $row = $this->h->rows('SELECT * FROM rmm_package_installs')[0];
        $this->assertSame([1, '2.4.1', 1, 'installed', 0, 1], [(int) $row['installed'], $row['installed_version'], (int) $row['satisfied'], $row['last_result'], (int) $row['fail_count'], (int) $row['attempts']]);
        $st = $this->api('GET', [$this->d(), 'deployments'], $this->viewer)[1];
        $this->assertSame('installed', $st['packages'][0]['last_result']);
        $h = $this->api('GET', [$this->d(), 'deployments', 'history'], $this->viewer)[1];
        $this->assertSame(['installed', 'run', 'install'], [$h['rows'][0]['result'], $h['rows'][0]['mode'], $h['rows'][0]['action']]);
    }

    public function testAFailedResultCountsAsAFailureAndAnUnreadableOneIsAFailureToo(): void
    {
        $pkg = $this->package();
        $this->msi($pkg);
        foreach ([['failed', 'failed'], ['hash_mismatch', 'failed'], ['not-a-result', 'succeeded']] as $i => [$result, $state]) {
            $this->api('POST', [$this->d(), 'deployments', 'run'], $this->deployer, ['package_id' => $pkg, 'mode' => 'run', 'confirm' => true]);
            $job = $this->offeredOne($this->tok, 'software_install');
            $this->report($this->tok, $job, $state, $result === 'not-a-result' ? 'garbage {' : self::installOut($result, ['ok' => false, 'code' => 'exit_1603', 'detail' => "line1\nline2\x00"]), 1);
            $this->assertSame($i + 1, (int) $this->h->one('SELECT fail_count FROM rmm_package_installs'));
        }
        $this->assertSame(['failed', 'hash_mismatch', 'failed'], array_column($this->h->rows('SELECT result FROM rmm_package_history ORDER BY id'), 'result'));
        $this->assertStringNotContainsString("\n", (string) $this->h->one("SELECT detail FROM rmm_package_history WHERE result = 'hash_mismatch'"));
        $this->assertSame(0, (int) $this->h->one('SELECT COALESCE(installed, 0) FROM rmm_package_installs'));
    }

    public function testALinuxRunUsesThePackageNameAndPinsOnlyWhenAsked(): void
    {
        [$lin, $lt] = $this->linked('L1', null, 'linux', null, 'Ubuntu 24.04 LTS');
        $pkg = $this->package('Dummy');
        $this->apt($pkg, 'rivetit-dummy', '1.5', ['pin_version' => true]);
        $this->assertSame(202, $this->api('POST', [(string) $lin, 'deployments', 'run'], $this->deployer, ['package_id' => $pkg, 'mode' => 'run', 'confirm' => true])[0]);
        $this->assertSame(['action' => 'install', 'mode' => 'run', 'fmt' => 'apt', 'pkg' => 'rivetit-dummy', 'version' => '1.5', 'max_minutes' => 30], (array) $this->offeredOne($lt, 'software_install')['params']);
        $this->h->q("UPDATE endpoint_agent_jobs SET state = 'cancelled'");
        $this->assertSame(202, $this->api('POST', [(string) $lin, 'deployments', 'run'], $this->deployer, ['package_id' => $pkg, 'action' => 'uninstall', 'mode' => 'run', 'confirm' => true])[0]);
        $this->assertSame(['action' => 'uninstall', 'mode' => 'run', 'fmt' => 'apt', 'pkg' => 'rivetit-dummy', 'max_minutes' => 30], (array) $this->offeredOne($lt, 'software_install')['params']);
        // a Windows version is never sent to a Linux device and a dnf version never to an apt machine
        $p2 = $this->package('Win only');
        $this->msi($p2);
        [$c, $j] = $this->api('POST', [(string) $lin, 'deployments', 'run'], $this->deployer, ['package_id' => $p2]);
        $this->assertSame(422, $c, json_encode($j));
        $p3 = $this->package('Rpm only');
        $this->api('POST', ['software_packages', (string) $p3, 'versions'], $this->admin, ['version' => '1', 'os' => 'linux', 'fmt' => 'dnf', 'pkg_name' => 'rpm-thing']);
        $this->assertSame(422, $this->api('POST', [(string) $lin, 'deployments', 'run'], $this->deployer, ['package_id' => $p3])[0]);
    }

    public function testARetiredVersionCanBeRemovedButNeverInstalled(): void
    {
        $pkg = $this->package();
        $vid = $this->msi($pkg);
        $this->api('PATCH', ['software_packages', (string) $pkg, 'versions', (string) $vid], $this->admin, ['retired' => true]);
        $this->assertSame(422, $this->api('POST', [$this->d(), 'deployments', 'run'], $this->deployer, ['package_id' => $pkg, 'version_id' => $vid])[0]);
        $this->assertSame(202, $this->api('POST', [$this->d(), 'deployments', 'run'], $this->deployer, ['package_id' => $pkg, 'version_id' => $vid, 'action' => 'uninstall'])[0]);
        $this->assertSame(['action' => 'uninstall', 'mode' => 'dry_run', 'fmt' => 'msi', 'product_code' => strtoupper(self::CODE), 'max_minutes' => 30], (array) $this->offeredOne($this->tok, 'software_install')['params']);
    }

    // ------------------------------------------------------------------ detection

    public function testDetectionOnlyWritesToPackagesTheServerAskedAbout(): void
    {
        $p1 = $this->package('One');
        $p2 = $this->package('Two');
        $this->msi($p1);
        $this->msi($p2, '3.0', ['product_code' => '{AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA}']);
        $this->assign($p1);
        [$c, $j] = $this->api('POST', [$this->d(), 'deployments', 'detect'], $this->tech);
        $this->assertSame(202, $c, json_encode($j));
        $job = $this->offeredOne($this->tok, 'software_detect');
        $rules = json_decode((string) ((array) $job['params'])['rules'], true);
        $this->assertSame([['key' => "p$p1", 'kind' => 'product_code', 'value' => strtoupper(self::CODE), 'min' => '2.4.1']], $rules);
        $this->report($this->tok, $job, 'succeeded', self::detectOut([['key' => "p$p1", 'installed' => true, 'version' => '2.4.1', 'satisfied' => true], ['key' => "p$p2", 'installed' => true, 'version' => '9', 'satisfied' => true],
            ['key' => 'zz', 'installed' => true, 'version' => '1', 'satisfied' => true]]));
        $rows = $this->h->rows('SELECT package_id, installed, satisfied FROM rmm_package_installs ORDER BY package_id');
        $this->assertCount(1, $rows, 'the other package was never asked about, so nothing was written for it');
        $this->assertSame([1, 1], [(int) $rows[0]['installed'], (int) $rows[0]['satisfied']]);
    }

    // ------------------------------------------------------------------ authorization

    public function testEveryRouteHidesOtherClientsDevicesAndRefusesMissingGrants(): void
    {
        $pkg = $this->package();
        $this->msi($pkg);
        [$b, $bt] = $this->linked('B1', $this->h->clientB);
        // a user restricted to client B: client A's device is the same 404 as a missing one on every device route
        $routes = [['GET', [$this->d(), 'deployments'], null], ['GET', [$this->d(), 'deployments', 'history'], null], ['POST', [$this->d(), 'deployments', 'detect'], null],
            ['POST', [$this->d(), 'deployments', 'run'], ['package_id' => $pkg]]];
        foreach ($routes as [$m, $seg, $body]) {
            $this->assertSame(404, $this->api($m, $seg, $this->scoped, $body)[0], implode('/', $seg));
            $this->assertSame(404, $this->api($m, ['99999', ...array_slice($seg, 1)], $this->scoped, $body)[0], 'missing is the same');
        }
        $this->assertSame(200, $this->api('GET', [(string) $b, 'deployments'], $this->scoped)[0]);
        $this->assertSame(202, $this->api('POST', [(string) $b, 'deployments', 'run'], $this->scoped, ['package_id' => $pkg])[0]);
        // the catalog and the host list are fleet-wide: a restricted user reads them but cannot change them
        $this->assertSame(200, $this->api('GET', ['software_packages'], $this->scoped)[0]);
        foreach ([['POST', ['software_packages'], ['name' => 'x']], ['PUT', ['software_packages', (string) $pkg], ['name' => 'y']], ['DELETE', ['software_packages', (string) $pkg], null],
            ['POST', ['software_packages', (string) $pkg, 'versions'], ['version' => '1']], ['PATCH', ['software_packages', (string) $pkg, 'versions', '1'], ['retired' => true]],
            ['POST', ['software_hosts'], ['host' => 'a.example.com']], ['DELETE', ['software_hosts', self::HOST], null]] as [$m, $seg, $body]) {
            $this->assertSame(403, $this->api($m, $seg, $this->scoped, $body)[0], "$m " . implode('/', $seg));
        }
        // assignments: only to the caller's own clients and devices
        $this->assertSame(403, $this->assign($pkg, 'global', 0, 'install', null, $this->scoped)[0]);
        $this->assertSame(403, $this->assign($pkg, 'group', 1, 'install', null, $this->scoped)[0]);
        $this->assertSame(404, $this->assign($pkg, 'client', $this->h->clientA, 'install', null, $this->scoped)[0]);
        $this->assertSame(404, $this->assign($pkg, 'device', $this->dev, 'install', null, $this->scoped)[0]);
        $this->assertSame(201, $this->assign($pkg, 'client', $this->h->clientB, 'install', null, $this->scoped)[0]);
        $this->assertSame(201, $this->assign($pkg, 'device', $this->dev, 'install', null, $this->admin)[0]);
        $list = $this->api('GET', ['software_assignments'], $this->scoped)[1]['assignments'];
        $this->assertSame([['client', $this->h->clientB]], array_map(static fn (array $a): array => [$a['scope_type'], $a['scope_id']], $list), 'a restricted user sees only assignments of their own clients');
        $aid = (int) $this->h->one("SELECT assignment_id FROM rmm_package_assignments WHERE scope_type = 'device'");
        $this->assertSame(404, $this->api('DELETE', ['software_assignments', (string) $aid], $this->scoped)[0]);
        // grants: a viewer reads only, a technician cannot assign or run for real, and the host list is for administrators
        $this->assertSame(200, $this->api('GET', ['software_report'], $this->viewer)[0]);
        $this->assertSame(403, $this->api('POST', [$this->d(), 'deployments', 'detect'], $this->viewer)[0]);
        $this->assertSame(403, $this->api('POST', [$this->d(), 'deployments', 'run'], $this->viewer, ['package_id' => $pkg])[0]);
        $this->assertSame(201, $this->api('POST', ['software_packages'], $this->tech, ['name' => 'made by a technician with the manage grant'])[0]);
        $this->assertSame(403, $this->api('POST', ['software_packages'], $this->viewer, ['name' => 'viewer makes none'])[0]);
        $this->assertSame(403, $this->assign($pkg, 'global', 0, 'install', null, $this->tech)[0]);
        $this->assertSame(403, $this->api('POST', ['software_hosts'], $this->tech, ['host' => 'b.example.com'])[0]);
        $this->assertNotSame('', $bt);
    }

    // ------------------------------------------------------------------ two people

    public function testARealRunOfATwoPersonPackageWaitsForAnotherUser(): void
    {
        $pkg = $this->package('Careful', true);
        $this->msi($pkg);
        [$c, $j] = $this->api('POST', [$this->d(), 'deployments', 'run'], $this->deployer, ['package_id' => $pkg, 'mode' => 'run', 'confirm' => true]);
        $this->assertSame([202, 'pending_approval'], [$c, $j['state']], json_encode($j));
        $aid = (int) $j['approval_id'];
        $this->assertSame('software', $this->h->one("SELECT kind FROM rmm_approvals WHERE approval_id = $aid"));
        $this->assertSame([], $this->offered($this->tok), 'nothing is queued before the second person');
        $sa = $this->h->module->scriptActions();
        $this->assertFalse($sa->decide($this->deployer, $aid, true)->ok, 'not your own request');
        $this->assertSame('forbidden', $sa->decide($this->viewer, $aid, true)->code);
        $this->assertFalse($sa->decide($this->scoped, $aid, true)->ok, 'a user restricted to another client can not decide it');
        $r = $sa->decide($this->second, $aid, true);
        $this->assertTrue($r->ok, $r->message);
        $job = $this->offeredOne($this->tok, 'software_install');
        $this->assertSame('run', ((array) $job['params'])['mode']);
        $this->assertSame(11, (int) $this->h->one("SELECT created_by FROM rmm_package_jobs WHERE mode = 'run'"), 'the job is attributed to the person who asked');
        $this->assertFalse($sa->decide($this->second, $aid, true)->ok, 'deciding twice is refused');
        // a dry run never needs a second person
        $this->h->q("UPDATE endpoint_agent_jobs SET state = 'cancelled'");
        $this->assertSame(202, $this->api('POST', [$this->d(), 'deployments', 'run'], $this->tech, ['package_id' => $pkg])[0]);
    }

    public function testAnAssignmentOfATwoPersonPackageGoesLiveOnlyWhenApprovedAndRejectionDropsIt(): void
    {
        $pkg = $this->package('Careful', true);
        $this->msi($pkg);
        [$c, $j] = $this->assign($pkg, 'client', $this->h->clientA);
        $this->assertSame([202, 'pending_approval'], [$c, $j['state']], json_encode($j));
        $this->assertTrue($j['assignment']['waiting_for_approval']);
        $this->pass();
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_package_installs'), 'a waiting assignment expects nothing');
        $aid = (int) $j['approval_id'];
        $this->assertTrue($this->h->module->scriptActions()->decide($this->second, $aid, true)->ok);
        $this->pass();
        $this->assertSame('install', $this->h->one('SELECT expected_action FROM rmm_package_installs'));
        // a second one, rejected
        $p2 = $this->package('Other', true);
        $this->msi($p2, '1.0', ['product_code' => '{BBBBBBBB-BBBB-BBBB-BBBB-BBBBBBBBBBBB}']);
        $j = $this->assign($p2, 'client', $this->h->clientA)[1];
        $this->assertTrue($this->h->module->scriptActions()->decide($this->second, (int) $j['approval_id'], false)->ok);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM rmm_package_assignments WHERE package_id = $p2"));
    }

    public function testAPendingAssignmentStaysPendingWhenAskedAgainAndTheInstallerAddressIsHiddenFromReaders(): void
    {
        $pkg = $this->package('Careful', true);
        $this->msi($pkg);
        $first = $this->assign($pkg, 'client', $this->h->clientA);
        $again = $this->assign($pkg, 'client', $this->h->clientA);
        $this->assertSame([202, 202, $first[1]['approval_id']], [$first[0], $again[0], $again[1]['approval_id']], 'asking again does not make it live and does not open a second approval');
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM rmm_approvals WHERE kind = 'software'"));
        $this->assertSame(0, (int) $this->h->one('SELECT enabled FROM rmm_package_assignments'));
        $manager = $this->api('GET', ['software_packages', (string) $pkg], $this->admin)[1]['version_list'][0]['url'];
        $reader = $this->api('GET', ['software_packages', (string) $pkg], $this->viewer)[1]['version_list'][0]['url'];
        $this->assertSame('https://' . self::HOST . '/acme/2.4.1/acme.msi', $manager);
        $this->assertSame('https://' . self::HOST . '/...', $reader, 'a reader sees the host, not the path');
        $this->assertSame('https://' . self::HOST . '/...', $this->api('GET', ['software_packages', (string) $pkg], $this->scoped)[1]['version_list'][0]['url']);
    }

    public function testOnlyAnAdministratorCanLowerTheTwoPersonFlag(): void
    {
        $pkg = $this->package('Careful', true);
        $seg = ['software_packages', (string) $pkg];
        $this->assertSame(403, $this->api('PATCH', $seg, $this->tech, ['two_person' => false])[0]);
        $this->assertSame(1, (int) $this->h->one("SELECT two_person FROM rmm_packages WHERE package_id = $pkg"));
        $this->assertSame(200, $this->api('PATCH', $seg, $this->tech, ['description' => 'edited, flag untouched'])[0]);
        $this->assertSame(200, $this->api('PATCH', $seg, $this->tech, ['two_person' => true])[0]);
        $this->assertSame(200, $this->api('PATCH', $seg, $this->admin, ['two_person' => false])[0]);
        $this->assertSame(0, (int) $this->h->one("SELECT two_person FROM rmm_packages WHERE package_id = $pkg"));
        $this->assertSame(200, $this->api('PATCH', $seg, $this->tech, ['two_person' => true])[0], 'raising it is open to the catalog manager');
        $this->assertSame(1, (int) $this->h->one("SELECT two_person FROM rmm_packages WHERE package_id = $pkg"));
    }

    // ------------------------------------------------------------------ events and the rest

    public function testEventsCarryNoHashUrlOrOutput(): void
    {
        $this->assertContains(RmmEvent::DEPLOY_STARTED, RmmEvent::all());
        $this->assertContains(RmmEvent::DEPLOY_FINISHED, RmmEvent::all());
        $this->assertContains(RmmEvent::DEPLOY_FAILED, RmmEvent::all());
        foreach (['rmm.deploy.started', 'rmm.deploy.finished', 'rmm.deploy.failed'] as $id) {
            $this->assertTrue(\RivetCore\Webhooks\EventCatalog::has($id), $id);
        }
    }

    public function testDeletingAPackageNeedsItsAssignmentsGoneFirstAndRemovesItsVersions(): void
    {
        $pkg = $this->package();
        $this->msi($pkg);
        $aid = (int) $this->assign($pkg, 'global')[1]['assignment']['assignment_id'];
        $this->assertSame(409, $this->api('DELETE', ['software_packages', (string) $pkg], $this->admin)[0]);
        $this->assertSame(200, $this->api('DELETE', ['software_assignments', (string) $aid], $this->admin)[0]);
        $this->assertSame(200, $this->api('DELETE', ['software_packages', (string) $pkg], $this->admin)[0]);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_package_versions'));
        $this->assertSame(404, $this->api('DELETE', ['software_packages', (string) $pkg], $this->admin)[0]);
    }
}
