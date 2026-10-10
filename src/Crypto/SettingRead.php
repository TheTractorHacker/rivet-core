<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * The result of {@see SettingsVault::read()}: the plaintext, and, when the stored value was legacy or sealed under a retired key, the
 * replacement ciphertext the caller should persist (compare-and-set against the value it read).
 *
 * @api
 */
final class SettingRead
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $plaintext,
        public readonly ?string $rewrapped,
        public readonly string $format,
    ) {
    }

    public function __debugInfo(): array
    {
        return ['format' => $this->format, 'rewrapped' => $this->rewrapped !== null];
    }
}
