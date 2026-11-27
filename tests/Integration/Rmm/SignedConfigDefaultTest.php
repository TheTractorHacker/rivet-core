<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * Red team 2026-11-21 CORE-AGENT-4 (Chain G): a NEW install starts with signed configuration at level 2, so the first enrollment already announces it and an
 * in-path party cannot slip unsigned checks to the agents; an install that already has devices keeps its level (no migration) and the hardening status
 * the administration page reads warns until it is raised.
 */
final class SignedConfigDefaultTest extends RmmTestCase
{
    public function testANewInstallStartsAtTheRecommendedLevelFromTheFirstEnrollment(): void
    {
        $s = $this->h->module->settings();
        $this->assertSame(0, $s->signedConfigLevel(), 'the harness starts like a database where migration 0053 just ran');
        $s->enable();
        $this->assertSame(RmmSettings::SIGNED_CONFIG_DEFAULT, $s->signedConfigLevel());
        $this->assertSame(2, RmmSettings::SIGNED_CONFIG_DEFAULT);
        $this->h->asset(['name' => 'PC-1', 'serial' => 'SN-SC-1']);
        $token = $this->h->module->enrollment()->createToken($this->h->clientA, 0, 'stable', 24, 5, 'test', 1)['token'];
        [$c, , $j] = $this->h->enroll($token, RmmHarness::device(['serial' => 'SN-SC-1']));
        $this->assertSame(201, $c);
        $this->assertSame(2, $j['config']['signed_config'], 'the enrollment response already announces it');
        [$c, , $r] = $this->h->checkin($j['device_token']);
        $this->assertSame(200, $c);
        $this->assertSame(2, $r['config']['signed_config']);
        $status = $this->h->module->readModel()->settingsSummary()['hardening'];
        $this->assertTrue($status['signed_config_ok']);
        $this->assertNull($status['signed_config_warning']);
    }

    public function testAnInstallThatAlreadyHasDevicesKeepsItsLevelAndIsWarned(): void
    {
        $s = $this->h->module->settings();
        $s->enable();
        $s->set(['signed_config' => 0]);   // as every install before this release
        [$c] = $this->h->enroll($this->h->token(), RmmHarness::device());
        $this->assertSame(201, $c);
        // switching off and on again, and even a first switch-on that has to mint a key while devices exist, never changes the level
        $s->disable();
        $s->enable();
        $this->assertSame(0, $s->signedConfigLevel());
        $this->h->q("UPDATE endpoint_agent_settings SET signing_public_key = '', signing_private_key_enc = NULL WHERE id = 1");
        $s->refresh();
        $s->enable();
        $this->assertSame(0, $s->signedConfigLevel(), 'devices exist: they may run agents older than CORE-DS-16, so the level is the administrator\'s call');

        $status = $this->h->module->readModel()->settingsSummary()['hardening'];
        $this->assertSame(0, $status['signed_config']);
        $this->assertSame(2, $status['signed_config_recommended']);
        $this->assertFalse($status['signed_config_ok']);
        $this->assertStringContainsString('Signed configuration is OFF', (string) $status['signed_config_warning']);
        $s->set(['signed_config' => 1]);
        $this->assertStringContainsString('level 1', (string) $s->hardeningStatus()['signed_config_warning']);
    }

    public function testTheLevelIsPartOfTheValidatedSettingsFormAndANewInstallNeverLowersIt(): void
    {
        $s = $this->h->module->settings();
        $this->assertSame([], $s->update(['signed_config' => 7]));
        $this->assertSame(2, $s->signedConfigLevel(), 'clamped to 2');
        $this->assertSame([], $s->update(['signed_config' => '1']));
        $this->assertSame(1, $s->signedConfigLevel());
        $this->assertTrue($this->h->module->admin()->saveSettings($this->h->principal(), ['signed_config' => 0])->ok);
        $this->assertSame(0, $s->signedConfigLevel(), 'an administrator may still choose off');
        $this->assertSame([], $s->update(['signed_config' => 'x']));
        $this->assertSame(0, $s->signedConfigLevel(), 'not a number: untouched');
    }
}
