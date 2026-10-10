<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Support;

use RivetCore\Rmm\Contracts\RmmEscalationInterface;

/**
 * Delivers nothing (editions without escalation). {@see notify()} answers false so a step stays due instead of being recorded as sent.
 *
 * @api
 */
final class NullRmmEscalation implements RmmEscalationInterface
{
    public function notify(int $integrationId, array $notification): bool
    {
        return false;
    }

    public function acknowledgeAlert(int $integrationId, int $alertId, int $userId): void
    {
    }

    public function raiseAlertSeverity(int $integrationId, int $alertId, string $severity): void
    {
    }
}
