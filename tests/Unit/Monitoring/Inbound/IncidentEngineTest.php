<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Monitoring\Inbound;

use PHPUnit\Framework\TestCase;
use RivetCore\Monitoring\Inbound\EngineConfig;
use RivetCore\Monitoring\Inbound\EngineOutcome as O;
use RivetCore\Monitoring\Inbound\Incident;
use RivetCore\Monitoring\Inbound\IncidentEngine;
use RivetCore\Monitoring\Inbound\InboundEvent;
use RivetCore\Monitoring\Inbound\MonitorStatus;
use RivetCore\Monitoring\Inbound\MonitorTarget;
use RivetCore\Monitoring\Inbound\UptimeKumaPayload;
use RivetCore\Testing\InMemoryIncidentStore;
use RivetCore\Testing\InMemoryMonitorClientMap;
use RivetCore\Testing\InMemoryTicketGateway;

final class IncidentEngineTest extends TestCase
{
    private const T0 = 1_800_000_000;

    private InMemoryIncidentStore $store;
    private InMemoryTicketGateway $gw;
    private InMemoryMonitorClientMap $map;
    private int $now = self::T0 + 100_000;

    protected function setUp(): void
    {
        $this->store = new InMemoryIncidentStore();
        $this->gw = new InMemoryTicketGateway();
        $this->map = (new InMemoryMonitorClientMap())->mapMonitor('7', MonitorTarget::client(3, 11));
    }

    private function engine(?EngineConfig $c = null): IncidentEngine
    {
        return new IncidentEngine($this->store, $this->gw, $this->map, $c ?? new EngineConfig(), fn (): int => $this->now);
    }

    /** @param list<string> $tags */
    private function ev(MonitorStatus $s, int $offset, string $monitor = '7', array $tags = [], string $msg = 'timeout'): InboundEvent
    {
        return new InboundEvent(UptimeKumaPayload::SOURCE, false, $s, $monitor, 'Web ' . $monitor, self::T0 + $offset, $msg, 'https://example.com', '', 'http', $tags);
    }

    public function testDownOpensAndUpResolvesWithDuration(): void
    {
        $e = $this->engine();
        $o = $e->process($this->ev(MonitorStatus::Down, 0));
        self::assertSame(O::OPENED, $o->action);
        $id = $o->ticketId;
        self::assertNotNull($id);
        self::assertTrue($this->gw->isStillOpen($id));
        $req = $this->gw->tickets[$id]['request'];
        self::assertSame(3, $req->target->clientId);
        self::assertSame(11, $req->target->assetId);
        self::assertSame('uptime-kuma:7', $req->incidentKey);
        self::assertSame('High', $req->priority);
        self::assertStringContainsString('Web 7', $req->subject);
        self::assertStringContainsString('timeout', $req->body);

        $o = $e->process($this->ev(MonitorStatus::Up, 3725));
        self::assertSame(O::RESOLVED, $o->action);
        self::assertFalse($this->gw->isStillOpen($id));
        self::assertStringContainsString('1 hour 2 minutes', $this->gw->tickets[$id]['notes'][0]);
        self::assertSame(Incident::HEALTHY, $this->store->get('uptime-kuma', '7')?->state);
    }

    public function testDuplicateDeliveriesAreIdempotent(): void
    {
        $e = $this->engine();
        $e->process($this->ev(MonitorStatus::Down, 0));
        self::assertSame(O::DUPLICATE, $e->process($this->ev(MonitorStatus::Down, 0))->action);
        self::assertCount(1, $this->gw->tickets);
        $e->process($this->ev(MonitorStatus::Up, 60));
        self::assertSame(O::DUPLICATE, $e->process($this->ev(MonitorStatus::Up, 60))->action);
        self::assertCount(1, $this->gw->tickets[500]['notes']);
    }

    public function testLateStaleDownAfterUpDoesNotReopen(): void
    {
        $e = $this->engine();
        $e->process($this->ev(MonitorStatus::Down, 0));
        $e->process($this->ev(MonitorStatus::Up, 120));
        $o = $e->process($this->ev(MonitorStatus::Down, 60));
        self::assertSame(O::STALE, $o->action);
        self::assertCount(1, $this->gw->tickets);
        self::assertFalse($this->gw->isStillOpen(500));
    }

