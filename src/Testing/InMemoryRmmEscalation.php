<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Rmm\Contracts\RmmEscalationInterface;

/**
 * Reference implementation (not API; tests may extend it to build a deliberately broken variant) of {@see RmmEscalationInterface}: keeps
 * every notification, acknowledgement and severity change, in order, and delivers (returns true) unless told to fail.
 *
 * @internal
 */
class InMemoryRmmEscalation implements RmmEscalationInterface
{
    /** @var list<array<string,mixed>> */
    protected array $notifications = [];
    /** @var list<array{alert_id:int,user_id:int}> */
    protected array $acks = [];
    /** @var list<array{alert_id:int,severity:string}> */
    protected array $severities = [];
    /** @var list<bool> answers to give to the next calls of notify(); empty = true */
    public array $answers = [];
    public ?\Throwable $throw = null;

    public function notify(int $integrationId, array $notification): bool
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }
        $this->notifications[] = $notification;

        return $this->answers === [] ? true : array_shift($this->answers);
    }

    public function acknowledgeAlert(int $integrationId, int $alertId, int $userId): void
    {
        $this->acks[] = ['alert_id' => $alertId, 'user_id' => $userId];
    }

    public function raiseAlertSeverity(int $integrationId, int $alertId, string $severity): void
    {
        $this->severities[] = ['alert_id' => $alertId, 'severity' => $severity];
    }

    /** @return list<array<string,mixed>> */
    public function notifications(): array
    {
        return $this->notifications;
    }

    /** @return list<array{alert_id:int,user_id:int}> */
    public function acks(): array
    {
        return $this->acks;
    }

    /** @return list<array{alert_id:int,severity:string}> */
    public function severities(): array
    {
        return $this->severities;
    }
}
