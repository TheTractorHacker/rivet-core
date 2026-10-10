<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Ready-made reader lists for the formats RivetIT and RivetMSP wrote before v3.
 *
 * @api
 */
final class LegacyReaders
{
    /**
     * `ENC2:` then `ENC:`, both under $config_settings_enc_key as configured.
     *
     * @return list<LegacyReader>
     */
    public static function settings(#[\SensitiveParameter] string $legacyKey): array
    {
        return [new LegacySettingsGcmReader($legacyKey), new LegacySettingsCbcReader($legacyKey)];
    }

    /**
     * Settings plus TOTP seeds (`enc:`, vault master key) for a SettingsVault used on a column that may hold either.
     *
     * @return list<LegacyReader>
     */
    public static function settingsAndOtp(#[\SensitiveParameter] string $legacyKey, #[\SensitiveParameter] string $legacyMasterKey): array
    {
        return [new LegacySettingsGcmReader($legacyKey), new LegacySettingsCbcReader($legacyKey), new LegacyOtpReader($legacyMasterKey)];
    }

    /** @return list<LegacyReader> */
    public static function plaintext(): array
    {
        return [new PlaintextPassthroughReader()];
    }
}
