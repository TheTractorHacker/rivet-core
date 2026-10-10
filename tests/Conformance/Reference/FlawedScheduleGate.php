<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Rmm\Contracts\ScheduleGateInterface;

/** A gate that holds back every device of client 5 on odd minutes, with an optional named flaw: bad_word, flips, throws_minimal. */
final class FlawedScheduleGate implements ScheduleGateInterface
{
    private int $calls = 0;

    public function __construct(private readonly ?string $flaw = null)
    {
    }

    public function decide(array $device, array $schedule, int $slotTimestamp): string
    {
        ++$this->calls;
        if ($this->flaw === 'bad_word') {
            return 'later';
        }
        if ($this->flaw === 'flips') {
            return $this->calls % 2 === 0 ? self::RUN : self::SKIP;
        }
        if ($this->flaw === 'throws_minimal') {
            if (!isset($device['hostname'])) {
                throw new \RuntimeException('a member the contract does not promise');
            }

            return self::RUN;
        }

        return (int) $device['client_id'] === 5 && intdiv($slotTimestamp, 60) % 2 === 1 ? self::DEFER : self::RUN;
    }
}
