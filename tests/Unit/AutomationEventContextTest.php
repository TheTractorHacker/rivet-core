<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Automation\EventContext;

/** CORE-DS-58 (CORE-36): a bare leaf name is an alias only when exactly one nested path ends in it. */
final class AutomationEventContextTest extends TestCase
{
    public function testAnAmbiguousLeafHasNoBareAlias(): void
    {
        $flat = EventContext::flatten(['metadata' => ['priority' => 'High', 'client_id' => '999'], 'ticket' => ['priority' => 'Low', 'client_id' => '5', 'id' => 7]]);
        $this->assertSame('High', $flat['metadata.priority']);
        $this->assertSame('Low', $flat['ticket.priority']);
        $this->assertArrayNotHasKey('priority', $flat, 'two paths end in priority: neither may stand for it');
        $this->assertArrayNotHasKey('client_id', $flat);
        $this->assertSame('7', $flat['id'], 'a leaf that occurs once keeps its alias');
    }

    public function testATopLevelKeyIsNeverOverriddenAndDeepLeavesStillAlias(): void
    {
        $flat = EventContext::flatten(['priority' => 'Real', 'metadata' => ['priority' => 'Fake'], 'a' => ['b' => ['c' => 'deep']]]);
        $this->assertSame('Real', $flat['priority']);
        $this->assertSame('deep', $flat['c']);
        $this->assertSame('deep', $flat['a.b.c']);
        $flat = EventContext::flatten(['event' => 'ticket.created', 'ticket' => ['priority' => 'High', 'id' => 7, 'vip' => true], 'tags' => ['a', 'b'], 'none' => null]);
        $this->assertSame(['High', '7', '1'], [$flat['priority'], $flat['id'], $flat['vip']]);
        $this->assertArrayNotHasKey('tags', $flat);
    }
}
