<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Rmm\Contracts\SecretBoxInterface;

/**
 * Reference implementation (not API; tests may extend it to build a deliberately broken variant) of {@see SecretBoxInterface} (libsodium secretbox, random key per instance). For tests only: the
 * ciphertext format is not RivetIT's or RivetMSP's.
 *
 * @internal
 */
class InMemorySecretBox implements SecretBoxInterface
{
    protected string $key;

    public function __construct(?string $key = null)
    {
        $this->key = $key ?? sodium_crypto_secretbox_keygen();
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    public function decrypt(string $ciphertext): string
    {
        $raw = base64_decode($ciphertext, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );

        return $plain === false ? '' : $plain;
    }
}
