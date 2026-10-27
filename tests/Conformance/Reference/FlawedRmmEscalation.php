<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Testing\InMemoryRmmEscalation;

/** The reference adapter with an optional named flaw: throws_unknown_ack, drops_targets, reverses, lies_true. */
final class FlawedRmmEscalation extends InMemoryRmmEscalation
{
    public function __construct(private ?string $flaw = null)
    {
    }

    public function notify(int $integrationId, array $notification): bool
    {
        if ($this->flaw === 'drops_targets') {
            $notification['targets'] = array_slice($notification['targets'], 0, 1);
        }
        if ($this->flaw === 'reverses') {
            array_unshift($this->notifications, $notification);

            return true;
        }
        if ($this->flaw === 'drops_nulls') {
            $notification = array_filter($notification, static fn (mixed $v): bool => $v !== null);
        }
        $this->notifications[] = $notification;

        return true;
    }

    public function acknowledgeAlert(int $integrationId, int $alertId, int $userId): void
    {
        if ($this->flaw === 'throws_unknown_ack') {
            throw new \RuntimeException('no such alert');
        }
        parent::acknowledgeAlert($integrationId, $alertId, $userId);
    }
}
