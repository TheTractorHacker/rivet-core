<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * The stored value could not be opened: wrong key, wrong context (AAD), tampered or truncated data, or a format nobody can read. The
 * cause is deliberately not distinguishable, so the exception is not an oracle. No partial plaintext is ever returned instead.
 *
 * @api
 */
final class DecryptionFailed extends CryptoException
{
}
