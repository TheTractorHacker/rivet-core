<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Http\ConsoleApi;
use RivetCore\Rmm\Http\RmmRequest;
use RivetCore\Rmm\Http\RmmResponse;
use RivetCore\Rmm\Mesh\MeshCookie;
use RivetCore\Rmm\Remote\ConsoleLink;
use RivetCore\Testing\InMemoryRmmEvents;

/**
 * Shared set-up of the Rivet Console redeem and events tests: a scratch database, a MockMeshServer, the console switch on with a public URL, one technician per role and
 * two devices (client A and client B), a counting rate limiter and helpers to launch, redeem and report.
 */
abstract class ConsoleTestCase extends RmmTestCase
{
    protected const ORIGIN = 'https://rmm.example.com';

    protected RmmPrincipal $admin;
    protected RmmPrincipal $tech;      // technician (client A and B), remote.manage
    protected RmmPrincipal $techB;     // second technician
    protected RmmPrincipal $launcher;  // remote.launch only (desktop)
    protected int $devA = 0;
    protected int $devB = 0;
    protected string $loginKey = '';
    protected ?MockMeshServer $mesh = null;
    protected RoleMatrixPolicy $policy;
    protected InMemoryRmmEvents $bus;
    protected ConsoleApi $api;
    /** @var array<string,int> */
    protected array $hits = [];
    /** @var array<string,int> bucket => limit override (0 = unlimited) */
    protected array $limitOverride = [];
    protected string $errorLog = '';

    protected function makeHarness(): RmmHarness
    {
        $this->policy = new RoleMatrixPolicy();
        $this->policy->addUser(1, 3, 3, 3, admin: true);
        $this->policy->addUser(18, 3, 3, 2);
        $this->policy->addUser(19, 3, 3, 2);
        $this->policy->addUser(20, 3, 3, 1);
        $this->bus = new InMemoryRmmEvents();

        return new RmmHarness(policy: $this->policy, events: $this->bus);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new RmmPrincipal(1, 'Admin');
        $this->tech = new RmmPrincipal(18, 'Tess');
        $this->techB = new RmmPrincipal(19, 'Tom');
        $this->launcher = new RmmPrincipal(20, 'Lou');
        $this->mesh = new MockMeshServer();
        $this->h->enable();
        $this->loginKey = MeshCookie::newLoginKey();
        $r = $this->h->module->admin()->saveMesh($this->admin, ['mesh_enabled' => 1, 'mesh_url' => $this->mesh->url(), 'mesh_login_key' => $this->loginKey, 'mesh_account_template' => 'rv-{user_id}']);
        $this->assertTrue($r->ok, $r->message);
        $tok = $this->h->token($this->h->clientA, 24, 50);
        $this->devA = $this->mkdev($tok, 'CON-A', $this->h->clientA);
        $this->devB = $this->mkdev($this->h->token($this->h->clientB, 24, 50), 'CON-B', $this->h->clientB);
        $ra = $this->h->module->remoteActions();
        $this->assertTrue($ra->updateSettings($this->admin, ['features' => ['remote_tools' => true, 'remote_console' => true], 'console_public_url' => self::ORIGIN])->ok);
        $this->api = $this->h->module->consoleApi(function (string $bucket, int $limit, int $window): bool {
            $this->hits[$bucket] = ($this->hits[$bucket] ?? 0) + 1;
            $eff = $this->limitOverride[$bucket] ?? $limit;

            return $eff === 0 || $this->hits[$bucket] <= $eff;
        });
        $this->errorLog = sys_get_temp_dir() . '/console_err_' . bin2hex(random_bytes(4)) . '.log';
        ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        $this->mesh?->stop();
        if ($this->errorLog !== '' && is_file($this->errorLog)) {
            unlink($this->errorLog);
        }
        parent::tearDown();
    }

    protected function mkdev(string $token, string $name, int $client): int
    {
        $this->h->asset(['name' => $name, 'serial' => "SER-$name", 'client_id' => $client]);
        [$c, , $j] = $this->h->enroll($token, $this->h::device(['serial' => "SER-$name", 'hostname' => $name]));
        $this->assertSame(201, $c);
        $id = (int) $j['device_id'];
        $this->assertTrue($this->h->module->devices()->setMeshNode($id, 'node//' . str_repeat('B', 24) . $id, 1));
        $this->h->checkin($j['device_token']);

        return $id;
    }

