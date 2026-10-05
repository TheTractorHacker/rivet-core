<?php

declare(strict_types=1);

namespace RivetCore\Support;

use RivetCore\Contracts\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
