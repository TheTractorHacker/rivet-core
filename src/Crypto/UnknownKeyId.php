<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * A v3 value names a `kid` that is not in the key ring (the key was retired too early, or the key file is from another install).
 *
 * @api
 */
final class UnknownKeyId extends CryptoException
{
    public function __construct(public readonly string $kid, ?\Throwable $previous = null)
    {
        parent::__construct('No key with id "' . $kid . '" is configured.', 0, $previous);
    }
}
