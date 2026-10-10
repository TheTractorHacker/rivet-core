<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * `ENC:base64(iv16 || ct)`, AES-128-CBC with key = first 16 bytes of sha256($config_settings_enc_key). The oldest settings format;
 * unauthenticated, so a wrong key can occasionally "decrypt" to garbage with valid padding. Read-only: nothing writes it any more.
 *
 * @api
 */
final class LegacySettingsCbcReader implements LegacyReader
{
    public function __construct(#[\SensitiveParameter] private string $legacyKey)
    {
    }

    public function name(): string
    {
        return 'ENC';
    }

    public function supports(string $stored): bool
    {
        return str_starts_with($stored, 'ENC:');
    }

    public function read(string $stored, string $context): string
    {
        if ($this->legacyKey === '') {
            throw new KeyUnavailable('The legacy settings key is not configured.');
        }
        $data = base64_decode(substr($stored, 4));
        if (strlen($data) <= 16) {
            throw new DecryptionFailed('The value is not in a readable format.');
        }

        return LegacyCbc::decrypt(substr($data, 16), substr(hash('sha256', $this->legacyKey, true), 0, 16), substr($data, 0, 16), OPENSSL_RAW_DATA);
    }
}
