<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Rmm\Contracts\RmmModuleStateInterface;
use RivetCore\Rmm\Http\DeviceApi;
use RivetCore\Rmm\Http\RmmRequest;
use RivetCore\Rmm\Http\RmmResponse;
use RivetCore\Rmm\Migration\Migration0014EndpointAgent;
use RivetCore\Rmm\Migration\Migration0015EndpointAgentConverge;
use RivetCore\Rmm\Migration\Migration0016ModuleSwitches;
use RivetCore\Rmm\RmmModule;
use RivetCore\Testing\InMemoryRmmAssets;
use RivetCore\Testing\InMemoryRmmAudit;
use RivetCore\Testing\InMemoryRmmMetricSink;
use RivetCore\Testing\InMemoryRmmModuleState;
use RivetCore\Testing\InMemoryRmmTenancy;
use RivetCore\Testing\InMemorySecretBox;

/**
 * A fully wired module for the integration tests: the REAL endpoint_agent_* tables on the scratch database (created by Core's own
 * migrations 0014 to 0016, strict SQL mode) and Core's in-memory reference adapters for everything the edition owns. Refuses any
 * database whose name does not contain "scratch".
 */
final class RmmHarness
{
    public const TABLES = ['endpoint_agent_checkins', 'endpoint_agent_checks', 'endpoint_agent_jobs', 'endpoint_agent_mesh_nodes', 'endpoint_agent_releases',
        'endpoint_agent_enroll_attempts', 'endpoint_agent_enrollment_tokens', 'endpoint_agent_devices', 'endpoint_agent_binaries'];

    public \mysqli $mysqli;
    public MysqliDatabase $db;
    public CountingDatabase $counting;
    public ClockInterface $clock;
    public InMemoryRmmTenancy $tenancy;
    public InMemoryRmmAssets $assets;
    public RecordingRmmBridge $bridge;
    public InMemorySecretBox $box;
    public InMemoryRmmAudit $audit;
    public InMemoryRmmMetricSink $metrics;
    public RmmModule $module;
    public DeviceApi $api;
    public int $clientA = 0;
    public int $clientB = 0;
    /** @var array<string,int> bucket => calls inside the current test */
    public array $rateCalls = [];
    /** @var array<string,array{0:int,1:int}> bucket => [limit, window] of the last call */
    public array $rateArgs = [];
    /** @var array<string,int> bucket => limit override (0 = unlimited) */
    public array $rateOverride = [];
    public string $binaryDir;
    /** @var list<int> microseconds the long poll asked to sleep */
    public array $slept = [];
    public int $seqCounter = 0;
    /** The long poll's monotonic clock: it only moves when the poll sleeps, so a 5 s wait costs no real time. */
    public float $pollClock = 1000.0;
    /** @var list<\mysqli> every connection opened by a harness since the last {@see closeAll()} */
    private static array $connections = [];

