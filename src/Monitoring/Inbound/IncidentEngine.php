<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * The incident state machine: one incident per (source, sender, monitor), driven by {@see InboundEvent}s.
 *
 *  - DOWN opens a ticket once {@see EngineConfig::$minConsecutiveDown} DOWN deliveries arrived or the outage lasted
 *    {@see EngineConfig::$minDownSeconds}; further DOWNs add at most one note per {@see EngineConfig::$noteIntervalSeconds}.
 *  - UP adds a recovery note with the outage duration and resolves the ticket.
 *  - PENDING and MAINTENANCE are ignored entirely (a monitor in maintenance never opens or resolves, and does not advance the monitor's clock).
 *  - A ticket a person already resolved or closed ({@see TicketGatewayInterface::isStillOpen()} false) is never reopened: the incident is
 *    closed; the next DOWN, after that, opens a NEW ticket.
 *  - Idempotent and order-safe: each monitor remembers the time of the newest delivery it acted on; an older one is STALE, an identical one
 *    (same time, same status) is a DUPLICATE; neither changes anything. A late DOWN after an UP therefore cannot reopen. Times come from the
 *    heartbeat, but one in the future is replaced by the arrival time (no slack, red team 2026-11-21 NEW-1): a delivery can make another one stale
 *    only when that one's heartbeat is older than the first one's arrival, i.e. for the delivery delay (plus however far the real sender's clock lags).
 *  - Flapping guard: more than {@see EngineConfig::$maxOpensPerHour} ticket opens for a monitor in an hour become notes on its most recent ticket (if still open).
 *  - Ticket cap (CORE-API-5): at most {@see EngineConfig::$maxOpensPerHourPerClient} opens per client and {@see EngineConfig::$maxOpensPerHourAll} in all per
 *    hour. Past a cap a DOWN opens no ticket of its own: the first one opens ONE summary ticket (for that client, or unassigned for the overall cap), the
 *    next ones are listed on it as notes (at most {@see self::SUMMARY_NOTES_MAX} per hour, the rest are counted). Nothing is dropped silently; the monitor
 *    stays pending, so its next DOWN after the hour opens its own ticket. The counters are incident rows of the pseudo-source {@see self::CAP_SOURCE}.
 *  - Senders ({@see InboundSender}): in single-secret mode every delivery is the shared sender and the keys are (source, monitor id) exactly as before. A
 *    named sender's incidents are keyed (source, "<sender>/<monitor id>"), so it cannot resolve, silence or flap another sender's monitors; one limited to some
 *    clients is refused (OUT_OF_SCOPE) a monitor the map sends to another client.
 *  - Unmapped monitors go to the default target or "unassigned"; the client is never inferred from the monitor name.
 *  - Ticket text (subject, body and every note) is plain text without markup: anything tag-shaped is removed and a stray '<' or '>' becomes '‹' or '›'.
 *
 * Gateway/store exceptions propagate with the incident unchanged, so the sender's retry (or the next DOWN) repeats the step.
 *
 * @api
 */
final class IncidentEngine
{
    public const FLAP_WINDOW_S = 3600;
    /** The source of the cap counter rows in the incident store ('_' is never part of a parser's source id, so they cannot collide with a monitor). */
    public const CAP_SOURCE = '_cap';
    /** Held-back DOWNs listed on a summary ticket per hour; past it they are only counted. */
    public const SUMMARY_NOTES_MAX = 50;
    private const CAP_WINDOW_S = 3600;
    private const ALL = 'all';

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

    /** @param InboundSender|null $sender who delivered it ({@see InboundAuthenticator::identify()}); null = the shared sender of single-secret mode */
    public function process(InboundEvent $event, ?InboundSender $sender = null): EngineOutcome
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
        $sender ??= InboundSender::shared();
        $event = $event->withSender($sender->id);
        $key = $sender->incidentKey($event->monitorKey);

        return $this->store->locked($event->source, $key, fn (): EngineOutcome => $this->apply($event, $sender, $key));
    }

    private function apply(InboundEvent $e, InboundSender $sender, string $key): EngineOutcome
    {
        $status = $e->status;
        if ($status === null) {
            return new EngineOutcome(EngineOutcome::TEST);
        }
        $now = ($this->clock)();
        $ts = min($e->time ?? $now, $now);   // NEW-1: a heartbeat time in the future is the arrival time, never later
        $inc = $this->store->get($e->source, $key) ?? new Incident($e->source, $key);

        if ($inc->lastEventAt !== null) {
            if ($ts < $inc->lastEventAt) {
                return new EngineOutcome(EngineOutcome::STALE);
            }
            if ($ts === $inc->lastEventAt && $inc->lastStatus === $status->value) {
                return new EngineOutcome(EngineOutcome::DUPLICATE);
            }
        }

        $inc->opens = array_values(array_filter($inc->opens, static fn (int $t): bool => $t > $ts - self::FLAP_WINDOW_S));
        $inc->monitorName = $e->monitorName;

        $outcome = $status === MonitorStatus::Down ? $this->down($e, $sender, $inc, $ts, $now) : $this->up($e, $inc, $ts);
        $inc->lastEventAt = $ts;
        $inc->lastStatus = $status->value;
        $this->store->put($inc);

        return $outcome;
    }

    private function down(InboundEvent $e, InboundSender $sender, Incident $inc, int $ts, int $now): EngineOutcome
    {
        if ($inc->state === Incident::OPEN && $inc->ticketId !== null) {
            if (!$this->gateway->isStillOpen($inc->ticketId)) {
                $this->close($inc);

                return new EngineOutcome(EngineOutcome::CLOSED_BY_HUMAN);
            }
            if ($ts - (int) $inc->lastNoteAt >= $this->config->noteIntervalSeconds) {
                $this->gateway->addNote($inc->ticketId, self::text('Still down at ' . self::utc($ts) . ' UTC. ' . $this->detail($e)));
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
                $this->gateway->addNote($last, self::text('Down again at ' . self::utc($ts) . ' UTC (flapping: no new ticket). ' . $this->detail($e)));
                $inc->lastNoteAt = $ts;
            }

            return new EngineOutcome(EngineOutcome::FLAP_SUPPRESSED, $last);
        }

        $target = $this->target($e, $sender);
        if ($target === null) {
            return new EngineOutcome(EngineOutcome::OUT_OF_SCOPE);
        }
        $over = $this->reserveOpen($target, $now);
        if ($over !== null) {
            $this->holdBack($e, $over, $ts, $now);

            return new EngineOutcome(EngineOutcome::CAPPED);
        }
        $ticket = $this->gateway->openTicket(new TicketRequest(
            $target,
            self::text(self::subject($e)),
            self::text($this->body($e, $down)),
            $this->config->priority,
            $this->config->category,
            $this->config->sourceLabel,
            $e->source . ':' . $inc->monitorKey,
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
            $this->gateway->resolveTicket($ticket, self::text('Recovered at ' . self::utc($ts) . ' UTC after ' . $duration . '. Resolved automatically by monitoring.'));
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

    /**
     * Where the ticket goes, or null when the sender may not report for that client. The shared sender and a named sender with no client list: the map, else
     * the default target, else unassigned (as before). A sender limited to some clients: a mapped client must be one of them; an unmapped monitor goes to its
     * only client, else to the default target when that is one of its clients, else unassigned.
     */
    private function target(InboundEvent $e, InboundSender $sender): ?MonitorTarget
    {
        $mapped = $this->map->resolve($e);
        if ($sender->clientIds === null) {
            return $mapped ?? $this->config->defaultTarget ?? MonitorTarget::unassigned();
        }
        if ($mapped !== null) {
            return $mapped->clientId === null || $sender->mayReportFor($mapped->clientId) ? $mapped : null;
        }
        if (count($sender->clientIds) === 1) {
            return MonitorTarget::client($sender->clientIds[0]);
        }
        $d = $this->config->defaultTarget;

        return $d !== null && ($d->clientId === null || $sender->mayReportFor($d->clientId)) ? $d : MonitorTarget::unassigned();
    }

    // ------------------------------------------------------------------ CORE-API-5: ticket cap

    /**
     * Count one ticket open against the client's and the overall budget of the current hour, or name the first budget that is already spent (nothing is
     * counted then). Short critical sections only (get and put of the counter rows, client first, then overall, always in that order); the ticket itself is
     * opened after the locks are released, so a failed open still counts once (the caps are generous on purpose).
     */
    private function reserveOpen(MonitorTarget $target, int $now): ?string
    {
        $buckets = [];
        if ($this->config->maxOpensPerHourPerClient > 0) {
            $buckets[self::clientBucket($target)] = $this->config->maxOpensPerHourPerClient;
        }
        if ($this->config->maxOpensPerHourAll > 0) {
            $buckets[self::ALL] = $this->config->maxOpensPerHourAll;
        }
        if ($buckets === []) {
            return null;
        }

        return $this->lockedBuckets(array_keys($buckets), function () use ($buckets, $now): ?string {
            $rows = [];
            foreach ($buckets as $k => $max) {
                $row = $this->bucket($k, $now);
                if ($row->consecutiveDown >= $max) {
                    return $k;
                }
                $rows[] = $row;
            }
            foreach ($rows as $row) {
                $row->consecutiveDown++;
                $row->lastEventAt = $now;
                $this->store->put($row);
            }

            return null;
        });
    }

    /**
     * A DOWN past a cap: the first one opens the summary ticket of that budget (reused while nobody closed it), the next ones become a note on it (at most
     * {@see self::SUMMARY_NOTES_MAX} per hour), the rest are counted on the counter row. Never silent.
     */
    private function holdBack(InboundEvent $e, string $bucket, int $ts, int $now): void
    {
        $line = 'Monitor DOWN: ' . $e->monitorName . ' (' . $e->source . ':' . ($e->sender !== '' ? $e->sender . '/' : '') . $e->monitorKey . ') at ' . self::utc($ts) . ' UTC.'
            . ($e->message !== '' ? ' Message: ' . $e->message : '');
        /** @var array{0:?int,1:bool} $r */
        $r = $this->store->locked(self::CAP_SOURCE, $bucket, function () use ($bucket, $now, $line): array {
            $row = $this->bucket($bucket, $now);
            $held = (int) ($row->opens[0] ?? 0) + 1;
            $notes = (int) ($row->opens[1] ?? 0);
            if ($row->ticketId === null || !$this->gateway->isStillOpen($row->ticketId)) {
                $who = $bucket === self::ALL ? 'all clients together' : ($bucket === 'unassigned' ? 'unassigned monitors' : 'this client');
                $max = $bucket === self::ALL ? $this->config->maxOpensPerHourAll : $this->config->maxOpensPerHourPerClient;
                $id = $this->gateway->openTicket(new TicketRequest(
                    self::bucketTarget($bucket),
                    self::text('Monitoring: ticket limit reached (' . $who . ')'),
                    self::text("More than $max monitoring tickets were opened for $who within an hour. To keep an alert storm (or a misused webhook secret) from "
                        . 'filling the queue, further DOWN alerts open no ticket of their own until the hour has passed: up to ' . self::SUMMARY_NOTES_MAX
                        . ' an hour are listed on this ticket as notes, the rest are counted. Check the monitoring tool for the full picture. Each monitor stays '
                        . "pending, so its next DOWN after the hour opens its own ticket.\nFirst held back: " . $line),
                    $this->config->priority,
                    $this->config->category,
                    $this->config->sourceLabel,
                    self::CAP_SOURCE . ':' . $bucket,
                ));
                $row->ticketId = $id;
                $row->lastTicketId = $id;
                $row->state = Incident::OPEN;
                $row->opens = [$held, $notes];
                $this->store->put($row);

                return [null, false];
            }
            $noteOn = $notes < self::SUMMARY_NOTES_MAX ? $row->ticketId : null;
            $row->opens = [$held, $notes + ($noteOn !== null ? 1 : 0)];
            $row->lastNoteAt = $now;
            $this->store->put($row);

            return [$noteOn, $noteOn !== null && $notes + 1 === self::SUMMARY_NOTES_MAX];
        });
        [$noteOn, $lastNote] = $r;
        if ($noteOn !== null) {
            $this->gateway->addNote($noteOn, self::text('Held back (ticket limit): ' . $line
                . ($lastNote ? ' Note limit for this hour reached: further held-back alerts are only counted.' : '')));
        }
    }

    /** The counter row of one budget, rolled over to a new window when the hour has passed (the summary ticket is kept while it is open). */
    private function bucket(string $bucket, int $now): Incident
    {
        $row = $this->store->get(self::CAP_SOURCE, $bucket) ?? new Incident(self::CAP_SOURCE, $bucket);
        if ($row->downSince === null || $now - $row->downSince >= self::CAP_WINDOW_S || $now < $row->downSince) {
            $row->downSince = $now;          // window start
            $row->consecutiveDown = 0;       // ticket opens counted in the window
            $row->opens = [0, 0];            // [held back, notes on the summary ticket] in the window
        }
        $row->monitorName = $bucket === self::ALL ? 'Ticket limit: all clients' : 'Ticket limit: ' . $bucket;
        $row->clientId = str_starts_with($bucket, 'client:') ? (int) substr($bucket, 7) : null;

        return $row;
    }

    /**
     * @param list<string> $keys locked in this order (client before overall, the same for every delivery, so two deliveries cannot deadlock)
     * @param \Closure():(?string) $fn
     */
    private function lockedBuckets(array $keys, \Closure $fn): ?string
    {
        if ($keys === []) {
            return $fn();
        }
        $k = array_shift($keys);

        return $this->store->locked(self::CAP_SOURCE, $k, fn (): ?string => $this->lockedBuckets($keys, $fn));
    }

    private static function clientBucket(MonitorTarget $t): string
    {
        return $t->clientId === null ? 'unassigned' : 'client:' . $t->clientId;
    }

    private static function bucketTarget(string $bucket): MonitorTarget
    {
        return str_starts_with($bucket, 'client:') ? MonitorTarget::client((int) substr($bucket, 7)) : MonitorTarget::unassigned();
    }

    // ------------------------------------------------------------------ text

    /**
     * Chain A: the text handed to the edition's ticket system carries no markup, whatever the sender put in a name, URL, tag or message. Runs that look like a
     * tag are removed, and any '<' or '>' left becomes '‹' or '›', so nothing can form an element even where a renderer forgets to escape. The edition still
     * escapes for its own storage and output (this is not HTML escaping).
     */
    private static function text(string $s): string
    {
        $s = preg_replace('/<[^<>]*>/u', '', $s) ?? '';

        return strtr($s, ['<' => "\u{2039}", '>' => "\u{203A}"]);
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
