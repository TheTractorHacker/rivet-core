<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

use Psr\Log\LoggerInterface;

/**
 * The whole inbound endpoint, independent of the HTTP stack. The edition's endpoint file only checks the method is POST, reads the
 * headers, the raw body (php://input) and REMOTE_ADDR, calls {@see self::handle()} and sends the {@see InboundResult}.
 *
 * Order: rate limit (429) > authentication (401, the same answer whether the secret is wrong, missing or not configured) > size
 * (413) > parse (400) > engine. Test notifications answer 200 {"ok":true,"action":"test"} without touching any incident. A failure in
 * the edition's store or ticket system answers 500 {"ok":false,"error":"internal"} so the sender can retry; the raw body is never
 * logged, and the optional logger only gets the source, the sender id, the exception class and the monitor key.
 *
 * A processed delivery answers 200 {"ok":true,"action":"<token>"} and nothing else: never a ticket id (red team 2026-11-21 Chain A, so a sender learns
 * nothing about tickets, its own or anybody else's). A sender limited to some clients that reports a monitor the map sends to another client gets
 * 403 {"ok":false,"error":"out_of_scope"} and nothing is opened. The sender identified by the secret ({@see InboundAuthenticator::identify()}) is handed
 * to the engine, which keys its incidents by it.
 *
 * @api
 */
final class InboundHandler
{
    public function __construct(
        private readonly InboundAuthenticator $auth,
        private readonly PayloadParserInterface $parser,
        private readonly IncidentEngine $engine,
        private readonly ?RateLimiterInterface $limiter = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $maxBody = UptimeKumaPayload::MAX_BODY,
    ) {
    }

    /** @param array<string,string|list<string>> $headers */
    public function handle(array $headers, string $rawBody, string $remoteIp): InboundResult
    {
        if ($this->limiter !== null && !$this->limiter->allow('inbound-monitoring:' . $remoteIp)) {
            return new InboundResult(429, ['ok' => false, 'error' => 'rate_limited'], ['Retry-After' => '60']);
        }
        $sender = $this->auth->identify($headers);
        if ($sender === null) {
            return new InboundResult(401, ['ok' => false, 'error' => 'unauthorized']);
        }
        if (strlen($rawBody) > $this->maxBody) {
            return new InboundResult(413, ['ok' => false, 'error' => PayloadRejection::TOO_LARGE]);
        }

        $event = $this->parser->parse($rawBody);
        if ($event instanceof PayloadRejection) {
            return new InboundResult($event->httpStatus(), ['ok' => false, 'error' => $event->code]);
        }

        try {
            $out = $this->engine->process($event, $sender);
        } catch (\Throwable $t) {
            $this->logger?->error('Inbound monitoring delivery failed', ['source' => $event->source, 'sender' => $sender->id, 'monitor' => $event->monitorKey, 'exception' => $t::class]);

            return new InboundResult(500, ['ok' => false, 'error' => 'internal']);
        }
        if ($out->action === EngineOutcome::OUT_OF_SCOPE) {
            return new InboundResult(403, ['ok' => false, 'error' => EngineOutcome::OUT_OF_SCOPE]);
        }

        return new InboundResult(200, ['ok' => true, 'action' => $out->action]);
    }
}