    /**
     * @param array<string,mixed> $options RmmModule options
     */
    public function __construct(?ClockInterface $clock = null, ?RmmModuleStateInterface $state = null, array $options = [], bool $withModuleState = false)
    {
        $name = (string) getenv('RIVETCORE_TEST_DB_NAME');
        if (!str_contains($name, 'scratch')) {
            throw new \RuntimeException('Refusing: RIVETCORE_TEST_DB_NAME must name a scratch database');
        }
        $m = ScratchDb::connect();
        if ($m === null) {
            throw new \RuntimeException('RIVETCORE_TEST_DB_NAME not set');
        }
        $this->mysqli = $m;
        self::$connections[] = $m;
        $this->db = new MysqliDatabase($m);
        $this->counting = new CountingDatabase($this->db);
        $this->clock = $clock ?? new MutableClock();
        $this->binaryDir = sys_get_temp_dir() . '/rmm_bin_' . bin2hex(random_bytes(4));
        mkdir($this->binaryDir, 0700);

        foreach ([new Migration0014EndpointAgent(), new Migration0015EndpointAgentConverge(), new Migration0016ModuleSwitches()] as $mig) {
            $mig->up($this->db);
        }
        $this->wipe();

        $this->tenancy = new InMemoryRmmTenancy();
        $this->clientA = $this->tenancy->addClient('Dept A');
        $this->clientB = $this->tenancy->addClient('Dept B');
        $this->assets = new InMemoryRmmAssets();
        $this->bridge = new RecordingRmmBridge($this->clock);
        $this->box = new InMemorySecretBox(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $this->audit = new InMemoryRmmAudit();
        $this->metrics = new InMemoryRmmMetricSink();
        $this->module = new RmmModule(
            $this->counting, $this->clock, $this->tenancy, $this->assets, $this->bridge, $this->box, $this->audit, $this->metrics,
            $state ?? new InMemoryRmmModuleState(true),
            $options + ['binary_dir' => $this->binaryDir, 'allow_insecure_http' => true],
        );
        $this->api = $this->module->deviceApi(
            fn (string $bucket, int $limit, int $window): bool => $this->rateLimit($bucket, $limit, $window),
            $withModuleState,
            function (int $us): void {
                $this->slept[] = $us;
                $this->pollClock += $us / 1e6;
            },
            fn (): float => $this->pollClock,
        );
    }

    /** Close every connection a test opened (a closure cycle keeps the harness alive until the next GC run, and the server has a connection limit). */
    public static function closeAll(): void
    {
        foreach (self::$connections as $c) {
            try {
                $c->close();
            } catch (\Throwable) {
                // already closed
            }
        }
        self::$connections = [];
    }

    public function __destruct()
    {
        foreach (glob($this->binaryDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->binaryDir);
    }

    private function rateLimit(string $bucket, int $limit, int $window): bool
    {
        $this->rateArgs[$bucket] = [$limit, $window];
        $this->rateCalls[$bucket] = ($this->rateCalls[$bucket] ?? 0) + 1;
        $eff = $this->rateOverride[$bucket] ?? $limit;

        return $eff === 0 || $this->rateCalls[$bucket] <= $eff;
    }

    public function wipe(): void
    {
        foreach (self::TABLES as $t) {
            $this->mysqli->query("DELETE FROM `$t`");
        }
        $this->mysqli->query('DELETE FROM endpoint_agent_settings');
        $this->mysqli->query('INSERT INTO endpoint_agent_settings (id) VALUES (1)');
    }

    // ------------------------------------------------------------------ requests

    /**
     * @param array<string,string> $query
     * @param array<string,string> $headers lower-case names
     */
    public function request(string $method, string $endpoint, ?string $rawBody = null, ?string $token = null, array $query = [], array $headers = [], string $ip = '127.0.0.1', bool $secure = true, ?int $declared = null): RmmRequest
    {
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }
        $stream = null;
        if ($rawBody !== null) {
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, $rawBody);
            rewind($stream);
        }

        return new RmmRequest($method, $endpoint, [], $query, $headers, $ip, 'test-agent', $secure, $declared ?? ($rawBody === null ? null : strlen($rawBody)), $stream);
    }

    /**
     * Call a device endpoint with a JSON body (arrays are encoded, strings are sent as given).
     *
     * @param array<string,string> $query
     * @param array<string,string> $headers
     * @return array{0:int,1:array<string,string>,2:mixed,3:RmmResponse}
     */
    public function call(string $method, string $endpoint, mixed $body = null, ?string $token = null, array $query = [], array $headers = [], string $ip = '127.0.0.1', bool $secure = true): array
    {
        $raw = $body === null ? null : (is_string($body) ? $body : (string) json_encode($body));
        $r = $this->api->handle($this->request($method, $endpoint, $raw, $token, $query, $headers + ($raw !== null && !is_string($body) ? ['content-type' => 'application/json'] : []), $ip, $secure));

        return [$r->status, $r->headers, $r->body === null ? null : json_decode($r->body, true), $r];
    }

    // ------------------------------------------------------------------ scenario helpers

    public function enable(): void
    {
        $this->module->settings()->enable();
    }

    public function token(?int $client = null, int $ttlH = 24, int $maxUses = 5, string $ring = 'stable'): string
    {
        $this->enable();

        return $this->module->enrollment()->createToken($client ?? $this->clientA, 0, $ring, $ttlH, $maxUses, 'test', 1)['token'];
    }

    /** @param array<string,mixed> $over */
    public static function device(array $over = []): array
    {
        return array_merge(['install_id' => self::uuid(), 'machine_guid' => strtolower(bin2hex(random_bytes(8))), 'hostname' => 'WS-' . strtoupper(bin2hex(random_bytes(2))), 'os' => 'windows',
            'os_version' => 'Windows 11 23H2', 'arch' => 'amd64', 'serial' => 'SN' . strtoupper(bin2hex(random_bytes(4))), 'manufacturer' => 'Dell', 'model' => 'Latitude 7440',
            'mac_addresses' => [], 'agent_version' => '1.0.0'], $over);
    }

    public static function uuid(): string
    {
        return \RivetCore\Rmm\Job\JobService::uuid();
    }

