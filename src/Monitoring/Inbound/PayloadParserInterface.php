<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * Turns the raw body of one monitoring source into an {@see InboundEvent}. Another monitor is added by implementing this and handing
 * it to {@see InboundHandler}. Must never throw on hostile input.
 *
 * @api
 */
interface PayloadParserInterface
{
    /** Stable source id, e.g. "uptime-kuma"; part of the incident key. [a-z0-9-], at most 32 characters. */
    public function source(): string;

    public function parse(string $rawBody): InboundEvent|PayloadRejection;
}
