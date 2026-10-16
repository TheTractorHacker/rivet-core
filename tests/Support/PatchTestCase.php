<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;

/**
 * Base of the Phase 4 (patch management) tests: a harness with Linux enrolment allowed, principals with different grants (admin, a technician who may manage but not
 * install, an installer, a second approver, a viewer, and a user restricted to client B who holds every ability for that client), and helpers that play the device.
 */
abstract class PatchTestCase extends RmmTestCase
{
    protected RmmPrincipal $admin;
    protected RmmPrincipal $tech;
    protected RmmPrincipal $installer;
    protected RmmPrincipal $second;
    protected RmmPrincipal $viewer;
    protected RmmPrincipal $scoped;

    public const WIN_KB = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
    public const WIN_KB2 = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';
    public const WIN_KB3 = 'cccccccc-3333-4333-8333-cccccccccccc';

    protected function makeHarness(): RmmHarness
    {
        $view = [RmmAbility::DEVICE_VIEW, RmmAbility::PATCH_VIEW];
        $tech = [...$view, RmmAbility::PATCH_MANAGE, RmmAbility::JOB_RUN_SAVED];

        return new RmmHarness(null, null, ['allow_linux' => true], false, null, new AllowUsersPolicy([
            1 => true,
            10 => $tech,
            11 => [...$tech, RmmAbility::PATCH_INSTALL, RmmAbility::JOB_REBOOT],
            12 => [...$view, RmmAbility::PATCH_MANAGE, RmmAbility::JOB_APPROVE],
            13 => $view,
            16 => true,
        ]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new RmmPrincipal(1, 'Admin');
        $this->tech = new RmmPrincipal(10, 'Tech');
        $this->installer = new RmmPrincipal(11, 'Installer');
        $this->second = new RmmPrincipal(12, 'Second');
        $this->viewer = new RmmPrincipal(13, 'Viewer');
        $this->scoped = new RmmPrincipal(16, 'Scoped');
        $this->h->tenancy->restrictUser(16, [$this->h->clientB]);
        $this->h->enable();
        $this->switchOn(['patching']);
    }

    /** @param list<string> $names */
    protected function switchOn(array $names): void
    {
        $f = ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true];
        foreach ($names as $n) {
            $f[$n] = true;
        }
        $this->assertSame([], $this->h->module->settings()->update(['features_json' => $f]));
    }

    /**
     * @param list<string>|null $caps
     * @return array{0:int,1:string}
     */
    protected function linked(string $name, ?int $client = null, string $os = 'windows', ?array $caps = null): array
    {
        $this->h->asset(['name' => $name, 'serial' => "SER-$name", 'client_id' => $client ?? $this->h->clientA]);
        [$c, , $j] = $this->h->enroll($this->h->token($client), $this->h::device(['serial' => "SER-$name", 'hostname' => $name, 'os' => $os]));
        $this->assertSame(201, $c);
        $id = (int) $j['device_id'];
        $token = (string) $j['device_token'];
        $this->announce($token, $caps ?? self::caps($os), $os);

        return [$id, $token];
    }

    /**
     * @param list<string> $caps
     */
    protected function announce(string $token, array $caps, string $os = 'windows'): void
    {
        [$c] = $this->h->checkin($token, ['capabilities' => $caps, 'platform' => $os]);
        $this->assertSame(200, $c);
    }

    /** @return list<string> */
    protected static function caps(string $os = 'windows', bool $patch = true): array
    {
        $caps = ['job:collect', 'job:reboot', $os === 'windows' ? 'job:powershell' : 'job:shell'];
        if ($patch) {
            array_push($caps, 'job:patch_scan', 'job:patch_install', 'patch:scan', 'patch:install');
        }
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

    /** @param array<string,mixed> $job */
    protected function report(string $token, array $job, string $state, string $output, ?int $exit = 0): void
    {
        [$c, , $j] = $this->h->call('POST', 'agent_jobs', ['job_id' => $job['job_id'], 'attempt' => $job['attempt'], 'state' => $state, 'exit_code' => $exit, 'output' => $output,
            'started_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 5), 'finished_at' => gmdate('Y-m-d\TH:i:s\Z')], $token);
        $this->assertSame(200, $c, json_encode($j));
    }

    /**
     * @param list<string> $seg
     * @param array<string,string> $query
     * @return array{0:int,1:mixed,2:\RivetCore\Rmm\Http\RmmResponse}
     */
    protected function api(string $method, array $seg, RmmPrincipal $who, mixed $body = null, array $query = []): array
    {
        return $this->h->tech($method, $seg, $who, $body, $query);
    }

    /**
     * A Windows scan report with the given updates (id => [kind, title, extra]).
     *
     * @param array<string,array{0:string,1:string}> $updates
     */
    protected static function winScan(array $updates, array $over = []): string
    {
        $list = [];
        foreach ($updates as $id => [$kind, $title]) {
            $list[] = ['id' => $id, 'kb' => 'KB5000001', 'title' => $title, 'kind' => $kind, 'severity' => $kind === 'security' ? 'important' : 'unspecified', 'size' => 1048576,
                'reboot_required' => $kind === 'security', 'released_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400 * 3), 'eula_accepted' => true, 'categories' => ['Security Updates'], 'current' => '', 'version' => ''];
        }

        return (string) json_encode($over + ['v' => 1, 'ok' => true, 'manager' => 'wua', 'scanned_at' => gmdate('Y-m-d\TH:i:s\Z'), 'scan_state' => 'ok', 'wu_state' => 'running', 'reboot_pending' => false,
            'last_install_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400 * 9), 'truncated' => false, 'total' => count($list), 'updates' => $list]);
    }

    /** Queue a scan through the API and let the device answer it. @return array{0:int,1:string} */
    protected function scanWith(int $dev, string $token, string $report): array
    {
        [$c, $j] = $this->api('POST', [(string) $dev, 'patches', 'scan'], $this->tech);
        $this->assertSame(202, $c, json_encode($j));
        $jobs = $this->offered($token);
        $this->assertCount(1, $jobs);
        $this->assertSame('patch_scan', $jobs[0]['type']);
        $this->report($token, $jobs[0], 'succeeded', $report);

        return [$c, (string) $jobs[0]['job_id']];
    }
}