    /**
     * @param array<string,mixed> $device
     * @return array{0:int,1:array<string,string>,2:mixed,3:RmmResponse}
     */
    public function enroll(string $token, array $device, string $ip = '127.0.0.1'): array
    {
        return $this->call('POST', 'agent_enroll', ['enrollment_token' => $token, 'device' => $device], null, [], [], $ip);
    }

    public static function ts(int $offset = 0): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', time() + $offset);
    }

    /**
     * @param array<string,mixed> $over
     * @return array{0:int,1:array<string,string>,2:mixed,3:RmmResponse}
     */
    public function checkin(string $deviceToken, array $over = []): array
    {
        $this->seqCounter++;
        $body = array_merge(['seq' => $this->seqCounter, 'collected_at' => self::ts(), 'agent_version' => '1.0.0', 'inventory' => null,
            'metrics' => ['cpu_pct' => 10.5, 'mem_pct' => 40.0, 'disk' => [['mount' => 'C:', 'used_pct' => 55.0]], 'net_rx_bps' => 100.0, 'net_tx_bps' => 50.0],
            'checks' => [], 'buffered' => []], $over);

        return $this->call('POST', 'agent_checkin', $body, $deviceToken);
    }

    /** @return mixed first column of the first row */
    public function one(string $sql): mixed
    {
        $r = $this->mysqli->query($sql);
        if (!$r instanceof \mysqli_result) {
            return null;
        }
        $row = $r->fetch_row();

        return $row[0] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $sql): array
    {
        $r = $this->mysqli->query($sql);

        return $r instanceof \mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function q(string $sql): void
    {
        $this->mysqli->query($sql);
    }

    public function integrationId(): int
    {
        return $this->module->settings()->integrationId();
    }

    /** Add an asset to the edition's (in-memory) inventory. @param array<string,mixed> $a */
    public function asset(array $a): int
    {
        return $this->assets->addAsset($a + ['client_id' => $this->clientA]);
    }

    /** Archive an asset of the in-memory edition inventory (the reference model has no archive method of its own). */
    public function archiveAsset(int $assetId): void
    {
        $ref = new \ReflectionProperty($this->assets, 'assets');
        $all = $ref->getValue($this->assets);
        $all[$assetId]['archived'] = true;
        $ref->setValue($this->assets, $all);
    }

    /** A syntactically valid PE image for a machine type (what the real publish path accepts). */
    public static function fakePe(int $machine, int $size, string $fill): string
    {
        $b = 'MZ' . str_repeat("\0", 0x3A) . pack('V', 128);
        $b = str_pad($b, 128, "\0");
        $b .= "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
        $chunk = hash('sha256', $fill . $machine, true);
        $need = $size - strlen($b);

        return $b . substr(str_repeat($chunk, intdiv($need, 32) + 1), 0, $need);
    }

    /**
     * Store a hosted agent binary the way the admin publish path does (file under the binary dir, rows in endpoint_agent_binaries
     * and, for a ring, endpoint_agent_releases) so the read side can be tested without the admin layer.
     *
     * @return array{binary_id:int,sha256:string,size:int,path:string,bytes:string}
     */
    public function publishBinary(string $version, string $arch = 'amd64', int $size = 8192, bool $current = false, ?string $ring = null, int $pct = 100, string $minVersion = '0.0.0'): array
    {
        $bytes = self::fakePe(\RivetCore\Rmm\RmmProtocol::ARCHS[$arch], $size, 'fill-' . $version);
        $name = 'bin_' . bin2hex(random_bytes(16)) . '.bin';
        file_put_contents($this->binaryDir . '/' . $name, $bytes);
        $sha = hash('sha256', $bytes);
        $this->mysqli->query("INSERT INTO endpoint_agent_binaries (version, arch, sha256, size_bytes, storage_name, uploaded_by, active, is_current, created_at) VALUES ('$version', '$arch', '$sha', $size, '$name', 1, 1, " . ($current ? 1 : 0) . ', UTC_TIMESTAMP())');
        $id = (int) $this->mysqli->insert_id;
        if ($ring !== null) {
            $url = $this->module->updates()->updateUrl($arch, $version) ?? 'https://example.test/x';
            $this->mysqli->query("INSERT INTO endpoint_agent_releases (version, url, sha256, min_version, ring, rollout_pct, notes, created_by, created_at, arch, binary_id) VALUES ('$version', '" . $this->mysqli->real_escape_string($url) . "', '$sha', '$minVersion', '$ring', $pct, 'test', 1, UTC_TIMESTAMP(), '$arch', $id)");
        }

        return ['binary_id' => $id, 'sha256' => $sha, 'size' => $size, 'path' => $this->binaryDir . '/' . $name, 'bytes' => $bytes];
    }
}
