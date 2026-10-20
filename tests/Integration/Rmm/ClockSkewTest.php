<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Testing\InMemoryRmmEvents;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/** Device clock skew: the 900 s window, the additive server_time/skew_s on a time refusal, the stored skew, the refusal reason and the once-a-day event. */
final class ClockSkewTest extends RmmTestCase
{
    private InMemoryRmmEvents $bus;
    private int $dev = 0;
    private string $T = '';

    protected function makeHarness(): RmmHarness
    {
        $this->bus = new InMemoryRmmEvents();

        return new RmmHarness(null, null, [], false, null, null, null, $this->bus);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $tok = $this->h->token(null, 24, 100);
        $this->h->asset(['name' => 'PC', 'serial' => 'CS-1', 'make' => 'Dell']);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'CS-1', 'hostname' => 'SKEWPC']));
        $this->dev = (int) $j['device_id'];
        $this->T = $j['device_token'];
        $this->assertSame(true, preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $j['server_time']) === 1, 'enroll carries server_time');
    }

    private function row(): array
    {
        return $this->h->rows("SELECT * FROM endpoint_agent_devices WHERE device_id = {$this->dev}")[0];
    }

    public function testWindowIsNineHundredSecondsAndOldAgentsAreUnchanged(): void
    {
        $this->assertSame(900, RmmProtocol::CHECKIN_FUTURE_SKEW_S);
        [$c, , $r] = $this->h->checkin($this->T, ['collected_at' => $this->h::ts(600)]);   // 10 minutes ahead: accepted since rc.23
        $this->assertSame(200, $c);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $r['server_time'], 'every 200 carries server_time');
        [$c] = $this->h->checkin($this->T, ['collected_at' => $this->h::ts(880)]);
        $this->assertSame(200, $c);
        [$c, , $r] = $this->h->checkin($this->T, ['collected_at' => $this->h::ts(960)]);
        $this->assertSame(422, $c);
        $this->assertSame('invalid', $r['code']);
        $this->assertSame('collected_at is in the future', $r['error']);
    }

    public function testRefusalCarriesServerTimeAndSkew(): void
    {
        [$c, , $r] = $this->h->checkin($this->T, ['collected_at' => $this->h::ts(3600)]);
        $this->assertSame(422, $c);
        $this->assertEqualsWithDelta(3600, $r['skew_s'], 3);
        $this->assertIsInt($r['skew_s']);
        $this->assertEqualsWithDelta(time(), strtotime($r['server_time']), 3);
        [$c, , $r] = $this->h->checkin($this->T, ['collected_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400 * 400)]);
        $this->assertSame(422, $c);
        $this->assertLessThan(-86400 * 399, $r['skew_s']);
        [$c, , $r] = $this->h->checkin($this->T, ['collected_at' => 'yesterday']);
        $this->assertSame([422, false], [$c, isset($r['skew_s'])], 'a malformed time has no skew to report');
    }

    public function testRefusalIsRecordedOnceAndClearedByAnAcceptedCheckin(): void
    {
        $this->h->checkin($this->T, ['collected_at' => $this->h::ts(1800)]);
        $a = $this->row();
        $this->assertSame('collected_at is in the future', $a['last_reject_reason']);
        $this->assertEqualsWithDelta(1800, (int) $a['clock_skew_s'], 3);
        $this->assertNull($a['last_checkin_at']);
        $this->h->mysqli->query("UPDATE endpoint_agent_devices SET last_reject_at = '2020-01-01 00:00:00' WHERE device_id = {$this->dev}");
        $this->h->checkin($this->T, ['collected_at' => $this->h::ts(1800)]);   // same reason, not recent by the stamp: rewritten
        $this->assertNotSame('2020-01-01 00:00:00', $this->row()['last_reject_at']);
        $at = $this->row()['last_reject_at'];
        $this->h->checkin($this->T, ['collected_at' => $this->h::ts(1800)]);
        $this->assertSame($at, $this->row()['last_reject_at'], 'a retry loop does not write the row each time');
        [$c] = $this->h->checkin($this->T, ['collected_at' => $this->h::ts(-30), 'clock_offset_s' => 1800]);
        $this->assertSame(200, $c);
        $b = $this->row();
        $this->assertNull($b['last_reject_reason']);
        $this->assertNull($b['last_reject_at']);
        $this->assertEqualsWithDelta(-30, (int) $b['clock_skew_s'], 3);
        $this->assertSame(1800, (int) $b['clock_offset_s']);
    }

    public function testAgentOffsetIsIgnoredWhenImplausibleNeverAnError(): void
    {
        foreach ([99999999, 'x', 1.5, -86401] as $bad) {
            [$c] = $this->h->checkin($this->T, ['clock_offset_s' => $bad]);
            $this->assertSame(200, $c);
            $this->assertNull($this->row()['clock_offset_s']);
        }
    }

    public function testEventFiresOncePerDayAndOnlyAboveTheLimit(): void
    {
        $this->h->checkin($this->T, ['collected_at' => $this->h::ts(60)]);
        $this->assertCount(0, $this->bus->of(RmmEvent::DEVICE_CLOCK_SKEW), '60 s is below the 120 s limit');
        $this->h->checkin($this->T, ['collected_at' => $this->h::ts(600)]);
        $ev = $this->bus->of(RmmEvent::DEVICE_CLOCK_SKEW);
        $this->assertCount(1, $ev);
        $this->assertSame('ahead', $ev[0]['direction'] ?? null);
        $this->h->checkin($this->T, ['collected_at' => $this->h::ts(700)]);
        $this->h->checkin($this->T, ['collected_at' => $this->h::ts(-700)]);
        $this->assertCount(1, $this->bus->of(RmmEvent::DEVICE_CLOCK_SKEW), 'no flapping inside a day');
        $this->h->mysqli->query("UPDATE endpoint_agent_devices SET clock_skew_event_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) WHERE device_id = {$this->dev}");
        $this->h->checkin($this->T, ['collected_at' => $this->h::ts(720)]);
        $this->assertCount(2, $this->bus->of(RmmEvent::DEVICE_CLOCK_SKEW));
    }

    public function testEventCanBeTurnedOffAndFiresOnTheFirstRefusal(): void
    {
        $this->h->module->settings()->set(['limits_json' => json_encode(['clock_skew_alert_s' => 0])]);
        $this->h->checkin($this->T, ['collected_at' => $this->h::ts(800)]);
        $this->assertCount(0, $this->bus->of(RmmEvent::DEVICE_CLOCK_SKEW), 'limit 0 is off');
        $this->h->module->settings()->set(['limits_json' => null]);
        $this->h->checkin($this->T, ['collected_at' => $this->h::ts(3000)]);   // refused, but the reason is still announced
        $ev = $this->bus->of(RmmEvent::DEVICE_CLOCK_SKEW);
        $this->assertCount(1, $ev);
    }

    public function testReadModelShowsTheClockOnlyOnceMeasured(): void
    {
        $d = $this->row();
        $this->assertNull(\RivetCore\Rmm\Read\RmmReadModel::clockOf($d));
        $this->h->checkin($this->T, ['collected_at' => $this->h::ts(1800)]);
        $c = \RivetCore\Rmm\Read\RmmReadModel::clockOf($this->row());
        $this->assertEqualsWithDelta(1800, $c['skew_s'], 3);
        $this->assertSame('collected_at is in the future', $c['last_reject']['reason']);
    }
}
