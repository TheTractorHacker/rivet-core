<?php

declare(strict_types=1);

namespace RivetCore\Testing;

/**
 * Conformance cases verify at runtime what an adapter's signature and docblock only promise, so they look at adapter
 * results as `mixed` instead of letting static analysis take the declared type on trust.
 *
 * @internal
 */
trait UntypedValues
{
    private static function untyped(mixed $value): mixed
    {
        return $value;
    }
}
