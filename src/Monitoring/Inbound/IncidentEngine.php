<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * The incident state machine: one incident per (source, monitor), driven by {@see InboundEvent}s.
 *
 *  - DOWN opens a ticket once {@see EngineConfig::$minConsecutiveDown} DOWN deliveries arrived or the outage lasted
 *    {@see EngineConfig::$minDownSeconds}; further DOWNs add at most one note per {@see EngineConfig::$noteIntervalSeconds}.
 *  - UP adds a recovery note with the outage duration and resolves the ticket.
 *  - PENDING and MAINTENANCE are ignored entirely (a monitor in maintenance never opens or resolves, and does not advance the monitor's clock).
 *  - A ticket a person already resolved or closed ({@see TicketGatewayInterface::isStillOpen()} false) is never reopened: the incident is
 *    closed; the next DOWN, after that, opens a NEW ticket.
 *  - Idempotent and order-safe: each monitor remembers the time of the newest delivery it acted on; an older one is STALE, an identical one
 *    (same time, same status) is a DUPLICATE; neither changes anything. A late DOWN after an UP therefore cannot reopen. Times come from the
 *    heartbeat; one claiming to be more than 5 minutes in the future is clamped to now so a bad sender clock cannot freeze a monitor.
 *  - Flapping guard: more than {@see EngineConfig::$maxOpensPerHour} ticket opens for a monitor in an hour become notes on its most recent ticket (if still open).
 *  - Unmapped monitors go to the default target or "unassigned"; the client is never inferred from the monitor name.
 *
 * Gateway/store exceptions propagate with the incident unchanged, so the sender's retry (or the next DOWN) repeats the step.
 *
 * @api
 */
final class IncidentEngine
{
    public const FLAP_WINDOW_S = 3600;
    private const FUTURE_SLACK_S = 300;

    /** @var \Closure():int */
    private \Closure $clock;

    /** @param (\Closure():int)|null $clock Unix time; null = time() */
    public function __construct(
        private readonly IncidentStoreInterface $store,
        private readonly TicketGatewayInterface $gateway,
        private readonly MonitorClientMapInterface $map,
        private readonly EngineConfig $config = new EngineConfig(),
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function process(InboundEvent $event): EngineOutcome
    {
        if ($event->isTest || $event->status === null) {
            return new EngineOutcome(EngineOutcome::TEST);
        }
        if ($event->status === MonitorStatus::Pending) {
            return new EngineOutcome(EngineOutcome::IGNORED_PENDING);
        }
        if ($event->status === MonitorStatus::Maintenance) {
            return new EngineOutcome(EngineOutcome::IGNORED_MAINTENANCE);
        }

        return $this->store->locked($event->source, $event->monitorKey, fn (): EngineOutcome => $this->apply($event));
    }

    private function apply(InboundEvent $e): EngineOutcome
    {
        $status = $e->status;
        if ($status === null) {
            return new EngineOutcome(EngineOutcome::TEST);
        }
        $now = ($this->clock)();
        $ts = $e->time ?? $now;
        if ($ts > $now + self::FUTURE_SLACK_S) {
            $ts = $now;
        }
        $inc = $this->store->get($e->source, $e->monitorKey) ?? new Incident($e->source, $e->monitorKey);

        if ($inc->lastEventAt !== null) {
            if ($ts < $inc->lastEventAt) {
                return new EngineOutcome(EngineOutcome::STALE, $inc->ticketId);
            }
            if ($ts === $inc->lastEventAt && $inc->lastStatus === $status->value) {
                return new EngineOutcome(EngineOutcome::DUPLICATE, $inc->ticketId);
            }
        }

        $inc->opens = array_values(array_filter($inc->opens, static fn (int $t): bool => $t > $ts - self::FLAP_WINDOW_S));
        $inc->monitorName = $e->monitorName;

        $outcome = $status === MonitorStatus::Down ? $this->down($e, $inc, $ts) : $this->up($e, $inc, $ts);
        $inc->lastEventAt = $ts;
        $inc->lastStatus = $status->value;
        $this->store->put($inc);

        return $outcome;
    }

    private function down(InboundEvent $e, Incident $inc, int $ts): EngineOutcome
    {
        if ($inc->state === Incident::OPEN && $inc->ticketId !== null) {
            if (!$this->gateway->isStillOpen($inc->ticketId)) {
                $this->close($inc);

                return new EngineOutcome(EngineOutcome::CLOSED_BY_HUMAN);
            }
            if ($ts - (int) $inc->lastNoteAt >= $this->config->noteIntervalSeconds) {
                $this->gateway->addNote($inc->ticketId, 'Still down at ' . self::utc($ts) . ' UTC. ' . $this->detail($e));
                $inc->lastNoteAt = $ts;

                return new EngineOutcome(EngineOutcome::NOTED, $inc->ticketId);
            }

            return new EngineOutcome(EngineOutcome::UNCHANGED, $inc->ticketId);
        }

        if ($inc->state !== Incident::PENDING) {
            $inc->state = Incident::PENDING;
            $inc->consecutiveDown = 0;
            $inc->downSince = $ts;
        }
        $inc->consecutiveDown++;
        $down = $inc->downSince ?? $ts;

        $due = $inc->consecutiveDown >= $this->config->minConsecutiveDown
            || ($this->config->minDownSeconds > 0 && $ts - $down >= $this->config->minDownSeconds);
        if (!$due) {
            return new EngineOutcome(EngineOutcome::WAITING);
        }

        $max = $this->config->maxOpensPerHour;
        if ($max > 0 && count($inc->opens) >= $max) {
            // Flapping: notes only, on the latest ticket while it is still open.
            $last = $inc->lastTicketId;
            if ($last !== null && $this->gateway->isStillOpen($last) && $ts - (int) $inc->lastNoteAt >= $this->config->noteIntervalSeconds) {
                $this->gateway->addNote($last, 'Down again at ' . self::utc($ts) . ' UTC (flapping: no new ticket). ' . $this->detail($e));
                $inc->lastNoteAt = $ts;
            }

            return new EngineOutcome(EngineOutcome::FLAP_SUPPRESSED, $last);
        }

        $target = $this->map->resolve($e) ?? $this->config->defaultTarget ?? MonitorTarget::unassigned();
        $ticket = $this->gateway->openTicket(new TicketRequest(
            $target,
            self::subject($e),
            $this->body($e, $down),
            $this->config->priority,
            $this->config->category,
            $this->config->sourceLabel,
            $e->source . ':' . $e->monitorKey,
        ));
        $inc->state = Incident::OPEN;
        $inc->ticketId = $ticket;
        $inc->lastTicketId = $ticket;
        $inc->lastNoteAt = $ts;
        $inc->opens[] = $ts;
        $inc->clientId = $target->clientId;
        $inc->assetId = $target->assetId;

        return new EngineOutcome(EngineOutcome::OPENED, $ticket);
    }

    private function up(InboundEvent $e, Incident $inc, int $ts): EngineOutcome
    {
        $ticket = $inc->ticketId;
        if ($inc->state === Incident::OPEN && $ticket !== null) {
            if (!$this->gateway->isStillOpen($ticket)) {
                $this->close($inc);

                return new EngineOutcome(EngineOutcome::CLOSED_BY_HUMAN);
            }
            $duration = self::duration(max(0, $ts - ($inc->downSince ?? $ts)));
            $this->gateway->resolveTicket($ticket, 'Recovered at ' . self::utc($ts) . ' UTC after ' . $duration . '. Resolved automatically by monitoring.');
            $this->close($inc);

            return new EngineOutcome(EngineOutcome::RESOLVED, $ticket);
        }
        $wasPending = $inc->state === Incident::PENDING;
        $this->close($inc);

        return new EngineOutcome($wasPending ? EngineOutcome::WAITING : EngineOutcome::UNCHANGED);
    }

    private function close(Incident $inc): void
    {
        $inc->state = Incident::HEALTHY;
        $inc->ticketId = null;
        $inc->consecutiveDown = 0;
        $inc->downSince = null;
    }

    private static function subject(InboundEvent $e): string
    {
        return UptimeKumaPayload::clean('Monitor DOWN: ' . $e->monitorName, 200);
    }

    private function body(InboundEvent $e, int $down): string
    {
        $lines = ['Monitor: ' . $e->monitorName, 'Down since: ' . self::utc($down) . ' UTC'];
        foreach (['URL' => $e->url, 'Host' => $e->hostname, 'Type' => $e->type, 'Message' => $e->message] as $k => $v) {
            if ($v !== '') {
                $lines[] = $k . ': ' . $v;
            }
        }
        if ($e->tags !== []) {
            $lines[] = 'Tags: ' . implode(', ', $e->tags);
        }
        $lines[] = 'Reported by ' . $this->config->sourceLabel . '. The ticket resolves itself when the monitor recovers.';

        return implode("\n", $lines);
    }

    private function detail(InboundEvent $e): string
    {
        return $e->message !== '' ? 'Message: ' . $e->message : '';
    }

    private static function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function duration(int $s): string
    {
        if ($s < 60) {
            return $s . ' second' . ($s === 1 ? '' : 's');
        }
        $parts = [];
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);
        if ($d > 0) {
            $parts[] = $d . ' day' . ($d === 1 ? '' : 's');
        }
        if ($h > 0) {
            $parts[] = $h . ' hour' . ($h === 1 ? '' : 's');
        }
        if ($m > 0 && $d === 0) {
            $parts[] = $m . ' minute' . ($m === 1 ? '' : 's');
        }

        return implode(' ', $parts);
    }
}
