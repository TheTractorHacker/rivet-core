<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\RemoteSession;

use PHPUnit\Framework\TestCase;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Database\ExecutionResult;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Rmm\Migration\Migration0050ClientRemotePolicy;
use RivetCore\Rmm\Migration\RemotePolicySchema;
use RivetCore\Rmm\Remote\DbRemoteSessionPolicyStore;
use RivetCore\Rmm\Remote\RemoteProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Tests\Support\FixedClock;

/** The `remote_console` switch, migration 0050 and the SQL of the policy store, against a recording database (no real database is needed or used). */
final class RemoteSessionStorageTest extends TestCase
{
    /** @return DatabaseInterface&object{calls:list<array{sql:string,params:array<mixed>}>,affected:int,row:?array<string,mixed>} */
    private function db(int $affected = 1, ?array $row = null): DatabaseInterface
    {
        return new class ($affected, $row) implements DatabaseInterface {
            /** @var list<array{sql:string,params:array<mixed>}> */
            public array $calls = [];

            /** @param array<string,mixed>|null $row */
            public function __construct(public int $affected, public ?array $row)
            {
            }

            public function fetchOne(string $sql, array $params = []): ?array
            {
                $this->calls[] = ['sql' => $sql, 'params' => $params];

                return $this->row;
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                $this->calls[] = ['sql' => $sql, 'params' => $params];

                return $this->row === null ? [] : [$this->row];
            }

            public function execute(string $sql, array $params = []): ExecutionResult
            {
                $this->calls[] = ['sql' => $sql, 'params' => $params];

                return new ExecutionResult($this->affected, 1);
            }

            public function transaction(callable $callback): mixed
            {
                return $callback();
            }
        };
    }

    private function sql(DatabaseInterface $db): Sql
    {
        return new Sql($db, new FixedClock(new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'))));
    }

    // ------------------------------------------------------------------ the switch and the migration

    public function testTheConsoleSwitchExistsAndIsNotOnByDefault(): void
    {
        $this->assertContains('remote_console', RmmSettings::FEATURES);
        $this->assertSame('remote_console', RemoteProtocol::FEATURE_REMOTE_CONSOLE);
        $this->assertNotContains('remote_console', RmmSettings::LEGACY_FEATURES, 'the legacy defaults (no features_json) never turn it on');
        $this->assertNotContains('background_mode', RmmSettings::FEATURES, 'Background Mode was dropped');
        [, $err] = RmmSettings::validateFeatures(['background_mode' => true]);
        $this->assertNotNull($err, 'the dropped switch is refused, not ignored');
    }

    public function testTheMigrationIsRegisteredInOrderWithAUniqueIdAndSkipsTheMissing0045(): void
    {
        $ids = array_map(static fn ($m): string => $m->id(), CoreMigrations::all());
        $this->assertSame(['0052_rmm_mesh_verify', '0053_rmm_mesh_token_exact', '0054_rmm_signed_contracts'], array_slice($ids, -3), 'update this when a later migration is added');
        $this->assertSame($ids, array_values(array_unique($ids)));
        $nums = array_map(static fn (string $id): int => (int) substr($id, 0, 4), $ids);
        $sorted = $nums;
        sort($sorted);
        $this->assertSame($sorted, $nums, 'ordered');
        $this->assertNotContains(45, $nums, '0045 was never shipped (the reporting views became 0047)');
        $this->assertCount(1, array_keys($nums, 50, true));
        $this->assertCount(1, array_keys($nums, 51, true));
    }

    public function testTheMigrationIsAdditiveAndIdempotent(): void
    {
        $db = $this->db();
        $m = new Migration0050ClientRemotePolicy();
        $m->up($db);
        $first = $db->calls;
        $m->up($db);
        $this->assertSame($first, array_slice($db->calls, count($first)), 'a second run issues the same statements: nothing depends on state it created');
        $tables = [];
        foreach ($first as $c) {
            $sql = $c['sql'];
            $this->assertMatchesRegularExpression('/^CREATE TABLE IF NOT EXISTS `rmm_[a-z_]+`/', $sql, 'only IF NOT EXISTS creates');
            $this->assertDoesNotMatchRegularExpression('/\b(ALTER|DROP|UPDATE|DELETE|TRUNCATE)\b/i', $sql);
            $this->assertStringNotContainsString('rmm_remote_sessions', $sql, 'the edition-owned session log is not touched');
            if (preg_match('/CREATE TABLE IF NOT EXISTS `([a-z_]+)`/', $sql, $mm) === 1) {
                $tables[] = $mm[1];
            }
        }
        $this->assertSame(['rmm_client_remote_policy'], $tables);
        $this->assertSame($tables, array_keys(RemotePolicySchema::tables()));
        $this->assertSame($first[0]['sql'], RemotePolicySchema::tables()['rmm_client_remote_policy']);
    }

    // ------------------------------------------------------------------ the store over SQL

    public function testReadsAreStrictAndAbsentMeansNoPolicy(): void
    {
        $this->assertNull((new DbRemoteSessionPolicyStore($this->sql($this->db(1, null))))->clientPolicy(5));
        $this->assertSame('everything', (new DbRemoteSessionPolicyStore($this->sql($this->db(1, ['policy' => 'everything']))))->clientPolicy(5), 'an unknown stored value is handed to the policy, which denies it');
        $this->assertSame('notify', (new DbRemoteSessionPolicyStore($this->sql($this->db(1, ['policy' => 'notify']))))->clientPolicy(5));
    }

    public function testSetterValidates(): void
    {
        $db = $this->db();
        $c = new DbRemoteSessionPolicyStore($this->sql($db));
        $this->assertFalse($c->setClientPolicy(0, 'allow', 1));
        $this->assertFalse($c->setClientPolicy(3, 'sometimes', 1));
        $this->assertSame([], $db->calls, 'nothing is written for a refused value');
        $this->assertTrue($c->setClientPolicy(3, 'notify', 9));
        $this->assertTrue($c->setClientPolicy(3, null, 9));
        $this->assertCount(2, $db->calls);
        $this->assertSame([3, 'notify', 9, '2026-01-02 03:04:05'], $db->calls[0]['params']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE policy = VALUES(policy)', $db->calls[0]['sql']);
        $this->assertStringStartsWith('DELETE FROM rmm_client_remote_policy WHERE client_id = ?', $db->calls[1]['sql']);
    }
}
