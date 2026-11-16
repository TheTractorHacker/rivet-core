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

    /**
     * CORE-DS-31: {@see self::decrypt()} for a value that is TEXT (a credential, a setting, a TOTP seed). The old formats carry no authentication, so a wrong key still gives
     * valid padding about once in 256 values and the result is random bytes; those must not be accepted (and then sealed into a v3 envelope over the only good copy). The
     * plaintext must be valid UTF-8 without control characters other than tab, line feed and carriage return.
     *
     * @throws DecryptionFailed
     */
    public static function decryptText(string $data, #[\SensitiveParameter] string $key, string $iv, int $flags): string
    {
        $plain = self::decrypt($data, $key, $iv, $flags);
        if (!mb_check_encoding($plain, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $plain) === 1) {
            throw new DecryptionFailed('The value could not be decrypted.');
        }

        return $plain;
    }
}
