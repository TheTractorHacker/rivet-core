<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Patch\PatchJobTypes;
use RivetCore\Rmm\Patch\PatchPolicyResolver;
use RivetCore\Rmm\Patch\PatchPolicySettings;
use RivetCore\Rmm\Patch\PatchProtocol;
use RivetCore\Rmm\Policy\PolicyLayer;

/** Phase 4: the pure parts: policy settings validation, the resolver (scope precedence, enforce, safe defaults), windows and the job parameter allow-lists. */
final class PatchPolicyResolverTest extends TestCase
{
    private const RINGS = ['pilot' => 0, 'broad' => 7, 'last' => 14];

    /** @param array<string,mixed> $values */
    private static function layer(int $id, string $scope, array $values, int $priority = 100, bool $enforce = false): PolicyLayer
    {
        $s = [];
        foreach ($values as $k => $v) {
            $s[$k] = ['mode' => 'override', 'value' => $v];
        }

        return new PolicyLayer($id, $id, $scope, $priority, $enforce, $s);
    }

    public function testNoPolicyMeansTheSafeDefaults(): void
    {
        $e = PatchPolicyResolver::resolve([], self::RINGS);
        $this->assertSame(['manual', false, 'never', null, 60, false, 0], [$e['approval_mode'], $e['install_enabled'], $e['reboot_policy'], $e['ring'], $e['max_minutes'], $e['two_person'], $e['ring_delay_days']]);
        $this->assertSame('none', $e['window']['mode']);
        $this->assertNull(PatchPolicyResolver::autoApproveAfterDays($e, 'security'), 'nothing is approved by itself');
        $this->assertSame([], $e['sources']);
    }

    public function testTheMostSpecificScopeWinsPerKeyAndEnforceLocksAKey(): void
    {
        $layers = [
            self::layer(1, 'global', ['ring' => 'broad', 'install_enabled' => true, 'max_minutes' => 30, 'approval_mode' => 'auto', 'auto_days_security' => 2]),
            self::layer(2, 'client', ['ring' => 'last']),
            self::layer(3, 'tag', ['ring' => 'pilot', 'max_minutes' => 90]),
            self::layer(4, 'device', ['install_enabled' => false]),
        ];
        $e = PatchPolicyResolver::resolve($layers, self::RINGS);
        $this->assertSame(['pilot', 0, false, 90], [$e['ring'], $e['ring_delay_days'], $e['install_enabled'], $e['max_minutes']]);
        $this->assertEquals(['scope_type' => 'device', 'policy_id' => 4, 'enforced' => false], $e['sources']['install_enabled']);
        $this->assertSame(2, PatchPolicyResolver::autoApproveAfterDays($e, 'security'), 'ring pilot adds no delay');
        $this->assertNull(PatchPolicyResolver::autoApproveAfterDays($e, 'optional'));
        // an enforced global layer cannot be overridden by anything more specific
        $layers[0] = self::layer(1, 'global', ['install_enabled' => true, 'ring' => 'broad'], 100, true);
        $e = PatchPolicyResolver::resolve(array_reverse($layers), self::RINGS);
        $this->assertSame(['broad', true, 7], [$e['ring'], $e['install_enabled'], $e['ring_delay_days']]);
        $this->assertSame(9, PatchPolicyResolver::autoApproveAfterDays(['approval_mode' => 'auto', 'auto_days' => ['security' => 2], 'ring_delay_days' => 7], 'security'));
    }

    public function testAnUnknownRingIsNoRingAndARestartNeedsItsConfirmation(): void
    {
        $e = PatchPolicyResolver::resolve([self::layer(1, 'global', ['ring' => 'deleted-ring', 'reboot_policy' => 'if_required_now'])], self::RINGS);
        $this->assertSame([null, 0, 'if_required_defer'], [$e['ring'], $e['ring_delay_days'], $e['reboot_policy']], 'without reboot_confirm a restart policy degrades to report-only');
        $e = PatchPolicyResolver::resolve([self::layer(1, 'global', ['reboot_policy' => 'if_required_now', 'reboot_confirm' => true])], self::RINGS);
        $this->assertSame(['if_required_now', true], [$e['reboot_policy'], $e['reboot_confirm']]);
    }

    public function testSettingsValidationIsStrict(): void
    {
        $ok = PatchPolicySettings::normalize(['ring' => 'pilot', 'approval_mode' => 'auto', 'auto_days_security' => 3, 'install_enabled' => true, 'window_mode' => 'daily', 'window_days' => 'MON,wed',
            'window_start' => '02:30', 'window_minutes' => 120, 'window_tz' => 'Europe/Berlin', 'max_minutes' => 45, 'two_person' => false, 'reboot_confirm' => null], array_keys(self::RINGS));
        $this->assertNull($ok[1], (string) $ok[1]);
        $this->assertSame('mon,wed', $ok[0]['window_days']['value']);
        $this->assertArrayNotHasKey('reboot_confirm', (array) $ok[0], 'a null removes the key');
        $bad = [
            ['ring' => 'nope'], ['approval_mode' => 'yolo'], ['auto_days_security' => -1], ['auto_days_security' => '3'], ['auto_days_other' => 3], ['install_enabled' => 'yes'], ['window_mode' => 'sometimes'],
            ['window_mode' => 'maintenance'], ['window_mode' => 'daily', 'window_days' => 'mon'], ['window_start' => '25:00'], ['window_minutes' => 4], ['window_tz' => 'Mars/Base'], ['reboot_policy' => 'now'],
            ['reboot_policy' => 'if_required_now'], ['max_minutes' => 241], ['unknown' => 1], 'text', [1, 2],
        ];
        foreach ($bad as $b) {
            $this->assertNotNull(PatchPolicySettings::normalize($b, array_keys(self::RINGS))[1], json_encode($b));
        }
        $this->assertNull(PatchPolicySettings::normalize(['reboot_policy' => 'if_required_now', 'reboot_confirm' => true], [])[1]);
    }

