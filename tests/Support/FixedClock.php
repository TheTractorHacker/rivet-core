<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Contracts\ClockInterface;

final class FixedClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $at = new \DateTimeImmutable('2026-01-02 03:04:05'))
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->at;
    }
}
