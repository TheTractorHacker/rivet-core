<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Store\RmmDataStoreFactory;
use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetCore\Rmm\Store\RmmDataStores;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Testing\RmmDataStoreContractTestCase;
use RivetCore\Tests\Support\RmmHarness;

/**
 * The shared contract of the HOT RMM stores, run against the MariaDB / MySQL implementation on the scratch database (phase 1 of #89). The PostgreSQL implementation runs the
 * same cases ({@see \RivetCore\Tests\Integration\Rmm\Postgres\PostgresDataStoreContractTest}): only {@see stores()} changes, the control data below always lives in MariaDB.
 */
class MariaDbDataStoreContractTest extends RmmDataStoreContractTestCase
{
    protected ?RmmHarness $h = null;
    protected ?RmmDataStores $stores = null;
    private string $previousLog = '';

    protected function setUp(): void
    {
        if (!getenv('RIVETCORE_TEST_DB_NAME')) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        if (!str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
            $this->markTestSkipped('The test database name must contain "scratch".');
        }
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');
        $this->h = new RmmHarness();
        $this->h->enable();
    }

    protected function tearDown(): void
    {
        RmmHarness::closeAll();
        ini_set('error_log', $this->previousLog);
        $this->h = null;
        $this->stores = null;
    }

    protected function expectedKind(): string
    {
        return RmmDataStoreKind::MARIADB;
    }

    protected function stores(): RmmDataStores
    {
        return $this->stores ??= RmmDataStoreFactory::create(RmmDataStoreKind::MARIADB, $this->h->module->sql(), new DatabaseMetricSink($this->h->db, $this->h->clock));
    }

    protected function device(int $clientId, string $hostname): int
    {
        [, , $j] = $this->h->enroll($this->h->token(null, 24, 50), $this->h::device(['hostname' => $hostname]));
        $id = (int) $j['device_id'];
        $this->h->q("UPDATE endpoint_agent_devices SET client_id = $clientId, hostname = '" . $this->h->mysqli->real_escape_string($hostname) . "' WHERE device_id = $id");

        return $id;
    }

    protected function package(string $name): int
    {
        $this->h->q("INSERT INTO rmm_packages (name, created_at, updated_at) VALUES ('" . $this->h->mysqli->real_escape_string($name) . "', NOW(), NOW())");

        return (int) $this->h->mysqli->insert_id;
    }

    protected function vm(int $deviceId): int
    {
        $this->h->q("INSERT INTO rmm_hypervisor_vms (device_id, vm_key, first_seen_at, last_seen_at) VALUES ($deviceId, 'qemu/" . random_int(1, 999999) . "', NOW(), NOW())");

        return (int) $this->h->mysqli->insert_id;
    }

    protected function deleteVm(int $vmId): void
    {
        $this->h->q("DELETE FROM rmm_hypervisor_vms WHERE vm_id = $vmId");
    }

    protected function existingVms(array $vmIds): array
    {
        if ($vmIds === []) {
            return [];
        }

        return array_map('intval', array_column($this->h->rows('SELECT vm_id FROM rmm_hypervisor_vms WHERE vm_id IN (' . implode(',', array_map('intval', $vmIds)) . ')'), 'vm_id'));
    }
}
