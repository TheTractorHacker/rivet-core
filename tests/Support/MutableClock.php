<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Contracts\ClockInterface;

/** A clock tests can move: starts at the real now (the database defaults are real time) and only moves when told. */
final class MutableClock implements ClockInterface
{
    private int $offset = 0;

    public function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . (time() + $this->offset)))->setTimezone(new \DateTimeZone('UTC'));
    }

    public function advance(int $seconds): void
    {
        $this->offset += $seconds;
    }
}
