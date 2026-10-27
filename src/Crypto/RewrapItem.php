<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * One stored ciphertext handed out by a {@see RewrapSource}.
 *
 * @api
 */
final class RewrapItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $ciphertext,
        public readonly string $context,
    ) {
    }
}
