<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * A key, key id or key file is malformed or weak (wrong length, a repeated byte pattern, a bad kid, an unparsable file).
 *
 * @api
 */
final class InvalidKeyMaterial extends CryptoException
{
}
