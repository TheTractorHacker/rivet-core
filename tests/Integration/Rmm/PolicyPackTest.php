<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Policy\StarterPack\PolicyPack;
use RivetCore\Rmm\Policy\StarterPack\PolicyPackInstaller;
use RivetCore\Tests\Support\AutomationTestCase;

/** The policy starter pack on a scratch database: install, idempotence, edit protection, update, reset, uninstall, REST, abilities, audit. */
final class PolicyPackTest extends AutomationTestCase
{
    private function inst(?PolicyPack $p = null): PolicyPackInstaller
    {
        $m = $this->h->module;

        return new PolicyPackInstaller($m->policyStore(), $m->patchPolicies(), $m->maintenance(), $m->changeSettings(), $p);
    }

    /** A copy of the bundled pack as the next release: rev + 1 for the given item, with a changed summary. */
    private function nextPack(string $id): PolicyPack
    {
        $m = require __DIR__ . '/../../../src/Rmm/Policy/StarterPack/data/pack.php';
        $m['version'] = '1.0.1';
        foreach ($m['items'] as &$i) {
            if ($i['id'] === $id) {
                ++$i['rev'];
                $i['summary'] .= ' Updated.';
            }
        }

        return new PolicyPack($m);
    }

    private function item(array $cat, string $id): array
    {
        foreach ($cat['items'] as $i) {
            if ($i['id'] === $id) {
                return $i;
            }
        }
        $this->fail("no item $id");
    }

