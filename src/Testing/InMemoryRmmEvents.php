<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Rmm\Contracts\RmmEventsInterface;

/**
 * Reference implementation (not API; tests may extend it to build a deliberately broken variant) of {@see RmmEventsInterface}: records
 * what it is given, in order.
 *
 * @internal
 */
class InMemoryRmmEvents implements RmmEventsInterface
{
    /** @var list<array{event:string,payload:array<string,mixed>}> */
    protected array $published = [];

    public function publish(string $event, array $payload): void
    {
        $this->published[] = ['event' => $event, 'payload' => $payload];
    }

    /** @return list<array{event:string,payload:array<string,mixed>}> */
    public function published(): array
    {
        return $this->published;
    }

    /**
     * The payloads of one event id, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function of(string $event): array
    {
        return array_values(array_map(static fn (array $e): array => $e['payload'], array_filter($this->published, static fn (array $e): bool => $e['event'] === $event)));
    }
}
