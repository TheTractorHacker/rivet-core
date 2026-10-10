<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Base class of every failure the Crypto namespace reports. Messages never contain plaintext, key bytes or ciphertext, so they are
 * safe to log.
 *
 * @api
 */
class CryptoException extends \RuntimeException
{
}
