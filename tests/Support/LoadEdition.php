<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Redis\RateLimiter;
use RivetCore\Rmm\Contracts\RmmAssetsInterface;
use RivetCore\Rmm\Contracts\RmmBridgeInterface;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Http\DeviceApi;
use RivetCore\Rmm\Migration\Migration0014EndpointAgent;
use RivetCore\Rmm\Migration\Migration0015EndpointAgentConverge;
use RivetCore\Rmm\Migration\Migration0016ModuleSwitches;
use RivetCore\Rmm\Migration\Migration0018InventoryFoundation;
use RivetCore\Rmm\RmmModule;
use RivetCore\Support\SystemClock;
use RivetCore\Testing\InMemoryRmmAudit;
use RivetCore\Testing\InMemorySecretBox;

/**
 * A stateless, database-backed "edition" for the load measurements (scripts/rmm-load): the real endpoint_agent_* tables plus small
 * tables standing in for the edition's assets, RMM links, alerts and metric samples, so every PHP process of a `php -S` server (or
 * several workers) sees the same state and the statements an edition really makes (a link UPDATE per check-in, one multi-row sample
 * INSERT) are in the numbers. Scratch databases only: the database name must contain "scratch".
 */
final class LoadEdition
{
    public const SECRET_SEED = 'rmm-load-secretbox-key';

    public \mysqli $mysqli;
    public DatabaseInterface $db;
    public RmmModule $module;
    public DeviceApi $api;
    public ClockInterface $clock;

