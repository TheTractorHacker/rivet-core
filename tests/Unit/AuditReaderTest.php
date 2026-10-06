<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Audit\AuditReader;
use RivetCore\Tests\Support\FakeDatabase;

final class AuditReaderTest extends TestCase
{
    public function testDecodeMetadataIsSafe(): void
    {
        self::assertSame(['a' => 1], AuditReader::decodeMetadata('{"a":1}'));
        self::assertNull(AuditReader::decodeMetadata('{bad'));
        self::assertNull(AuditReader::decodeMetadata(''));
        self::assertNull(AuditReader::decodeMetadata(null));
        self::assertNull(AuditReader::decodeMetadata('"str"'));
        self::assertNull(AuditReader::decodeMetadata('12'));
    }

    public function testFiltersBecomeParametersNeverSql(): void
    {
        $db = new FakeDatabase();
        $db->rows = [['n' => 0]];
        (new AuditReader($db))->page([
            'search' => "50%_\\' OR 1=1 --", 'eventType' => 'set_tings', 'actorUserId' => '5', 'entityType' => 'user', 'entityId' => 9,
            'from' => '2026-01-01', 'to' => '2026-01-31',
        ], 1, 10);
        $sql = $db->calls[0]['sql'];
        $p = $db->calls[0]['params'];
        self::assertStringNotContainsString('OR 1=1', $sql);
        self::assertSame(['%50\\%\\_\\\\\' OR 1=1 --%', '%50\\%\\_\\\\\' OR 1=1 --%', '%50\\%\\_\\\\\' OR 1=1 --%', '%50\\%\\_\\\\\' OR 1=1 --%', '%50\\%\\_\\\\\' OR 1=1 --%', 'set_tings', 'set\\_tings.%', 5, 'user', '9', '2026-01-01 00:00:00', '2026-01-31 23:59:59'], $p);
        self::assertSame(count($p), substr_count($sql, '?'));
    }

    public function testInvalidDatesIgnoredAndPerPageClamped(): void
    {
        $db = new FakeDatabase();
        $db->rows = [['n' => 1000]];
        $page = (new AuditReader($db))->page(['from' => 'yesterday', 'to' => "2026-01-01'; DROP"], 99, 100000);
        self::assertSame([], $db->calls[0]['params']);
        self::assertSame(200, $page->perPage);
        self::assertSame(5, $page->pages);
        self::assertSame(5, $page->page);
        self::assertStringContainsString('LIMIT 200 OFFSET 800', $db->calls[1]['sql']);
    }

    public function testIterateRespectsCap(): void
    {
        $db = new class implements \RivetCore\Database\DatabaseInterface {
            /** @var list<array{sql:string,params:array}> */
            public array $calls = [];

            public function fetchOne(string $sql, array $params = []): ?array
            {
                return null;
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                $this->calls[] = ['sql' => $sql, 'params' => $params];
                preg_match('/LIMIT (\d+)/', $sql, $m);
                $start = $params === [] ? 100 : (int) end($params) - 1;
                $rows = [];
                for ($i = 0; $i < (int) $m[1]; $i++) {
                    $rows[] = ['audit_id' => $start - $i, 'metadata_json' => null];
                }

                return $rows;
            }

            public function execute(string $sql, array $params = []): \RivetCore\Database\ExecutionResult
            {
                return new \RivetCore\Database\ExecutionResult(0, 0);
            }

            public function transaction(callable $callback): mixed
            {
                return $callback();
            }
        };
        $ids = [];
        foreach ((new AuditReader($db))->iterate([], 25, 10) as $r) {
            $ids[] = $r['audit_id'];
        }
        self::assertCount(25, $ids);
        self::assertSame(100, $ids[0]);
        self::assertSame(76, $ids[24]);
        self::assertCount(3, $db->calls);
        self::assertStringContainsString('LIMIT 5', $db->calls[2]['sql']);
    }
}
