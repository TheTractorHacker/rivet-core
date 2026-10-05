<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Compliance\RetentionPolicy;

final class RetentionPolicyTest extends TestCase
{
    public function testFloorsPerPreset(): void
    {
        $this->assertSame(0, RetentionPolicy::floorDays('none'));
        $this->assertSame(365, RetentionPolicy::floorDays('iso27001'));
        $this->assertSame(365, RetentionPolicy::floorDays('soc2'));
        $this->assertSame(365, RetentionPolicy::floorDays('pci'));
        $this->assertSame(2190, RetentionPolicy::floorDays('hipaa'));
        $this->assertSame(0, RetentionPolicy::floorDays('made-up'), 'an unknown preset means no minimum, never a crash');
    }

    public function testEffectiveDaysNeverDropsBelowTheFloor(): void
    {
        $this->assertSame(365, RetentionPolicy::effectiveDays('iso27001', 30));
        $this->assertSame(365, RetentionPolicy::effectiveDays('iso27001', 365));
        $this->assertSame(500, RetentionPolicy::effectiveDays('iso27001', 500), 'a longer choice is kept');
        $this->assertSame(2190, RetentionPolicy::effectiveDays('hipaa', 365));
        $this->assertSame(30, RetentionPolicy::effectiveDays('none', 30));
    }

    public function testKeepForeverIsAlwaysAllowed(): void
    {
        foreach (['none', 'iso27001', 'hipaa'] as $p) {
            $this->assertSame(0, RetentionPolicy::effectiveDays($p, 0), $p);
            $this->assertSame(0, RetentionPolicy::effectiveDays($p, -7), 'a negative value counts as keep forever, not delete everything');
            $this->assertFalse(RetentionPolicy::isBelowFloor($p, 0));
        }
    }

    public function testIsBelowFloor(): void
    {
        $this->assertTrue(RetentionPolicy::isBelowFloor('soc2', 90));
        $this->assertFalse(RetentionPolicy::isBelowFloor('soc2', 365));
        $this->assertFalse(RetentionPolicy::isBelowFloor('none', 1));
    }

    public function testProfileValidationAndDescriptions(): void
    {
        $this->assertTrue(RetentionPolicy::isValidProfile('pci'));
        $this->assertFalse(RetentionPolicy::isValidProfile("pci'; DROP TABLE settings;--"));
        $this->assertFalse(RetentionPolicy::isValidProfile(''));
        $this->assertSame('kept forever', RetentionPolicy::describeDays(0));
        $this->assertSame('1 day', RetentionPolicy::describeDays(1));
        $this->assertSame('365 days', RetentionPolicy::describeDays(365));
        foreach (RetentionPolicy::PROFILES as $key => $def) {
            $this->assertArrayHasKey('label', $def, $key);
            $this->assertNotSame('', $def['note'], $key);
        }
    }
}
