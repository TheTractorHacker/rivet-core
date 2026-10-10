<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use RivetCore\Crypto\Envelope;
use RivetCore\Crypto\KeyRing;
use RivetCore\Crypto\KeyUnavailable;
use RivetCore\Crypto\LegacyReaders;
use RivetCore\Crypto\RotationPlan;

final class RotationPlanTest extends TestCase
{
    private function ring(): KeyRing
    {
        return KeyRing::single(random_bytes(32), 'k1')->withKey(random_bytes(32), 'k2', null, true);
    }

    public function testEverythingCurrentIsComplete(): void
    {
        $plan = RotationPlan::build($this->ring(), ['v3:k2' => 10, 'empty' => 4]);
        $this->assertTrue($plan->isComplete());
        $this->assertSame(10, $plan->total());
        $this->assertSame(10, $plan->current());
        $this->assertSame([], $plan->pending());
        $this->assertSame(['k1'], $plan->retirable());
        $this->assertStringContainsString('one backup cycle', implode(' ', $plan->steps()));
    }

    public function testPendingLegacyAndOldKid(): void
    {
        $plan = RotationPlan::build($this->ring(), ['v3:k1' => 3, 'v3:k2' => 5, 'legacy:ENC2' => 7, 'legacy:ENC' => 1, 'legacy:plaintext' => 2]);
        $this->assertFalse($plan->isComplete());
        $this->assertSame(['legacy:ENC' => 1, 'legacy:ENC2' => 7, 'legacy:plaintext' => 2, 'v3:k1' => 3], $plan->pending());
        $this->assertSame(13, $plan->pendingCount());
        $this->assertSame([], $plan->retirable(), 'k1 is still in use');
        $this->assertFalse($plan->hasBlockers());
        $this->assertStringContainsString('13 value(s)', $plan->steps()[0]);
    }

    public function testUnknownKidsAndUnknownFormatsAreBlockers(): void
    {
        $plan = RotationPlan::build($this->ring(), ['v3:k9' => 2, 'unknown' => 1, 'v3:k2' => 1]);
        $this->assertTrue($plan->hasBlockers());
        $this->assertSame(['unknown' => 1, 'v3:k9' => 2], $plan->unreadable());
        $this->assertSame([], $plan->pending());
        $this->assertFalse($plan->isComplete());
        $this->assertStringContainsString('missing key', $plan->steps()[0]);
        $this->assertSame(1, $plan->toArray()['current']);
    }

    public function testEmptyInventoryAndNoActiveKey(): void
    {
        $plan = RotationPlan::build($this->ring(), []);
        $this->assertTrue($plan->isComplete());
        $this->assertStringContainsString('Nothing to do', $plan->steps()[0]);
        $this->expectException(KeyUnavailable::class);
        RotationPlan::build(KeyRing::empty(), []);
    }

    public function testInventoryFromStoredValues(): void
    {
        $k = random_bytes(32);
        $e = new Envelope(KeyRing::single($k, 'k1'), LegacyReaders::plaintext());
        $e2 = new Envelope(KeyRing::single($k, 'k1')->withKey(random_bytes(32), 'k2', null, true), LegacyReaders::plaintext());
        $values = [$e->seal('a', 'c'), $e->seal('b', 'c'), $e2->seal('c', 'c'), 'cleartext', '', null, 'v3:zz:AAAA'];
        $inv = RotationPlan::inventory($values, $e2);
        $this->assertSame(['legacy:plaintext' => 1, 'v3:k1' => 2, 'v3:k2' => 1, 'v3:zz' => 1], $inv);
        $plan = RotationPlan::build($e2->ring(), $inv);
        $this->assertSame(['v3:zz' => 1], $plan->unreadable());
    }
}
