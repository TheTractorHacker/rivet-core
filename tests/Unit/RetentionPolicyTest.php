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
        $this->assertSame(365, RetentionPolicy::effectiveDays('nist171', 30));
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

    public function testOperationalFloorsAreShorterThanAuditFloors(): void
    {
        $this->assertSame(365, RetentionPolicy::floorDaysFor('iso27001', RetentionPolicy::KIND_AUDIT));
        $this->assertSame(2190, RetentionPolicy::floorDaysFor('hipaa', RetentionPolicy::KIND_AUDIT));
        foreach (['iso27001', 'soc2', 'pci', 'hipaa', 'nist171'] as $preset) {
            $this->assertSame(30, RetentionPolicy::floorDaysFor($preset, RetentionPolicy::KIND_DELIVERIES));
            $this->assertSame(30, RetentionPolicy::floorDaysFor($preset, RetentionPolicy::KIND_JOBS));
        }
        $this->assertSame(7, RetentionPolicy::floorDaysFor('none', RetentionPolicy::KIND_JOBS));
        $this->assertSame(7, RetentionPolicy::floorDaysFor('made-up', RetentionPolicy::KIND_DELIVERIES));
        $this->assertSame(0, RetentionPolicy::floorDaysFor('none', RetentionPolicy::KIND_AUDIT));
    }

    public function testEffectiveDaysForRaisesToTheKindFloorButKeepsZero(): void
    {
        $this->assertSame(7, RetentionPolicy::effectiveDaysFor('none', RetentionPolicy::KIND_JOBS, 1));
        $this->assertSame(30, RetentionPolicy::effectiveDaysFor('soc2', RetentionPolicy::KIND_DELIVERIES, 10));
        $this->assertSame(90, RetentionPolicy::effectiveDaysFor('soc2', RetentionPolicy::KIND_DELIVERIES, 90));
        $this->assertSame(365, RetentionPolicy::effectiveDaysFor('soc2', RetentionPolicy::KIND_AUDIT, 90));
        $this->assertSame(0, RetentionPolicy::effectiveDaysFor('hipaa', RetentionPolicy::KIND_JOBS, 0));
        $this->assertSame(0, RetentionPolicy::effectiveDaysFor('hipaa', RetentionPolicy::KIND_JOBS, -4));
    }
}
