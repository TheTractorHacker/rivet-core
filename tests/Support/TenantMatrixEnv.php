<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Mesh\MeshCookie;
use RivetCore\Testing\InMemoryRmmEvents;

/**
 * The fixture of the client-scope (tenant isolation) matrix, tests/Integration/Rmm/TenantIsolationMatrixTest.php.
 *
 * Two clients, A and B. One technician (`$tech`, user 20) holds EVERY rmm.* ability and is restricted by the tenancy to client A: the only thing
 * between that technician and client B's data is Core's own client scoping (MeshCentral does not separate clients, see docs/modules/rmm.md).
 * Everything the matrix may target is built here for both clients: devices, queued jobs, transfers, approvals, package assignments, alerts,
 * enrollment tokens, maintenance windows, escalation policies, log and change rules, policy assignments, schedules, custom field values, hypervisor VMs.
 *
 * A "target" is one of FOREIGN (an object of client B), MISSING (an id that does not exist) or OWN (an object of client A, the positive control).
 * Objects a control consumes (a job that gets cancelled, an approval that gets decided) are made fresh for every OWN request.
 *
 * Built ONCE per test class (the foreign requests change nothing, which the matrix asserts, so sharing is sound) and torn down by {@see close()}.
 */
final class TenantMatrixEnv
{
    public const FOREIGN = 'foreign';
    public const MISSING = 'missing';
    public const OWN = 'own';
    public const NO_ID = 987654;
    public const NO_UUID = '00000000-0000-4000-8000-00000000dead';
    public const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public const HOST = 'downloads.example.test';
    public const WIN_UPDATE = '11111111-2222-3333-4444-555555555555';

    public RmmHarness $h;
    public InMemoryRmmEvents $bus;
    public MockMeshServer $mesh;
    public string $meshLog;
    public string $transferDir;
    /** Every rmm.* ability, restricted to client A by the tenancy. */
    public RmmPrincipal $tech;
    /** Every rmm.* ability, no restriction: builds client B's objects and is the "other person" of the two-person flows. */
    public RmmPrincipal $admin;
    public RmmPrincipal $other;
    public int $devA = 0;
    public int $devB = 0;
    public string $tokenA = '';
    public string $tokenB = '';
    public int $locationA = 0;
    public int $locationB = 0;
    private int $seq = 0;
    /** @var array<string,mixed> the objects of client B (built once) */
    public array $b = [];
    public int $scriptId = 0;
    public int $approvalScriptId = 0;
    public int $fieldDevice = 0;
    public int $fieldClient = 0;
    public int $groupId = 0;
    public int $tagId = 0;
    public int $policyId = 0;
    public int $packageId = 0;
    public int $versionId = 0;
    public int $savedScript = 0;
    public int $patchPolicyId = 0;
    public const CHECK_KEY = 'cpu_tm';

    public function __construct()
    {
        $this->transferDir = sys_get_temp_dir() . '/rmm_tenant_xfer_' . bin2hex(random_bytes(4));
        $this->meshLog = sys_get_temp_dir() . '/rmm_tenant_mesh_' . bin2hex(random_bytes(4)) . '.log';
        file_put_contents($this->meshLog, '');
        putenv('MOCK_MESH_LOG=' . $this->meshLog);
        $this->bus = new InMemoryRmmEvents();
        $policy = new AllowUsersPolicy([1 => true, 20 => true, 21 => true]);
        $this->h = new RmmHarness(null, null, ['allow_linux' => true, 'transfer_dir' => $this->transferDir], false, null, $policy, new \RivetCore\Webhooks\UrlPolicy(true), $this->bus);
        $this->admin = new RmmPrincipal(1, 'Admin');
        $this->tech = new RmmPrincipal(20, 'ScopedTech');
        $this->other = new RmmPrincipal(21, 'Other');
        $this->h->tenancy->restrictUser(20, [$this->h->clientA]);
        $this->locationA = $this->h->tenancy->addLocation($this->h->clientA);
        $this->locationB = $this->h->tenancy->addLocation($this->h->clientB);
        $this->build();
    }

