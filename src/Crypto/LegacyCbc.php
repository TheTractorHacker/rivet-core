<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * The unauthenticated AES-128-CBC call the old formats used, with PHP's silent key adjustment made explicit (zero-padded or truncated to 16 bytes).
 *
 * @internal
 */
final class LegacyCbc
{
    public static function decrypt(string $data, #[\SensitiveParameter] string $key, string $iv, int $flags): string
    {
        if (strlen($iv) !== 16) {
            throw new DecryptionFailed('The value is not in a readable format.');
        }
        $key = substr(str_pad($key, 16, "\0"), 0, 16);
        $plain = openssl_decrypt($data, 'aes-128-cbc', $key, $flags, $iv);
        if ($plain === false) {
            throw new DecryptionFailed('The value could not be decrypted.');
        }

        return $plain;
    }
}
