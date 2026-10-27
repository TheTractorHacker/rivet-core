<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * `ENC2:base64(nonce12 || tag16 || ct)`, AES-256-GCM with key = sha256($config_settings_enc_key) and no AAD. The settings format of
 * RivetIT and RivetMSP before v3 (functions.php encryptSetting). The legacy key is the configured string as it was (a 64-character hex
 * string on installs set up by the editions), not decoded.
 *
 * @api
 */
final class LegacySettingsGcmReader implements LegacyReader
{
    public function __construct(#[\SensitiveParameter] private string $legacyKey)
    {
    }

    public function name(): string
    {
        return 'ENC2';
    }

    public function supports(string $stored): bool
    {
        return str_starts_with($stored, 'ENC2:');
    }

    public function read(string $stored, string $context): string
    {
        if ($this->legacyKey === '') {
            throw new KeyUnavailable('The legacy settings key is not configured.');
        }
        $data = base64_decode(substr($stored, 5), true);
        if ($data === false || strlen($data) < 28) {
            throw new DecryptionFailed('The value is not in a readable format.');
        }
        $plain = openssl_decrypt(substr($data, 28), 'aes-256-gcm', hash('sha256', $this->legacyKey, true), OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16));
        if ($plain === false) {
            throw new DecryptionFailed('The value could not be authenticated.');
        }

        return $plain;
    }
}