    public function close(): void
    {
        $this->mesh->stop();
        putenv('MOCK_MESH_LOG');
        foreach (glob($this->transferDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->transferDir);
        @unlink($this->meshLog);
        RmmHarness::closeAll();
    }

    public function clientA(): int
    {
        return $this->h->clientA;
    }

    public function clientB(): int
    {
        return $this->h->clientB;
    }

    // ------------------------------------------------------------------ builders

    /** @param list<string> $names */
    private function features(array $names): void
    {
        $f = ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true];
        foreach ($names as $n) {
            $f[$n] = true;
        }
        $e = $this->h->module->settings()->update(['features_json' => $f, 'failure_debounce' => 1, 'recovery_debounce' => 1]);
        self::must($e === [], 'features: ' . json_encode($e));
        $this->h->module->alertingEngine()->forget();
    }

    private static function must(bool $cond, string $what): void
    {
        if (!$cond) {
            throw new \LogicException('tenant matrix fixture: ' . $what);
        }
    }

    /** @return list<string> */
    private static function caps(): array
    {
        $caps = ['job:collect', 'job:reboot', 'job:powershell', 'job:shell', 'job:svc_list', 'job:svc_control', 'job:proc_list', 'job:proc_kill', 'job:file_get', 'job:file_put',
            'remote:services', 'remote:processes', 'remote:files', 'logs:collect', 'job:patch_scan', 'job:patch_install', 'patch:scan', 'patch:install',
            'job:software_detect', 'job:software_install', 'software:detect', 'software:install', 'job:mesh_install', 'job:mesh_uninstall'];
        sort($caps);

        return $caps;
    }

