<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;

/**
 * Base of the Phase 5 (software deployment) tests: a harness with Linux enrolment allowed, principals with different grants (admin, a technician who may manage but not
 * deploy, a deployer, a second approver, a viewer, and a user restricted to client B who holds every ability for that client), and helpers that play the device.
 */
abstract class DeployTestCase extends RmmTestCase
{
    protected RmmPrincipal $admin;
    protected RmmPrincipal $tech;
    protected RmmPrincipal $deployer;
    protected RmmPrincipal $second;
    protected RmmPrincipal $viewer;
    protected RmmPrincipal $scoped;

    public const HOST = 'downloads.example.com';
    public const SHA = 'aa11bb22cc33dd44ee55ff6600770088aa11bb22cc33dd44ee55ff6600770088';
    public const CODE = '{12345678-ABCD-1234-ABCD-1234567890AB}';

    protected function makeHarness(): RmmHarness
    {
        $view = [RmmAbility::DEVICE_VIEW, RmmAbility::SOFTWARE_VIEW];
        $tech = [...$view, RmmAbility::SOFTWARE_MANAGE, RmmAbility::JOB_RUN_SAVED];

        return new RmmHarness(null, null, ['allow_linux' => true], false, null, new AllowUsersPolicy([
            1 => true,
            10 => $tech,
            11 => [...$tech, RmmAbility::SOFTWARE_DEPLOY],
            12 => [...$view, RmmAbility::SOFTWARE_DEPLOY, RmmAbility::JOB_APPROVE],
            13 => $view,
            16 => true,
        ]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new RmmPrincipal(1, 'Admin');
        $this->tech = new RmmPrincipal(10, 'Tech');
        $this->deployer = new RmmPrincipal(11, 'Deployer');
        $this->second = new RmmPrincipal(12, 'Second');
        $this->viewer = new RmmPrincipal(13, 'Viewer');
        $this->scoped = new RmmPrincipal(16, 'Scoped');
        $this->h->tenancy->restrictUser(16, [$this->h->clientB]);
        $this->h->enable();
        $this->switchOn(['software_deploy']);
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

    /** @param array<string,mixed> $l */
    protected function limits(array $l): void
    {
        $this->assertSame([], $this->h->module->settings()->update(['limits_json' => json_encode($l)]));
    }

    /**
     * @param list<string>|null $caps
     * @return array{0:int,1:string}
     */
    protected function linked(string $name, ?int $client = null, string $os = 'windows', ?array $caps = null, string $osVersion = ''): array
    {
        $this->h->asset(['name' => $name, 'serial' => "SER-$name", 'client_id' => $client ?? $this->h->clientA]);
        $dev = $this->h::device(['serial' => "SER-$name", 'hostname' => $name, 'os' => $os]);
        if ($osVersion !== '') {
            $dev['os_version'] = $osVersion;
        }
        [$c, , $j] = $this->h->enroll($this->h->token($client), $dev);
        $this->assertSame(201, $c);
        $id = (int) $j['device_id'];
        $token = (string) $j['device_token'];
        [$c] = $this->h->checkin($token, ['capabilities' => $caps ?? self::caps($os), 'platform' => $os]);
        $this->assertSame(200, $c);

        return [$id, $token];
    }

    /** @return list<string> */
    protected static function caps(string $os = 'windows', bool $software = true): array
    {
        $caps = ['job:collect', 'job:reboot', $os === 'windows' ? 'job:powershell' : 'job:shell'];
        if ($software) {
            array_push($caps, 'job:software_detect', 'job:software_install', 'software:detect', 'software:install');
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

    // ---- catalog helpers (as the admin)

    protected function allowHost(string $host = self::HOST): void
    {
        $this->assertSame(201, $this->api('POST', ['software_hosts'], $this->admin, ['host' => $host])[0]);
    }

    protected function package(string $name = 'Acme Agent', bool $twoPerson = false): int
    {
        [$c, $j] = $this->api('POST', ['software_packages'], $this->admin, ['name' => $name, 'two_person' => $twoPerson]);
        $this->assertSame(201, $c, json_encode($j));

        return (int) $j['package_id'];
    }

    /** @param array<string,mixed> $over */
    protected function msi(int $pkg, string $version = '2.4.1', array $over = []): int
    {
        $this->allowHost();
        [$c, $j] = $this->api('POST', ['software_packages', (string) $pkg, 'versions'], $this->admin, $over + ['version' => $version, 'os' => 'windows', 'fmt' => 'msi', 'url' => 'https://' . self::HOST . "/acme/$version/acme.msi",
            'sha256' => self::SHA, 'size_bytes' => 1048576, 'product_code' => self::CODE, 'detect_min' => $version]);
        $this->assertSame(201, $c, json_encode($j));

        return (int) $j['version_id'];
    }

    /** @param array<string,mixed> $over */
    protected function apt(int $pkg, string $name = 'rivetit-dummy', string $version = '1.5', array $over = []): int
    {
        [$c, $j] = $this->api('POST', ['software_packages', (string) $pkg, 'versions'], $this->admin, $over + ['version' => $version, 'os' => 'linux', 'fmt' => 'apt', 'pkg_name' => $name]);
        $this->assertSame(201, $c, json_encode($j));

        return (int) $j['version_id'];
    }

    /** @return array{0:int,1:mixed} */
    protected function assign(int $pkg, string $scope = 'global', int $scopeId = 0, string $action = 'install', ?int $version = null, ?RmmPrincipal $by = null): array
    {
        $body = ['package_id' => $pkg, 'scope_type' => $scope, 'scope_id' => $scopeId, 'action' => $action];
        if ($version !== null) {
            $body['version_id'] = $version;
        }
        [$c, $j] = $this->api('POST', ['software_assignments'], $by ?? $this->admin, $body);

        return [$c, $j];
    }

    protected function pass(): array
    {
        $this->h->module->settings()->refresh();

        return $this->h->module->deployScheduler()->run();
    }

    /** @return array<string,mixed> the job of that type the device is offered (exactly one) */
    protected function offeredOne(string $token, string $type): array
    {
        $jobs = array_values(array_filter($this->offered($token), static fn (array $j): bool => $j['type'] === $type));
        $this->assertCount(1, $jobs, "one $type job is offered");

        return $jobs[0];
    }

    protected static function installOut(string $result, array $over = []): string
    {
        $ok = in_array($result, ['would_install', 'would_uninstall', 'installed', 'uninstalled', 'already_installed', 'not_installed', 'needs_reboot'], true);

        return (string) json_encode($over + ['v' => 1, 'ok' => $ok, 'action' => 'install', 'mode' => 'run', 'fmt' => 'msi', 'result' => $result, 'code' => '', 'detail' => '', 'version' => '2.4.1', 'reboot_required' => false]);
    }

    /** @param list<array{key:string,installed:bool,version:string,satisfied:bool}> $items */
    protected static function detectOut(array $items): string
    {
        return (string) json_encode(['v' => 1, 'ok' => true, 'items' => $items]);
    }
}
