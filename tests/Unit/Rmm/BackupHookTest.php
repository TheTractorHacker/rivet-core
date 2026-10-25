<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Store\BackupHook;
use RivetCore\Rmm\Store\Postgres\PostgresConfig;

final class BackupHookTest extends TestCase
{
    private function config(): PostgresConfig
    {
        return PostgresConfig::fromArray(['database' => 'rivet_rmm', 'user' => 'rivet', 'password' => 'S3cret-pw!', 'host' => '/var/run/postgresql', 'port' => 5433, 'sslmode' => 'disable', 'schema' => 'rmm']);
    }

    public function testMariadbNeedsNothingExtra(): void
    {
        $d = BackupHook::describe('mariadb');
        $this->assertFalse($d['needs_dump']);
        $this->assertNull($d['connection']);
        $this->assertSame([], $d['env']);
        $this->expectException(\InvalidArgumentException::class);
        BackupHook::command('mariadb', null);
    }

    public function testPostgresDescriptorHasNoPasswordAndTheExactCommand(): void
    {
        $d = BackupHook::describe('postgres', $this->config());
        $this->assertTrue($d['needs_dump']);
        $this->assertSame(['host' => '/var/run/postgresql', 'port' => 5433, 'database' => 'rivet_rmm', 'user' => 'rivet', 'schema' => 'rmm', 'sslmode' => 'disable'], $d['connection']);
        $this->assertSame(['PGPASSWORD', 'PGPASSFILE'], $d['password_env']);
        $c = BackupHook::command('postgres', $this->config(), 'vendor/rivet/rivet-core/');
        $this->assertSame(['vendor/rivet/rivet-core/scripts/rmm-postgres-backup.sh'], $c['argv']);
        $this->assertSame("RIVETCORE_PG_DATABASE='rivet_rmm' RIVETCORE_PG_USER='rivet' RIVETCORE_PG_HOST='/var/run/postgresql' RIVETCORE_PG_PORT='5433' RIVETCORE_PG_SSLMODE='disable' RIVETCORE_PG_SCHEMA='rmm' 'vendor/rivet/rivet-core/scripts/rmm-postgres-backup.sh'", $c['string']);
        $this->assertStringNotContainsString('S3cret', json_encode([$d, $c], JSON_THROW_ON_ERROR));
    }

    public function testPostgresWithoutAConnectionAndUnknownKindsAreRefused(): void
    {
        foreach ([['postgres', null], ['mongo', null]] as [$k, $c]) {
            try {
                BackupHook::describe($k, $c);
                $this->fail($k);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
