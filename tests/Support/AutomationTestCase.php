<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Testing\InMemoryRmmEvents;

/**
 * Base of the Phase 2 integration tests: a module with every switch on, an administrator, a technician (view and run saved scripts), two approvers, a
 * viewer and a technician restricted to Dept B, an event bus, and helpers to enrol linked devices of either platform.
 */
abstract class AutomationTestCase extends RmmTestCase
{
    protected RmmPrincipal $admin;
    protected RmmPrincipal $tech;
    protected RmmPrincipal $approver;
    protected RmmPrincipal $approver2;
    protected RmmPrincipal $viewer;
    protected RmmPrincipal $scoped;
    protected InMemoryRmmEvents $bus;

    protected function makeHarness(): RmmHarness
    {
        $this->bus = new InMemoryRmmEvents();
        $run = [RmmAbility::DEVICE_VIEW, RmmAbility::JOB_RUN_SAVED];
        $policy = new AllowUsersPolicy([
            1 => true,
            10 => [...$run, RmmAbility::JOB_RUN_SCRIPT],
            11 => [...$run, RmmAbility::JOB_APPROVE],
            13 => [...$run, RmmAbility::JOB_APPROVE],
            12 => [RmmAbility::DEVICE_VIEW],
            16 => [...$run, RmmAbility::JOB_APPROVE, RmmAbility::DEVICE_MANAGE],
        ]);

        return new RmmHarness(null, null, ['allow_linux' => true], false, null, $policy, null, $this->bus);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new RmmPrincipal(1, 'Admin');
        $this->tech = new RmmPrincipal(10, 'Tech');
        $this->approver = new RmmPrincipal(11, 'Approver');
        $this->viewer = new RmmPrincipal(12, 'Viewer');
        $this->approver2 = new RmmPrincipal(13, 'Approver2');
        $this->scoped = new RmmPrincipal(16, 'Scoped');
        $this->h->tenancy->restrictUser(16, [$this->h->clientB]);
        $this->h->enable();
        $this->features([]);
    }

    /** Every switch on, except what a test overrides. @param array<string,bool> $over */
    protected function features(array $over): void
    {
        $f = ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true, 'inventory_software' => true, 'policies' => true, 'scripts' => true];
        $this->h->module->settings()->update(['features_json' => $over + $f]);
    }

    /** @return array{0:int,1:string} [device id, device token] */
    protected function device(string $name, ?int $client = null, string $os = 'windows'): array
    {
        $client ??= $this->h->clientA;
        $this->h->asset(['name' => $name, 'serial' => "SER-$name", 'client_id' => $client]);
        [$c, , $j] = $this->h->enroll($this->h->token($client), $this->h::device(['serial' => "SER-$name", 'hostname' => $name, 'os' => $os, 'os_version' => $os === 'linux' ? 'Ubuntu 24.04' : 'Windows 11']));
        $this->assertSame(201, $c);
        $this->assertSame('linked', $j['status']);

        return [(int) $j['device_id'], $j['device_token']];
    }

    /**
     * A check-in announcing capabilities (an agent of this release), returning the decoded response.
     *
     * @param list<string>|null $caps null = an old agent that announces nothing
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    protected function checkin(string $token, ?array $caps = ['check:disk', 'check:pending_reboot', 'check:script', 'check:service', 'job:collect', 'job:reboot', 'job:powershell', 'job:shell', 'policies', 'software_inventory'], array $over = []): array
    {
        if ($caps !== null) {
            $over += ['capabilities' => $caps];
        }
        [$c, , $j] = $this->h->checkin($token, $over);
        $this->assertSame(200, $c, json_encode($j) ?: '');

        return $j;
    }

    protected function tagDevice(int $deviceId, string $tag): int
    {
        return (int) $this->h->module->tags()->assign($deviceId, $tag, 'manual', 1)['tag_id'];
    }

    /** @return array<string,mixed> */
    protected function policy(string $name, array $settings): array
    {
        return $this->h->module->policyStore()->create(['name' => $name, 'settings' => $settings], 1);
    }

    /** @return list<array<string,mixed>> */
    protected function jobs(int $deviceId): array
    {
        return $this->h->rows("SELECT * FROM endpoint_agent_jobs WHERE device_id = $deviceId ORDER BY created_at, job_id");
    }

    protected function setClock(int $deltaSeconds): void
    {
        $clock = $this->h->clock;
        $this->assertInstanceOf(MutableClock::class, $clock);
        $clock->advance($deltaSeconds);
    }
}