    public function testUpArrivingBeforeItsDownLeavesNothingOpen(): void
    {
        $e = $this->engine();
        self::assertSame(O::UNCHANGED, $e->process($this->ev(MonitorStatus::Up, 120))->action);
        self::assertSame(O::STALE, $e->process($this->ev(MonitorStatus::Down, 60))->action);
        self::assertSame([], $this->gw->tickets);
    }

    public function testStaleBeforeAnyEventOnANewerDownStillStale(): void
    {
        $e = $this->engine();
        $e->process($this->ev(MonitorStatus::Down, 100));
        self::assertSame(O::STALE, $e->process($this->ev(MonitorStatus::Down, 50))->action);
        self::assertSame(O::STALE, $e->process($this->ev(MonitorStatus::Up, 50))->action);
        self::assertTrue($this->gw->isStillOpen(500));
    }

    public function testRepeatedDownsNoteAtMostOncePerInterval(): void
    {
        $e = $this->engine(new EngineConfig(noteIntervalSeconds: 1800));
        $e->process($this->ev(MonitorStatus::Down, 0));
        self::assertSame(O::UNCHANGED, $e->process($this->ev(MonitorStatus::Down, 600))->action);
        self::assertSame(O::UNCHANGED, $e->process($this->ev(MonitorStatus::Down, 1799))->action);
        self::assertSame(O::NOTED, $e->process($this->ev(MonitorStatus::Down, 1800))->action);
        self::assertSame(O::UNCHANGED, $e->process($this->ev(MonitorStatus::Down, 2400))->action);
        self::assertSame(O::NOTED, $e->process($this->ev(MonitorStatus::Down, 3600))->action);
        self::assertCount(2, $this->gw->tickets[500]['notes']);
        self::assertCount(1, $this->gw->tickets);
    }

    public function testConsecutiveDownThresholdBeforeOpening(): void
    {
        $e = $this->engine(new EngineConfig(minConsecutiveDown: 3));
        self::assertSame(O::WAITING, $e->process($this->ev(MonitorStatus::Down, 0))->action);
        self::assertSame(O::WAITING, $e->process($this->ev(MonitorStatus::Down, 60))->action);
        self::assertSame([], $this->gw->tickets);
        self::assertSame(O::OPENED, $e->process($this->ev(MonitorStatus::Down, 120))->action);
        $body = $this->gw->tickets[500]['request']->body;
        self::assertStringContainsString(gmdate('Y-m-d H:i:s', self::T0), $body);   // "down since" is the first DOWN, not the opening one
    }

    public function testUpBeforeThresholdResetsTheCount(): void
    {
        $e = $this->engine(new EngineConfig(minConsecutiveDown: 3));
        $e->process($this->ev(MonitorStatus::Down, 0));
        $e->process($this->ev(MonitorStatus::Down, 60));
        self::assertSame(O::WAITING, $e->process($this->ev(MonitorStatus::Up, 90))->action);
        self::assertSame(O::WAITING, $e->process($this->ev(MonitorStatus::Down, 120))->action);
        self::assertSame(O::WAITING, $e->process($this->ev(MonitorStatus::Down, 180))->action);
        self::assertSame([], $this->gw->tickets);
    }

    public function testMinimumDurationOpensEvenBelowTheCount(): void
    {
        $e = $this->engine(new EngineConfig(minConsecutiveDown: 10, minDownSeconds: 300));
        self::assertSame(O::WAITING, $e->process($this->ev(MonitorStatus::Down, 0))->action);
        self::assertSame(O::WAITING, $e->process($this->ev(MonitorStatus::Down, 299))->action);
        self::assertSame(O::OPENED, $e->process($this->ev(MonitorStatus::Down, 300))->action);
    }

    public function testPendingAndMaintenanceAreIgnoredAndNeverResolve(): void
    {
        $e = $this->engine();
        self::assertSame(O::IGNORED_PENDING, $e->process($this->ev(MonitorStatus::Pending, 0))->action);
        self::assertSame(O::IGNORED_MAINTENANCE, $e->process($this->ev(MonitorStatus::Maintenance, 5))->action);
        self::assertSame([], $this->gw->tickets);
        self::assertNull($this->store->get('uptime-kuma', '7'));

        $e->process($this->ev(MonitorStatus::Down, 10));
        self::assertSame(O::IGNORED_MAINTENANCE, $e->process($this->ev(MonitorStatus::Maintenance, 20))->action);
        self::assertSame(O::IGNORED_PENDING, $e->process($this->ev(MonitorStatus::Pending, 30))->action);
        self::assertTrue($this->gw->isStillOpen(500));
        // maintenance did not advance the monitor's clock: an UP before it (by time) is still accepted
        self::assertSame(O::RESOLVED, $e->process($this->ev(MonitorStatus::Up, 15))->action);
    }

