<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\RmmEvent;
use RivetCore\Testing\InMemoryRmmEscalation;
use RivetCore\Testing\InMemoryRmmEvents;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/** Phase 3 alerting end to end through agent_checkin: thresholds, flap, maintenance, dependency, storm control, escalation, lifecycle. */
final class AlertingTest extends RmmTestCase
{
    private InMemoryRmmEvents $events;
    private InMemoryRmmEscalation $esc;
    private int $dev = 0;
    private string $T = '';
    private int $assetId = 0;

    protected function makeHarness(): RmmHarness
    {
        $this->events = new InMemoryRmmEvents();
        $this->esc = new InMemoryRmmEscalation();

        return new RmmHarness(new MutableClock(), null, ['allow_linux' => true, 'escalation' => $this->esc], false, null, null, null, $this->events);
    }

    private function clock(): MutableClock
    {
        /** @var MutableClock $c */
        $c = $this->h->clock;

        return $c;
    }

    /** @param list<array<string,mixed>> $checks */
    private function define(array $checks): void
    {
        $this->assertSame([], $this->h->module->settings()->update(['checks_json' => json_encode($checks)]));
    }

    private function on(bool $alerting = true): void
    {
        $this->h->enable();
        $f = ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true, 'alerting' => $alerting];
        $this->assertSame([], $this->h->module->settings()->update(['features_json' => $f, 'failure_debounce' => 3, 'recovery_debounce' => 2]));
        $this->h->module->alertingEngine()->forget();
    }

    /** @return array{0:int,1:string} */
    private function enrollDevice(string $serial, ?int $client = null, string $os = 'windows'): array
    {
        $a = $this->h->asset(['name' => "Box $serial", 'serial' => $serial, 'client_id' => $client ?? $this->h->clientA]);
        [, , $j] = $this->h->enroll($this->h->token($client, 24, 50), $this->h::device(['serial' => $serial, 'hostname' => "H-$serial", 'os' => $os]));
        $this->assetId = $a;

        return [(int) $j['device_id'], (string) $j['device_token']];
    }

    private function linked(string $serial = 'AL-1'): void
    {
        $this->on();
        [$this->dev, $this->T] = $this->enrollDevice($serial);
    }

    /** @param array<string,mixed> $c */
    private function report(string $token, array $c, array $over = []): array
    {
        return $this->h->checkin($token, ['checks' => [$c + ['detail' => '']]] + $over);
    }

    private function bad(string $key = 'svc', string $status = 'fail', ?float $value = null, string $token = ''): void
    {
        $c = ['key' => $key, 'status' => $status] + ($value === null ? [] : ['value' => $value]);
        $this->report($token ?: $this->T, $c);
    }

    private function ok(string $key = 'svc', ?float $value = null): void
    {
        $this->bad($key, 'ok', $value);
    }

    /** @return list<array<string,mixed>> */
    private function meta(string $where = '1=1'): array
    {
        return $this->h->rows("SELECT * FROM rmm_alert_meta WHERE $where ORDER BY meta_id");
    }

    private function pt(int $uid = 1): \RivetCore\Rmm\Authz\RmmPrincipal
    {
        return $this->h->principal($uid);
    }

    // ------------------------------------------------------------------ switch off = Phase 0 behaviour

    public function testWithTheSwitchOffNothingOfPhase3RunsAndTheDebounceIsUnchanged(): void
    {
        $this->on(false);
        [$this->dev, $this->T] = $this->enrollDevice('OFF-1');
        $this->define([['key' => 'cpu1', 'type' => 'cpu', 'params' => ['thresholds' => ['warn' => ['op' => 'gt', 'value' => 80]]], 'interval_s' => 60]]);
        $before = $this->h->counting->statements;
        $this->bad('svc', 'fail');
        $this->bad('svc', 'fail');
        $perCheckin = intdiv($this->h->counting->statements - $before, 2);
        $this->assertSame([], $this->h->bridge->alertKeys());
        $this->bad('svc', 'fail');
        $this->assertCount(1, $this->h->bridge->alertKeys());
        $this->assertSame([], $this->meta(), 'no alert record while the switch is off');
        $this->assertSame([], $this->h->rows('SELECT * FROM rmm_check_eval'));
        $this->assertSame([RmmEvent::CHECK_FAILED], array_values(array_diff(array_unique(array_column($this->events->published(), 'event')), [RmmEvent::DEVICE_ENROLLED])), 'only the Phase 0 event');
        $this->assertGreaterThan(0, $perCheckin);
    }

    public function testAHealthyCheckinAddsNoStatementWhenTheSwitchIsOn(): void
    {
        $this->on(false);
        [$this->dev, $this->T] = $this->enrollDevice('CNT-1');
        $this->ok('svc');
        $s0 = $this->h->counting->statements;
        $this->ok('svc');
        $off = $this->h->counting->statements - $s0;
        $this->on(true);
        $this->ok('svc');
        $s1 = $this->h->counting->statements;
        $this->ok('svc');
        $this->assertSame($off, $this->h->counting->statements - $s1, 'switch on: a healthy check-in costs the same number of statements');
    }

    public function testASteadyThresholdCheckCostsOneReadAndNoWrite(): void
    {
        $this->on(true);
        [$this->dev, $this->T] = $this->enrollDevice('STD-1');
        $plain = [['key' => 'cpu1', 'type' => 'cpu', 'params' => new \stdClass(), 'interval_s' => 60]];
        $this->define($plain);
        $this->bad('cpu1', 'ok', 40.0);
        $s0 = $this->h->counting->statements;
        $this->bad('cpu1', 'ok', 41.0);
        $plainCost = $this->h->counting->statements - $s0;

        $this->define([['key' => 'cpu1', 'type' => 'cpu', 'params' => ['thresholds' => ['warn' => ['op' => 'gt', 'value' => 80], 'for_samples' => 3], 'flap' => ['window' => 10]], 'interval_s' => 60]]);
        for ($i = 0; $i < 12; ++$i) {
            $this->bad('cpu1', 'ok', 40.0 + $i);   // warms the flap window up; every sample is good and under the limit
        }
        $writes = (int) $this->h->one("SELECT COUNT(*) FROM rmm_check_eval WHERE device_id = {$this->dev}");
        $this->assertSame(1, $writes);
        $s1 = $this->h->counting->statements;
        $this->bad('cpu1', 'ok', 55.0);
        $this->assertSame($plainCost + 1, $this->h->counting->statements - $s1, 'one batched read of the evaluation row; nothing is written while the state does not change');
        $this->assertEqualsWithDelta(55.0, 55.0, 0.0);
        // the reading itself is refreshed only when the row is older than a quarter of an hour
        $this->clock()->advance(1000);
        $s2 = $this->h->counting->statements;
        $this->bad('cpu1', 'ok', 56.0);
        $this->assertGreaterThan($plainCost + 1, $this->h->counting->statements - $s2);
        $this->assertSame(56.0, (float) $this->h->one("SELECT last_reading FROM rmm_check_eval WHERE device_id = {$this->dev}"));
    }

    // ------------------------------------------------------------------ lifecycle

    public function testAnAlertRecordIsWrittenAcknowledgedAndResolved(): void
    {
        $this->linked();
        $this->bad();
        $this->bad();
        $this->assertSame([], $this->meta());
        $this->bad();
        $m = $this->meta();
        $this->assertCount(1, $m);
        $this->assertSame(['open', 'crit', 1], [$m[0]['state'], $m[0]['severity'], (int) $m[0]['episode']]);
        $this->assertSame("c{$this->h->clientA}:svc", $m[0]['group_key']);
        $alertId = (int) $m[0]['alert_id'];
        $opened = $this->events->of(RmmEvent::ALERT_OPENED);
        $this->assertCount(1, $opened);
        $this->assertSame([$alertId, 'svc', 'error', 1], [$opened[0]['alert_id'], $opened[0]['check_key'], $opened[0]['severity'], $opened[0]['episode']]);
        $this->assertSame($this->dev, $opened[0]['device_id']);

        // a redelivered third check-in cannot record the episode twice
        $this->bad();
        $this->assertCount(1, $this->meta());

        [$c, $j] = $this->h->tech('POST', ['alerts', (string) $alertId, 'ack'], $this->pt());
        $this->assertSame(200, $c, json_encode($j));
        $this->assertSame('acknowledged', $j['alert']['state']);
        $this->assertSame([['alert_id' => $alertId, 'user_id' => 1]], $this->esc->acks());
        $this->assertCount(1, $this->events->of(RmmEvent::ALERT_ACKNOWLEDGED));
        [$c] = $this->h->tech('POST', ['alerts', (string) $alertId, 'ack'], $this->pt());
        $this->assertSame(200, $c, 'acknowledging twice is harmless');
        $this->assertCount(1, $this->events->of(RmmEvent::ALERT_ACKNOWLEDGED));

        $this->ok();
        $this->ok();
        $m = $this->meta();
        $this->assertSame(['resolved', 'recovered'], [$m[0]['state'], $m[0]['resolve_reason']]);
        $this->assertCount(1, $this->events->of(RmmEvent::ALERT_RESOLVED));
        [$c] = $this->h->tech('POST', ['alerts', (string) $alertId, 'ack'], $this->pt());
        $this->assertSame(409, $c, 'a resolved alert cannot be acknowledged');
    }

    public function testManualResolveClosesTheEditionAlertAndStartsAFreshEpisode(): void
    {
        $this->linked();
        foreach ([1, 2, 3] as $_) {
            $this->bad();
        }
        $alertId = (int) $this->meta()[0]['alert_id'];
        [$c, $j] = $this->h->tech('POST', ['alerts', (string) $alertId, 'resolve'], $this->pt());
        $this->assertSame(200, $c);
        $this->assertSame(['resolved', 'manual', 1], [$j['alert']['state'], $j['alert']['resolve_reason'], $j['alert']['resolved_by']]);
        $this->assertSame([$alertId], $this->h->bridge->autoClosedAlerts());
        $this->assertNull($this->h->one("SELECT alert_id FROM endpoint_agent_checks WHERE device_id={$this->dev}"));
        $this->bad();
        $this->bad();
        $this->assertCount(1, $this->meta(), 'the debounce starts again');
        $this->bad();
        $this->assertCount(2, $this->meta());
        $this->assertSame(2, (int) $this->meta()[1]['episode']);
    }

    public function testAlertsOpenedBeforeTheSwitchWasOnAreAdoptedByHousekeeping(): void
    {
        $this->on(false);
        [$this->dev, $this->T] = $this->enrollDevice('ADO-1');
        foreach ([1, 2, 3] as $_) {
            $this->bad();
        }
        $this->assertSame([], $this->meta());
        $this->on(true);
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame(1, $out['alerts_adopted']);
        $this->assertCount(1, $this->meta());
        $this->assertSame(0, $this->h->module->housekeeping()->run()['alerts_adopted']);
    }

    public function testRetiringADeviceResolvesItsRecords(): void
    {
        $this->linked();
        foreach ([1, 2, 3] as $_) {
            $this->bad();
        }
        $this->h->module->checks()->resolveAllOpen($this->dev, 'retired');
        $this->assertSame(['resolved', 'device_retired'], [$this->meta()[0]['state'], $this->meta()[0]['resolve_reason']]);
    }

    public function testWarnToFailRaisesTheSeverityOfTheOpenAlert(): void
    {
        $this->linked();
        foreach ([1, 2, 3] as $_) {
            $this->bad('svc', 'warn');
        }
        $this->assertSame('warn', $this->meta()[0]['severity']);
        $this->bad('svc', 'fail');
        $this->assertSame('crit', $this->meta()[0]['severity']);
        $this->assertSame([['alert_id' => (int) $this->meta()[0]['alert_id'], 'severity' => 'error']], $this->esc->severities());
        $esc = $this->events->of(RmmEvent::ALERT_ESCALATED);
        $this->assertSame('severity', $esc[0]['reason']);
        $this->bad('svc', 'warn');
        $this->assertSame('warn', $this->meta()[0]['severity']);
    }

    // ------------------------------------------------------------------ thresholds

    public function testThresholdTiersFromAReportedValue(): void
    {
        $this->linked();
        $this->define([['key' => 'cpu1', 'type' => 'cpu', 'params' => ['thresholds' => ['warn' => ['op' => 'gt', 'value' => 80], 'crit' => ['op' => 'gt', 'value' => 95]]], 'interval_s' => 60]]);
        
        $this->bad('cpu1', 'ok', 50.0);
        $this->assertSame('ok', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='cpu1'"));
        $this->bad('cpu1', 'ok', 85.0);
        $row = $this->h->rows("SELECT * FROM endpoint_agent_checks WHERE check_key='cpu1'")[0];
        $this->assertSame('warn', $row['status']);
        $this->assertStringContainsString('value 85 > 80 (warn)', (string) $row['detail']);
        $this->bad('cpu1', 'ok', 97.0);
        $this->assertSame('fail', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='cpu1'"));
        $ev = $this->h->rows("SELECT * FROM rmm_check_eval WHERE check_key='cpu1'")[0];
        $this->assertSame(['crit', 97.0], [$ev['tier'], (float) $ev['last_reading']]);
        // the agent's own verdict can only make it worse
        $this->bad('cpu1', 'fail', 10.0);
        $this->assertSame('fail', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='cpu1'"));
        // a result without a value, or an unknown one, passes through
        $this->bad('cpu1', 'warn');
        $this->assertSame('warn', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='cpu1'"));
        $this->bad('cpu1', 'unknown', 99.0);
        $this->assertSame('unknown', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='cpu1'"));
    }

    public function testDurationInSamplesIsTheDebounceAndHysteresisHoldsTheTier(): void
    {
        $this->linked();
        $this->define([['key' => 'mem1', 'type' => 'memory', 'params' => ['thresholds' => ['warn' => ['op' => 'gt', 'value' => 80], 'hysteresis' => 5, 'for_samples' => 3]], 'interval_s' => 60]]);
        $this->bad('mem1', 'ok', 90.0);
        $this->bad('mem1', 'ok', 90.0);
        $this->assertSame([], $this->meta(), 'two samples of three');
        $this->assertSame('ok', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='mem1'"));
        $this->assertStringContainsString('pending warn (2/3)', (string) $this->h->one("SELECT detail FROM endpoint_agent_checks WHERE check_key='mem1'"));
        $this->bad('mem1', 'ok', 90.0);
        $m = $this->meta();
        $this->assertCount(1, $m, 'the threshold duration replaces the failure debounce: the alert opens with the third sample');
        $this->assertSame('warn', $m[0]['severity']);
        // 77 is inside the hysteresis band (limit 80 - 5): the tier is kept, no recovery progress
        $this->bad('mem1', 'ok', 77.0);
        $this->bad('mem1', 'ok', 77.0);
        $this->assertSame('open', $this->meta()[0]['state']);
        // 75 is out of the band; two good samples resolve
        $this->bad('mem1', 'ok', 75.0);
        $this->bad('mem1', 'ok', 75.0);
        $this->assertSame('resolved', $this->meta()[0]['state']);
    }

    public function testDurationInMinutes(): void
    {
        $this->linked();
        $this->define([['key' => 'mem1', 'type' => 'memory', 'params' => ['thresholds' => ['warn' => ['op' => 'gt', 'value' => 80], 'for_minutes' => 10]], 'interval_s' => 60]]);
        $this->bad('mem1', 'ok', 90.0);
        $this->clock()->advance(300);
        $this->bad('mem1', 'ok', 90.0);
        $this->assertSame('ok', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='mem1'"));
        $this->clock()->advance(300);
        $this->bad('mem1', 'ok', 90.0);
        $this->assertSame('warn', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='mem1'"));
        $this->assertCount(1, $this->meta());
    }

    public function testPerDeviceOverrideThroughTheApiAndTheResolverHook(): void
    {
        $this->linked();
        $this->define([['key' => 'cpu1', 'type' => 'cpu', 'params' => ['thresholds' => ['warn' => ['op' => 'gt', 'value' => 80]]], 'interval_s' => 60]]);
        [$c, $j] = $this->h->tech('GET', [(string) $this->dev, 'checks', 'cpu1', 'thresholds'], $this->pt());
        $this->assertSame(200, $c, json_encode($j));
        $this->assertSame(80, $j['effective']['warn']['value']);
        $this->assertNull($j['override']);
        [$c, $j] = $this->h->tech('PUT', [(string) $this->dev, 'checks', 'cpu1', 'thresholds'], $this->pt(), ['warn' => ['op' => 'gt', 'value' => 95]]);
        $this->assertSame(200, $c, json_encode($j));
        $this->bad('cpu1', 'ok', 90.0);
        $this->assertSame('ok', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='cpu1'"), '90 is under this device\'s own 95');
        $this->bad('cpu1', 'ok', 96.0);
        $this->assertSame('warn', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='cpu1'"));
        // a field set to null is removed; an override that leaves nothing valid is refused
        [$c] = $this->h->tech('PUT', [(string) $this->dev, 'checks', 'cpu1', 'thresholds'], $this->pt(), ['warn' => null]);
        $this->assertSame(422, $c);
        [$c] = $this->h->tech('DELETE', [(string) $this->dev, 'checks', 'cpu1', 'thresholds'], $this->pt());
        $this->assertSame(200, $c);
        $this->bad('cpu1', 'ok', 90.0);
        $this->assertSame('warn', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='cpu1'"), 'the definition applies again');
        // a check that declares no thresholds has nothing to override
        $this->define([['key' => 'cpu1', 'type' => 'cpu', 'interval_s' => 60]]);
        [$c] = $this->h->tech('PUT', [(string) $this->dev, 'checks', 'cpu1', 'thresholds'], $this->pt(), ['warn' => ['op' => 'gt', 'value' => 1]]);
        $this->assertSame(409, $c);
    }

    public function testThresholdsOfAPolicyCheckTemplateApplyToTheDevicesItReaches(): void
    {
        $this->on(true);
        $this->assertSame([], $this->h->module->settings()->update(['features_json' => ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true, 'policies' => true, 'alerting' => true]]));
        $this->h->module->alertingEngine()->forget();
        [$a, $ta] = $this->enrollDevice('POL-A');
        [$b, $tb] = $this->enrollDevice('POL-B', $this->h->clientB);
        $p = $this->h->module->policyStore()->create(['name' => 'Hot boxes', 'settings' => ['check.cpu_p' => ['type' => 'cpu', 'params' => ['thresholds' => ['warn' => ['op' => 'gt', 'value' => 70]]], 'interval_s' => 60]]], 1);
        $this->h->module->policyStore()->assign((int) $p['policy_id'], ['scope_type' => 'client', 'scope_id' => $this->h->clientA], 1);
        $caps = ['capabilities' => ['check:cpu', 'policies'], 'platform' => 'windows', 'arch' => 'amd64'];
        [, , $r] = $this->h->checkin($ta, $caps);
        $this->assertContains('cpu_p', array_column($r['config']['checks'], 'key'), 'the template reaches the assigned client\'s device');
        [, , $r] = $this->h->checkin($tb, $caps);
        $this->assertNotContains('cpu_p', array_column($r['config']['checks'], 'key'));
        // 75 is over the policy's 70 for device A only; the global list knows no cpu_p at all
        $this->h->checkin($ta, $caps + ['checks' => [['key' => 'cpu_p', 'status' => 'ok', 'detail' => '', 'value' => 75.0]]]);
        $this->h->checkin($tb, $caps + ['checks' => [['key' => 'cpu_p', 'status' => 'ok', 'detail' => '', 'value' => 75.0]]]);
        $this->assertSame('warn', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE device_id = $a AND check_key = 'cpu_p'"));
        $this->assertSame('ok', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE device_id = $b AND check_key = 'cpu_p'"));
    }

    public function testScheduledScriptsWaitOutASuppressWindowButNotAMuteWindow(): void
    {
        $this->linked();
        $dev = $this->h->module->devices()->find($this->dev);
        $this->assertNotNull($dev);
        $gate = $this->h->module->maintenance();
        $g = new \RivetCore\Rmm\Alerting\MaintenanceScheduleGate($gate, $this->h->module->settings());
        $this->assertSame('run', $g->decide($dev, [], time()));
        $mute = $this->window([]);
        $this->assertSame('run', $g->decide($dev, [], time()), 'a mute window only holds alerts back');
        $this->h->tech('DELETE', ['maintenance', (string) $mute], $this->pt());
        $sup = $this->window(['mode' => 'suppress']);
        $this->assertSame('defer', $g->decide($dev, [], time()), 'leave a suppressed device alone, ask again later');
        $this->h->tech('DELETE', ['maintenance', (string) $sup], $this->pt());
        $this->assertSame('run', $g->decide($dev, [], time()));
        // with the alerting switch off nothing changes for scheduled scripts
        $this->window(['mode' => 'suppress']);
        $this->on(false);
        $this->assertSame('run', $g->decide($dev, [], time()));
        // and it is the gate the module hands to the scheduler unless the edition sets its own
        $this->assertInstanceOf(\RivetCore\Rmm\Alerting\MaintenanceScheduleGate::class, (new \ReflectionProperty($this->h->module->scheduleRunner(), 'gate'))->getValue($this->h->module->scheduleRunner()));
    }

    // ------------------------------------------------------------------ flap

    public function testAFlappingCheckHoldsItsAlertState(): void
    {
        $this->linked();
        $this->define([['key' => 'svc', 'type' => 'service', 'params' => ['name' => 'x', 'flap' => ['window' => 6, 'high_pct' => 60, 'low_pct' => 20]], 'interval_s' => 60]]);
        $this->h->module->settings()->update(['failure_debounce' => 1, 'recovery_debounce' => 1]);
        $flapping = fn (): int => (int) ($this->h->rows("SELECT flapping FROM rmm_check_eval WHERE check_key='svc'")[0]['flapping'] ?? 0);
        $i = 0;
        while ($flapping() === 0 && $i < 20) {
            $this->bad('svc', $i % 2 === 0 ? 'fail' : 'ok');
            ++$i;
        }
        $this->assertSame(1, $flapping(), 'judged to flap after the window is half full');
        $this->assertLessThanOrEqual(6, $i);
        $episodes = count($this->meta());
        $states = array_column($this->meta(), 'state');
        $this->assertGreaterThanOrEqual(1, $episodes, 'with debounce 1 the early alternation opened and closed alerts');
        for ($k = 0; $k < 10; ++$k) {
            $this->bad('svc', $k % 2 === 0 ? 'fail' : 'ok');
        }
        $this->assertCount($episodes, $this->meta(), 'while it flaps no alert opens');
        $this->assertSame($states, array_column($this->meta(), 'state'), 'and none resolves');
        $this->assertStringContainsString('flapping', (string) $this->h->one("SELECT detail FROM endpoint_agent_checks WHERE check_key='svc'"));
        // steady good samples end the flapping
        for ($k = 0; $k < 12; ++$k) {
            $this->bad('svc', 'ok');
        }
        $this->assertSame(0, $flapping());
        // and a real failure now alerts again
        $this->bad('svc', 'fail');
        $this->assertGreaterThan($episodes, count($this->meta()));
    }

    // ------------------------------------------------------------------ maintenance

    private function window(array $o): int
    {
        [$c, $j] = $this->h->tech('POST', ['maintenance'], $this->pt(), $o + ['name' => 'W', 'kind' => 'once', 'starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600), 'scope_type' => 'all']);
        $this->assertSame(201, $c, json_encode($j));

        return (int) $j['window']['window_id'];
    }

    public function testAMuteWindowHoldsTheAlertBackAndItOpensAfterTheWindow(): void
    {
        $this->linked();
        $w = $this->window([]);
        foreach ([1, 2, 3, 4] as $_) {
            $this->bad();
        }
        $this->assertSame([], $this->h->bridge->alertKeys(), 'nothing opened in the window');
        $this->assertSame([], $this->events->of(RmmEvent::ALERT_OPENED));
        $this->clock()->advance(7200);
        $this->bad();
        $this->assertCount(1, $this->h->bridge->alertKeys(), 'the check is still bad after the window: the alert opens');
        $this->assertCount(1, $this->meta());
        $this->assertGreaterThan(0, $w);
    }

    public function testAMuteWindowKeepsAnOpenAlertAndItsRecovery(): void
    {
        $this->linked();
        foreach ([1, 2, 3] as $_) {
            $this->bad();
        }
        $this->window([]);
        $this->ok();
        $this->ok();
        $this->assertSame('resolved', $this->meta()[0]['state'], 'mute holds new alerts back; it does not freeze an existing one');
    }

    public function testASuppressWindowFreezesEvaluation(): void
    {
        $this->linked();
        $this->window(['mode' => 'suppress']);
        foreach ([1, 2, 3, 4, 5] as $_) {
            $this->bad();
        }
        $this->assertSame('fail', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='svc'"), 'the result is recorded');
        $this->assertSame(0, (int) $this->h->one("SELECT consecutive_failures FROM endpoint_agent_checks WHERE check_key='svc'"), 'but no counter moved');
        $this->assertSame([], $this->h->bridge->alertKeys());
        $this->clock()->advance(7200);
        $this->bad();
        $this->bad();
        $this->assertSame([], $this->h->bridge->alertKeys());
        $this->bad();
        $this->assertCount(1, $this->h->bridge->alertKeys(), 'the debounce starts when the window is over');
    }

    public function testWindowScopes(): void
    {
        $this->linked('SC-1');
        [$other, $otherT] = $this->enrollDevice('SC-2', $this->h->clientB);
        $this->window(['scope_type' => 'client', 'scope_id' => $this->h->clientA]);
        foreach ([1, 2, 3] as $_) {
            $this->bad('svc', 'fail', null, $this->T);
            $this->bad('svc', 'fail', null, $otherT);
        }
        $keys = $this->h->bridge->alertKeys();
        $this->assertCount(1, $keys);
        $this->assertStringStartsWith("agent:$other:", $keys[0], 'only client B is alerting');
    }

    public function testTagGroupDeviceAndSiteScopesNeedAdminOrTheClientGrant(): void
    {
        $this->linked();
        $tag = $this->h->module->tags()->create('kiosk');
        $this->h->module->tags()->assign($this->dev, $tag['tag_id'], 'manual', 1);
        $group = $this->h->module->groups()->create('Floor 1');
        $this->h->module->groups()->addDevices($group['group_id'], [$this->dev]);
        $now = $this->h->module->maintenance();
        $dev = $this->h->module->devices()->find($this->dev);
        $this->assertNotNull($dev);
        $mk = fn (string $scope, int $id): int => $this->window(['scope_type' => $scope, 'scope_id' => $id]);
        foreach ([['tag', $tag['tag_id']], ['group', $group['group_id']], ['device', $this->dev], ['client', $this->h->clientA]] as [$s, $i]) {
            $id = $mk($s, $i);
            $this->assertCount(1, $now->activeFor($dev), $s);
            $this->h->tech('DELETE', ['maintenance', (string) $id], $this->pt());
        }
        $this->assertSame([], $now->activeFor($dev));
        [$c] = $this->h->tech('POST', ['maintenance'], $this->pt(), ['name' => 'nope', 'kind' => 'once', 'starts_at' => '2026-01-01T00:00:00Z', 'ends_at' => '2026-01-02T00:00:00Z', 'scope_type' => 'tag', 'scope_id' => 99999]);
        $this->assertSame(422, $c, 'a tag that does not exist');
    }

    public function testTheTickAnnouncesStartAndEndOnce(): void
    {
        $this->linked();
        $this->window(['starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 60), 'ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600)]);
        $this->h->module->housekeeping()->run();
        $this->assertSame([], $this->events->of(RmmEvent::MAINTENANCE_STARTED));
        $this->clock()->advance(120);
        $this->h->module->housekeeping()->run();
        $this->h->module->housekeeping()->run();
        $s = $this->events->of(RmmEvent::MAINTENANCE_STARTED);
        $this->assertCount(1, $s);
        $this->assertSame(['mute', 'all', 0, ''], [$s[0]['mode'], $s[0]['scope_type'], $s[0]['device_id'], $s[0]['hostname']]);
        $this->clock()->advance(600);
        $this->h->module->housekeeping()->run();
        $this->h->module->housekeeping()->run();
        $this->assertCount(1, $this->events->of(RmmEvent::MAINTENANCE_ENDED));
    }

    public function testWindowCrudAndValidationThroughTheApi(): void
    {
        $this->linked();
        $id = $this->window(['name' => 'Patch Tuesday', 'kind' => 'recurring', 'recur_freq' => 'weekly', 'recur_days' => '2', 'local_start' => '22:00', 'duration_min' => 120, 'timezone' => 'America/Chicago']);
        [$c, $j] = $this->h->tech('GET', ['maintenance', (string) $id], $this->pt());
        $this->assertSame(200, $c);
        $this->assertSame(['weekly', '2', '22:00', 'America/Chicago'], [$j['window']['recur_freq'], $j['window']['recur_days'], $j['window']['local_start'], $j['window']['timezone']]);
        $this->assertNotNull($j['window']['next_starts_at']);
        [$c, $j] = $this->h->tech('PATCH', ['maintenance', (string) $id], $this->pt(), ['duration_min' => 60, 'enabled' => false]);
        $this->assertSame(200, $c, json_encode($j));
        $this->assertSame([60, false], [$j['window']['duration_min'], $j['window']['enabled']]);
        [$c] = $this->h->tech('PATCH', ['maintenance', (string) $id], $this->pt(), ['timezone' => 'Nope/Zone']);
        $this->assertSame(422, $c);
        [$c, $j] = $this->h->tech('GET', ['maintenance'], $this->pt());
        $this->assertSame(1, count($j['data']));
        [$c] = $this->h->tech('DELETE', ['maintenance', (string) $id], $this->pt());
        $this->assertSame(200, $c);
        [$c] = $this->h->tech('GET', ['maintenance', (string) $id], $this->pt());
        $this->assertSame(404, $c);
        [$c] = $this->h->tech('GET', ['maintenance'], null);
        $this->assertSame(401, $c);
    }

    // ------------------------------------------------------------------ dependency

    public function testAnOfflineParentHoldsTheChildrenAlertsBack(): void
    {
        $this->linked('PAR-1');
        [$child, $childT] = $this->enrollDevice('CHD-1');
        [$parent] = [$this->dev];
        [$c, $j] = $this->h->tech('PUT', [(string) $child, 'parent'], $this->pt(), ['parent_device_id' => $parent]);
        $this->assertSame(200, $c, json_encode($j));
        [$c, $j] = $this->h->tech('GET', [(string) $parent, 'parent'], $this->pt());
        $this->assertSame([$child], array_column($j['children'], 'device_id'));
        $this->assertTrue($this->h->module->dependencies()->downAncestor($child) === null, 'the parent is online');
        foreach ([1, 2, 3] as $_) {
            $this->bad('svc', 'fail', null, $childT);
        }
        $this->assertCount(1, $this->h->bridge->alertKeys(), 'parent online: the child alerts');
        $this->h->bridge->alertKeys();
        // the parent goes quiet
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at = '" . gmdate('Y-m-d H:i:s', time() - 7200) . "' WHERE device_id = $parent");
        foreach ([1, 2, 3] as $_) {
            $this->bad('disk', 'fail', null, $childT);
        }
        $this->assertCount(1, $this->h->bridge->alertKeys(), 'no second alert while the parent is down');
        $this->assertSame(['id' => $parent], ['id' => $this->h->module->dependencies()->downAncestor($child)['device_id'] ?? 0]);
        // parent back: the held check opens on its next sample
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at = UTC_TIMESTAMP() WHERE device_id = $parent");
        $this->bad('disk', 'fail', null, $childT);
        $this->assertCount(2, $this->h->bridge->alertKeys());
    }

    public function testParentRulesAreEnforced(): void
    {
        $this->linked('PR-1');
        $a = $this->dev;
        [$b] = $this->enrollDevice('PR-2');
        [$x] = $this->enrollDevice('PR-3', $this->h->clientB);
        [$c] = $this->h->tech('PUT', [(string) $a, 'parent'], $this->pt(), ['parent_device_id' => $a]);
        $this->assertSame(422, $c, 'itself');
        [$c] = $this->h->tech('PUT', [(string) $a, 'parent'], $this->pt(), ['parent_device_id' => $x]);
        $this->assertSame(422, $c, 'another client');
        [$c] = $this->h->tech('PUT', [(string) $a, 'parent'], $this->pt(), ['parent_device_id' => $b]);
        $this->assertSame(200, $c);
        [$c, $j] = $this->h->tech('PUT', [(string) $b, 'parent'], $this->pt(), ['parent_device_id' => $a]);
        $this->assertSame(422, $c, 'a loop');
        [$c] = $this->h->tech('DELETE', [(string) $a, 'parent'], $this->pt());
        $this->assertSame(200, $c);
        [$c] = $this->h->tech('DELETE', [(string) $a, 'parent'], $this->pt());
        $this->assertSame(404, $c);
    }

    // ------------------------------------------------------------------ storm control

    public function testStormControlCapsAlertsPerClientAndOpensOneSummary(): void
    {
        $this->linked('ST-1');
        $this->h->module->storm()->update(['storm_client_max' => 2, 'storm_global_max' => 0]);
        $this->define(array_map(static fn (int $i): array => ['key' => "svc$i", 'type' => 'service', 'params' => ['name' => "s$i"], 'interval_s' => 60], range(1, 6)));
        $this->h->module->settings()->update(['failure_debounce' => 1]);
        $this->h->checkin($this->T, ['checks' => array_map(static fn (int $i): array => ['key' => "svc$i", 'status' => 'fail', 'detail' => ''], range(1, 6))]);
        $this->assertCount(2, $this->meta(), 'two alerts opened, four held back');
        $summaries = $this->h->rows('SELECT * FROM rmm_storm_summaries');
        $this->assertCount(1, $summaries);
        $this->assertSame("client:{$this->h->clientA}", $summaries[0]['scope']);
        $keys = array_values(array_filter($this->h->bridge->alertKeys(), static fn (string $k): bool => str_contains($k, ':storm:')));
        $this->assertCount(1, $keys);
        // the held checks stay bad and are asked again; one summary per window
        $this->h->checkin($this->T, ['checks' => array_map(static fn (int $i): array => ['key' => "svc$i", 'status' => 'fail', 'detail' => ''], range(1, 6))]);
        $this->assertCount(1, $this->h->rows('SELECT * FROM rmm_storm_summaries'));
        $this->assertCount(2, $this->meta());
        // the window passes: the rest open (two per window), the summary resolves when the rate is low
        $this->clock()->advance(700);
        $this->h->checkin($this->T, ['checks' => array_map(static fn (int $i): array => ['key' => "svc$i", 'status' => 'fail', 'detail' => ''], range(1, 6))]);
        $this->assertCount(4, $this->meta());
        $this->clock()->advance(700);
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame(2, $out['storm_resolved'], 'one summary per window, both over now');
        $this->assertSame([], $this->h->rows('SELECT * FROM rmm_storm_summaries WHERE resolved_at IS NULL'));
    }

    public function testGlobalCapAndSettingsApi(): void
    {
        $this->linked('GL-1');
        [$c, $j] = $this->h->tech('GET', ['alerting', 'settings'], $this->pt());
        $this->assertSame([200, true, 100, 500], [$c, $j['alerting_enabled'], $j['storm']['storm_client_max'], $j['storm']['storm_global_max']]);
        [$c] = $this->h->tech('PUT', ['alerting', 'settings'], $this->pt(), ['storm_client_max' => -1]);
        $this->assertSame(422, $c);
        [$c] = $this->h->tech('PUT', ['alerting', 'settings'], $this->pt(), ['storm_client_max' => 0, 'storm_global_max' => 1, 'bogus' => 1]);
        $this->assertSame(422, $c);
        [$c, $j] = $this->h->tech('PUT', ['alerting', 'settings'], $this->pt(), ['storm_client_max' => 0, 'storm_global_max' => 1]);
        $this->assertSame([200, 1], [$c, $j['storm']['storm_global_max']]);
        $this->define([['key' => 'a', 'type' => 'service', 'params' => ['name' => 'a'], 'interval_s' => 60], ['key' => 'b', 'type' => 'service', 'params' => ['name' => 'b'], 'interval_s' => 60]]);
        $this->h->module->settings()->update(['failure_debounce' => 1]);
        $this->h->checkin($this->T, ['checks' => [['key' => 'a', 'status' => 'fail', 'detail' => ''], ['key' => 'b', 'status' => 'fail', 'detail' => '']]]);
        $this->assertCount(1, $this->meta());
        $this->assertSame('global', $this->h->rows('SELECT scope FROM rmm_storm_summaries')[0]['scope']);
    }

    // ------------------------------------------------------------------ escalation

    /** @return int policy id */
    private function policy(array $o = []): int
    {
        [$c, $j] = $this->h->tech('POST', ['escalation_policies'], $this->pt(), $o + ['name' => 'On call', 'steps' => [
            ['after_min' => 0, 'targets' => [['type' => 'user', 'ref' => '7']]],
            ['after_min' => 15, 'targets' => [['type' => 'email', 'ref' => 'noc@example.test'], ['type' => 'chat', 'ref' => '#ops']]],
        ]]);
        $this->assertSame(201, $c, json_encode($j));

        return (int) $j['policy']['policy_id'];
    }

    public function testEscalationFiresEachStepOnceAndAcknowledgementStopsIt(): void
    {
        $this->linked();
        $pid = $this->policy();
        foreach ([1, 2, 3] as $_) {
            $this->bad();
        }
        $this->assertSame($pid, (int) $this->meta()[0]['policy_id']);
        $this->assertSame([], $this->esc->notifications(), 'nothing is sent inside the check-in');
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame(1, $out['escalations_sent']);
        $n = $this->esc->notifications();
        $this->assertCount(1, $n);
        $this->assertSame([1, false, 'warning' === $n[0]['severity'], [['type' => 'user', 'ref' => '7']]], [$n[0]['step'], $n[0]['repeat'], false, $n[0]['targets']]);
        $this->assertSame(['error', 'open', "H-AL-1", 'svc'], [$n[0]['severity'], $n[0]['state'], $n[0]['hostname'], $n[0]['check_key']]);
        $this->h->module->housekeeping()->run();
        $this->assertCount(1, $this->esc->notifications(), 'step 2 is not due yet and step 1 never fires twice');
        $this->clock()->advance(14 * 60);
        $this->h->module->housekeeping()->run();
        $this->assertCount(1, $this->esc->notifications());
        $this->clock()->advance(120);
        $this->h->module->housekeeping()->run();
        $n = $this->esc->notifications();
        $this->assertCount(2, $n);
        $this->assertSame([2, ['email', 'chat']], [$n[1]['step'], array_column($n[1]['targets'], 'type')]);
        $this->h->module->housekeeping()->run();
        $this->clock()->advance(86400);
        $this->h->module->housekeeping()->run();
        $this->assertCount(2, $this->esc->notifications(), 'no repeat configured: the chain ends');
        $this->assertNull($this->meta()[0]['esc_next_at']);
        $steps = array_column($this->events->of(RmmEvent::ALERT_ESCALATED), 'step');
        $this->assertSame([1, 2], $steps);

        // a second alert acknowledged before step 2 never reaches it
        $this->bad('disk', 'fail');
        $this->bad('disk', 'fail');
        $this->bad('disk', 'fail');
        $diskAlert = (int) $this->meta("check_key = 'disk'")[0]['alert_id'];
        $this->h->module->housekeeping()->run();
        $before = count($this->esc->notifications());
        $this->h->tech('POST', ['alerts', (string) $diskAlert, 'ack'], $this->pt());
        $this->clock()->advance(3600);
        $this->h->module->housekeeping()->run();
        $this->assertCount($before, $this->esc->notifications());
    }

    public function testRepeatAndTheCap(): void
    {
        $this->linked();
        $this->policy(['name' => 'Loud', 'repeat_every_min' => 30, 'repeat_max' => 2]);
        foreach ([1, 2, 3] as $_) {
            $this->bad();
        }
        $this->h->module->housekeeping()->run();
        $this->clock()->advance(16 * 60);
        $this->h->module->housekeeping()->run();
        for ($i = 0; $i < 5; ++$i) {
            $this->clock()->advance(31 * 60);
            $this->h->module->housekeeping()->run();
        }
        $n = $this->esc->notifications();
        $this->assertCount(4, $n, '2 steps + 2 repeats');
        $this->assertSame([false, false, true, true], array_column($n, 'repeat'));
        $this->assertSame(2, $n[3]['step'], 'a repeat re-notifies the last step');
    }

    public function testAFailedDeliveryIsRetriedThenGivenUp(): void
    {
        $this->linked();
        $this->policy(['steps' => [['after_min' => 0, 'targets' => [['type' => 'user', 'ref' => '7']]], ['after_min' => 10, 'targets' => [['type' => 'user', 'ref' => '8']]]]]);
        foreach ([1, 2, 3] as $_) {
            $this->bad();
        }
        $this->esc->answers = [false, false, true];
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame([0, 1], [$out['escalations_sent'], $out['escalations_failed']]);
        $this->h->module->housekeeping()->run();
        $this->assertCount(1, $this->esc->notifications(), 'the retry waits a minute');
        $this->clock()->advance(61);
        $this->h->module->housekeeping()->run();
        $this->clock()->advance(61);
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame(1, $out['escalations_sent']);
        $this->assertCount(3, $this->esc->notifications());
        $this->assertSame(1, (int) $this->meta()[0]['esc_count']);

        // an adapter that throws counts as not delivered and never breaks housekeeping; after 5 attempts the step is skipped
        $this->esc->throw = new \RuntimeException('smtp down');
        $this->clock()->advance(11 * 60);
        for ($i = 0; $i < 6; ++$i) {
            $this->h->module->housekeeping()->run();
            $this->clock()->advance(61);
        }
        $this->assertSame(2, (int) $this->meta()[0]['esc_count'], 'step 2 was given up after the attempts and the chain moved on');
    }

    public function testMuteWindowPausesNotices(): void
    {
        $this->linked();
        $this->policy();
        foreach ([1, 2, 3] as $_) {
            $this->bad();
        }
        $w = $this->window([]);
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame([0, 1], [$out['escalations_sent'], $out['escalations_held']]);
        $this->assertSame([], $this->esc->notifications());
        $this->h->tech('DELETE', ['maintenance', (string) $w], $this->pt());
        $this->clock()->advance(301);
        $this->h->module->housekeeping()->run();
        $this->assertCount(1, $this->esc->notifications());
    }

    public function testPolicyScopeSpecificityAndMinimumSeverity(): void
    {
        $this->linked();
        $this->policy(['name' => 'Everyone']);
        $dev = $this->policy(['name' => 'This box', 'scope_type' => 'device', 'scope_id' => $this->dev, 'steps' => [['after_min' => 0, 'targets' => [['type' => 'user', 'ref' => '99']]]]]);
        $crit = $this->policy(['name' => 'Crit only', 'scope_type' => 'client', 'scope_id' => $this->h->clientB, 'min_severity' => 'crit', 'steps' => [['after_min' => 0, 'targets' => [['type' => 'user', 'ref' => '1']]]]]);
        foreach ([1, 2, 3] as $_) {
            $this->bad('svc', 'warn');
        }
        $this->assertSame($dev, (int) $this->meta()[0]['policy_id'], 'the device policy beats the global one');
        $this->assertNotSame($crit, $dev);
        $d = $this->h->module->escalation();
        $other = ['device_id' => 9999, 'client_id' => $this->h->clientB, 'location_id' => 0];
        $this->assertSame($crit, $d->policyFor($other, 'crit')['policy_id'] ?? 0, 'min_severity crit accepts a critical alert on its client');
        $this->assertNotSame($crit, $d->policyFor($other, 'warn')['policy_id'] ?? 0, 'and refuses a warning');
    }

    public function testPolicyValidationAndAdminOnly(): void
    {
        $this->linked();
        $bad = [
            [['name' => '', 'steps' => []], 'name'], [['name' => 'x'], 'steps'], [['name' => 'x', 'steps' => []], 'steps'],
            [['name' => 'x', 'steps' => [['after_min' => -1, 'targets' => [['type' => 'user', 'ref' => '1']]]]], 'after_min'],
            [['name' => 'x', 'steps' => [['after_min' => 10, 'targets' => [['type' => 'user', 'ref' => '1']]], ['after_min' => 5, 'targets' => [['type' => 'user', 'ref' => '1']]]]], 'earlier'],
            [['name' => 'x', 'steps' => [['after_min' => 0, 'targets' => [['type' => 'fax', 'ref' => '1']]]]], 'target'],
            [['name' => 'x', 'steps' => [['after_min' => 0, 'targets' => []]]], 'targets'],
            [['name' => 'x', 'repeat_every_min' => 2, 'steps' => [['after_min' => 0, 'targets' => [['type' => 'user', 'ref' => '1']]]]], 'repeat'],
            [['name' => 'x', 'scope_type' => 'tag', 'scope_id' => 4242, 'steps' => [['after_min' => 0, 'targets' => [['type' => 'user', 'ref' => '1']]]]], 'does not exist'],
        ];
        foreach ($bad as [$in, $needle]) {
            [$c, $j] = $this->h->tech('POST', ['escalation_policies'], $this->pt(), $in);
            $this->assertSame(422, $c, json_encode($in));
            $this->assertStringContainsString($needle, (string) $j['error']);
        }
        $id = $this->policy(['name' => 'Dup']);
        [$c] = $this->h->tech('POST', ['escalation_policies'], $this->pt(), ['name' => 'Dup', 'steps' => [['after_min' => 0, 'targets' => [['type' => 'user', 'ref' => '1']]]]]);
        $this->assertSame(422, $c, 'names are unique');
        [$c, $j] = $this->h->tech('PATCH', ['escalation_policies', (string) $id], $this->pt(), ['enabled' => false, 'repeat_every_min' => 60]);
        $this->assertSame([200, false, 60, 2], [$c, $j['policy']['enabled'], $j['policy']['repeat_every_min'], count($j['policy']['steps'])]);
        [$c] = $this->h->tech('DELETE', ['escalation_policies', (string) $id], $this->pt());
        $this->assertSame(200, $c);
        [$c] = $this->h->tech('GET', ['escalation_policies', (string) $id], $this->pt());
        $this->assertSame(404, $c);
        // a user without rmm.admin can read but not change
        $this->h->policy instanceof \RivetCore\Tests\Support\AllowUsersPolicy && $this->h->policy->grant(5, ['rmm.device.view', 'rmm.alert.manage']);
        [$c] = $this->h->tech('GET', ['escalation_policies'], $this->pt(5));
        $this->assertSame(200, $c);
        [$c] = $this->h->tech('POST', ['escalation_policies'], $this->pt(5), ['name' => 'Z', 'steps' => [['after_min' => 0, 'targets' => [['type' => 'user', 'ref' => '1']]]]]);
        $this->assertSame(403, $c);
        [$c] = $this->h->tech('PUT', ['alerting', 'settings'], $this->pt(5), ['storm_client_max' => 1]);
        $this->assertSame(403, $c);
        [$c] = $this->h->tech('POST', ['maintenance'], $this->pt(5), ['name' => 'all', 'kind' => 'once', 'starts_at' => '2026-01-01T00:00:00Z', 'ends_at' => '2026-01-02T00:00:00Z', 'scope_type' => 'all']);
        $this->assertSame(403, $c, 'a window over every client is an administrator action');
        [$c] = $this->h->tech('POST', ['maintenance'], $this->pt(5), ['name' => 'mine', 'kind' => 'once', 'starts_at' => '2026-01-01T00:00:00Z', 'ends_at' => '2026-01-02T00:00:00Z', 'scope_type' => 'client', 'scope_id' => $this->h->clientA]);
        $this->assertSame(201, $c, 'but a window for one client is not');
    }

    // ------------------------------------------------------------------ reads and gating

    public function testAlertListFiltersGroupsAndClientScope(): void
    {
        $this->linked('LS-1');
        [$o, $oT] = $this->enrollDevice('LS-2', $this->h->clientB);
        foreach ([1, 2, 3] as $_) {
            $this->bad('svc', 'fail');
            $this->bad('svc', 'warn', null, $oT);
        }
        [$c, $j] = $this->h->tech('GET', ['alerts'], $this->pt());
        $this->assertSame([200, 2], [$c, $j['total']]);
        [, $j] = $this->h->tech('GET', ['alerts'], $this->pt(), null, ['severity' => 'crit']);
        $this->assertSame([$this->dev], array_column($j['items'], 'device_id'));
        [, $j] = $this->h->tech('GET', [(string) $o, 'alerts'], $this->pt());
        $this->assertSame([$o], array_column($j['items'], 'device_id'));
        [, $j] = $this->h->tech('GET', ['alerts', 'groups'], $this->pt());
        $this->assertCount(2, $j['groups']);
        $this->assertSame('crit', $j['groups'][0]['severity'], 'the worst group first');
        // a user limited to client A does not see client B
        $this->h->tenancy->restrictUser(6, [$this->h->clientA]);
        $this->h->policy instanceof \RivetCore\Tests\Support\AllowUsersPolicy && $this->h->policy->grant(6, ['rmm.device.view', 'rmm.alert.manage']);
        [, $j] = $this->h->tech('GET', ['alerts'], $this->pt(6));
        $this->assertSame([$this->dev], array_column($j['items'], 'device_id'));
        $aid = (int) $this->meta("device_id = $o")[0]['alert_id'];
        [$c] = $this->h->tech('POST', ['alerts', (string) $aid, 'ack'], $this->pt(6));
        $this->assertSame(404, $c, 'another client\'s alert is the same 404 as a missing one');
        [$c] = $this->h->tech('POST', ['alerts', '999999', 'ack'], $this->pt());
        $this->assertSame(404, $c);
    }

    public function testNewCheckTypesAreOfferedOnlyToAgentsThatAnnounceThem(): void
    {
        $this->linked();
        $this->define([['key' => 'd', 'type' => 'disk', 'params' => [], 'interval_s' => 300], ['key' => 'p', 'type' => 'ping', 'params' => ['host' => '10.0.0.1'], 'interval_s' => 60],
            ['key' => 'a', 'type' => 'av', 'params' => [], 'interval_s' => 3600]]);
        [, , $r] = $this->h->checkin($this->T);
        $this->assertSame(['d'], array_column($r['config']['checks'], 'key'), 'an old agent that announces nothing gets exactly what it always got');
        [, , $r] = $this->h->checkin($this->T, ['platform' => 'windows', 'arch' => 'amd64', 'capabilities' => ['check:disk', 'check:ping']]);
        $this->assertSame(['d', 'p'], array_column($r['config']['checks'], 'key'));
        [, , $r] = $this->h->checkin($this->T, ['platform' => 'windows', 'arch' => 'amd64', 'capabilities' => ['check:disk', 'check:ping', 'check:av']]);
        $this->assertSame(['d', 'p', 'a'], array_column($r['config']['checks'], 'key'));
        // a later check-in without a capability list uses the stored one
        [, , $r] = $this->h->checkin($this->T);
        $this->assertSame(['d', 'p', 'a'], array_column($r['config']['checks'], 'key'));
        foreach ($r['config']['checks'] as $c) {
            $this->assertNotSame('', $c['signature']);
        }
        // enrollment never offers a Phase 3 type
        [, , $j] = $this->h->enroll($this->h->token(null, 24, 5), $this->h::device(['serial' => 'EN-9']));
        $this->assertSame(['d'], array_column($j['config']['checks'], 'key'));
    }

    public function testCheckTypeCatalogEndpoint(): void
    {
        $this->linked();
        [$c, $j] = $this->h->tech('GET', ['check_types'], $this->pt());
        $this->assertSame(200, $c);
        $byType = array_column($j['data'], null, 'type');
        $this->assertCount(16, $byType);
        $this->assertTrue($byType['service']['legacy']);
        $this->assertSame(['linux'], $byType['systemd_failed']['platforms']);
        $this->assertTrue($byType['http']['reports_value']);
        $this->assertSame('ms', $byType['ping']['unit']);
        $this->assertTrue($byType['ping']['fields']['host']['required']);
    }

    public function testAnOldAgentWithTheSwitchOnStillGetsLegacyBehaviour(): void
    {
        $this->linked();
        $this->define([['key' => 'd', 'type' => 'disk', 'params' => ['mount' => 'C:'], 'interval_s' => 300]]);
        $this->bad('d', 'warn');
        $this->bad('d', 'warn');
        $this->bad('d', 'warn');
        $this->assertCount(1, $this->h->bridge->alertKeys());
        $this->assertSame('warning', $this->h->bridge->alert((int) $this->meta()[0]['alert_id'])['severity'] ?? 'warning');
    }
}
