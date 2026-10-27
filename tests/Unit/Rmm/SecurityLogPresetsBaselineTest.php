<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Logs\SecurityLogPresets;

final class SecurityLogPresetsBaselineTest extends TestCase
{
    public function testTheBaselineIsEveryPresetExceptTheDeviceOneAndOnlyKnownIds(): void
    {
        $b = SecurityLogPresets::complianceBaseline();
        self::assertSame(array_values(array_diff(SecurityLogPresets::ids(), ['win.sec.devices'])), $b);
        self::assertSame($b, array_values(array_unique($b)));
        self::assertNotContains('win.sec.devices', $b, 'needs an extra audit subcategory');
    }

    public function testTheBaselineNeverExceedsTheSourceCapAndNeverDropsAnIdSilently(): void
    {
        $b = SecurityLogPresets::complianceBaseline();
        foreach ([true, false] as $canAggregate) {
            $sources = SecurityLogPresets::sourcesFor($b, $canAggregate);
            self::assertLessThanOrEqual(SecurityLogPresets::MAX_SOURCES, count($sources));
            $got = [];
            foreach ($sources as $s) {
                self::assertLessThanOrEqual(50, count($s['event_ids']), 'one source holds at most 50 ids');
                foreach ($s['event_ids'] as $id) {
                    $got[$s['channel']][$id] = true;
                }
            }
            // every event id of every baseline preset (except the aggregated one, offered only to an agent that can aggregate) is present in a source of its channel
            foreach ($b as $preset) {
                foreach (SecurityLogPresets::catalog()[$preset]['sources'] as $src) {
                    if (isset($src['aggregate']) && !$canAggregate) {
                        continue;
                    }
                    foreach ($src['event_ids'] as $id) {
                        self::assertArrayHasKey($id, $got[$src['channel']] ?? [], "$preset id $id dropped (aggregate=" . (int) $canAggregate . ')');
                    }
                }
            }
        }
        // the whole catalog fits too
        self::assertLessThanOrEqual(SecurityLogPresets::MAX_SOURCES, count(SecurityLogPresets::sourcesFor(SecurityLogPresets::ids(), true)));
    }

    public function testEveryPresetHasFrameworkReferencesAndAnAuditPolicyNote(): void
    {
        $refs = SecurityLogPresets::frameworkReferences();
        $needs = SecurityLogPresets::auditPolicyNeeds();
        self::assertSame(SecurityLogPresets::ids(), array_keys($refs));
        self::assertSame(SecurityLogPresets::ids(), array_keys($needs));
        foreach (SecurityLogPresets::complianceBaseline() as $id) {
            $r = $refs[$id];
            self::assertContains('HIPAA 164.312(b)', $r, $id);
            self::assertContains('PCI DSS 10.2', $r, $id);
            self::assertContains('NIST 800-171 3.3.1', $r, $id);
            self::assertContains('SOC 2 CC7.2', $r, $id);
            self::assertContains('ISO 27001 A.8.15', $r, $id);
            self::assertContains('NIST CSF DE.CM', $r, $id);
            self::assertSame($r, array_values(array_unique($r)));
        }
        self::assertStringContainsString('Audit PNP Activity', $needs['win.sec.devices']);
        self::assertStringStartsWith('Enable preset win.sec.accounts (needs Windows audit policy: Audit User Account Management', SecurityLogPresets::remediation('win.sec.accounts'));
    }

    public function testTheCatalogShapeIsUnchanged(): void
    {
        foreach (SecurityLogPresets::catalog() as $p) {
            self::assertSame(['title', 'platform', 'aggregate', 'description', 'sources'], array_keys($p));
        }
        self::assertSame(4, SecurityLogPresets::MAX_SOURCES);
    }
}