    public function testTestEventTouchesNothing(): void
    {
        $o = $this->engine()->process(new InboundEvent('uptime-kuma', true, null, '', '', null));
        self::assertSame(O::TEST, $o->action);
        self::assertSame(0, $this->store->puts);
    }

    public function testATicketAPersonResolvedIsNeverReopenedAndALaterDownOpensANewOne(): void
    {
        $e = $this->engine();
        $e->process($this->ev(MonitorStatus::Down, 0));
        $this->gw->closeByPerson(500);

        $o = $e->process($this->ev(MonitorStatus::Down, 4000));
        self::assertSame(O::CLOSED_BY_HUMAN, $o->action);
        self::assertTrue(!$this->gw->isStillOpen(500));
        self::assertSame([], $this->gw->tickets[500]['notes']);
        self::assertCount(1, $this->gw->tickets);
        self::assertSame(Incident::HEALTHY, $this->store->get('uptime-kuma', '7')?->state);

        $o = $e->process($this->ev(MonitorStatus::Down, 4100));
        self::assertSame(O::OPENED, $o->action);
        self::assertSame(501, $o->ticketId);
        self::assertFalse($this->gw->isStillOpen(500));
    }

    public function testUpForAPersonResolvedTicketAddsNothing(): void
    {
        $e = $this->engine();
        $e->process($this->ev(MonitorStatus::Down, 0));
        $this->gw->closeByPerson(500);
        self::assertSame(O::CLOSED_BY_HUMAN, $e->process($this->ev(MonitorStatus::Up, 100))->action);
        self::assertSame([], $this->gw->tickets[500]['notes']);
        self::assertSame('person', $this->gw->tickets[500]['resolution']);
    }

    public function testFlapGuardTurnsExtraOpensIntoNotes(): void
    {
        $e = $this->engine(new EngineConfig(maxOpensPerHour: 3));
        $t = 0;
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(O::OPENED, $e->process($this->ev(MonitorStatus::Down, $t += 60))->action);
            self::assertSame(O::RESOLVED, $e->process($this->ev(MonitorStatus::Up, $t += 60))->action);
        }
        $o = $e->process($this->ev(MonitorStatus::Down, $t += 60));
        self::assertSame(O::FLAP_SUPPRESSED, $o->action);
        self::assertCount(3, $this->gw->tickets);
        self::assertSame(O::WAITING, $e->process($this->ev(MonitorStatus::Up, $t += 60))->action);

