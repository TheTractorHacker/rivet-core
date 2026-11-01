<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Audit\AuditChainHasher;
use RivetCore\Audit\AuditChainWriter;
use RivetCore\Audit\AuditService;
use RivetCore\Database\DatabaseException;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Database\ExecutionResult;
use RivetCore\Support\NullRequestContext;
use RivetCore\Tests\Support\FakeDatabase;

final class AuditChainUnitTest extends TestCase
{
    /** @return array<string,mixed> */
    private function row(): array
    {
        return ['audit_id' => '7', 'event_type' => 't', 'actor_user_id' => '3', 'entity_type' => null, 'entity_id' => '9', 'action' => 'a', 'summary' => 's', 'metadata_json' => '{"k":1}', 'ip_address' => null, 'user_agent' => null, 'request_id' => null, 'created_at' => '2030-01-01 00:00:00'];
    }

    public function testHashIsDeterministicSha256WithPrevHashInside(): void
    {
        $r = $this->row();
        $h = AuditChainHasher::rowHash($r, AuditChainHasher::GENESIS);
        $this->assertSame($h, AuditChainHasher::rowHash($r, AuditChainHasher::GENESIS));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h);
        $this->assertSame(hash('sha256', AuditChainHasher::GENESIS . "\n" . json_encode(['7', 't', '3', null, '9', 'a', 's', '{"k":1}', null, null, null, '2030-01-01 00:00:00'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $h);
        $this->assertNotSame($h, AuditChainHasher::rowHash($r, str_repeat('1', 64)), 'prev_hash is part of the hash');
    }

    public function testEveryCoveredFieldChangesTheHash(): void
    {
        $base = AuditChainHasher::rowHash($this->row(), AuditChainHasher::GENESIS);
        foreach (AuditChainHasher::FIELDS as $f) {
            $r = $this->row();
            $r[$f] = ($r[$f] ?? '') . 'x';
            $this->assertNotSame($base, AuditChainHasher::rowHash($r, AuditChainHasher::GENESIS), "$f must be covered");
        }
        $r = $this->row();
        $r['entity_type'] = '';
        $this->assertNotSame($base, AuditChainHasher::rowHash($r, AuditChainHasher::GENESIS), 'NULL and empty string differ');
    }

    public function testIntegerAndStringColumnsHashTheSame(): void
    {
        $a = $this->row();
        $b = $this->row();
        $b['audit_id'] = 7;
        $b['actor_user_id'] = 3;
        $this->assertSame(AuditChainHasher::rowHash($a, AuditChainHasher::GENESIS), AuditChainHasher::rowHash($b, AuditChainHasher::GENESIS));
    }

    public function testKeyedHashDiffersAndIsHmac(): void
    {
        $key = AuditChainHasher::deriveKey(str_repeat('k', 32));
        $this->assertSame(32, strlen($key));
        $plain = AuditChainHasher::rowHash($this->row(), AuditChainHasher::GENESIS);
        $keyed = AuditChainHasher::rowHash($this->row(), AuditChainHasher::GENESIS, $key);
        $this->assertNotSame($plain, $keyed);
        $this->assertNotSame($keyed, AuditChainHasher::rowHash($this->row(), AuditChainHasher::GENESIS, AuditChainHasher::deriveKey(str_repeat('j', 32))));
        $this->assertSame('hmac', AuditChainHasher::mode($key));
        $this->assertSame('plain', AuditChainHasher::mode(null));
        $this->assertSame('', AuditChainHasher::keyTag(null));
        $this->assertSame(12, strlen(AuditChainHasher::keyTag($key)));
    }

    public function testWithoutAWriterAuditServiceStillMakesOneInsert(): void
    {
        $db = new FakeDatabase();
        (new AuditService($db, new NullRequestContext()))->log('a.b', 1, null, null, 'x');
        $this->assertCount(1, $db->calls);
        $this->assertStringStartsWith('INSERT INTO audit_events', $db->calls[0]['sql']);
    }

    public function testWithAWriterTheHeadIsLockedBeforeTheInsertAndMovedAfterIt(): void
    {
        $db = new FakeDatabase();
        $db->rows = [['head_audit_id' => null, 'head_hash' => AuditChainHasher::GENESIS, 'hash_mode' => '', 'key_fp' => '', 'audit_id' => 1, 'event_type' => 'a.b', 'action' => 'x', 'created_at' => '2030-01-01 00:00:00']];
        (new AuditService($db, new NullRequestContext(), null, new AuditChainWriter($db)))->log('a.b', 1, null, null, 'x');
        $sql = array_map(static fn (array $c): string => preg_replace('/\s+/', ' ', $c['sql']), $db->calls);
        $this->assertStringContainsString('FROM audit_chain_head WHERE id = 1 FOR UPDATE', $sql[0]);
        $this->assertStringStartsWith('INSERT INTO audit_events', $sql[1]);
        $this->assertStringStartsWith('SELECT * FROM audit_events', $sql[2]);
        $this->assertStringStartsWith('UPDATE audit_events SET prev_hash', $sql[3]);
        $this->assertStringStartsWith('UPDATE audit_chain_head', $sql[4]);
        $this->assertSame(AuditChainHasher::GENESIS, $db->calls[3]['params'][0]);
    }

    public function testAFailingChainFallsBackToAPlainInsertSoTheEventIsNotLost(): void
    {
        $db = new class implements DatabaseInterface {
            /** @var list<array{sql:string,params:array}> */
            public array $calls = [];

            public function fetchOne(string $sql, array $params = []): ?array
            {
                return null;
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                return [];
            }

            public function execute(string $sql, array $params = []): ExecutionResult
            {
                $this->calls[] = ['sql' => $sql, 'params' => $params];

                return new ExecutionResult(1, 1);
            }

            public function transaction(callable $callback): mixed
            {
                throw new DatabaseException("Table 'audit_chain_head' doesn't exist", 1146);
            }
        };
        $log = ini_set('error_log', '/dev/null');
        try {
            $svc = new AuditService($db, new NullRequestContext(), null, new AuditChainWriter($db));
            $svc->log('a.b', 1, null, null, 'x');
            $svc->log('a.b', 1, null, null, 'y');
        } finally {
            ini_set('error_log', (string) $log);
        }
        $this->assertCount(2, $db->calls);
        $this->assertStringStartsWith('INSERT INTO audit_events', $db->calls[0]['sql']);
    }

    public function testADatabaseFailureStillPropagatesOutOfAChainedService(): void
    {
        $db = new FakeDatabase();
        $db->failWith = new DatabaseException('down');
        $log = ini_set('error_log', '/dev/null');
        try {
            $this->expectException(DatabaseException::class);
            (new AuditService($db, new NullRequestContext(), null, new AuditChainWriter($db)))->log('a.b', 1, null, null, 'x');
        } finally {
            ini_set('error_log', (string) $log);
        }
    }
}
