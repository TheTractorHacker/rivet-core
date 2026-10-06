<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

/** Lets {@see Harness} run a reference case against an adapter with one named flaw. Null (the default) = a correct adapter. */
trait Flaw
{
    public static ?string $flaw = null;
}
