<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\RmmEventsInterface;
use RivetCore\Rmm\RmmEvent;

/**
 * Conformance kit for {@see RmmEventsInterface}. The bus is optional, so what is checked everywhere is the safety rule: publishing any
 * `rmm.*` event with the documented payload shape never throws. A bus that can be observed also tells the case what it received
 * ({@see self::received()}, null when it keeps nothing) and then the payload must arrive unchanged and in order.
 *
 * @api
 */
abstract class RmmEventsConformanceTestCase extends TestCase
{
    abstract protected function events(): RmmEventsInterface;

    /**
     * What the bus received so far, oldest first, or null when it discards everything (the null bus).
     *
     * @return list<array{event:string,payload:array<string,mixed>}>|null
     */
    protected function received(): ?array
    {
        return null;
    }

    /** @return array<string,mixed> */
    private function payload(int $n): array
    {
        return ['device_id' => 1000 + $n, 'asset_id' => $n % 2 === 0 ? null : 77, 'client_id' => 5, 'hostname' => "HOST-$n", 'occurred_at' => '2026-10-10T12:00:00Z',
            'tags' => ['a', 'b'], 'detail' => null, 'exit_code' => 0, 'ratio' => 0.5, 'ok' => true];
    }

    public function testEveryEventIdIsAccepted(): void
    {
        foreach (RmmEvent::all() as $i => $event) {
            $this->events()->publish($event, $this->payload($i));
        }
        $this->addToAssertionCount(1);
    }

    public function testAnEmptyExtraPayloadIsAccepted(): void
    {
        $this->events()->publish(RmmEvent::DEVICE_ONLINE, ['device_id' => 1, 'asset_id' => null, 'client_id' => 0, 'hostname' => '', 'occurred_at' => '2026-10-10T12:00:00Z']);
        $this->addToAssertionCount(1);
    }

    public function testPayloadsArriveUnchangedAndInOrder(): void
    {
        $bus = $this->events();
        $bus->publish(RmmEvent::DEVICE_OFFLINE, $this->payload(1));
        $bus->publish(RmmEvent::DEVICE_ONLINE, $this->payload(2));
        $got = $this->received();
        if ($got === null) {
            $this->markTestSkipped('This bus does not keep events.');
        }
        $this->assertSame([RmmEvent::DEVICE_OFFLINE, RmmEvent::DEVICE_ONLINE], array_column($got, 'event'));
        $this->assertEquals($this->payload(1), $got[0]['payload']);
        $this->assertEquals($this->payload(2), $got[1]['payload']);
    }

    public function testNullAndFalseValuesAreNotDropped(): void
    {
        $this->events()->publish(RmmEvent::JOB_COMPLETED, ['device_id' => 3, 'asset_id' => null, 'client_id' => 0, 'hostname' => 'H', 'occurred_at' => '2026-10-10T12:00:00Z', 'exit_code' => null, 'flag' => false]);
        $got = $this->received();
        if ($got === null) {
            $this->markTestSkipped('This bus does not keep events.');
        }
        $payload = $got[count($got) - 1]['payload'];
        $this->assertArrayHasKey('asset_id', $payload);
        $this->assertNull($payload['asset_id']);
        $this->assertArrayHasKey('exit_code', $payload);
        $this->assertFalse($payload['flag']);
    }
}
