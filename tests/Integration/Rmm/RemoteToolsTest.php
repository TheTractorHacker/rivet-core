<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Tests\Support\RemoteTestCase;

/** Phase 6: service and process managers, capability flags, confirmation rules, audit, result reads, the Mesh terminal and files views and the settings. */
final class RemoteToolsTest extends RemoteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->switchOn(['remote_tools']);
        [$this->dev, $this->devToken] = $this->linked('W1');
    }

    private function d(): string
    {
        return (string) $this->dev;
    }

    // ------------------------------------------------------------------ order of decisions

    public function testOrderOfDecisionsRoleThenScopeThenAbilityThenSwitch(): void
    {
        $route = [$this->d(), 'services', 'query'];
        $this->assertSame(401, $this->h->tech('POST', $route, null)[0]);
        $this->assertSame(403, $this->api('POST', $route, $this->viewer)[0], 'a viewer without remote.manage is refused');
        $this->assertSame(403, $this->api('POST', $route, $this->runner)[0], 'saved-script rights are not remote.manage');
        [$c, $j] = $this->api('POST', $route, $this->scoped);
        $this->assertSame([404, 'not_found'], [$c, $j['code']], 'a device outside the clients is the same 404 as a missing one');
        $this->assertSame(404, $this->api('POST', ['99999', 'services', 'query'], $this->tech)[0]);
        $this->assertSame(202, $this->api('POST', $route, $this->tech)[0]);
        $this->assertSame(202, $this->api('POST', $route, $this->admin)[0]);
        $this->assertStringContainsString('Remote Tool Denied', $this->auditText(), 'a denial is audited');
        // switch off: every remote-tool route answers 409 feature_disabled (after the ability check)
        $this->assertSame([], $this->h->module->settings()->update(['features_json' => ['monitoring' => true, 'jobs' => true]]));
        [$c, $j] = $this->api('POST', $route, $this->tech);
        $this->assertSame([409, 'feature_disabled'], [$c, $j['code']]);
        $this->assertStringContainsString('switched off', $j['error']);
        $this->assertSame(403, $this->api('POST', $route, $this->runner)[0], 'ability is checked before the switch');
    }

    // ------------------------------------------------------------------ services

    public function testServiceControlQueuesAValidatedSignedJobAndAsksForConfirmation(): void
    {
        $base = [$this->d(), 'services'];
        [$c, $j] = $this->api('POST', [...$base, 'Spooler', 'restart'], $this->tech, []);
        $this->assertSame([422, 'confirmation_required'], [$c, $j['code']], 'restart interrupts the service');
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='svc_control'"));
        [$c, $j] = $this->api('POST', [...$base, 'Spooler', 'restart'], $this->tech, ['confirm' => true]);
        $this->assertSame(202, $c);
        $row = $this->h->rows("SELECT * FROM endpoint_agent_jobs WHERE job_id='{$j['job_id']}'")[0];
        $this->assertSame(['svc_control', 1, 10], [$row['type'], (int) $row['destructive'], (int) $row['created_by']]);
        $this->assertSame('{"name":"Spooler","action":"restart"}', $row['params_json'], 'exactly the validated parameters, nothing else');
        // start and enable do not interrupt anything: no confirmation, not destructive
        [$c, $j] = $this->api('POST', [...$base, 'Spooler', 'start'], $this->tech);
        $this->assertSame(202, $c);
        $this->assertSame(0, (int) $this->h->one("SELECT destructive FROM endpoint_agent_jobs WHERE job_id='{$j['job_id']}'"));
        foreach (['stop', 'disable'] as $a) {
            $this->assertSame('confirmation_required', $this->api('POST', [...$base, 'Spooler', $a], $this->tech, [])[1]['code'], $a);
        }
        foreach (['enable', 'manual'] as $a) {
            $this->assertSame(202, $this->api('POST', [...$base, 'Spooler', $a], $this->tech, [])[0], $a);
        }
        // audited with who, what and which device
        $this->assertStringContainsString('restart Spooler', $this->auditText());
    }

    private function auditText(): string
    {
        return json_encode($this->h->audit->records()) ?: '';
    }

    public function testHostileServiceNamesAndActionsNeverReachTheQueue(): void
    {
        $base = [$this->d(), 'services'];
        foreach (['-rf', '--now', 'a b', 'x;reboot', '$(id)', "a\nb", '../etc/passwd', 'a%2Fb', str_repeat('a', 201), '@x', 'a`id`'] as $name) {
            [$c, $j] = $this->api('POST', [...$base, $name, 'start'], $this->tech, ['confirm' => true]);
            $this->assertSame([422, 'invalid'], [$c, $j['code'] ?? ''], json_encode($name));
        }
        foreach (['kill', 'RESTART', 'restart;ls', '', 'start --now'] as $action) {
            $c = $this->api('POST', [...$base, 'Spooler', $action], $this->tech, ['confirm' => true])[0];
            $this->assertContains($c, [404, 422], json_encode($action));
        }
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='svc_control'"));
    }

    public function testTheAgentsOwnServiceCannotBeStoppedFromHere(): void
    {
        foreach (['RivetITAgent', 'rivetitagent'] as $n) {
            [$c, $j] = $this->api('POST', [$this->d(), 'services', $n, 'stop'], $this->tech, ['confirm' => true]);
            $this->assertSame([422, 'protected'], [$c, $j['code']], $n);
        }
        [$l, $lt] = $this->linked('L1', null, 'linux');
        foreach (['rivetit-agent', 'rivetit-agent.service'] as $n) {
            $this->assertSame('protected', $this->api('POST', [(string) $l, 'services', $n, 'restart'], $this->tech, ['confirm' => true])[1]['code'], $n);
        }
        // a linux unit gets its suffix; "manual" does not exist in systemd
        [$c, $j] = $this->api('POST', [(string) $l, 'services', 'ssh', 'restart'], $this->tech, ['confirm' => true]);
        $this->assertSame(202, $c);
        $this->assertSame('{"name":"ssh.service","action":"restart"}', $this->h->one("SELECT params_json FROM endpoint_agent_jobs WHERE job_id='{$j['job_id']}'"));
        $this->assertSame(422, $this->api('POST', [(string) $l, 'services', 'ssh', 'manual'], $this->tech, [])[0]);
        $this->assertSame(422, $this->api('POST', [(string) $l, 'services', 'cron.timer', 'start'], $this->tech, [])[0], 'only .service units');
        $this->assertNotSame('', $lt);
    }

    public function testNothingIsQueuedForAnAgentThatDidNotAnnounceTheCapability(): void
    {
        [$old, $oldToken] = $this->linked('OLD', null, 'windows', ['job:collect', 'job:powershell']);
        foreach ([[$old, 'services', 'query'], [$old, 'processes', 'query']] as $r) {
            [$c, $j] = $this->api('POST', array_map('strval', $r), $this->tech);
            $this->assertSame([409, 'unsupported'], [$c, $j['code']], implode('/', $r));
        }
        $this->assertSame(409, $this->api('POST', [(string) $old, 'services', 'Spooler', 'start'], $this->tech)[0]);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=$old"));
        // an agent from before capability negotiation (announced nothing at all) is never assumed to run a remote tool
        [$bare, $bareToken] = $this->linked('BARE', null, 'windows', []);
        $this->h->q("DELETE FROM rmm_device_state WHERE device_id=$bare");
        $this->assertSame(409, $this->api('POST', [(string) $bare, 'processes', 'query'], $this->tech)[0]);
        // and a queued job whose capability later disappears is not offered
        $this->assertSame(202, $this->api('POST', [$this->d(), 'services', 'query'], $this->tech)[0]);
        $this->announce($this->devToken, ['job:collect', 'job:svc_list', 'job:powershell']);   // downgraded: svc_list without the flag
        $this->assertSame([], $this->offered($this->devToken));
        $this->assertSame(409, $this->api('POST', [$this->d(), 'services', 'query'], $this->tech)[0], 'and nothing new is queued while the flag is missing');
        $this->announce($this->devToken, self::allCaps());
        $this->assertCount(1, $this->offered($this->devToken));
        $this->assertNotSame('', $oldToken . $bareToken);
    }

    public function testTheListIsReadBackFromTheDevicesReport(): void
    {
        $this->assertSame('none', $this->api('GET', [$this->d(), 'services'], $this->tech)[1]['state']);
        $this->api('POST', [$this->d(), 'services', 'query'], $this->tech);
        $job = $this->offered($this->devToken)[0];
        $this->assertSame(['type' => 'svc_list'], ['type' => $job['type']]);
        $this->assertSame([], $job['params'], 'an empty parameter object');
        $doc = ['v' => 1, 'platform' => 'windows', 'truncated' => false, 'count' => 2, 'services' => [
            ['name' => 'Spooler', 'display' => 'Print Spooler', 'state' => 'running', 'startup' => 'auto'], ['name' => 'wuauserv', 'display' => 'Windows Update', 'state' => 'stopped', 'startup' => 'manual']]];
        $this->report($this->devToken, $job, 'succeeded', (string) json_encode($doc));
        [$c, $j] = $this->api('GET', [$this->d(), 'services'], $this->tech);
        $this->assertSame(200, $c);
        $this->assertSame(['succeeded', 2, 'Spooler'], [$j['state'], $j['data']['count'], $j['data']['services'][0]['name']]);
        $this->assertSame($job['job_id'], $j['job_id']);
        // a saved-script runner may not read it, nor can it be read through the generic job list
        $this->assertSame(403, $this->api('GET', [$this->d(), 'services'], $this->runner)[0]);
        $jobs = $this->api('GET', [$this->d(), 'jobs'], $this->runner)[1]['data'];
        $this->assertArrayNotHasKey('output', $jobs[0], 'remote-tool output is not job output');
        $this->assertSame(403, $this->h->module->readModel()->job($this->dev, $job['job_id'], $this->runner)->http);
        $this->assertTrue($this->h->module->readModel()->job($this->dev, $job['job_id'], $this->tech)->ok);
        $this->assertSame('Spooler', $this->h->module->readModel()->remoteSnapshot($this->dev, 'services')['data']['services'][0]['name']);
        // a failed job is reported as such, a garbled report is not shown as data
        $this->h->clock->advance(5);
        $this->api('POST', [$this->d(), 'services', 'query'], $this->tech);
        $job2 = $this->offered($this->devToken)[0];
        $this->report($this->devToken, $job2, 'succeeded', 'not json at all');
        $j = $this->api('GET', [$this->d(), 'services'], $this->tech)[1];
        $this->assertNull($j['data']);
        $this->assertNotNull($j['error']);
        // the structured result of a single job
        [$c, $j] = $this->api('GET', [$this->d(), 'remote', 'jobs', $job['job_id']], $this->tech);
        $this->assertSame([200, 'svc_list', 'succeeded'], [$c, $j['type'], $j['state']]);
        $this->assertSame(404, $this->api('GET', [$this->d(), 'remote', 'jobs', 'nope'], $this->tech)[0]);
        $this->assertSame(403, $this->api('GET', [$this->d(), 'remote', 'jobs', $job['job_id']], $this->runner)[0]);
        $col = $this->api('POST', [$this->d(), 'jobs'], $this->tech, ['type' => 'collect']);
        $this->assertSame(201, $col[0]);
        $this->assertSame(404, $this->api('GET', [$this->d(), 'remote', 'jobs', $col[1]['job_id']], $this->tech)[0], 'only remote-tool jobs are read here');
    }

    public function testTheGenericJobRouteCannotQueueARemoteTool(): void
    {
        foreach (['svc_control' => ['name' => 'Spooler', 'action' => 'stop'], 'svc_list' => [], 'proc_kill' => ['pid' => 4242, 'name' => 'x'], 'file_get' => [], 'file_put' => []] as $type => $params) {
            [$c, $j] = $this->api('POST', [$this->d(), 'jobs'], $this->admin, ['type' => $type, 'params' => $params, 'confirm' => true]);
            $this->assertSame([422, 'invalid'], [$c, $j['code']], $type);
        }
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type IN ('svc_control','svc_list','proc_kill','file_get','file_put')"));
    }

    // ------------------------------------------------------------------ processes

    public function testProcessListAndKill(): void
    {
        [$c, $j] = $this->api('POST', [$this->d(), 'processes', 'query'], $this->tech, ['limit' => 50, 'sort' => 'mem']);
        $this->assertSame(202, $c);
        $this->assertSame('{"limit":50,"sort":"mem"}', $this->h->one("SELECT params_json FROM endpoint_agent_jobs WHERE job_id='{$j['job_id']}'"));
        foreach ([['limit' => 0], ['limit' => 9999], ['limit' => 'x'], ['sort' => 'name'], ['limit' => [1]]] as $bad) {
            $this->assertSame(422, $this->api('POST', [$this->d(), 'processes', 'query'], $this->tech, $bad)[0], json_encode($bad));
        }
        $kill = [$this->d(), 'processes', '4242', 'kill'];
        [$c, $j] = $this->api('POST', $kill, $this->tech, ['name' => 'notepad.exe']);
        $this->assertSame([422, 'confirmation_required'], [$c, $j['code']]);
        [$c, $j] = $this->api('POST', $kill, $this->tech, ['name' => 'notepad.exe', 'confirm' => true]);
        $this->assertSame(202, $c);
        $row = $this->h->rows("SELECT * FROM endpoint_agent_jobs WHERE job_id='{$j['job_id']}'")[0];
        $this->assertSame([1, '{"pid":4242,"name":"notepad.exe","force":false}'], [(int) $row['destructive'], $row['params_json']]);
        $this->assertSame(202, $this->api('POST', $kill, $this->tech, ['name' => 'notepad.exe', 'confirm' => true, 'force' => true])[0]);
        foreach ([[$kill, []], [$kill, ['confirm' => true]], [$kill, ['name' => 'x;y', 'confirm' => true]], [$kill, ['name' => '', 'confirm' => true]],
            [[$this->d(), 'processes', '1', 'kill'], ['name' => 'init', 'confirm' => true]], [[$this->d(), 'processes', '0', 'kill'], ['name' => 'x', 'confirm' => true]],
            [[$this->d(), 'processes', '99999999', 'kill'], ['name' => 'x', 'confirm' => true]]] as [$route, $body]) {
            $this->assertSame(422, $this->api('POST', $route, $this->tech, $body)[0], json_encode([$route, $body]));
        }
        $this->assertSame(404, $this->api('POST', [$this->d(), 'processes', 'abc', 'kill'], $this->tech, ['name' => 'x', 'confirm' => true])[0]);
        $this->assertSame(404, $this->api('POST', [$this->d(), 'processes', '-5', 'kill'], $this->tech, ['name' => 'x', 'confirm' => true])[0]);
        $this->assertSame(2, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='proc_kill'"));
    }

    // ------------------------------------------------------------------ every route is client scoped

    /** @return iterable<string,array{string,list<string>,mixed}> */
    public static function deviceRoutes(): iterable
    {
        $tid = '0b1c2d3e-0000-4000-8000-000000000001';
        yield 'services list' => ['GET', ['services'], null];
        yield 'services query' => ['POST', ['services', 'query'], null];
        yield 'service control' => ['POST', ['services', 'Spooler', 'restart'], ['confirm' => true]];
        yield 'processes list' => ['GET', ['processes'], null];
        yield 'processes query' => ['POST', ['processes', 'query'], []];
        yield 'process kill' => ['POST', ['processes', '4242', 'kill'], ['name' => 'x', 'confirm' => true]];
        yield 'mesh info' => ['GET', ['remote', 'mesh'], null];
        yield 'remote job result' => ['GET', ['remote', 'jobs', $tid], null];
        yield 'remote node' => ['PUT', ['remote', 'node'], ['node_id' => 'node//abc']];
        yield 'remote launch terminal' => ['POST', ['remote'], ['view' => 'terminal']];
        yield 'remote launch files' => ['POST', ['remote'], ['view' => 'files']];
        yield 'files list' => ['GET', ['files'], null];
        yield 'file download request' => ['POST', ['files', 'download'], ['path' => '/srv/data/a']];
        yield 'file upload declare' => ['POST', ['files', 'upload'], ['path' => '/srv/data/a', 'size' => 1, 'sha256' => 'x', 'confirm' => true]];
        yield 'file transfer state' => ['GET', ['files', $tid], null];
        yield 'file content get' => ['GET', ['files', $tid, 'content'], null];
        yield 'file content put' => ['PUT', ['files', $tid, 'content'], 'bytes'];
        yield 'file delete' => ['DELETE', ['files', $tid], null];
        yield 'device logs' => ['GET', ['logs'], null];
        yield 'device log stats' => ['GET', ['logs', 'stats'], null];
    }

    /** @param list<string> $tail */
    #[\PHPUnit\Framework\Attributes\DataProvider('deviceRoutes')]
    public function testEveryDeviceRouteIsTheSame404ForAnotherClientsDevice(string $method, array $tail, mixed $body): void
    {
        $this->switchOn(['remote_tools', 'filetransfer', 'logs']);
        $route = [$this->d(), ...$tail];
        [$c, $j] = $this->api($method, $route, $this->scoped, $body);
        $this->assertSame([404, 'not_found'], [$c, $j['code'] ?? null], 'a technician limited to client B sees client A\'s device as not found');
        [$c2, $j2] = $this->api($method, [(string) 987654, ...$tail], $this->scoped, $body);
        $this->assertSame([404, 'not_found'], [$c2, $j2['code'] ?? null], 'and the answer is identical for a device that does not exist');
        $this->assertSame($j['error'], $j2['error'], 'no existence oracle in the text either');
    }

    public function testFleetRoutesWithADeviceFilterAreScopedToo(): void
    {
        $this->switchOn(['remote_tools', 'logs']);
        $this->assertSame(404, $this->api('GET', ['logs'], $this->scoped, null, ['device_id' => $this->d()])[0]);
        $this->assertSame(404, $this->api('GET', ['logs'], $this->scoped, null, ['client_id' => (string) $this->h->clientA])[0]);
        $this->assertSame(200, $this->api('GET', ['logs'], $this->scoped, null, ['client_id' => (string) $this->h->clientB])[0]);
        $this->assertSame(200, $this->api('GET', ['logs'], $this->tech)[0]);
        $this->assertSame(403, $this->api('GET', ['logs'], $this->viewer)[0], 'reading log text needs the job-output grant');
        $this->assertSame(403, $this->api('GET', ['logs', 'stats'], $this->viewer)[0]);
        $this->assertSame(403, $this->api('GET', ['remote', 'settings'], $this->tech)[0], 'settings are for administrators');
        $this->assertSame(403, $this->api('PUT', ['remote', 'settings'], $this->tech, [])[0]);
    }

    // ------------------------------------------------------------------ Mesh terminal and files

    public function testMeshTerminalAndFilesViews(): void
    {
        $mock = new \RivetCore\Tests\Support\MockMeshServer();
        try {
            $key = \RivetCore\Rmm\Mesh\MeshCookie::newLoginKey();
            $this->assertTrue($this->h->module->admin()->saveMesh($this->admin, ['mesh_enabled' => 1, 'mesh_url' => $mock->url(), 'mesh_login_key' => $key, 'mesh_account_template' => 'rivetit-support'])->ok);
            $node = 'node//' . str_repeat('A', 64);
            $this->assertSame(200, $this->api('PUT', [$this->d(), 'remote', 'node'], $this->admin, ['node_id' => $node])[0]);
            $this->assertSame(403, $this->api('PUT', [$this->d(), 'remote', 'node'], $this->tech, ['node_id' => $node])[0], 'mapping a node is for administrators');
            [$c, $j] = $this->api('GET', [$this->d(), 'remote', 'mesh'], $this->tech);
            $this->assertSame([200, true, true, $node], [$c, $j['configured'], $j['mapped'], $j['node_id']]);
            $this->assertSame(['desktop', 'terminal', 'files'], $j['views']);
            $this->assertSame(['desktop'], $this->api('GET', [$this->d(), 'remote', 'mesh'], $this->runner)[1]['views'], 'a remote.launch-only role is offered the desktop only');
            $this->assertSame([], $this->api('GET', [$this->d(), 'remote', 'mesh'], $this->viewer)[1]['views']);

            $mode = static function (string $url): string {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

                return (string) ($q['viewmode'] ?? '');
            };
            foreach (['desktop' => '11', 'terminal' => '12', 'files' => '13'] as $view => $want) {
                [$c, $j] = $this->api('POST', [$this->d(), 'remote'], $this->tech, ['force' => true, 'view' => $view]);
                $this->assertSame(200, $c, json_encode($j));
                $this->assertSame($want, $mode($j['url']), $view);
                $this->assertStringContainsString('gotonode=' . rawurlencode($node), $j['url']);
            }
            [$c, $j] = $this->api('POST', [$this->d(), 'remote'], $this->tech, ['force' => true]);
            $this->assertSame('11', $mode($j['url']), 'no view means the desktop, exactly as before');
            $this->assertStringContainsString('opened MeshCentral terminal session', $this->auditText());

            // the rules around the views
            $this->assertSame(200, $this->api('POST', [$this->d(), 'remote'], $this->runner, ['force' => true, 'view' => 'desktop'])[0] === 200 ? 200 : 0, 'desktop stays a remote.launch matter');
            [$c, $j] = $this->api('POST', [$this->d(), 'remote'], $this->runner, ['force' => true, 'view' => 'terminal']);
            $this->assertSame([403, 'forbidden'], [$c, $j['code']], 'the terminal needs remote.manage as well');
            $this->assertSame(403, $this->api('POST', [$this->d(), 'remote'], $this->runner, ['force' => true, 'view' => 'files'])[0]);
            foreach ([['view' => 'registry'], ['view' => ['terminal']], ['view' => 5], ['view' => 'terminal;x']] as $bad) {
                $this->assertSame(422, $this->api('POST', [$this->d(), 'remote'], $this->tech, ['force' => true] + $bad)[0], json_encode($bad));
            }
            $this->assertSame(404, $this->api('POST', [$this->d(), 'remote'], $this->scoped, ['force' => true, 'view' => 'terminal'])[0]);
            $this->h->module->settings()->update(['features_json' => ['monitoring' => true, 'jobs' => true]]);
            [$c, $j] = $this->api('POST', [$this->d(), 'remote'], $this->tech, ['force' => true, 'view' => 'terminal']);
            $this->assertSame([409, 'feature_disabled'], [$c, $j['code']], 'a terminal is a shell: it follows the remote_tools switch');
            $this->assertSame(200, $this->api('POST', [$this->d(), 'remote'], $this->tech, ['force' => true, 'view' => 'desktop'])[0], 'the desktop does not');
        } finally {
            $mock->stop();
        }
    }

    // ------------------------------------------------------------------ settings

    public function testSwitchingOnFileTransferAndLogsNeedsAnAcknowledgement(): void
    {
        $s = $this->api('GET', ['remote', 'settings'], $this->admin);
        $this->assertSame(200, $s[0]);
        $this->assertTrue($s[1]['features']['remote_tools']);
        $this->assertFalse($s[1]['features']['filetransfer']);
        $this->assertFalse($s[1]['features']['logs']);
        $this->assertStringContainsString('cannot be un-copied', $s[1]['file_transfer_risk']);
        $this->assertStringContainsString('multiplies database size', $s[1]['logs_storage_warning']);
        [$c, $j] = $this->api('PUT', ['remote', 'settings'], $this->admin, ['features' => ['filetransfer' => true]]);
        $this->assertSame([422, 'confirmation_required'], [$c, $j['code']]);
        $this->assertStringContainsString('accept_filetransfer_risk', $j['error']);
        $this->assertFalse($this->h->module->settings()->featureOn('filetransfer'));
        [$c, $j] = $this->api('PUT', ['remote', 'settings'], $this->admin, ['features' => ['logs' => true]]);
        $this->assertSame([422, 'confirmation_required'], [$c, $j['code']]);
        $this->assertFalse($this->h->module->settings()->featureOn('logs'));
        [$c, $j] = $this->api('PUT', ['remote', 'settings'], $this->admin, ['features' => ['filetransfer' => true, 'logs' => true], 'accept_filetransfer_risk' => true, 'accept_logs_storage' => true,
            'transfer_allow' => ['linux' => ['/srv/shared'], 'windows' => ['C:\\Data']], 'logs_collect' => ['linux' => [['units' => ['ssh.service'], 'min_level' => 'warning']], 'windows' => [['channel' => 'System', 'levels' => ['error'], 'event_ids' => [7036]]]]]);
        $this->assertSame(200, $c, json_encode($j));
        $this->assertTrue($j['features']['filetransfer'] && $j['features']['logs'] && $j['features']['remote_tools']);
        $this->assertSame(['/srv/shared'], $j['transfer_allow']['linux']);
        $this->assertStringContainsString('Remote Settings Changed', $this->auditText());
        // turning it off needs no acknowledgement and keeps the rest
        $this->assertSame(200, $this->api('PUT', ['remote', 'settings'], $this->admin, ['features' => ['filetransfer' => false]])[0]);
        $this->assertFalse($this->h->module->settings()->featureOn('filetransfer'));
        $this->assertTrue($this->h->module->settings()->featureOn('logs'));
        // hostile settings are refused as a whole and nothing is written
        foreach ([['transfer_allow' => ['linux' => ['/']]], ['transfer_allow' => ['linux' => ['/etc']]], ['transfer_allow' => ['linux' => ['/srv/../etc']]], ['transfer_allow' => ['windows' => ['C:\\']]],
            ['transfer_allow' => ['mac' => ['/x/y']]], ['transfer_allow' => 'all'], ['logs_collect' => ['linux' => [['units' => ['-x']]]]], ['logs_collect' => ['linux' => [['units' => ['a b']]]]],
            ['logs_collect' => ['windows' => [['channel' => 'System; calc', 'levels' => ['error']]]]], ['logs_collect' => ['windows' => [['channel' => 'System', 'levels' => ['loud']]]]],
            ['logs_collect' => ['windows' => [['channel' => 'System', 'levels' => ['error'], 'event_ids' => [70000]]]]], ['limits' => ['transfer_max_bytes' => 9999999999]], ['limits' => ['nope' => 1]],
            ['features' => ['logs' => 'yes']], ['features' => ['monitoring' => false]], ['surprise' => 1]] as $bad) {
            $this->assertSame(422, $this->api('PUT', ['remote', 'settings'], $this->admin, $bad)[0], json_encode($bad));
        }
        $this->assertSame(['/srv/shared'], $this->h->module->remoteSettings()->allowRoots()['linux']);
    }
}