    public function __construct(?string $binaryDir = null)
    {
        $name = (string) getenv('RIVETCORE_TEST_DB_NAME');
        if (!str_contains($name, 'scratch')) {
            throw new \RuntimeException('Refusing: RIVETCORE_TEST_DB_NAME must name a scratch database');
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = mysqli_init();
        $m->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
        $m->real_connect(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', (string) getenv('RIVETCORE_TEST_DB_USER'), (string) getenv('RIVETCORE_TEST_DB_PASS'), $name, (int) (getenv('RIVETCORE_TEST_DB_PORT') ?: 0));
        if ($m->connect_errno) {
            throw new \RuntimeException('database connection failed');
        }
        $m->set_charset('utf8mb4');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->mysqli = $m;
        $this->db = new MysqliDatabase($m);
        $this->clock = new SystemClock();
        $limiter = TestRedis::available() ? new RateLimiter(new TestRedis(), 'rmm-load:') : null;
        $dir = $binaryDir ?? sys_get_temp_dir();
        $stateDir = (string) getenv('RMM_LOAD_STATE_DIR');
        $this->module = new RmmModule(
            $this->db, $this->clock, $this->tenancy(), $this->assets(), $this->bridge(), new InMemorySecretBox(hash('sha256', self::SECRET_SEED, true)), new InMemoryRmmAudit(), $this->sink(),
            new \RivetCore\Testing\InMemoryRmmModuleState(true, $stateDir !== '' ? $stateDir : null), ['binary_dir' => $dir, 'allow_insecure_http' => true],
        );
        $this->api = $this->module->deviceApi(static fn (string $bucket, int $limit, int $window): bool => $limiter === null || $limiter->hit($bucket, $limit, $window)['allowed']);
    }

    public static function migrate(DatabaseInterface $db): void
    {
        foreach ([new \RivetCore\Jobs\Migration\Migration0002IntegrationJobs(), new \RivetCore\Jobs\Migration\Migration0012JobHeartbeat(), new Migration0014EndpointAgent(),
            new Migration0015EndpointAgentConverge(), new Migration0016ModuleSwitches(), new Migration0018InventoryFoundation()] as $mig) {
            $mig->up($db);
        }
        $db->execute('CREATE TABLE IF NOT EXISTS load_assets (asset_id int AUTO_INCREMENT PRIMARY KEY, client_id int NOT NULL, name varchar(200), serial varchar(100), model varchar(200), make varchar(200), os varchar(200), KEY idx_serial (serial)) ENGINE=InnoDB');
        $db->execute('CREATE TABLE IF NOT EXISTS load_links (integration_id int NOT NULL, asset_id int NOT NULL, agent_key varchar(64) NOT NULL, status varchar(20) NOT NULL DEFAULT \'unknown\', cpu_pct int NULL, ram_pct int NULL, disk_pct int NULL, last_seen datetime NULL, ticks int NOT NULL DEFAULT 0, PRIMARY KEY (integration_id, asset_id), UNIQUE KEY uq_key (integration_id, agent_key)) ENGINE=InnoDB');
        $db->execute('CREATE TABLE IF NOT EXISTS load_alerts (alert_id int AUTO_INCREMENT PRIMARY KEY, integration_id int NOT NULL, alert_key varchar(190) NOT NULL, asset_id int NULL, status varchar(20) NOT NULL DEFAULT \'new\', message varchar(500), UNIQUE KEY uq_alert (integration_id, alert_key)) ENGINE=InnoDB');
        $db->execute('CREATE TABLE IF NOT EXISTS load_metrics (id bigint AUTO_INCREMENT PRIMARY KEY, asset_id int NOT NULL, metric_key varchar(40) NOT NULL, instance varchar(64) NULL, value double NOT NULL, at datetime NOT NULL, KEY idx_asset_key_at (asset_id, metric_key, at)) ENGINE=InnoDB');
    }

    public static function wipe(DatabaseInterface $db): void
    {
        foreach (RmmHarness::TABLES as $t) {
            $db->execute("DELETE FROM `$t`");
        }
        foreach (['load_assets', 'load_links', 'load_alerts', 'load_metrics', 'integration_jobs', 'endpoint_agent_settings'] as $t) {
            $db->execute("DELETE FROM `$t`");
        }
        $db->execute('INSERT INTO endpoint_agent_settings (id) VALUES (1)');
    }

    private function tenancy(): RmmTenancyInterface
    {
        return new class () implements RmmTenancyInterface {
            public function visibleClientIds(int $userId): ?array
            {
                return null;
            }

            public function clientName(int $clientId): ?string
            {
                return $clientId > 0 ? "Client $clientId" : null;
            }

            public function locationInClient(int $locationId, int $clientId): bool
            {
                return true;
            }
        };
    }

    private function assets(): RmmAssetsInterface
    {
        $db = $this->db;

        return new class ($db) implements RmmAssetsInterface {
            public function __construct(private readonly DatabaseInterface $db)
            {
            }

            public function findBySerial(string $serial, int $limit = 10): array
            {
                return array_map(static fn (array $r): array => ['asset_id' => (int) $r['asset_id'], 'asset_name' => (string) $r['name'], 'client_id' => (int) $r['client_id'], 'serial' => $r['serial']],
                    $this->db->fetchAll('SELECT asset_id, name, client_id, serial FROM load_assets WHERE serial = ? LIMIT ' . (int) $limit, [$serial]));
            }

            public function findByMacs(array $macs, int $limit = 20): array
            {
                return [];
            }

            public function findByHostname(string $hostname, int $limit = 10): array
            {
                return [];
            }

            public function find(int $assetId): ?array
            {
                $r = $this->db->fetchOne('SELECT asset_id, client_id FROM load_assets WHERE asset_id = ?', [$assetId]);

                return $r === null ? null : ['asset_id' => (int) $r['asset_id'], 'client_id' => (int) $r['client_id'], 'archived' => false];
            }

            public function createForDevice(array $device, int $clientId, int $locationId): int
            {
                return (int) $this->db->execute('INSERT INTO load_assets (client_id, name, serial, model, make, os) VALUES (?, ?, ?, ?, ?, ?)',
                    [$clientId, $device['hostname'], $device['serial'], (string) $device['model'], (string) $device['manufacturer'], 'Windows ' . $device['os_version']])->insertId;
            }

            public function fillBlanks(int $assetId, array $facts): void
            {
                $this->db->execute("UPDATE load_assets SET serial = IF(serial IS NULL OR serial = '', ?, serial), model = IF(model = '', ?, model), make = IF(make = '', ?, make), os = IF(os = '', ?, os) WHERE asset_id = ?",
                    [$facts['serial'], (string) $facts['model'], (string) $facts['manufacturer'], $facts['os'], $assetId]);
            }

            public function moveToClient(int $assetId, int $clientId, int $locationId): void
            {
                $this->db->execute('UPDATE load_assets SET client_id = ? WHERE asset_id = ?', [$clientId, $assetId]);
            }
        };
    }

    private function bridge(): RmmBridgeInterface
    {
        $db = $this->db;

        return new class ($db) implements RmmBridgeInterface {
            public function __construct(private readonly DatabaseInterface $db)
            {
            }

            public function ensureIntegration(string $type, string $name): int
            {
                return 1;
            }

            public function integrationExists(int $integrationId, string $type): bool
            {
                return $integrationId === 1;
            }

            public function upsertLink(int $integrationId, int $assetId, string $agentKey, array $facts): void
            {
                $this->db->execute('DELETE FROM load_links WHERE integration_id = ? AND agent_key = ? AND asset_id <> ?', [$integrationId, $agentKey, $assetId]);
                $this->db->execute('INSERT INTO load_links (integration_id, asset_id, agent_key) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE agent_key = VALUES(agent_key)', [$integrationId, $assetId, $agentKey]);
            }

            public function removeLink(int $integrationId, string $agentKey): void
            {
                $this->db->execute('DELETE FROM load_links WHERE integration_id = ? AND agent_key = ?', [$integrationId, $agentKey]);
            }

            public function applyHealth(int $integrationId, int $assetId, array $health): bool
            {
                return $this->db->execute("UPDATE load_links SET status = 'online', cpu_pct = ?, ram_pct = ?, disk_pct = ?, last_seen = UTC_TIMESTAMP(), ticks = ticks + 1 WHERE integration_id = ? AND asset_id = ?",
                    [$health['cpu_pct'], $health['ram_pct'], $health['disk_pct'], $integrationId, $assetId])->affectedRows > 0;
            }

            public function markOffline(int $integrationId, array $agentKeys): int
            {
                if ($agentKeys === []) {
                    return 0;
                }

                return $this->db->execute("UPDATE load_links SET status = 'offline' WHERE integration_id = ? AND status = 'online' AND agent_key IN (" . implode(',', array_fill(0, count($agentKeys), '?')) . ')', array_merge([$integrationId], $agentKeys))->affectedRows;
            }

            public function openAlert(int $integrationId, string $alertKey, ?int $assetId, int $clientId, string $severity, string $message, array $raw): int
            {
                $this->db->execute('INSERT IGNORE INTO load_alerts (integration_id, alert_key, asset_id, message) VALUES (?, ?, ?, ?)', [$integrationId, $alertKey, $assetId, mb_substr($message, 0, 500)]);

                return (int) ($this->db->fetchOne('SELECT alert_id FROM load_alerts WHERE integration_id = ? AND alert_key = ?', [$integrationId, $alertKey])['alert_id'] ?? 0);
            }

            public function resolveAlert(int $integrationId, int $alertId): void
            {
                $this->db->execute("UPDATE load_alerts SET status = 'resolved' WHERE integration_id = ? AND alert_id = ?", [$integrationId, $alertId]);
            }

            public function reassignAlerts(int $integrationId, int $assetId, int $clientId): void
            {
            }

            public function savedPowerShellScript(int $scriptId): ?string
            {
                return null;
            }

            public function recordRemoteSession(int $assetId, int $clientId, int $userId, string $connectionType, string $reference, ?string $ipAddress, ?string $userAgent): void
            {
            }
        };
    }

    private function sink(): RmmMetricSinkInterface
    {
        $db = $this->db;

        return new class ($db) implements RmmMetricSinkInterface {
            public function __construct(private readonly DatabaseInterface $db)
            {
            }

            public function ingest(array $samples, int $integrationId): void
            {
                foreach (array_chunk($samples, 500) as $chunk) {
                    $ph = [];
                    $args = [];
                    foreach ($chunk as $s) {
                        $ph[] = '(?, ?, ?, ?, ?)';
                        array_push($args, $s['asset_id'], $s['key'], $s['instance'], $s['value'], $s['at']->format('Y-m-d H:i:s'));
                    }
                    $this->db->execute('INSERT INTO load_metrics (asset_id, metric_key, instance, value, at) VALUES ' . implode(',', $ph), $args);
                }
            }
        };
    }
}
