<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;

/**
 * Base of the Phase 6 and 8 integration tests: a harness with Linux enrolment allowed, a private transfer folder, five principals
 * (admin, technician with the remote grants, a saved-script runner with remote.launch but without the remote grants, a viewer, and a technician restricted to client B)
 * and helpers that play the device: announce capabilities, fetch jobs, report results.
 */
abstract class RemoteTestCase extends RmmTestCase
{
    protected RmmPrincipal $admin;
    protected RmmPrincipal $tech;
    protected RmmPrincipal $runner;
    protected RmmPrincipal $viewer;
    /** Has every ability, but only for client B. */
    protected RmmPrincipal $scoped;
    protected string $transferDir = '';
    protected int $dev = 0;
    protected string $devToken = '';

    protected function makeHarness(): RmmHarness
    {
        $this->transferDir = sys_get_temp_dir() . '/rmm_xfer_' . bin2hex(random_bytes(4));
        $tech = [RmmAbility::DEVICE_VIEW, RmmAbility::JOB_RUN_SAVED, RmmAbility::REMOTE_LAUNCH, RmmAbility::REMOTE_MANAGE, RmmAbility::ALERT_MANAGE];

        return new RmmHarness(null, null, ['allow_linux' => true, 'transfer_dir' => $this->transferDir], false, null,
            new AllowUsersPolicy([1 => true, 10 => $tech, 11 => [RmmAbility::DEVICE_VIEW, RmmAbility::JOB_RUN_SAVED, RmmAbility::REMOTE_LAUNCH], 12 => [RmmAbility::DEVICE_VIEW], 16 => true]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new RmmPrincipal(1, 'Admin');
        $this->tech = new RmmPrincipal(10, 'Tech');
        $this->runner = new RmmPrincipal(11, 'Runner');
        $this->viewer = new RmmPrincipal(12, 'Viewer');
        $this->scoped = new RmmPrincipal(16, 'Scoped');
        $this->h->tenancy->restrictUser(16, [$this->h->clientB]);
        $this->h->enable();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->transferDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->transferDir);
        parent::tearDown();
    }

    /**
     * Switch sub-switches on (the legacy four stay on).
     *
     * @param list<string> $names
     */
    protected function switchOn(array $names): void
    {
        $f = ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true];
        foreach ($names as $n) {
            $f[$n] = true;
        }
        $this->assertSame([], $this->h->module->settings()->update(['features_json' => $f]));
    }

    /**
     * Enrol a device linked to an asset and let it announce capabilities.
     *
     * @param list<string> $caps
     * @return array{0:int,1:string}
     */
    protected function linked(string $name, ?int $client = null, string $os = 'windows', ?array $caps = null): array
    {
        $this->h->asset(['name' => $name, 'serial' => "SER-$name", 'client_id' => $client ?? $this->h->clientA]);
        [$c, , $j] = $this->h->enroll($this->h->token($client), $this->h::device(['serial' => "SER-$name", 'hostname' => $name, 'os' => $os]));
        $this->assertSame(201, $c);
        $this->assertSame('linked', $j['status']);
        $id = (int) $j['device_id'];
        $token = (string) $j['device_token'];
        $this->announce($token, $caps ?? self::allCaps($os), $os);

        return [$id, $token];
    }

    /**
     * @param list<string> $caps
     * @return array<string,mixed>
     */
    protected function announce(string $token, array $caps, string $os = 'windows'): array
    {
        [$c, , $j] = $this->h->checkin($token, ['capabilities' => $caps, 'platform' => $os]);
        $this->assertSame(200, $c);

        return $j;
    }

    /** @return list<string> */
    protected static function allCaps(string $os = 'windows'): array
    {
        $caps = ['job:collect', 'job:reboot', 'job:svc_list', 'job:svc_control', 'job:proc_list', 'job:proc_kill', 'job:file_get', 'job:file_put', 'remote:services', 'remote:processes', 'remote:files', 'logs:collect'];
        $caps[] = $os === 'windows' ? 'job:powershell' : 'job:shell';
        sort($caps);

        return $caps;
    }

    /** @return list<array<string,mixed>> the jobs the device is offered now */
    protected function offered(string $token): array
    {
        [$c, , $j] = $this->h->call('GET', 'agent_jobs', null, $token);
        $this->assertSame(200, $c);

        return $j['jobs'];
    }

    /** The device reports a job result. @return array<string,mixed>|null */
    protected function report(string $token, array $job, string $state, string $output, ?int $exit = 0): ?array
    {
        [$c, , $j] = $this->h->call('POST', 'agent_jobs', ['job_id' => $job['job_id'], 'attempt' => $job['attempt'], 'state' => $state, 'exit_code' => $exit, 'output' => $output, 'started_at' => self::iso(-5), 'finished_at' => self::iso(0)], $token);
        $this->assertSame(200, $c, json_encode($j));

        return $j;
    }

    protected static function iso(int $off): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', time() + $off);
    }

    /**
     * Call the technician API.
     *
     * @param list<string> $seg
     * @param array<string,string> $query
     * @return array{0:int,1:mixed,2:\RivetCore\Rmm\Http\RmmResponse}
     */
    protected function api(string $method, array $seg, RmmPrincipal $who, mixed $body = null, array $query = []): array
    {
        return $this->h->tech($method, $seg, $who, $body, $query);
    }
}
