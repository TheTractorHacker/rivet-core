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
 * logged, and the optional logger only gets the source, the exception class and the monitor key.
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
        if (!$this->auth->authenticate($headers)) {
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
            $out = $this->engine->process($event);
        } catch (\Throwable $t) {
            $this->logger?->error('Inbound monitoring delivery failed', ['source' => $event->source, 'monitor' => $event->monitorKey, 'exception' => $t::class]);

            return new InboundResult(500, ['ok' => false, 'error' => 'internal']);
        }

        return new InboundResult(200, ['ok' => true, 'action' => $out->action, 'ticket_id' => $out->ticketId]);
    }
}
