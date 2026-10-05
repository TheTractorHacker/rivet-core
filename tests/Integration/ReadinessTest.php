<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Database\DatabaseException;
use RivetCore\Health\ReadinessChecker;
use RivetCore\Tests\Support\FakeDatabase;
use RivetCore\Tests\Support\TestRedis;

final class ReadinessTest extends TestCase
{
    public function testReadyWhenDbAndSchemaOkEvenIfRedisDown(): void
    {
        $r = (new ReadinessChecker(new FakeDatabase(), fn () => true, new TestRedis(null, true)))->check();
        $this->assertTrue($r['ready']);
        $this->assertSame(['database' => 'ok', 'schema' => 'ok', 'redis' => 'unavailable'], $r['checks']);
    }

    public function testNotReadyWhenSchemaBehind(): void
    {
        $r = (new ReadinessChecker(new FakeDatabase(), fn () => false))->check();
        $this->assertFalse($r['ready']);
        $this->assertSame('fail', $r['checks']['schema']);
    }

    public function testNotReadyWhenDatabaseDownAndNoLeakedErrorText(): void
    {
        $db = new FakeDatabase();
        $db->failWith = new DatabaseException('SQLSTATE secret-host:3306 refused');
        $r = (new ReadinessChecker($db, fn () => true))->check();
        $this->assertFalse($r['ready']);
        $this->assertStringNotContainsString('secret', json_encode($r));
    }

    public function testRedisOk(): void
    {
        if (!TestRedis::available()) {
            $this->markTestSkipped('throwaway Redis required');
        }
        $this->assertSame('ok', (new ReadinessChecker(new FakeDatabase(), fn () => true, new TestRedis()))->check()['checks']['redis']);
    }
}