    public function testInstallCreatesEverythingSwitchedOffAndUnassigned(): void
    {
        $n = count(PolicyPack::bundled()->items());
        $this->assertSame(0, $this->inst()->catalog()['counts']['installed']);
        $r = $this->inst()->run('install', [], 1);
        $this->assertSame(['added' => $n - 1, 'blocked' => 1], $r['counts'], 'the instance-wide item waits for a confirmation');
        $this->assertSame($n - 1, $this->inst()->catalog()['counts']['installed']);

        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policies WHERE enabled = 1'));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policy_assignments'));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_patch_policies WHERE enabled = 1'));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_patch_assignments'));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_maintenance_windows WHERE enabled = 1'));
        $this->assertSame(3, (int) $this->h->one('SELECT COUNT(*) FROM rmm_maintenance_windows'));
        $this->assertSame(4, (int) $this->h->one('SELECT COUNT(*) FROM rmm_patch_policies'));
        $this->assertSame(['windows' => [], 'linux' => []], ['windows' => $this->h->module->changeSettings()->enabledPresets('windows'), 'linux' => $this->h->module->changeSettings()->enabledPresets('linux')]);
        // the windows time zone is the installer's parameter, the stored description carries the marker
        $this->assertStringContainsString('[pp|windows-server|1|', (string) $this->h->one("SELECT description FROM rmm_policies WHERE name = 'Pack: Windows server baseline'"));
    }

    public function testInstallingTwiceChangesNothing(): void
    {
        $this->inst()->run('install', [], 1);
        $snap = fn (): string => md5(json_encode([$this->h->mysqli->query('SELECT * FROM rmm_policies ORDER BY policy_id')->fetch_all(), $this->h->mysqli->query('SELECT * FROM rmm_policy_versions ORDER BY policy_id, version')->fetch_all(),
            $this->h->mysqli->query('SELECT * FROM rmm_patch_policies ORDER BY policy_id')->fetch_all(), $this->h->mysqli->query('SELECT * FROM rmm_maintenance_windows ORDER BY window_id')->fetch_all()]));
        $before = $snap();
        $r = $this->inst()->run('install', [], 1);
        $this->assertSame($before, $snap());
        $this->assertArrayNotHasKey('added', $r['counts']);
        $this->assertArrayNotHasKey('updated', $this->inst()->run('update', [], 1)['counts']);
    }

    public function testAnEditedItemIsNeverOverwrittenButResetTakesThePackVersionBack(): void
    {
        $this->inst()->run('install', ['ids' => ['windows-server', 'patch-pilot', 'mw-nightly']], 1);
        $pid = (int) $this->h->one("SELECT policy_id FROM rmm_policies WHERE name = 'Pack: Windows server baseline'");
        $before = (string) $this->h->one("SELECT body_json FROM rmm_policies WHERE policy_id = $pid");
        $edit = $this->h->module->policyStore()->find($pid);
        $s = (array) $edit['settings'];
        $s['check.starter.disk_free']['value']['params']['warn_free_pct'] = 30;
        $this->h->module->policyStore()->update($pid, ['settings' => $s], 1);
        $this->h->module->patchPolicies()->save((int) $this->h->one("SELECT policy_id FROM rmm_patch_policies WHERE name LIKE 'Pack patch: Pilot%'"), ['name' => 'Pack patch: Pilot (about 10 % of devices, next day)', 'description' => (string) $this->h->one("SELECT description FROM rmm_patch_policies WHERE name LIKE 'Pack patch: Pilot%'"), 'settings' => ['max_minutes' => 30]], 1);
        $this->h->module->maintenance()->update((int) $this->h->one("SELECT window_id FROM rmm_maintenance_windows WHERE name LIKE '%Nightly%'"), ['duration_min' => 90]);

        $cat = $this->inst()->catalog();
        foreach (['windows-server', 'patch-pilot', 'mw-nightly'] as $id) {
            $this->assertSame('edited', $this->item($cat, $id)['state'], $id);
        }
        // install and update leave it alone
        $after = (string) $this->h->one("SELECT body_json FROM rmm_policies WHERE policy_id = $pid");
        $this->assertNotSame($before, $after);
        $this->assertSame(['unchanged' => 3], $this->inst()->run('install', ['ids' => ['windows-server', 'patch-pilot', 'mw-nightly']], 1)['counts']);
        $this->assertSame(['skipped' => 3], $this->inst()->run('update', ['ids' => ['windows-server', 'patch-pilot', 'mw-nightly']], 1)['counts']);
        $this->assertSame(['skipped' => 3], $this->inst()->run('uninstall', ['ids' => ['windows-server', 'patch-pilot', 'mw-nightly']], 1)['counts']);
        $this->assertSame($after, (string) $this->h->one("SELECT body_json FROM rmm_policies WHERE policy_id = $pid"));

        // reset
        $this->assertSame(['reset' => 3], $this->inst()->run('reset', ['ids' => ['windows-server', 'patch-pilot', 'mw-nightly']], 1)['counts']);
        $this->assertSame($before, (string) $this->h->one("SELECT body_json FROM rmm_policies WHERE policy_id = $pid"));
        $cat = $this->inst()->catalog();
        foreach (['windows-server', 'patch-pilot', 'mw-nightly'] as $id) {
            $this->assertSame('installed', $this->item($cat, $id)['state'], $id);
        }
        $this->assertSame(120, (int) $this->h->one('SELECT duration_min FROM rmm_maintenance_windows WHERE name LIKE \'%Nightly%\''));
    }

    public function testRenamingOrChangingTheDescriptionCountsAsAnEditAndEnablingDoesNot(): void
    {
        $this->inst()->run('install', ['ids' => ['minimal', 'laptop']], 1);
        $a = (int) $this->h->one("SELECT policy_id FROM rmm_policies WHERE name = 'Pack: Minimal / low noise'");
        $b = (int) $this->h->one("SELECT policy_id FROM rmm_policies WHERE name = 'Pack: Laptop add-on'");
        $this->h->module->policyStore()->update($a, ['enabled' => true], 1);
        $this->h->module->policyStore()->update($b, ['name' => 'My laptop policy'], 1);
        $cat = $this->inst()->catalog();
        $this->assertSame('installed', $this->item($cat, 'minimal')['state']);
        $this->assertTrue($this->item($cat, 'minimal')['enabled']);
        $this->assertSame('edited', $this->item($cat, 'laptop')['state']);
    }

    public function testUpdateOnlyReachesUntouchedItemsThatAreNotLive(): void
    {
        $this->inst()->run('install', ['ids' => ['minimal', 'laptop', 'alert-quiet']], 1);
        $next = $this->nextPack('minimal');
        $m = $this->h->module;
        $cat = $this->inst($next)->catalog();
        $this->assertSame('update_available', $this->item($cat, 'minimal')['state']);
        $this->assertSame('installed', $this->item($cat, 'laptop')['state']);

        // live (enabled) is not updated without include_live
        $pid = (int) $this->h->one("SELECT policy_id FROM rmm_policies WHERE name = 'Pack: Minimal / low noise'");
        $m->policyStore()->update($pid, ['enabled' => true], 1);
        $r = $this->inst($next)->run('update', ['ids' => ['minimal']], 1);
        $this->assertSame(['skipped' => 1], $r['counts']);
        $this->assertStringContainsString('In use', $r['items'][0]['reason']);
        $r = $this->inst($next)->run('update', ['ids' => ['minimal'], 'include_live' => true], 1);
        $this->assertSame(['updated' => 1], $r['counts']);
        $this->assertStringContainsString('Updated.', (string) $this->h->one("SELECT description FROM rmm_policies WHERE policy_id = $pid"));
        $this->assertSame(1, (int) $this->h->one("SELECT enabled FROM rmm_policies WHERE policy_id = $pid"), 'the switch is kept');
        $this->assertSame('installed', $this->item($this->inst($next)->catalog(), 'minimal')['state']);

        // an untouched, off item updates
        $next2 = $this->nextPack('laptop');
        $this->assertSame(['updated' => 1], $this->inst($next2)->run('update', ['ids' => ['laptop']], 1)['counts']);
    }

    public function testUninstallRemovesOnlyUntouchedUnassignedItems(): void
    {
        $this->inst()->run('install', [], 1);
        $edited = (int) $this->h->one("SELECT policy_id FROM rmm_policies WHERE name = 'Pack: Windows workstation baseline'");
        $this->h->module->policyStore()->update($edited, ['description' => 'mine'], 1);
        $assigned = (int) $this->h->one("SELECT policy_id FROM rmm_policies WHERE name = 'Pack: Laptop add-on'");
        $this->h->module->policyStore()->assign($assigned, ['scope_type' => 'global'], 1);
        $foreign = $this->h->module->policyStore()->create(['name' => 'My own policy', 'settings' => []], 1);

        $dry = $this->inst()->run('uninstall', [], 1, true);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM rmm_policies WHERE name = 'x'"));
        $this->assertArrayHasKey('removed', $dry['counts']);
        $total = (int) $this->h->one('SELECT COUNT(*) FROM rmm_policies');

        $r = $this->inst()->run('uninstall', [], 1);
        $this->assertSame(2, $r['counts']['skipped'] - 1, 'edited and assigned stay (the change preset is skipped too)');
        $this->assertSame(2, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policies WHERE description NOT LIKE \'%[pp|%\''), 'the edited one and my own policy remain, no other');
        $this->assertNotNull($this->h->module->policyStore()->find($edited));
        $this->assertNotNull($this->h->module->policyStore()->find($assigned));
        $this->assertNotNull($this->h->module->policyStore()->find((int) $foreign['policy_id']));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_patch_policies'));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_maintenance_windows'));
        $this->assertLessThan($total, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policies'));
    }

    public function testAForeignPolicyWithTheSameNameBlocksTheInstall(): void
    {
        $this->h->module->policyStore()->create(['name' => 'Pack: Linux server baseline', 'settings' => []], 1);
        $r = $this->inst()->run('install', ['ids' => ['linux-server']], 1);
        $this->assertSame(['blocked' => 1], $r['counts']);
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policies'));
    }

    public function testAPolicyWhoseMarkerWasRemovedIsNotTouchedAgain(): void
    {
        $this->inst()->run('install', ['ids' => ['minimal']], 1);
        $pid = (int) $this->h->one("SELECT policy_id FROM rmm_policies WHERE name = 'Pack: Minimal / low noise'");
        $this->h->module->policyStore()->update($pid, ['description' => 'mine now'], 1);
        $this->assertSame('not_installed', $this->item($this->inst()->catalog(), 'minimal')['state']);
        $this->assertSame(['blocked' => 1], $this->inst()->run('install', ['ids' => ['minimal']], 1)['counts']);
        $this->assertSame(['skipped' => 1], $this->inst()->run('uninstall', ['ids' => ['minimal']], 1)['counts']);
        $this->assertNotNull($this->h->module->policyStore()->find($pid));
    }

    public function testInstallParametersAreValidatedAndApplied(): void
    {
        $r = $this->inst()->run('install', ['ids' => ['linux-server'], 'params' => ['linux-server' => ['ssh_unit' => 'sshd', 'cron_unit' => 'crond']]], 1);
        $this->assertSame(['added' => 1], $r['counts']);
        $body = (string) $this->h->one("SELECT body_json FROM rmm_policies WHERE name = 'Pack: Linux server baseline'");
        $this->assertStringContainsString('"name":"sshd"', $body);
        $this->assertSame('installed', $this->item($this->inst()->catalog(), 'linux-server')['state'], 'custom parameters are not an edit');
        $bad = $this->inst()->run('install', ['ids' => ['mw-nightly'], 'params' => ['mw-nightly' => ['timezone' => 'x; drop']]], 1);
        $this->assertSame(['blocked' => 1], $bad['counts']);
        $this->assertSame(['blocked' => 1], $this->inst()->run('install', ['ids' => ['mw-nightly'], 'params' => ['mw-nightly' => ['timezone' => 'Nowhere/Land']]], 1)['counts']);
        $this->assertSame(['added' => 1], $this->inst()->run('install', ['ids' => ['mw-nightly'], 'params' => ['mw-nightly' => ['timezone' => 'America/Chicago']]], 1)['counts']);
        $this->assertSame('America/Chicago', (string) $this->h->one('SELECT timezone FROM rmm_maintenance_windows'));
    }

    public function testChangePresetsNeedAConfirmationAndOnlyAddPresets(): void
    {
        $cs = $this->h->module->changeSettings();
        $cs->update(['presets' => ['windows' => ['win.hosts']]], 1);
        $this->assertSame(['blocked' => 1], $this->inst()->run('install', ['ids' => ['change-servers']], 1)['counts']);
        $this->assertSame(['win.hosts'], $cs->enabledPresets('windows'));
        $this->assertSame(['added' => 1], $this->inst()->run('install', ['ids' => ['change-servers'], 'confirm_instance_wide' => true], 1)['counts']);
        $this->assertEqualsCanonicalizing(['win.hosts', 'win.startup', 'win.local_admins', 'win.defender'], $cs->enabledPresets('windows'));
        $this->assertSame('installed', $this->item($this->inst()->catalog(), 'change-servers')['state']);
        $this->assertSame(['skipped' => 1], $this->inst()->run('uninstall', ['ids' => ['change-servers']], 1)['counts']);
    }

    public function testTheCliPathNeedsNoAbilityButIsAudited(): void
    {
        $r = $this->h->module->policyPackActions()->runFromCli('install', ['ids' => ['minimal']], 0);
        $this->assertTrue($r->ok);
        $this->assertSame(['added' => 1], $r->data['counts']);
        $rec = array_values(array_filter($this->h->audit->records(), static fn (array $x): bool => $x['action'] === 'Policy Pack Item Added'));
        $this->assertStringStartsWith('CLI added', $rec[0]['description']);
        $this->assertFalse($this->h->module->policyPackActions()->runFromCli('install', ['ids' => ['nope']])->ok);
    }

    public function testUnknownIdsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->inst()->run('install', ['ids' => ['nope']], 1);
    }

    public function testEveryInstalledPolicyResolvesToTheRightOsOnly(): void
    {
        $this->inst()->run('install', [], 1);
        foreach ($this->h->module->policyStore()->all() as $p) {
            $isWin = str_contains((string) $p['name'], 'Windows') || str_contains((string) $p['name'], 'add-on:');
            foreach ((array) $p['settings'] as $k => $e) {
                if (str_starts_with($k, 'check.') && ($e['mode'] ?? '') === 'override' && str_contains((string) $p['name'], 'Linux')) {
                    $this->assertSame('linux', $e['value']['os'] ?? null, "{$p['name']} $k");
                }
                if (str_starts_with($k, 'check.') && $isWin && ($e['mode'] ?? '') === 'override') {
                    $this->assertSame('windows', $e['value']['os'] ?? null, "{$p['name']} $k");
                }
            }
        }
    }

    public function testRestRoutesAbilitiesAndAudit(): void
    {
        [$c, $j] = $this->h->tech('GET', ['starter', 'policies'], $this->admin);
        $this->assertSame(200, $c);
        $this->assertSame('rivetcore-policies', $j['pack_id']);
        $this->assertSame(count(PolicyPack::bundled()->items()), count($j['items']));
        $this->assertArrayNotHasKey('body', $j['items'][0]);

        [$c, $j] = $this->h->tech('POST', ['starter', 'policies', 'plan'], $this->admin, ['op' => 'install', 'ids' => ['minimal']]);
        $this->assertSame([200, ['added' => 1], true], [$c, $j['counts'], $j['dry_run']]);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policies'));

        $this->h->audit->records();
        [$c, $j] = $this->h->tech('POST', ['starter', 'policies', 'install'], $this->admin, ['ids' => ['minimal']]);
        $this->assertSame([200, ['added' => 1]], [$c, $j['counts']]);
        $actions = array_column($this->h->audit->records(), 'action');
        $this->assertContains('Policy Pack Item Added', $actions);
        $this->assertContains('Policy Pack Install', $actions);
        foreach ($this->h->audit->records() as $rec) {
            $this->assertStringNotContainsString('warn_free_pct', $rec['description']);
        }
        [$c, $j] = $this->h->tech('POST', ['starter', 'policies', 'uninstall'], $this->admin, ['ids' => ['minimal']]);
        $this->assertSame(['removed' => 1], $j['counts']);
        $this->assertContains('Policy Pack Item Removed', array_column($this->h->audit->records(), 'action'));

        [$c, $j] = $this->h->tech('POST', ['starter', 'policies', 'install'], $this->admin, ['ids' => ['nope']]);
        $this->assertSame([422, 'invalid'], [$c, $j['code']]);
        [$c] = $this->h->tech('POST', ['starter', 'policies', 'plan'], $this->admin, ['op' => 'bogus']);
        $this->assertSame(422, $c);
        [$c] = $this->h->tech('DELETE', ['starter', 'policies'], $this->admin);
        $this->assertSame(405, $c);
        [$c] = $this->h->tech('GET', ['starter', 'policies', 'nope'], $this->admin);
        $this->assertSame(404, $c);
        [$c] = $this->h->tech('GET', ['starter', 'policies', 'install'], $this->admin);
        $this->assertSame(405, $c);
        [$c] = $this->h->tech('GET', ['starter', 'policies'], null);
        $this->assertSame(401, $c);

        foreach ([$this->tech, $this->approver, $this->viewer, $this->scoped] as $who) {
            foreach ([['GET', 'policies'], ['POST', 'plan'], ['POST', 'install'], ['POST', 'update'], ['POST', 'reset'], ['POST', 'uninstall']] as [$m, $what]) {
                $path = $what === 'policies' ? ['starter', 'policies'] : ['starter', 'policies', $what];
                [$c, $j] = $this->h->tech($m, $path, $who, $m === 'POST' ? [] : null);
                $this->assertSame([403, 'forbidden'], [$c, $j['code']], "{$who->userName} $m $what");
            }
        }
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_policies'), 'refused callers wrote nothing');
    }
}
