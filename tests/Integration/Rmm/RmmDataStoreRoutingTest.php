<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Tests\Support\RmmTestCase;

/** Service-level proof that the callers reach the stores for every read the existing suites do not pin (found by mutation checks of the routing, phase 1 of #89). */
final class RmmDataStoreRoutingTest extends RmmTestCase
{
    private int $dev = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->h->enable();
        $this->h->asset(['name' => 'R', 'serial' => 'ROUTE-1']);
        [, , $j] = $this->h->enroll($this->h->token(null, 24, 5), $this->h::device(['serial' => 'ROUTE-1']));
        $this->dev = (int) $j['device_id'];
    }

    public function testTheCheckHistoryTimeInStateStartsFromTheResultBeforeTheWindow(): void
    {
        $at = static fn (int $secondsAgo): string => gmdate('Y-m-d H:i:s', time() - $secondsAgo);
        $this->h->q("INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) VALUES
            ({$this->dev}, 'disk_c', 'fail', 'before the window', '" . $at(3 * 3600) . "'), ({$this->dev}, 'disk_c', 'ok', 'back', '" . $at(1800) . "')");
        $r = $this->h->module->readModel()->checkHistory($this->dev, 'disk_c', 1);
        $this->assertSame(['back'], array_column($r['points'], 'detail'), 'only the result inside the window is a point');
        $this->assertEqualsWithDelta(1800, $r['seconds']['fail'], 5, 'the device was failing from the start of the window until the ok result: the state comes from the result before the window');
        $this->assertEqualsWithDelta(1800, $r['seconds']['ok'], 5);
        $this->assertSame(1, $r['changes']);
    }

    public function testTheSoftwareHistoryOfTheReadModelAndTheTimelineComeFromTheSoftwareStore(): void
    {
        $now = gmdate('Y-m-d H:i:s', time() - 60);
        $this->h->q("INSERT INTO rmm_software_history (device_id, software_key, name, source, change_type, old_version, new_version, publisher, occurred_at)
            VALUES ({$this->dev}, 'k', 'Routed App', 'msi', 'installed', NULL, '1.0', 'P', '$now')");
        $page = $this->h->module->readModel()->softwareHistory($this->dev, 'routed');
        $this->assertSame(1, $page['total']);
        $this->assertSame('Routed App', $page['items'][0]['name']);
        $timeline = $this->h->module->changeService()->timeline($this->dev, [], 50, true);
        $this->assertContains('Routed App', array_column($timeline['items'], 'target'));
        $without = $this->h->module->changeService()->timeline($this->dev, [], 50, false);
        $this->assertNotContains('Routed App', array_column($without['items'], 'target'), 'a caller who may not see software does not get it');
    }

    public function testThePatchDeployAndHypervisorReadersReachTheirStores(): void
    {
        $at = gmdate('Y-m-d H:i:s', time() - 60);
        $this->h->q("INSERT INTO rmm_patch_history (device_id, client_id, update_id, title, action, result, code, detail, at, job_id, user_id)
            VALUES ({$this->dev}, 1, 'KB1', 'Update 1', 'install', 'ok', '', '', '$at', 'j', NULL)");
        $this->h->q("INSERT INTO rmm_packages (name, created_at, updated_at) VALUES ('Routed Package', NOW(), NOW())");
        $pkg = (int) $this->h->mysqli->insert_id;
        $this->h->q("INSERT INTO rmm_package_history (device_id, client_id, package_id, version_id, action, mode, result, code, detail, version, at, job_id, user_id)
            VALUES ({$this->dev}, 1, $pkg, NULL, 'install', 'live', 'installed', '', '', '2.0', '$at', NULL, NULL)");
        $patch = $this->h->module->patchReader()->deviceHistory($this->dev);
        $this->assertSame([1, 'KB1'], [$patch['total'], $patch['items'][0]['update_id']]);
        $deploy = $this->h->module->deployReader()->history($this->dev, 10, 0);
        $this->assertSame([1, 'Routed Package', '2.0'], [$deploy['total'], $deploy['rows'][0]['package'], $deploy['rows'][0]['version']]);
    }

    public function testTheSchedulersPruneTheirHistoryThroughTheStores(): void
    {
        $this->h->module->settings()->update(['features_json' => ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true, 'patching' => true, 'software_deploy' => true]]);
        $old = gmdate('Y-m-d H:i:s', time() - 800 * 86400);
        $new = gmdate('Y-m-d H:i:s', time() - 60);
        $this->h->q("INSERT INTO rmm_package_history (device_id, client_id, package_id, version_id, action, mode, result, code, detail, version, at, job_id, user_id) VALUES
            ({$this->dev}, 1, 1, NULL, 'install', 'live', 'installed', '', '', '1', '$old', NULL, NULL), ({$this->dev}, 1, 1, NULL, 'install', 'live', 'installed', '', '', '2', '$new', NULL, NULL)");
        $r = $this->h->module->deployScheduler()->run();
        $this->assertGreaterThanOrEqual(1, $r['deploy_pruned']);
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_package_history'));
        $this->assertSame('2', $this->h->one('SELECT version FROM rmm_package_history'), 'the recent entry stays');
    }

    public function testTheHypervisorPruneSweepsTheHistoryOfVmsThatAreGone(): void
    {
        $this->h->q("INSERT INTO rmm_hypervisor_vms (device_id, vm_key, first_seen_at, last_seen_at) VALUES ({$this->dev}, 'qemu/101', NOW(), NOW())");
        $vm = (int) $this->h->mysqli->insert_id;
        $this->h->q("INSERT INTO rmm_hypervisor_vm_history (vm_id, device_id, at, from_state, to_state) VALUES ($vm, {$this->dev}, NOW(), 'running', 'stopped'), (999999, {$this->dev}, NOW(), 'running', 'stopped')");
        $out = $this->h->module->hypervisorService()->prune();
        $this->assertSame(1, $out['history'], 'the entry of the VM that does not exist is swept, the live VM keeps its history');
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM rmm_hypervisor_vm_history WHERE vm_id = $vm"));
    }
}