        // an hour after the first open the window has moved on and a ticket opens again
        self::assertSame(O::OPENED, $e->process($this->ev(MonitorStatus::Down, $t += 3600))->action);
        self::assertCount(4, $this->gw->tickets);
    }

    public function testFlapGuardNotesTheLatestTicketWhileItIsOpen(): void
    {
        $e = $this->engine(new EngineConfig(maxOpensPerHour: 1, noteIntervalSeconds: 0));
        $e->process($this->ev(MonitorStatus::Down, 0));
        $this->gw->tickets[500]['open'] = true;
        $inc = $this->store->get('uptime-kuma', '7');
        self::assertNotNull($inc);
        $inc->state = Incident::PENDING;   // as if the incident closed without resolving the ticket
        $inc->ticketId = null;
        $this->store->put($inc);
        self::assertSame(O::FLAP_SUPPRESSED, $e->process($this->ev(MonitorStatus::Down, 60))->action);
        self::assertStringContainsString('flapping', $this->gw->tickets[500]['notes'][0]);
        self::assertCount(1, $this->gw->tickets);
    }

    public function testUnmappedMonitorGoesToTheDefaultClient(): void
    {
        $e = $this->engine(new EngineConfig(defaultTarget: MonitorTarget::client(99)));
        $o = $e->process($this->ev(MonitorStatus::Down, 0, '8'));
        self::assertSame(99, $this->gw->clientOf((int) $o->ticketId));
    }

    public function testUnmappedMonitorWithoutDefaultIsUnassignedNeverGuessed(): void
    {
        // the name says Acme, the url says another client: neither is used
        $ev = new InboundEvent('uptime-kuma', false, MonitorStatus::Down, '8', 'ACME Corp router', self::T0, 'x', 'https://client-3.example.com', '', 'http', ['client-3']);
        $o = $this->engine()->process($ev);
        self::assertNull($this->gw->clientOf((int) $o->ticketId));
        self::assertTrue($this->gw->tickets[(int) $o->ticketId]['request']->target->isUnassigned());
    }

    public function testTagMappingAndAmbiguityNeverLeaksToAnotherClient(): void
    {
        $this->map->mapTag('client:acme', MonitorTarget::client(5))->mapTag('client:globex', MonitorTarget::client(6));
        $e = $this->engine(new EngineConfig(defaultTarget: MonitorTarget::client(99)));

        $o = $e->process($this->ev(MonitorStatus::Down, 0, '20', ['client:acme']));
        self::assertSame(5, $this->gw->clientOf((int) $o->ticketId));

        // two tags pointing at two clients: ambiguous, so it is treated as unmapped (default), never one of the two
        $o = $e->process($this->ev(MonitorStatus::Down, 0, '21', ['client:acme', 'client:globex']));
        self::assertSame(99, $this->gw->clientOf((int) $o->ticketId));

        // an id entry for client 3 and a tag for client 5: disagree
        $o = $e->process($this->ev(MonitorStatus::Down, 0, '7', ['client:acme']));
        self::assertSame(99, $this->gw->clientOf((int) $o->ticketId));
    }

    public function testTheTicketKeepsItsClientWhenTheMapChangesMidIncident(): void
    {
        $e = $this->engine();
        $e->process($this->ev(MonitorStatus::Down, 0));
        $this->map->mapMonitor('7', MonitorTarget::client(77));
        $e->process($this->ev(MonitorStatus::Down, 4000));
        $e->process($this->ev(MonitorStatus::Up, 5000));
        self::assertSame(3, $this->gw->clientOf(500));
        self::assertCount(1, $this->gw->tickets);
    }

    public function testMonitorsAreIndependent(): void
    {
        $e = $this->engine();
        $a = $e->process($this->ev(MonitorStatus::Down, 0, '7'))->ticketId;
        $b = $e->process($this->ev(MonitorStatus::Down, 0, '8'))->ticketId;
        self::assertNotSame($a, $b);
        $e->process($this->ev(MonitorStatus::Up, 10, '7'));
        self::assertFalse($this->gw->isStillOpen((int) $a));
        self::assertTrue($this->gw->isStillOpen((int) $b));
    }

    public function testAFailingGatewayLeavesStateUnchangedSoTheRetryWorks(): void
    {
        $e = $this->engine();
        $this->gw->beforeCall = static function (): void {
            throw new \RuntimeException('ticket system down');
        };
        try {
            $e->process($this->ev(MonitorStatus::Down, 0));
            self::fail('expected the exception');
        } catch (\RuntimeException) {
        }
        self::assertNull($this->store->get('uptime-kuma', '7'));
        $this->gw->beforeCall = null;
        self::assertSame(O::OPENED, $e->process($this->ev(MonitorStatus::Down, 0))->action);
    }

    public function testAFutureTimestampIsClampedSoItCannotFreezeTheMonitor(): void
    {
        $e = $this->engine();
        $e->process($this->ev(MonitorStatus::Down, 10_000_000));   // far future
        self::assertSame($this->now, $this->store->get('uptime-kuma', '7')?->lastEventAt);
        self::assertSame(O::RESOLVED, $e->process($this->ev(MonitorStatus::Up, 100_050))->action);
    }

    public function testMissingTimeUsesArrivalTime(): void
    {
        $ev = new InboundEvent('uptime-kuma', false, MonitorStatus::Down, '7', 'Web', null);
        $this->engine()->process($ev);
        self::assertSame($this->now, $this->store->get('uptime-kuma', '7')?->lastEventAt);
    }

    public function testConfigValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EngineConfig(minConsecutiveDown: 0);
    }

    public function testIncidentArrayRoundTripToleratesGarbage(): void
    {
        $i = Incident::fromArray(['source' => 's', 'monitorKey' => 'k', 'state' => 'weird', 'opens' => [1, 'x', '3'], 'ticketId' => 'abc']);
        self::assertSame(Incident::HEALTHY, $i->state);
        self::assertSame([1, 3], $i->opens);
        self::assertNull($i->ticketId);
    }
}
