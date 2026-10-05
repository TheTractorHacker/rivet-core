<?php

declare(strict_types=1);

namespace RivetCore\Contracts;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
