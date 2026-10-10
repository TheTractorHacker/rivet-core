<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Support;

use RivetCore\Rmm\Contracts\RmmEventsInterface;

/**
 * The module's side of {@see RmmEventsInterface}: builds the common payload from a device row, holds events back while a database
 * transaction is open (so a rolled-back check-in publishes nothing) and delivers them once it is committed. A failing bus is logged,
 * never thrown. When the edition gave no bus {@see enabled()} is false and callers skip the work that only exists to detect an event.
 *
 * @api
 */
final class RmmEventPublisher
{
    /** @var list<array{0:string,1:array<string,mixed>}> */
    private array $held = [];
    private int $holds = 0;

    public function __construct(private readonly Sql $sql, private readonly RmmEventsInterface $bus)
    {
    }

    public function enabled(): bool
    {
        return !$this->bus instanceof NullRmmEvents;
    }

    /**
     * @param array<string,mixed> $dev the device row
     * @param array<string,mixed> $fields the event's own fields
     */
    public function emit(string $event, array $dev, array $fields = []): void
    {
        if (!$this->enabled()) {
            return;
        }
        $assetId = $dev['asset_id'] ?? null;
        $payload = [
            'device_id' => (int) $dev['device_id'],
            'asset_id' => $assetId === null || (int) $assetId <= 0 ? null : (int) $assetId,
            'client_id' => (int) ($dev['client_id'] ?? 0),
            'hostname' => (string) ($dev['hostname'] ?? ''),
            'occurred_at' => $this->sql->isoNow(),
        ] + $fields;
        if ($this->holds > 0) {
            $this->held[] = [$event, $payload];

            return;
        }
        $this->deliver($event, $payload);
    }

    /**
     * Publish an event that is not about one device (a policy assignment, an approval). The payload keeps the device shape so a subscriber
     * does not need a second code path: device_id 0, asset_id null, an empty hostname.
     *
     * @param array<string,mixed> $fields the event's own fields
     */
    public function emitScoped(string $event, int $clientId, array $fields = []): void
    {
        $this->emit($event, ['device_id' => 0, 'asset_id' => null, 'client_id' => $clientId, 'hostname' => ''], $fields);
    }

    /** Start holding events (nestable). Pair with {@see release()} or {@see discard()}. */
    public function hold(): void
    {
        ++$this->holds;
    }

    /** The transaction committed: deliver what was held once the outermost hold ends. */
    public function release(): void
    {
        if ($this->holds > 0 && --$this->holds === 0) {
            $held = $this->held;
            $this->held = [];
            foreach ($held as [$event, $payload]) {
                $this->deliver($event, $payload);
            }
        }
    }

    /** The transaction rolled back: drop what was held once the outermost hold ends. */
    public function discard(): void
    {
        if ($this->holds > 0 && --$this->holds === 0) {
            $this->held = [];
        }
    }

    /** @param array<string,mixed> $payload */
    private function deliver(string $event, array $payload): void
    {
        try {
            $this->bus->publish($event, $payload);
        } catch (\Throwable $e) {
            error_log('endpoint agent event ' . $event . ' not delivered: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }
}