    /** @return array{0:int,1:string} */
    public function linked(string $name, int $client, bool $node = true): array
    {
        $this->h->asset(['name' => $name, 'serial' => "SER-$name", 'client_id' => $client]);
        $tok = $this->h->token($client, 24, 500);
        [$c, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => "SER-$name", 'hostname' => $name, 'os' => 'windows']));
        self::must($c === 201, "enroll $name: $c " . json_encode($j));
        $id = (int) $j['device_id'];
        self::must($j['status'] === 'linked', "$name is linked");
        if ($node) {
            self::must($this->h->module->devices()->setMeshNode($id, 'node//' . str_repeat('A', 24) . $id, 1), 'mesh node');
        }
        [$c] = $this->h->checkin((string) $j['device_token'], ['capabilities' => self::caps(), 'platform' => 'windows']);
        self::must($c === 200, "checkin $name");

        return [$id, (string) $j['device_token']];
    }

    private function build(): void
    {
        $this->h->enable();
        $this->features(['remote', 'inventory_software', 'policies', 'scripts', 'patching', 'software', 'software_deploy', 'logs', 'change_monitor', 'hypervisor', 'alerting', 'remote_tools', 'filetransfer']);
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.test']);
        $this->mesh = new MockMeshServer();
        $r = $this->h->module->admin()->saveMesh($this->admin, ['mesh_enabled' => 1, 'mesh_url' => $this->mesh->url(), 'mesh_login_key' => MeshCookie::newLoginKey(), 'mesh_account_template' => 'rivetit-support']);
        self::must($r->ok, 'saveMesh ' . $r->message);
        $this->h->module->meshAgentSettings()->update(['mesh_group_id' => '4a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9'], 1);
        $rs = $this->h->module->remoteSettings()->update(['transfer_allow' => ['linux' => ['/srv/shared'], 'windows' => ['C:\\Temp']]], 1);
        self::must($rs === [], 'transfer_allow ' . json_encode($rs));
        $this->h->publishBinary('1.0.0', 'amd64', 8192, true, null);
        [$this->devA, $this->tokenA] = $this->linked('TENANT-A-PC', $this->h->clientA);
        [$this->devB, $this->tokenB] = $this->linked('TENANT-B-PC', $this->h->clientB);

        $s = $this->h->module->scriptActions();
        $r = $s->createScript($this->admin, ['name' => 'Plain', 'language' => 'powershell', 'body' => "Get-Date\n", 'params' => []]);
        self::must($r->ok, 'script ' . $r->message);
        $this->scriptId = (int) $r->data['script']['script_id'];
        $r = $s->createScript($this->admin, ['name' => 'Needs approval', 'language' => 'powershell', 'body' => "Get-Date\n", 'params' => [], 'requires_approval' => true]);
        self::must($r->ok, 'script2 ' . $r->message);
        $this->approvalScriptId = (int) $r->data['script']['script_id'];

        // a package in the catalog (fleet-wide), with a version
        $r = $this->h->module->deployActions()->addHost($this->admin, ['host' => self::HOST]);
        self::must($r->ok, 'host ' . $r->message);
        $this->packageId = $this->newPackage('Fleet Pack');
        $this->versionId = $this->version($this->packageId);
        $this->savedScript = $this->h->bridge->addScript('Get-Date');
        $ck = $this->h->module->settings()->update(['checks_json' => json_encode([['key' => self::CHECK_KEY, 'type' => 'cpu', 'params' => ['thresholds' => ['warn' => ['op' => 'gt', 'value' => 80]]], 'interval_s' => 60]])]);
        self::must($ck === [], 'checks ' . json_encode($ck));

        $f = $this->h->module->fieldActions();
        $r = $f->define($this->admin, ['name' => 'devnote', 'scope' => 'device', 'type' => 'text']);
        self::must($r->ok, 'field ' . $r->message);
        $this->fieldDevice = (int) $r->data['field']['field_id'];
        $r = $f->define($this->admin, ['name' => 'clientnote', 'scope' => 'client', 'type' => 'text']);
        self::must($r->ok, 'field2 ' . $r->message);
        $this->fieldClient = (int) $r->data['field']['field_id'];

        $r = $this->h->module->inventory()->createGroup($this->admin, ['name' => 'Everyone']);
        self::must($r->ok, 'group ' . $r->message);
        $this->groupId = (int) ($r->data['group']['group_id'] ?? 0);
        $r = $this->h->module->inventory()->createTag($this->admin, ['name' => 'tm-tag']);
        self::must($r->ok, 'tag ' . $r->message);
        $this->tagId = (int) ($r->data['tag']['tag_id'] ?? 0);
        $r = $this->h->module->policyActions()->create($this->admin, ['name' => 'Fleet policy', 'settings' => ['interval.collect_s' => 45]]);
        self::must($r->ok, 'policy ' . $r->message);
        $this->policyId = (int) $r->data['policy']['policy_id'];

        $pp = $this->h->module->patchActions()->savePolicy($this->admin, null, ['name' => 'careful', 'settings' => ['two_person' => true]]);
        self::must($pp->ok, 'patch policy ' . $pp->message);
        $this->patchPolicyId = (int) $pp->data['policy']['policy_id'];
        foreach ([$this->h->clientA, $this->h->clientB] as $c) {
            $ap = $this->h->module->patchActions()->assignPolicy($this->admin, $this->patchPolicyId, ['scope_type' => 'client', 'scope_id' => $c]);
            self::must($ap->ok, 'patch assign ' . $ap->message);
        }
        $this->seedPatches($this->devA, $this->tokenA);
        $this->seedPatches($this->devB, $this->tokenB);

        // ---- the objects of client B (every one is the target of a foreign request)
        $b = &$this->b;
        $b['job'] = $this->newJob($this->devB);
        $b['transfer'] = $this->newUpload($this->devB);
        $b['approval'] = $this->newApproval($this->devB);
        $b['approval_sw'] = $this->newSoftwareApproval($this->devB);
        $b['tooljob'] = $this->newToolJob($this->devB);
        $b['assign_device'] = $this->newAssignment('device', $this->devB);
        $b['assign_client'] = $this->newAssignment('client', $this->h->clientB);
        $b['token'] = $this->newToken($this->h->clientB);
        $b['alert'] = $this->newAlert($this->devB, $this->tokenB);
        $b['window_client'] = $this->newWindow('client', $this->h->clientB);
        $b['window_device'] = $this->newWindow('device', $this->devB);
        $b['esc_client'] = $this->newEscalation('client', $this->h->clientB);
        $b['log_rule'] = $this->newLogRule($this->h->clientB);
        $b['change_rule'] = $this->newChangeRule($this->h->clientB);
        $b['pol_assign_client'] = $this->newPolicyAssignment('client', $this->h->clientB);
        $b['pol_assign_device'] = $this->newPolicyAssignment('device', $this->devB);
        $b['schedule'] = $this->newSchedule('client', $this->h->clientB);
        $b['vm'] = $this->newVm($this->devB);
        $b['pending'] = $this->newPendingDevice($this->h->clientB);
        $b['device'] = $this->devB;
        $b['ppolicy'] = $this->patchPolicyId;
        $r = $this->h->module->scriptActions()->runOnDevice($this->admin, $this->devB, ['library_script_id' => $this->scriptId]);
        self::must($r->ok, 'a library run on device B ' . $r->message);
        $b['package'] = $this->packageId;
        $b['version'] = $this->versionId;
        $b['script'] = $this->scriptId;
        $b['policy'] = $this->policyId;
        $b['field'] = $this->fieldDevice;
        $b['group'] = $this->groupId;
        $b['tag'] = $this->tagId;
        $b['approval_patch'] = $this->newPatchApproval($this->devB);
        $b['patch_rule'] = $this->newPatchRule($this->h->clientB);
        $b['esc_all'] = $this->newEscalation('all', 0);
        $this->h->module->settings()->refresh();
    }

    // ------------------------------------------------------------------ object factories (admin builds, the matrix targets)

    public function newPackage(string $name = ''): int
    {
        $name = $name !== '' ? $name : 'Pack ' . (++$this->seq);
        $r = $this->h->module->deployActions()->savePackage($this->admin, null, ['name' => $name]);
        self::must($r->ok, 'package ' . $r->message);

        return (int) $r->data['package_id'];
    }

    public function version(int $pkg, string $version = '2.4.1'): int
    {
        $r = $this->h->module->deployActions()->addVersion($this->admin, $pkg, ['version' => $version, 'os' => 'windows', 'fmt' => 'msi', 'url' => 'https://' . self::HOST . "/acme/$version/acme.msi",
            'sha256' => self::SHA, 'size_bytes' => 1048576, 'product_code' => '{11111111-2222-3333-4444-555555555555}', 'detect_min' => $version]);
        self::must($r->ok, 'version ' . $r->message);

        return (int) $r->data['version_id'];
    }

    public function newJob(int $device): string
    {
        $r = $this->h->module->technician()->submitJob($this->admin, $device, ['type' => 'powershell', 'script' => 'Get-Date']);
        self::must($r->ok, 'job ' . $r->message);

        return (string) $r->data['job_id'];
    }

    public function newUpload(int $device): string
    {
        $r = $this->h->module->remoteActions()->createUpload($this->admin, $device, 'C:\\Temp\\tm' . (++$this->seq) . '.txt', 5, hash('sha256', 'hello'), false, true);
        self::must($r->ok, 'upload ' . $r->message);

        return (string) $r->data['transfer_id'];
    }

    public function newApproval(int $device): int
    {
        $r = $this->h->module->scriptActions()->runOnDevice($this->other, $device, ['library_script_id' => $this->approvalScriptId]);
        self::must($r->ok && $r->http === 202, 'approval ' . $r->message);

        return (int) $r->data['approval_id'];
    }

    public function newAssignment(string $scope, int $scopeId): int
    {
        $r = $this->h->module->deployActions()->assign($this->admin, ['package_id' => $this->newPackage(), 'scope_type' => $scope, 'scope_id' => $scopeId, 'action' => 'install']);
        self::must($r->ok, 'assignment ' . $r->message);

        return (int) $r->data['assignment']['assignment_id'];
    }

    public function newToken(int $client): int
    {
        $r = $this->h->module->technician()->createToken($this->admin, $client, 0, 'stable', 24, 5, 'tm');
        self::must($r->ok, 'token ' . $r->message);

        return (int) $r->data['token_id'];
    }

    public function newAlert(int $device, string $deviceToken): int
    {
        $this->h->checkin($deviceToken, ['checks' => [['key' => 'svc' . (++$this->seq), 'status' => 'fail', 'detail' => '']]]);
        $id = (int) $this->h->one("SELECT alert_id FROM rmm_alert_meta WHERE device_id = $device ORDER BY meta_id DESC LIMIT 1");
        self::must($id > 0, "an alert for device $device");

        return $id;
    }

    public function newWindow(string $scope, int $scopeId): int
    {
        $r = $this->h->module->alertingActions()->createWindow($this->admin, ['name' => 'W' . (++$this->seq), 'kind' => 'once', 'starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            'ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 7200), 'scope_type' => $scope, 'scope_id' => $scopeId]);
        self::must($r->ok, 'window ' . $r->message);

        return (int) $r->data['window']['window_id'];
    }

    public function newEscalation(string $scope, int $scopeId): int
    {
        $r = $this->h->module->alertingActions()->createPolicy($this->admin, ['name' => 'Esc' . (++$this->seq), 'scope_type' => $scope, 'scope_id' => $scopeId,
            'steps' => [['after_min' => 0, 'targets' => [['type' => 'user', 'ref' => '1']]]]]);
        self::must($r->ok, 'escalation ' . $r->message);

        return (int) $r->data['policy']['policy_id'];
    }

    public function newLogRule(int $client): int
    {
        $r = $this->h->module->logActions()->createRule($this->admin, ['name' => 'LR' . (++$this->seq), 'min_level' => 'error', 'contains' => 'spooler', 'severity' => 'fail', 'client_id' => $client]);
        self::must($r->ok, 'log rule ' . $r->message);

        return (int) $r->data['rule_id'];
    }

    public function newChangeRule(int $client): int
    {
        $r = $this->h->module->changeActions()->createRule($this->admin, ['name' => 'CR' . (++$this->seq), 'watch' => 'win.startup', 'severity' => 'fail', 'client_id' => $client]);
        self::must($r->ok, 'change rule ' . $r->message);

        return (int) $r->data['rule_id'];
    }

    public function newPolicyAssignment(string $scope, int $scopeId): int
    {
        $r = $this->h->module->policyActions()->assign($this->admin, $this->policyId, ['scope_type' => $scope, 'scope_id' => $scopeId, 'priority' => 100 + (++$this->seq)]);
        self::must($r->ok, 'policy assignment ' . $r->message);

        return (int) $r->data['assignment']['assignment_id'];
    }

    public function newSchedule(string $scope, int $scopeId): int
    {
        $r = $this->h->module->scriptActions()->saveSchedule($this->admin, null, ['name' => 'S' . (++$this->seq), 'library_script_id' => $this->scriptId, 'target' => ['type' => $scope, 'id' => $scopeId],
            'kind' => 'interval', 'interval_s' => 3600, 'start_in_s' => 0]);
        self::must($r->ok, 'schedule ' . $r->message);

        return (int) $r->data['schedule']['schedule_id'];
    }

    public function newVm(int $device): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->h->q("INSERT IGNORE INTO rmm_hypervisor_hosts (device_id, platform, updated_at) VALUES ($device, 'proxmox', '$now')");
        $this->h->q("INSERT INTO rmm_hypervisor_vms (device_id, vm_key, name, first_seen_at, last_seen_at) VALUES ($device, 'vm" . (++$this->seq) . "', 'guest', '$now', '$now')");

        return (int) $this->h->one('SELECT MAX(vm_id) FROM rmm_hypervisor_vms');
    }

    /** A device that enrolled and waits for approval (not linked to an asset). */
    public function newPendingDevice(int $client): int
    {
        $name = 'PEND-' . (++$this->seq);
        [$c, , $j] = $this->h->enroll($this->h->token($client, 24, 500), $this->h::device(['serial' => "SER-$name", 'hostname' => $name]));
        self::must($c === 201 && $j['status'] !== 'linked', 'pending device ' . json_encode($j));

        return (int) $j['device_id'];
    }


    public function newPackageWithVersion(): int
    {
        $id = $this->newPackage();
        $this->version($id);

        return $id;
    }

    /** MeshCentral address: the mock (http, for the launch) or an https one (the install of the Mesh agent refuses anything else). */
    public function meshUrl(bool $https): void
    {
        $this->h->module->settings()->set(['mesh_url' => $https ? 'https://mesh.example.test' : $this->mesh->url()]);
        $this->h->module->settings()->refresh();
    }

    /** Every missing update of both devices is "available" again (an earlier control may have approved or declined it). */
    public function resetPatches(): void
    {
        $this->h->q("UPDATE rmm_patch_available SET status = 'available', approval_id = NULL, status_by = NULL WHERE update_id = '" . self::WIN_UPDATE . "'");
    }

    public function newScript(): int
    {
        $r = $this->h->module->scriptActions()->createScript($this->admin, ['name' => 'Script ' . (++$this->seq), 'language' => 'powershell', 'body' => "Get-Date\n", 'params' => []]);
        self::must($r->ok, 'script ' . $r->message);

        return (int) $r->data['script']['script_id'];
    }

    public function newPolicy(): int
    {
        $r = $this->h->module->policyActions()->create($this->admin, ['name' => 'Policy ' . (++$this->seq), 'settings' => ['interval.collect_s' => 45]]);
        self::must($r->ok, 'policy ' . $r->message);

        return (int) $r->data['policy']['policy_id'];
    }

    public function newField(): int
    {
        $r = $this->h->module->fieldActions()->define($this->admin, ['name' => 'f' . (++$this->seq), 'scope' => 'device', 'type' => 'text']);
        self::must($r->ok, 'field ' . $r->message);

        return (int) $r->data['field']['field_id'];
    }

    public function newGroup(): int
    {
        $r = $this->h->module->inventory()->createGroup($this->admin, ['name' => 'Group ' . (++$this->seq)]);
        self::must($r->ok, 'group ' . $r->message);

        return (int) $r->data['group']['group_id'];
    }

    public function newTag(): int
    {
        $r = $this->h->module->inventory()->createTag($this->admin, ['name' => 'tag' . (++$this->seq)]);
        self::must($r->ok, 'tag ' . $r->message);

        return (int) $r->data['tag']['tag_id'];
    }

    public function newPatchPolicy(): int
    {
        $r = $this->h->module->patchActions()->savePolicy($this->admin, null, ['name' => 'PP' . (++$this->seq)]);
        self::must($r->ok, 'patch policy ' . $r->message);

        return (int) $r->data['policy']['policy_id'];
    }

    public function newAsset(int $client): int
    {
        return $this->h->asset(['name' => 'Asset ' . (++$this->seq), 'serial' => 'SER-X' . $this->seq, 'client_id' => $client]);
    }

    public function newPatchRule(int $client): int
    {
        $r = $this->h->module->patchActions()->saveRule($this->admin, null, ['name' => 'rule' . (++$this->seq), 'after_days' => 1, 'client_id' => $client]);
        self::must($r->ok, 'patch rule ' . $r->message);

        return (int) $r->data['rule']['rule_id'];
    }

    /** The device reports a scan with one missing update, so patch approvals have something to act on. */
    private function seedPatches(int $device, string $token): void
    {
        $r = $this->h->module->patchActions()->scan($this->admin, $device);
        self::must($r->ok, 'scan ' . $r->message);
        [, , $j] = $this->h->call('GET', 'agent_jobs', null, $token);
        $job = $j['jobs'][0];
        $upd = ['id' => self::WIN_UPDATE, 'kb' => 'KB5000001', 'title' => 'Cumulative Update', 'kind' => 'security', 'severity' => 'important', 'size' => 1048576, 'reboot_required' => true,
            'released_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400 * 3), 'eula_accepted' => true, 'categories' => ['Security Updates'], 'current' => '', 'version' => ''];
        $out = (string) json_encode(['v' => 1, 'ok' => true, 'manager' => 'wua', 'scanned_at' => gmdate('Y-m-d\TH:i:s\Z'), 'scan_state' => 'ok', 'wu_state' => 'running', 'reboot_pending' => false,
            'last_install_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400 * 9), 'truncated' => false, 'total' => 1, 'updates' => [$upd]]);
        [$c] = $this->h->call('POST', 'agent_jobs', ['job_id' => $job['job_id'], 'attempt' => $job['attempt'], 'state' => 'succeeded', 'exit_code' => 0, 'output' => $out,
            'started_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 5), 'finished_at' => gmdate('Y-m-d\TH:i:s\Z')], $token);
        self::must($c === 200, 'scan report');
    }

    /** A patch approval waiting for a second person (every client has the two-person patch policy): its id. */
    public function newPatchApproval(int $device): int
    {
        $r = $this->h->module->patchActions()->approve($this->other, ['update_ids' => [self::WIN_UPDATE], 'device_id' => $device]);
        self::must($r->ok && $r->http === 202, 'patch approval ' . $r->message . ' ' . $r->http);

        return (int) $r->data['approval_id'];
    }

    // ------------------------------------------------------------------ targets

    /** The id of the device a target names. */
    public function dev(string $t): int
    {
        return match ($t) {
            self::FOREIGN => $this->devB,
            self::OWN => $this->devA,
            default => self::NO_ID,
        };
    }

    public function client(string $t): int
    {
        return match ($t) {
            self::FOREIGN => $this->h->clientB,
            self::OWN => $this->h->clientA,
            default => self::NO_ID,
        };
    }

    public function location(string $t): int
    {
        return match ($t) {
            self::FOREIGN => $this->locationB,
            self::OWN => $this->locationA,
            default => self::NO_ID,
        };
    }

    /** Keys whose ids are uuids (jobs, transfers): a missing one must look like a uuid too. */
    private const UUID_KEYS = ['job', 'tooljob', 'transfer'];

    /**
     * An object a target names: client B's (built once), a fresh one of client A (the control may consume it), or an id that does not exist.
     *
     * @param string $key a key of {@see $b}
     */
    public function get(string $t, string $key): mixed
    {
        if ($t === self::FOREIGN) {
            return $this->b[$key];
        }
        if ($t === self::MISSING) {
            return in_array($key, self::UUID_KEYS, true) ? self::NO_UUID : self::NO_ID;
        }
        $a = $this->h->clientA;

        return match ($key) {
            'job' => $this->newJob($this->devA),
            'tooljob' => $this->newToolJob($this->devA),
            'transfer' => $this->newUpload($this->devA),
            'approval' => $this->newApproval($this->devA),
            'approval_sw' => $this->newSoftwareApproval($this->devA),
            'assign_device' => $this->newAssignment('device', $this->devA),
            'assign_client' => $this->newAssignment('client', $a),
            'token' => $this->newToken($a),
            'alert' => $this->newAlert($this->devA, $this->tokenA),
            'window_client' => $this->newWindow('client', $a),
            'window_device' => $this->newWindow('device', $this->devA),
            'esc_client' => $this->newEscalation('client', $a),
            'log_rule' => $this->newLogRule($a),
            'change_rule' => $this->newChangeRule($a),
            'pol_assign_client' => $this->newPolicyAssignment('client', $a),
            'pol_assign_device' => $this->newPolicyAssignment('device', $this->devA),
            'schedule' => $this->newSchedule('client', $a),
            'vm' => $this->newVm($this->devA),
            'pending' => $this->newPendingDevice($a),
            'device' => $this->linked('OWN-' . (++$this->seq), $a)[0],
            'package' => $this->newPackageWithVersion(),
            'version' => $this->version($this->newPackage()),
            'script' => $this->newScript(),
            'policy' => $this->newPolicy(),
            'field' => $this->newField(),
            'group' => $this->newGroup(),
            'tag' => $this->newTag(),
            'approval_patch' => $this->newPatchApproval($this->devA),
            'ppolicy' => $this->newPatchPolicy(),
            'patch_rule' => $this->newPatchRule($a),
            'esc_all' => $this->newEscalation('all', 0),
            default => throw new \LogicException("no own factory for $key"),
        };
    }

    public function newToolJob(int $device): string
    {
        $r = $this->h->module->remoteActions()->listServices($this->admin, $device);
        self::must($r->ok, 'tool job ' . $r->message);

        return (string) $r->data['job_id'];
    }

    /** A software assignment that waits for a second person (two-person package): the approval id. */
    public function newSoftwareApproval(int $device): int
    {
        $r = $this->h->module->deployActions()->savePackage($this->admin, null, ['name' => 'Two ' . (++$this->seq), 'two_person' => true]);
        self::must($r->ok, 'two-person package ' . $r->message);
        $pkg = (int) $r->data['package_id'];
        $this->version($pkg);
        $r = $this->h->module->deployActions()->assign($this->other, ['package_id' => $pkg, 'scope_type' => 'device', 'scope_id' => $device, 'action' => 'install']);
        self::must($r->ok && $r->http === 202, 'software approval ' . $r->message . ' ' . $r->http);

        return (int) $r->data['approval_id'];
    }

    // ------------------------------------------------------------------ what a request may not change

    /**
     * Everything an out-of-scope request must leave alone: a checksum of every table the module owns, the audit trail, the events published, the remote
     * sessions recorded by the bridge, every request that reached the (mock) MeshCentral server, and the files in the transfer folder.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $tables = array_merge(RmmHarness::TABLES, ['integration_jobs', 'endpoint_agent_settings']);
        // Read-through caches and rate buckets move on a plain read; nothing else does.
        $tables = array_values(array_diff($tables, ['rmm_cache', 'rmm_token_buckets', 'rmm_daily_budget']));
        $sums = [];
        $res = $this->h->mysqli->query('CHECKSUM TABLE `' . implode('`, `', $tables) . '`');
        foreach ($res instanceof \mysqli_result ? $res->fetch_all(MYSQLI_NUM) : [] as [$name, $sum]) {
            $sums[(string) $name] = $sum;
        }
        $bridge = new \ReflectionObject($this->h->bridge);
        $state = [];
        foreach ($bridge->getProperties() as $p) {
            $v = $p->getValue($this->h->bridge);
            if (!is_object($v) && !($v instanceof \Closure)) {
                $state[$p->getName()] = $v;
            }
        }

        return ['tables' => $sums, 'audit' => $this->h->audit->records(), 'events' => $this->bus->published(), 'bridge' => md5(serialize($state)),
            'sessions' => $this->h->bridge->sessions(), 'mesh' => (string) @file_get_contents($this->meshLog), 'files' => array_map('basename', glob($this->transferDir . '/*') ?: [])];
    }

    /** Names of the parts that differ between two snapshots. @param array<string,mixed> $a @param array<string,mixed> $b @return list<string> */
    public static function diff(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            if ($k === 'tables') {
                foreach ($v as $t => $sum) {
                    if (($b['tables'][$t] ?? null) !== $sum) {
                        $out[] = "table $t";
                    }
                }
            } elseif ($v !== $b[$k]) {
                $out[] = (string) $k;
            }
        }

        return $out;
    }
}