    public function testDailyWindowsIncludingOneThatCrossesMidnightAndATimeZone(): void
    {
        $w = ['mode' => 'daily', 'maintenance_id' => null, 'days' => ['sat'], 'start' => '23:00', 'minutes' => 180, 'tz' => 'UTC'];
        $sat2300 = gmmktime(23, 0, 0, 10, 17, 2026);   // 2026-10-17 is a Saturday
        $this->assertSame(6, (int) gmdate('N', $sat2300));
        $this->assertTrue(PatchPolicyResolver::inDailyWindow($w, $sat2300));
        $this->assertSame($sat2300, PatchPolicyResolver::dailyWindowStart($w, $sat2300 + 3600 * 2));
        $this->assertTrue(PatchPolicyResolver::inDailyWindow($w, $sat2300 + 3600 * 2 + 59), 'after midnight the window that began on Saturday is still open');
        $this->assertFalse(PatchPolicyResolver::inDailyWindow($w, $sat2300 + 3600 * 3), 'closed at 02:00');
        $this->assertFalse(PatchPolicyResolver::inDailyWindow($w, $sat2300 - 1));
        $this->assertFalse(PatchPolicyResolver::inDailyWindow($w, $sat2300 - 86400 + 60), 'Friday 23:01 is not a Saturday window');
        $berlin = ['mode' => 'daily', 'maintenance_id' => null, 'days' => ['sun'], 'start' => '01:00', 'minutes' => 60, 'tz' => 'Europe/Berlin'];
        $sunUtc2300 = gmmktime(23, 30, 0, 10, 17, 2026);   // Saturday 23:30 UTC = Sunday 01:30 in Berlin (CEST)
        $this->assertTrue(PatchPolicyResolver::inDailyWindow($berlin, $sunUtc2300));
        $this->assertFalse(PatchPolicyResolver::inDailyWindow(['mode' => 'none'] + $berlin, $sunUtc2300));
        $this->assertFalse(PatchPolicyResolver::inDailyWindow(['tz' => 'Not/AZone'] + $berlin, $sunUtc2300), 'an unreadable zone never opens a window');
    }

    public function testJobParameterAllowLists(): void
    {
        $id = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
        $this->assertSame([['categories' => 'security,critical', 'max_items' => 500], null], PatchJobTypes::scan(['categories' => 'security,critical,security']));
        foreach ([['categories' => 'bogus'], ['categories' => ['security']], ['max_items' => 0], ['max_items' => 1001], ['max_items' => '5'], ['x' => 1]] as $b) {
            $this->assertNotNull(PatchJobTypes::scan($b)[1], json_encode($b));
        }
        $this->assertSame([['ids' => "$id,openssl", 'mode' => 'dry_run', 'max_minutes' => 60], null], PatchJobTypes::install(['ids' => "$id,openssl"]));
        $hostile = ['-oAPT::Foo', 'a b', 'x;rm', '$(x)', "a\nb", str_repeat('a', 101), 'ünï', 'a,,b', ',', "$id,$id", implode(',', array_map(static fn (int $i): string => "p$i", range(1, 41))), str_repeat('a,', 600)];
        foreach ($hostile as $h) {
            $this->assertNotNull(PatchJobTypes::install(['ids' => $h])[1], substr($h, 0, 40));
        }
        foreach ([['mode' => 'yes'], ['mode' => 'install', 'max_minutes' => 241], ['max_minutes' => '5'], ['reboot' => true], []] as $b) {
            $this->assertNotNull(PatchJobTypes::install($b + ['ids' => 'openssl'])[1] ?? 'x', json_encode($b));
        }
        $this->assertNotNull(PatchJobTypes::install([])[1], 'ids are required');
    }

    public function testIdSyntaxPerPlatform(): void
    {
        $this->assertTrue(PatchProtocol::validId('aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', 'windows'));
        $this->assertFalse(PatchProtocol::validId('AAAAAAAA-1111-4111-8111-AAAAAAAAAAAA', 'windows'), 'lower case only');
        $this->assertFalse(PatchProtocol::validId('openssl', 'windows'));
        foreach (['openssl', 'libc6:amd64', 'kernel-core.x86_64', 'g++', 'a'] as $ok) {
            $this->assertTrue(PatchProtocol::validId($ok, 'linux'), $ok);
        }
        foreach (['-x', '.x', 'A', 'a b', '', "a\n", 'a;b', 'a/b', 'a:b:c', 'a:', str_repeat('a', 101), 12] as $bad) {
            $this->assertFalse(PatchProtocol::validId($bad, 'linux'), json_encode($bad));
        }
    }
}
