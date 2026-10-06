<?php

declare(strict_types=1);

namespace RivetCore\Contracts;

/**
 * The current instant. Contract (checked by Testing\ClockConformanceTestCase): now() returns the real current time (a
 * test double may be frozen), successive calls never go backwards, the instant is right whatever timezone it is reported
 * in, and the returned value is immutable.
 *
 * @api
 */
interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
