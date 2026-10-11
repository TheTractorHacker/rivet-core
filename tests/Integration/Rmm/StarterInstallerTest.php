<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Starter\StarterInstaller;
use RivetCore\Rmm\Starter\StarterPack;
use RivetCore\Tests\Support\AutomationTestCase;
use RivetCore\Tests\Support\StarterDocker;

/** Installing the starter pack: golden counts, idempotence, admin edits, drafts, selection, uninstall, the REST routes and who may use them. */
final class StarterInstallerTest extends AutomationTestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        StarterPack::forget();
        foreach ($this->temp as $d) {
            StarterDocker::rmTree($d);
        }
        parent::tearDown();
    }

    private function installer(?StarterPack $pack = null): StarterInstaller
    {
        $m = $this->h->module;

        return new StarterInstaller($m->sql(), $m->scripts(), $m->policyStore(), $m->settings(), $pack === null ? null : [$pack]);
    }

    /**
     * A copy of the bundled pack as version $version with $edit applied to its files (and a rebuilt manifest).
     *
     * @param callable(string):void $edit gets the folder
     */
    private function nextPack(string $version, callable $edit): StarterPack
    {
        $dir = StarterDocker::tempDir('pack');
        $this->temp[] = $dir;
        $src = StarterPack::bundled()->directory();
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $f) {
            $rel = substr($f->getPathname(), strlen($src) + 1);
            $f->isDir() ? @mkdir("$dir/$rel", 0755, true) : copy($f->getPathname(), "$dir/$rel");
        }
        $pj = json_decode((string) file_get_contents("$dir/pack.json"), true);
        $pj['version'] = $version;
        file_put_contents("$dir/pack.json", json_encode($pj));
        $edit($dir);
        file_put_contents("$dir/manifest.json", json_encode(StarterPack::buildManifest($dir)));
        StarterPack::forget();

        return StarterPack::fromDirectory($dir);
    }

    private function scriptOf(string $itemId): int
    {
        return (int) $this->h->one("SELECT script_id FROM rmm_script_versions WHERE note LIKE 'starter|rivet-core-starter|$itemId|%' ORDER BY version LIMIT 1");
    }

    private function scriptId(string $name): int
    {
        return (int) $this->h->one("SELECT script_id FROM rmm_scripts_v2 WHERE name = '" . addslashes($name) . "'");
    }

    // ------------------------------------------------------------------ golden install

    public function testTheCatalogStartsEmptyAndThePlanWritesNothing(): void
    {
        $inst = $this->installer();
        $cat = $inst->catalog();
        $this->assertTrue($cat['ready']);
        $this->assertSame(73, $cat['totals']['scripts']);
        $this->assertSame(0, $cat['totals']['installed']);
        $this->assertSame('not_installed', $cat['packs'][0]['scripts'][0]['state']);

        $plan = $inst->plan([]);
        $this->assertSame(['add' => 76], $plan['counts']);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_scripts_v2'));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policies'));
        $this->assertArrayNotHasKey('_item', $plan['items'][0]);
        $this->assertArrayNotHasKey('body', $plan['items'][0]);
    }

    public function testInstallPutsSignedScriptsAndDisabledUnassignedDraftsIntoTheInstance(): void
    {
        $r = $this->h->module->starterActions()->install($this->admin, []);
        $this->assertTrue($r->ok, $r->message);
        $this->assertSame(['added' => 76], $r->data['counts']);

        $svc = $this->h->module->scripts();
        $this->assertSame(73, (int) $this->h->one('SELECT COUNT(*) FROM rmm_scripts_v2'));
        $byId = [];
        foreach (StarterPack::bundled()->scripts() as $s) {
            $byId[$s['id']] = $s;
        }
        foreach ($this->h->rows('SELECT * FROM rmm_scripts_v2') as $row) {
            [$loaded, $err] = $svc->load((int) $row['script_id']);
            $this->assertNull($err, $row['name'] . ': ' . $err);
            $note = (string) $this->h->one("SELECT note FROM rmm_script_versions WHERE script_id = {$row['script_id']} AND version = 1");
            $this->assertMatchesRegularExpression('/^starter\|rivet-core-starter\|[a-z0-9-]+\|1\.0\.0\|[0-9a-f]{64}$/', $note);
            $item = $byId[explode('|', $note)[2]];
            $tags = json_decode((string) $row['tags_json'], true);
            $this->assertContains('starter', $tags);
            $this->assertContains('risk:' . $item['risk'], $tags);
            $this->assertSame($item['name'], $row['name']);
            $this->assertSame((int) $item['requires_approval'], (int) $row['requires_approval'], $item['id']);
            $this->assertSame((int) $item['destructive'], (int) $row['destructive'], $item['id']);
            $this->assertSame($item['platform'], $row['platform']);
            $this->assertSame($item['body'], $loaded['version']['body']);
            $this->assertSame((int) $item['timeout_s'], (int) $row['timeout_s']);
            $this->assertNull($row['retired_at']);
        }
        // a few concrete rules
        $disruptive = $svc->get($this->scriptOf('win-restart-service'));
        $this->assertTrue($disruptive['requires_approval']);
        $this->assertTrue($disruptive['destructive']);
        $reader = $svc->get($this->scriptId('System information summary (Windows)'));
        $this->assertFalse($reader['requires_approval']);

        // drafts
        $this->assertSame(3, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policies'));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policies WHERE enabled = 1'), 'drafts are switched off');
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policy_assignments'), 'and assigned to nothing');
        foreach ($this->h->module->policyStore()->all() as $p) {
            $this->assertStringStartsWith('Starter: ', $p['name']);
            $this->assertMatchesRegularExpression('/ \[starter\|rivet-core-starter\|policy-[a-z-]+\|1\.0\.0\|[0-9a-f]{64}\]$/', $p['description']);
            $this->assertArrayHasKey('check.starter.disk_free', $p['settings']);
        }

        // audit and event
        $audit = array_column($this->h->audit->records(), 'action');
        $this->assertSame(76, count(array_filter($audit, static fn (string $a): bool => $a === 'Starter Pack Item Added')));
        $this->assertContains('Starter Pack Installed', $audit);
        foreach ($this->h->audit->records() as $rec) {
            $this->assertStringNotContainsString('echo ', $rec['description'], 'no script text in the activity log');
        }
        $ev = $this->bus->of(RmmEvent::STARTER_INSTALLED);
        $this->assertCount(1, $ev);
        $this->assertSame(76, $ev[0]['added']);
    }

    public function testInstallingTwiceChangesNothing(): void
    {
        $inst = $this->installer();
        $inst->install([], 1);
        $versions = (int) $this->h->one('SELECT COUNT(*) FROM rmm_script_versions');
        $policyVersions = (int) $this->h->one('SELECT COUNT(*) FROM rmm_policy_versions');
        $again = $inst->install([], 1);
        $this->assertSame(['unchanged' => 76], $again['counts']);
        $this->assertSame($versions, (int) $this->h->one('SELECT COUNT(*) FROM rmm_script_versions'));
        $this->assertSame($policyVersions, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policy_versions'));
        $this->assertSame(73, (int) $this->h->one('SELECT COUNT(*) FROM rmm_scripts_v2'));
        $this->assertSame(['unchanged' => 76], $inst->plan([])['counts']);
        $cat = $inst->catalog();
        $this->assertSame(73, $cat['totals']['installed']);
        $states = array_unique(array_column($cat['packs'][0]['scripts'], 'state'));
        $this->assertSame(['current'], $states);
    }

    // ------------------------------------------------------------------ selection

    public function testSelectiveInstallByPlatformRiskTagAndId(): void
    {
        $inst = $this->installer();
        $linuxRead = $inst->plan(['platform' => 'linux', 'risk' => 'read_only']);
        $this->assertGreaterThanOrEqual(20, $linuxRead['counts']['add']);
        foreach ($linuxRead['items'] as $i) {
            $this->assertSame(['script', 'linux', 'read_only'], [$i['kind'], $i['platform'], $i['risk']]);
        }
        $disruptive = $inst->plan(['risk' => ['disruptive']]);
        $this->assertSame(['script'], array_values(array_unique(array_column($disruptive['items'], 'kind'))), 'a risk filter is about scripts');
        $tag = $inst->plan(['tags' => ['print']]);
        $this->assertNotEmpty($tag['items']);
        $one = $inst->install(['ids' => ['win-flush-dns', 'policy-linux-server']], 1);
        $this->assertSame(['added' => 2], $one['counts']);
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_scripts_v2'));
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policies'));
        $win = $inst->plan(['platform' => 'windows', 'kinds' => ['policies']]);
        $this->assertSame(['policy-workstation', 'policy-windows-server'], array_column($win['items'], 'id'), 'the two Windows drafts');
    }

    public function testABadSelectionIsRefused(): void
    {
        $inst = $this->installer();
        foreach ([['nope' => 1], ['ids' => ['does-not-exist']], ['platform' => 'mac'], ['risk' => 'scary'], ['pack' => 'other'], ['kinds' => ['widgets']], ['params' => [1, 2]]] as $bad) {
            try {
                $inst->plan($bad);
                $this->fail('expected a refusal for ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ updates and admin edits

    public function testAnUpdateReplacesAnUntouchedScriptButParksItNextToAnEditedOne(): void
    {
        $inst = $this->installer();
        $inst->install(['ids' => ['lin-system-summary', 'lin-disk-usage', 'lin-inode-usage']], 1);
        $svc = $this->h->module->scripts();
        $edited = $this->scriptOf('lin-system-summary');
        $svc->update($edited, ['body' => "#!/usr/bin/env bash\necho 'my own version'\n", 'note' => 'local tweak'], 1);
        $untouched = $this->scriptOf('lin-disk-usage');

        $v2 = $this->nextPack('1.1.0', static function (string $dir): void {
            file_put_contents("$dir/scripts/linux/system-summary.sh", "\necho 'pack v2'\n", FILE_APPEND);
            file_put_contents("$dir/scripts/linux/disk-usage.sh", "\necho 'pack v2'\n", FILE_APPEND);
        });
        $upd = $this->installer($v2);
        $plan = $upd->plan(['ids' => ['lin-system-summary', 'lin-disk-usage', 'lin-inode-usage']]);
        $actions = array_column($plan['items'], 'action', 'id');
        $this->assertEquals(['lin-system-summary' => 'stage', 'lin-disk-usage' => 'update', 'lin-inode-usage' => 'unchanged'], $actions);

        $r = $upd->install(['ids' => ['lin-system-summary', 'lin-disk-usage', 'lin-inode-usage']], 1);
        $this->assertSame(['staged' => 1, 'unchanged' => 1, 'updated' => 1], $r['counts']);

        // the edited script still runs the administrator's text; the pack's text waits as a flagged, non-current version
        $this->assertSame(2, (int) $svc->get($edited)['current_version']);
        $this->assertStringContainsString('my own version', (string) $svc->version($edited, 2)['body']);
        $parked = $svc->version($edited, 3);
        $this->assertNotNull($parked);
        $this->assertStringContainsString("echo 'pack v2'", $parked['body']);
        $this->assertStringStartsWith('starter-updated|rivet-core-starter|lin-system-summary|1.1.0|', $parked['note']);
        [$ok, $err] = $svc->load($edited, 3);
        $this->assertNull($err, 'the parked version is signed like any other');
        $this->assertNotNull($ok);
        // the untouched one moved to the new version
        $this->assertSame(2, (int) $svc->get($untouched)['current_version']);
        $this->assertStringContainsString("echo 'pack v2'", (string) $svc->version($untouched, 2)['body']);

        // running it again changes nothing and says why
        $again = $upd->plan(['ids' => ['lin-system-summary', 'lin-disk-usage']]);
        $this->assertEquals(['lin-system-summary' => 'skip', 'lin-disk-usage' => 'unchanged'], array_column($again['items'], 'action', 'id'));
        $this->assertStringContainsString('already parked as version 3', array_column($again['items'], 'reason', 'id')['lin-system-summary']);
        $before = (int) $this->h->one('SELECT COUNT(*) FROM rmm_script_versions');
        $upd->install(['ids' => ['lin-system-summary', 'lin-disk-usage']], 1);
        $this->assertSame($before, (int) $this->h->one('SELECT COUNT(*) FROM rmm_script_versions'));

        // an administrator can still edit afterwards (version numbers do not collide with the parked one)
        $svc->update($edited, ['body' => "#!/usr/bin/env bash\necho 'edited again'\n"], 1);
        $this->assertSame(4, (int) $svc->get($edited)['current_version']);
    }

    public function testEditingOnlyTheFlagsOrTheDescriptionDoesNotCountAsAnEditOfTheText(): void
    {
        $inst = $this->installer();
        $inst->install(['ids' => ['lin-cron-jobs']], 1);
        $id = $this->scriptOf('lin-cron-jobs');
        $this->h->module->scripts()->update($id, ['requires_approval' => true, 'description' => 'Ours.', 'timeout_s' => 90], 1);
        $v2 = $this->nextPack('1.0.1', static function (string $dir): void {
            file_put_contents("$dir/scripts/linux/cron-jobs.sh", "\necho more\n", FILE_APPEND);
        });
        $r = $this->installer($v2)->install(['ids' => ['lin-cron-jobs']], 1);
        $this->assertSame(['updated' => 1], $r['counts']);
        $row = $this->h->module->scripts()->get($id);
        $this->assertTrue($row['requires_approval'], 'the administrator\'s approval flag survives an update');
        $this->assertSame('Ours.', $row['description']);
        $this->assertSame(90, $row['timeout_s']);
    }

    public function testARetiredStarterScriptStaysRetiredAndANameClashGetsASuffix(): void
    {
        $inst = $this->installer();
        $inst->install(['ids' => ['win-flush-dns']], 1);
        $id = $this->scriptOf('win-flush-dns');
        $this->assertGreaterThan(0, $id);
        $this->h->module->scripts()->setRetired($id, true);
        $again = $inst->install(['ids' => ['win-flush-dns']], 1);
        $this->assertSame(['skipped' => 1], $again['counts']);
        $this->assertStringContainsString('retired', $again['items'][0]['message']);
        $this->assertSame('retired', $inst->catalog(['q' => 'flush'])['packs'][0]['scripts'][0]['state']);

        // an administrator already has a script with the name of another item
        $this->h->module->scripts()->create(['name' => 'Free space by volume (Windows)', 'language' => 'powershell', 'body' => "Get-Volume\n"], 1);
        $r = $inst->install(['ids' => ['win-free-space-volumes']], 1);
        $this->assertSame(['added' => 1], $r['counts']);
        $this->assertGreaterThan(0, $this->scriptId('Free space by volume (Windows) (starter)'));
        $this->assertSame(['unchanged' => 1], $inst->install(['ids' => ['win-free-space-volumes']], 1)['counts'], 'and it is found again by its marker, not by name');
    }

    public function testWithoutASigningKeyScriptsAreBlockedButNothingIsHalfWritten(): void
    {
        $this->h->q("UPDATE endpoint_agent_settings SET signing_public_key = '' WHERE id = 1");
        $this->h->module->settings()->refresh();
        $inst = $this->installer();
        $plan = $inst->plan(['ids' => ['win-flush-dns', 'policy-workstation']]);
        $this->assertFalse($plan['ready']);
        $this->assertStringContainsString('signing key', (string) $plan['blocker']);
        $this->assertSame(['win-flush-dns' => 'blocked', 'policy-workstation' => 'add'], array_column($plan['items'], 'action', 'id'));
        $r = $inst->install(['ids' => ['win-flush-dns', 'policy-workstation']], 1);
        $this->assertSame(['added' => 1, 'blocked' => 1], $r['counts']);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_scripts_v2'));
    }

    // ------------------------------------------------------------------ policy drafts

    public function testPolicyParametersDecideTheOptionalChecksAndADraftFollowsThem(): void
    {
        $inst = $this->installer();
        $plan = $inst->plan(['ids' => ['policy-windows-server']]);
        $this->assertSame(['starter.backup_service'], $plan['items'][0]['omitted']);
        $inst->install(['ids' => ['policy-windows-server']], 1);
        $p = $this->h->module->policyStore()->all()[0];
        $this->assertArrayNotHasKey('check.starter.backup_service', $p['settings']);

        $with = ['ids' => ['policy-windows-server'], 'params' => ['backup_service' => 'VeeamEndpointBackupSvc']];
        $this->assertSame(['update' => 1], $inst->plan($with)['counts'], 'other parameter values on an untouched, off, unassigned draft');
        $this->assertSame(['updated' => 1], $inst->install($with, 1)['counts']);
        $p = $this->h->module->policyStore()->all()[0];
        $this->assertSame('VeeamEndpointBackupSvc', $p['settings']['check.starter.backup_service']['value']['params']['name']);
        $this->assertSame(['unchanged' => 1], $inst->install($with, 1)['counts']);

        $bad = $inst->plan(['ids' => ['policy-linux-server'], 'policy_params' => ['policy-linux-server' => ['cert_paths' => ['relative/path']]]]);
        $this->assertSame('blocked', $bad['items'][0]['action']);
    }

    public function testADraftThatIsEditedEnabledOrAssignedIsNotOverwritten(): void
    {
        $store = $this->h->module->policyStore();
        $inst = $this->installer();
        $inst->install(['kinds' => ['policies']], 1);
        $byName = array_column($store->all(), null, 'name');
        $ws = $byName['Starter: Workstation baseline'];
        $srv = $byName['Starter: Windows Server baseline'];
        $lin = $byName['Starter: Linux Server baseline'];

        // the administrator edits one, switches on and assigns another, leaves the third alone
        $settings = $ws['settings'];
        $settings['check.starter.disk_free']['value']['params']['warn_free_pct'] = 30;
        $store->update((int) $ws['policy_id'], ['settings' => $settings], 1);
        $store->update((int) $srv['policy_id'], ['enabled' => true], 1);
        $store->assign((int) $srv['policy_id'], ['scope_type' => 'global'], 1);

        $v2 = $this->nextPack('1.1.0', static function (string $dir): void {
            $c = json_decode((string) file_get_contents("$dir/checks.json"), true);
            foreach ($c['sets'] as &$set) {
                foreach ($set['checks'] as &$ck) {
                    if ($ck['key'] === 'starter.disk_free') {
                        $ck['params']['warn_free_pct'] = 25;
                    }
                }
            }
            file_put_contents("$dir/checks.json", json_encode($c));
        });
        $upd = $this->installer($v2);
        $actions = array_column($upd->plan(['kinds' => ['policies']])['items'], 'action', 'id');
        $this->assertSame(['policy-workstation' => 'skip', 'policy-windows-server' => 'skip', 'policy-linux-server' => 'update'], $actions);
        $r = $upd->install(['kinds' => ['policies']], 1);
        $this->assertSame(['skipped' => 2, 'updated' => 1], $r['counts']);
        $now = array_column($store->all(), null, 'name');
        $this->assertSame(30, $now['Starter: Workstation baseline']['settings']['check.starter.disk_free']['value']['params']['warn_free_pct']);
        $this->assertSame(25, $now['Starter: Linux Server baseline']['settings']['check.starter.disk_free']['value']['params']['warn_free_pct']);
        $this->assertTrue($now['Starter: Windows Server baseline']['enabled']);
        $this->assertSame(2, (int) $store->find((int) $lin['policy_id'])['version'], 'a new policy version, the old body is kept');
    }

    // ------------------------------------------------------------------ uninstall

    public function testUninstallRetiresUntouchedScriptsAndDeletesUntouchedDraftsOnly(): void
    {
        $inst = $this->installer();
        $inst->install(['ids' => ['lin-cron-jobs', 'lin-disk-usage', 'policy-workstation', 'policy-linux-server']], 1);
        $svc = $this->h->module->scripts();
        $svc->update($this->scriptOf('lin-cron-jobs'), ['body' => "#!/bin/bash\necho mine\n"], 1);
        $store = $this->h->module->policyStore();
        $ws = array_column($store->all(), null, 'name')['Starter: Workstation baseline'];
        $store->update((int) $ws['policy_id'], ['enabled' => true], 1);

        $preview = $inst->uninstall(['ids' => ['lin-cron-jobs', 'lin-disk-usage', 'policy-workstation', 'policy-linux-server']], 1, true);
        $this->assertSame(['skipped' => 2, 'would_delete' => 1, 'would_retire' => 1], $preview['counts']);
        $this->assertSame(2, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policies'));

        $r = $this->h->module->starterActions()->uninstall($this->admin, ['ids' => ['lin-cron-jobs', 'lin-disk-usage', 'policy-workstation', 'policy-linux-server']]);
        $this->assertTrue($r->ok);
        $this->assertSame(['deleted' => 1, 'retired' => 1, 'skipped' => 2], $r->data['counts']);
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_scripts_v2 WHERE retired_at IS NOT NULL'));
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policies'));
        $this->assertContains('Starter Pack Item Retired', array_column($this->h->audit->records(), 'action'));
        $this->assertCount(1, $this->bus->of(RmmEvent::STARTER_INSTALLED));
    }

    // ------------------------------------------------------------------ REST routes and abilities

    public function testTheRoutesAndWhoMayUseThem(): void
    {
        [$c, $j] = $this->h->tech('GET', ['starter', 'catalog'], $this->admin);
        $this->assertSame(200, $c);
        $this->assertSame(73, $j['totals']['scripts']);
        $this->assertArrayNotHasKey('body', $j['packs'][0]['scripts'][0], 'the catalog carries no script text');

        [$c, $j] = $this->h->tech('GET', ['starter', 'catalog'], $this->admin, null, ['platform' => 'linux', 'risk' => 'disruptive,changes_system']);
        $this->assertSame(200, $c);
        $this->assertGreaterThan(0, $j['totals']['scripts']);
        foreach ($j['packs'][0]['scripts'] as $s) {
            $this->assertSame('linux', $s['platform']);
            $this->assertNotSame('read_only', $s['risk']);
        }

        [$c, $j] = $this->h->tech('POST', ['starter', 'plan'], $this->admin, ['platform' => 'windows', 'risk' => 'read_only']);
        $this->assertSame(200, $c);
        $this->assertGreaterThan(20, $j['counts']['add']);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_scripts_v2'));

        [$c, $j] = $this->h->tech('POST', ['starter', 'install'], $this->admin, ['ids' => ['win-flush-dns']]);
        $this->assertSame(200, $c);
        $this->assertSame(['added' => 1], $j['counts']);
        $this->assertGreaterThan(0, $j['items'][0]['script_id']);

        [$c, $j] = $this->h->tech('POST', ['starter', 'uninstall'], $this->admin, ['ids' => ['win-flush-dns'], 'dry_run' => true]);
        $this->assertSame(['would_retire' => 1], $j['counts']);

        [$c, $j] = $this->h->tech('POST', ['starter', 'install'], $this->admin, ['ids' => ['nope']]);
        $this->assertSame([422, 'invalid'], [$c, $j['code']]);

        // everyone below administrator is refused, on every route
        foreach ([$this->tech, $this->approver, $this->viewer, $this->scoped] as $who) {
            foreach ([['GET', 'catalog'], ['POST', 'plan'], ['POST', 'install'], ['POST', 'uninstall']] as [$m, $what]) {
                [$c, $j] = $this->h->tech($m, ['starter', $what], $who, $m === 'POST' ? [] : null);
                $this->assertSame([403, 'forbidden'], [$c, $j['code']], "{$who->userName} $m $what");
            }
        }
        [$c] = $this->h->tech('GET', ['starter', 'nope'], $this->admin);
        $this->assertSame(404, $c);
        [$c] = $this->h->tech('DELETE', ['starter', 'plan'], $this->admin);
        $this->assertSame(405, $c);
        [$c] = $this->h->tech('GET', ['starter', 'catalog'], null);
        $this->assertSame(401, $c);
    }
}
