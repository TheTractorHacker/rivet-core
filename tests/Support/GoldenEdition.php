<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Redis\RateLimiter;
use RivetCore\Rmm\Http\DeviceApi;
use RivetCore\Rmm\Http\RmmRequest;
use RivetCore\Rmm\Migration\Migration0014EndpointAgent;
use RivetCore\Rmm\Migration\Migration0015EndpointAgentConverge;
use RivetCore\Rmm\Migration\Migration0016ModuleSwitches;
use RivetCore\Rmm\RmmModule;
use RivetCore\Support\SystemClock;
use RivetCore\Testing\InMemoryRmmAssets;
use RivetCore\Testing\InMemoryRmmAudit;
use RivetCore\Testing\InMemoryRmmBridge;
use RivetCore\Testing\InMemoryRmmMetricSink;
use RivetCore\Testing\InMemoryRmmTenancy;
use RivetCore\Testing\InMemorySecretBox;

/**
 * A Core-backed "edition" for the golden-transcript replay: the real endpoint_agent_* tables on a scratch database and Core's in-memory
 * reference adapters for everything an edition owns. A `php -S` server forgets everything between requests, so the in-memory
 * adapters are serialised to a state directory after each request and loaded before the next one (single-threaded server: no locking
 * beyond flock). Used by scripts/rmm-golden/{core-router,adapter-core}.php; never point it at anything but a scratch database.
 */
final class GoldenEdition
{
    public const SECRET_BOX_KEY_SEED = 'rmm-golden-core-secretbox-key';

    public InMemoryRmmTenancy $tenancy;
    public InMemoryRmmAssets $assets;
    public InMemoryRmmBridge $bridge;
    public InMemoryRmmAudit $audit;
    public InMemoryRmmMetricSink $metrics;
    public InMemorySecretBox $box;
    public RmmModule $module;
    public DeviceApi $api;
    public \mysqli $mysqli;
    public ClockInterface $clock;

    public function __construct(public readonly string $stateDir, public readonly bool $insecureHttp, bool $withModuleState = false)
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
        $this->clock = new SystemClock();
        if (!is_dir($stateDir . '/bin')) {
            mkdir($stateDir . '/bin', 0700, true);
        }
        $this->box = new InMemorySecretBox(hash('sha256', self::SECRET_BOX_KEY_SEED, true));
        $loaded = is_file($this->file()) ? @unserialize((string) file_get_contents($this->file())) : false;
        if (is_array($loaded) && ($loaded['tenancy'] ?? null) instanceof InMemoryRmmTenancy) {
            [$this->tenancy, $this->assets, $this->bridge, $this->audit, $this->metrics] = [$loaded['tenancy'], $loaded['assets'], $loaded['bridge'], $loaded['audit'], $loaded['metrics']];
        } else {
            $this->tenancy = new InMemoryRmmTenancy();
            $this->assets = new InMemoryRmmAssets();
            $this->bridge = new InMemoryRmmBridge($this->clock);
            $this->audit = new InMemoryRmmAudit();
            $this->metrics = new InMemoryRmmMetricSink();
        }
        $db = new MysqliDatabase($m);
        $this->module = new RmmModule($db, $this->clock, $this->tenancy, $this->assets, $this->bridge, $this->box, $this->audit, $this->metrics,
            $withModuleState ? new \RivetCore\Testing\InMemoryRmmModuleState(true) : null,
            ['binary_dir' => $stateDir . '/bin', 'allow_insecure_http' => $insecureHttp]);
        $limiter = TestRedis::available() ? new RateLimiter(new TestRedis(), 'rmm-golden:') : null;
        $this->api = $this->module->deviceApi(
            static fn (string $bucket, int $limit, int $window): bool => $limiter === null || $limiter->hit($bucket, $limit, $window)['allowed'],
            $withModuleState,
        );
    }

    public static function migrate(\mysqli $m): void
    {
        $db = new MysqliDatabase($m);
        foreach ([new Migration0014EndpointAgent(), new Migration0015EndpointAgentConverge(), new Migration0016ModuleSwitches()] as $mig) {
            $mig->up($db);
        }
    }

    private function file(): string
    {
        return $this->stateDir . '/edition.ser';
    }

    /** Persist the in-memory adapters for the next request. */
    public function save(): void
    {
        $tmp = $this->file() . '.' . getmypid();
        file_put_contents($tmp, serialize(['tenancy' => $this->tenancy, 'assets' => $this->assets, 'bridge' => $this->bridge, 'audit' => $this->audit, 'metrics' => $this->metrics]));
        rename($tmp, $this->file());
    }

    /** @param array<string,string> $query @param array<string,string> $headers */
    public function request(string $method, string $endpoint, array $query, array $headers, string $ip, bool $secure, ?int $declared): RmmRequest
    {
        return new RmmRequest($method, $endpoint, [], $query, $headers, $ip, $headers['user-agent'] ?? null, $secure, $declared, fopen('php://input', 'rb') ?: null);
    }
}