    /**
     * Launch a console session as a technician.
     *
     * @return array{0:string,1:string,2:string} [code, session id, stock fallback url]
     */
    protected function launch(RmmPrincipal $who, int $device, string $view = 'desktop', ?string $kind = null, ?string $ip = '198.51.100.7'): array
    {
        $r = $this->h->module->technician()->launchRemote($who, $device, false, $ip, 'UA', $view, 'standard', true, $kind);
        $this->assertTrue($r->ok, $r->message);
        parse_str((string) parse_url($r->data['console']['url'], PHP_URL_QUERY), $q);

        return [(string) $q['code'], (string) $r->data['session_id'], (string) $r->data['console']['fallback_url']];
    }

    protected function req(string $method, string $segment, ?string $raw, array $headers = [], string $ip = '203.0.113.50', bool $secure = true): RmmRequest
    {
        $stream = null;
        if ($raw !== null) {
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, $raw);
            rewind($stream);
        }

        return new RmmRequest($method, 'endpoint_console', [$segment], [], array_change_key_case($headers), $ip, 'test-agent', $secure, $raw === null ? null : strlen($raw), $stream);
    }

    /** @param array<string,mixed>|string $body */
    protected function redeem(array|string $body, string $ip = '203.0.113.50', ?array $client = ['app' => 'rivet-console', 'version' => '0.1.0', 'platform' => 'win32']): RmmResponse
    {
        $raw = is_string($body) ? $body : (string) json_encode(['code' => $body['code'] ?? '', 'client' => $client] + $body);

        return $this->api->handle($this->req('POST', 'redeem', $raw, ['content-type' => 'application/json'], $ip));
    }

    protected function redeemCode(string $code, string $ip = '203.0.113.50', ?array $client = ['app' => 'rivet-console', 'version' => '0.1.0', 'platform' => 'win32']): RmmResponse
    {
        return $this->redeem(['code' => $code], $ip, $client);
    }

    /** @return array<string,mixed> */
    protected function json(RmmResponse $r): array
    {
        $d = json_decode((string) $r->body, true);
        $this->assertIsArray($d);

        return $d;
    }

    /** Launch and redeem in one go, returning the decoded 200 body. @return array<string,mixed> */
    protected function session(?RmmPrincipal $who = null, ?int $device = null, string $view = 'desktop', ?string $kind = null): array
    {
        [$code] = $this->launch($who ?? $this->tech, $device ?? $this->devA, $view, $kind);
        $r = $this->redeemCode($code);
        $this->assertSame(200, $r->status, (string) $r->body);

        return $this->json($r) + ['_code' => $code];
    }

    /** @param list<array<string,mixed>>|array<string,mixed> $events */
    protected function report(string $token, string $sessionId, array $events, string $ip = '203.0.113.50', ?string $raw = null, ?string $authorization = null): RmmResponse
    {
        $raw ??= (string) json_encode(['session_id' => $sessionId, 'events' => $events]);

        return $this->api->handle($this->req('POST', 'events', $raw, ['content-type' => 'application/json', 'authorization' => $authorization ?? ('Bearer ' . $token)], $ip));
    }

    protected function advance(int $seconds): void
    {
        $this->h->clock->advance($seconds);
    }

    /** Everything Core wrote that an operator can read: audit rows, published events, the error log and the console tables except the hash columns. */
    protected function everythingWritten(): string
    {
        $parts = [json_encode($this->h->audit->records()), json_encode($this->bus->published()), is_file($this->errorLog) ? (string) file_get_contents($this->errorLog) : ''];
        foreach (['endpoint_agent_log_events', 'integration_jobs'] as $t) {
            $parts[] = json_encode($this->h->module->sql()->all("SELECT * FROM `$t`"));
        }

        return implode("\n", $parts);
    }

    /** @return list<array{action:string,description:string,client_id:int,entity_id:int}> */
    protected function auditOf(string $action): array
    {
        return array_values(array_filter($this->h->audit->records(), static fn (array $a): bool => $a['action'] === $action));
    }
}
