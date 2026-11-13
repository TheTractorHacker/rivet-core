<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Monitoring\Inbound\Incident;
use RivetCore\Monitoring\Inbound\IncidentStoreInterface;

/**
 * Conformance kit for {@see IncidentStoreInterface}.
 *
 * Checks: unknown key is null; put/get round-trips every field (nulls and the opens list included); put replaces; the store hands out
 * copies (changing a returned Incident without put() changes nothing); keys with another source or monitor never collide; locked() returns
 * the closure's value, lets get/put run inside, releases the lock when the closure throws (and rethrows), and can be taken again afterwards.
 *
 * @api
 */
abstract class IncidentStoreConformanceTestCase extends TestCase
{
    abstract protected function store(): IncidentStoreInterface;

    private function full(string $source = 'uptime-kuma', string $key = '7'): Incident
    {
        return new Incident($source, $key, Incident::OPEN, 42, 41, 3, 1000, 2000, 0, 1900, [1500, 1800], 9, 5, 'Web server');
    }

    public function testUnknownKeyIsNull(): void
    {
        self::assertNull($this->store()->get('uptime-kuma', 'nope-' . bin2hex(random_bytes(3))));
    }

    public function testRoundTripKeepsEveryField(): void
    {
        $key = 'k' . bin2hex(random_bytes(3));
        $this->store()->put($this->full('uptime-kuma', $key));
        self::assertEquals($this->full('uptime-kuma', $key), $this->store()->get('uptime-kuma', $key));

        $bare = new Incident('uptime-kuma', $key . 'b');
        $this->store()->put($bare);
        self::assertEquals($bare, $this->store()->get('uptime-kuma', $key . 'b'));
    }

    public function testPutReplacesAndStoreHandsOutCopies(): void
    {
        $key = 'r' . bin2hex(random_bytes(3));
        $this->store()->put($this->full('uptime-kuma', $key));
        $changed = $this->full('uptime-kuma', $key);
        $changed->state = Incident::HEALTHY;
        $changed->ticketId = null;
        $this->store()->put($changed);
        $first = $this->store()->get('uptime-kuma', $key);
        self::assertSame(Incident::HEALTHY, $first->state ?? null);

        if ($first !== null) {
            $first->state = Incident::OPEN;   // a copy: the store must not see this
        }
        $second = $this->store()->get('uptime-kuma', $key);
        self::assertSame(Incident::HEALTHY, $second->state ?? null);
    }

    public function testKeysDoNotCollide(): void
    {
        $key = 'c' . bin2hex(random_bytes(3));
        $this->store()->put($this->full('uptime-kuma', $key));
        self::assertNull($this->store()->get('other-source', $key));
        self::assertNull($this->store()->get('uptime-kuma', $key . '0'));
    }

    public function testLockedReturnsValueAndReleasesOnException(): void
    {
        $s = $this->store();
        self::assertSame('v', $s->locked('uptime-kuma', 'lk', static function () use ($s): string {
            $s->put(new Incident('uptime-kuma', 'lk'));

            return 'v';
        }));
        $message = null;
        try {
            $s->locked('uptime-kuma', 'lk', static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }
        self::assertSame('boom', $message, 'The exception must propagate.');
        self::assertSame(1, $s->locked('uptime-kuma', 'lk', static fn (): int => 1));
    }
}
