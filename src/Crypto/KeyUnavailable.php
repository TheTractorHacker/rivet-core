<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * There is no usable key (none configured, the key file is missing or unsafe, or the ring has no active key). Callers must stop: the
 * Crypto namespace never falls back to storing or returning cleartext.
 *
 * @api
 */
final class KeyUnavailable extends CryptoException
{
}
