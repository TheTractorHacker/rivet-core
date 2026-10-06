<?php

declare(strict_types=1);

namespace RivetCore\Contracts;

/** @api */
interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
