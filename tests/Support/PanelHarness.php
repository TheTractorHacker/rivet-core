<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Panel\Http\PanelApi;
use RivetCore\Panel\PanelModule;
use RivetCore\Rmm\Http\RmmRequest;
use RivetCore\Rmm\Http\RmmResponse;
use RivetCore\Testing\InMemoryPanelTicketGateway;

/**
 * The RivetPanel module on the same scratch database and in-memory adapters as {@see RmmHarness} (which it wraps, so one test can drive
 * the RMM device API and the panel API against the same tables).
 */
final class PanelHarness
{
    public RmmHarness $rmm;
    public InMemoryPanelTicketGateway $gateway;
    public PanelModule $module;
    public PanelApi $api;
    /** @var array<string,int> bucket => calls inside the test */
    public array $rateCalls = [];
    /** @var array<string,int> bucket => limit override (0 = unlimited) */
    public array $rateOverride = [];
    public int $clientA;
    public int $clientB;

    /** @param array<string,mixed> $options PanelModule options */
    public function __construct(array $options = [], ?InMemoryPanelTicketGateway $gateway = null, bool $wipeRmm = true)
    {
        $this->rmm = new RmmHarness();
        $this->clientA = $this->rmm->clientA;
        $this->clientB = $this->rmm->clientB;
        $this->gateway = $gateway ?? new InMemoryPanelTicketGateway();
        $this->module = new PanelModule($this->rmm->counting, $this->rmm->clock, $this->rmm->tenancy, $this->rmm->audit, $this->gateway, $options + ['allow_insecure_http' => true]);
        $this->api = $this->module->api(fn (string $bucket, int $limit, int $window): bool => $this->rateLimit($bucket, $limit, $window));
    }

    private function rateLimit(string $bucket, int $limit, int $window): bool
    {
        $this->rateCalls[$bucket] = ($this->rateCalls[$bucket] ?? 0) + 1;
        $eff = $this->rateOverride[$bucket] ?? $limit;

        return $eff === 0 || $this->rateCalls[$bucket] <= $eff;
    }

    /**
     * @param array<string,string> $query
     * @param list<string> $segments
     * @param array<string,string> $headers lower-case names
     */
    public function request(string $method, string $endpoint, ?string $raw = null, ?string $token = null, array $query = [], array $segments = [], string $ip = '127.0.0.1', bool $secure = true, array $headers = [], ?int $declared = null): RmmRequest
    {
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }
        $stream = null;
        if ($raw !== null) {
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, $raw);
            rewind($stream);
        }

        return new RmmRequest($method, $endpoint, $segments, $query, $headers, $ip, 'panel-test', $secure, $declared ?? ($raw === null ? null : strlen($raw)), $stream);
    }

    /**
     * @param array<string,string> $query
     * @param list<string> $segments
     * @return array{0:int,1:array<string,string>,2:mixed,3:RmmResponse}
     */
    public function call(string $method, string $endpoint, mixed $body = null, ?string $token = null, array $query = [], array $segments = [], string $ip = '127.0.0.1', bool $secure = true): array
    {
        $raw = $body === null ? null : (is_string($body) ? $body : (string) json_encode($body));
        $r = $this->api->handle($this->request($method, $endpoint, $raw, $token, $query, $segments, $ip, $secure));

        return [$r->status, $r->headers, $r->body === null ? null : json_decode($r->body, true), $r];
    }

    /** @return array{token_id:int,token:string,expires_at:string} */
    public function mint(?int $client = null, ?int $contact = null, int $ttlH = 24): array
    {
        return $this->module->enrollment()->createToken($client ?? $this->clientA, $contact, $ttlH, 'test', 1);
    }

    /** @param array<string,mixed> $over */
    public static function device(array $over = []): array
    {
        return $over + ['install_id' => RmmHarness::uuid(), 'hostname' => 'PC-' . strtoupper(bin2hex(random_bytes(2))), 'os' => 'windows', 'os_version' => 'Windows 11', 'app_version' => '0.1.0'];
    }

    /**
     * @param array<string,mixed> $device
     * @return array{0:int,1:array<string,string>,2:mixed,3:RmmResponse}
     */
    public function enroll(string $token, array $device = [], string $ip = '127.0.0.1'): array
    {
        return $this->call('POST', 'panel_enroll', ['enrollment_token' => $token, 'device' => self::device($device)], null, [], [], $ip);
    }

    /**
     * Mint and enroll in one go; returns the device credential and id.
     *
     * @param array<string,mixed> $device
     * @return array{token:string,device_id:int}
     */
    public function device_for(?int $client = null, ?int $contact = null, array $device = []): array
    {
        [$c, , $j] = $this->enroll($this->mint($client, $contact)['token'], $device);
        if ($c !== 201) {
            throw new \RuntimeException('enrollment failed: ' . json_encode($j));
        }

        return ['token' => $j['device_token'], 'device_id' => $j['device_id']];
    }

    /** @return list<string> the audit actions recorded so far */
    public function actions(): array
    {
        return array_column($this->rmm->audit->records(), 'action');
    }
}
