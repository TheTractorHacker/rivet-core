<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Audit\AuditService;
use RivetCore\Contracts\RequestContextInterface;
use RivetCore\Database\DatabaseException;
use RivetCore\Tests\Support\FakeDatabase;

final class AuditServiceTest extends TestCase
{
    private function ctx(?string $ua = 'UA'): RequestContextInterface
    {
        return new class($ua) implements RequestContextInterface {
            public function __construct(private ?string $ua)
            {
            }

            public function ipAddress(): ?string
            {
                return '10.0.0.9';
            }

            public function userAgent(): ?string
            {
                return $this->ua;
            }

            public function requestId(): ?string
            {
                return 'req-1';
            }
        };
    }

    public function testInsertsAllColumnsWithRequestContext(): void
    {
        $db = new FakeDatabase();
        (new AuditService($db, $this->ctx()))->log('auth.login_success', 7, 'user', 7, 'login', 'hi', ['a' => 1]);
        $this->assertCount(1, $db->calls);
        $this->assertStringContainsString('INSERT INTO audit_events', $db->calls[0]['sql']);
        $this->assertSame(
            ['auth.login_success', 7, 'user', '7', 'login', 'hi', '{"a":1}', '10.0.0.9', 'UA', 'req-1'],
            $db->calls[0]['params']
        );
    }

    public function testNullsAndEmptyMetadata(): void
    {
        $db = new FakeDatabase();
        (new AuditService($db, $this->ctx(null)))->log('x', null, null, null, 'a');
        $p = $db->calls[0]['params'];
        $this->assertNull($p[1]);
        $this->assertNull($p[3]);
        $this->assertNull($p[5]);
        $this->assertNull($p[6]);
        $this->assertNull($p[8]);
    }

    public function testLongSummaryAndUserAgentAreTruncatedToColumnWidth(): void
    {
        $db = new FakeDatabase();
        (new AuditService($db, $this->ctx(str_repeat('u', 400))))->log('x', 1, 't', 1, 'a', str_repeat('é', 900));
        $p = $db->calls[0]['params'];
        $this->assertSame(500, mb_strlen($p[5]));
        $this->assertSame(255, strlen($p[8]));
    }

    public function testDatabaseFailuresPropagate(): void
    {
        $db = new FakeDatabase();
        $db->failWith = new DatabaseException('down');
        $this->expectException(DatabaseException::class);
        (new AuditService($db, $this->ctx()))->log('x', 1, 't', 1, 'a');
    }

    public function testAfterLogListenerGetsTheEventAndCannotBreakTheWrite(): void
    {
        $db = new FakeDatabase();
        $seen = [];
        $svc = new AuditService($db, $this->ctx(), function (...$args) use (&$seen) {
            $seen[] = $args;
        });
        $svc->log('ticket.closed', 5, 'ticket', 12, 'close', 'closed it', ['k' => 'v']);
        $this->assertSame([['ticket.closed', 5, 'ticket', '12', 'close', 'closed it', ['k' => 'v']]], $seen);

        $boom = new AuditService($db, $this->ctx(), function () {
            throw new \RuntimeException('listener failed');
        });
        $boom->log('x.y', null, null, null, 'a');   // must not throw
        $this->assertTrue(true);
    }
}
