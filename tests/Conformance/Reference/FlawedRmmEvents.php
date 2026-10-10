<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Testing\InMemoryRmmEvents;

/** The reference bus with an optional named flaw: drops_nulls, reverses, throws_unknown. */
final class FlawedRmmEvents extends InMemoryRmmEvents
{
    public function __construct(private ?string $flaw = null)
    {
    }

    public function publish(string $event, array $payload): void
    {
        if ($this->flaw === 'throws_unknown' && $event === 'rmm.device.online') {
            throw new \RuntimeException('no subscriber');
        }
        if ($this->flaw === 'drops_nulls') {
            $payload = array_filter($payload, static fn (mixed $v): bool => $v !== null && $v !== false);
        }
        if ($this->flaw === 'reverses') {
            array_unshift($this->published, ['event' => $event, 'payload' => $payload]);

            return;
        }
        parent::publish($event, $payload);
    }
}
