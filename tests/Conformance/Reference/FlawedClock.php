<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Contracts\ClockInterface;

/** A correct clock, or one with a named flaw: backwards, skewed, wrong_tz, epoch. */
final class FlawedClock implements ClockInterface
{
    private int $calls = 0;

    public function __construct(private ?string $flaw = null)
    {
    }

    public function now(): \DateTimeImmutable
    {
        $this->calls++;
        $now = new \DateTimeImmutable();

        return match ($this->flaw) {
            'backwards' => $now->modify('-' . $this->calls . ' seconds'),
            // classic bug: the wall clock of one zone re-read as another zone
            'skewed' => new \DateTimeImmutable($now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), new \DateTimeZone('Pacific/Auckland')),
            'wrong_tz' => $now->setTimezone(new \DateTimeZone(date_default_timezone_get() === 'Pacific/Auckland' ? 'UTC' : 'Pacific/Auckland')),
            'epoch' => (new \DateTimeImmutable('@0'))->modify('+' . $this->calls . ' seconds'),
            default => $now,
        };
    }
}
