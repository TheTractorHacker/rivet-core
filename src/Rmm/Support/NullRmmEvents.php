<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Support;

use RivetCore\Rmm\Contracts\RmmEventsInterface;

/**
 * Discards every event (editions without an event bus).
 *
 * @api
 */
final class NullRmmEvents implements RmmEventsInterface
{
    public function publish(string $event, array $payload): void
    {
    }
}
