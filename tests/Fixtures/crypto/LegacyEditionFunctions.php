<?php

declare(strict_types=1);

namespace RivetCore\Tests\Fixtures\LegacyEditions;

/**
 * TEST FIXTURE ONLY. Verbatim copies of the function bodies RivetIT (origin/main functions.php) and RivetMSP (github/beta functions.php)
 * use to write the pre-v3 formats; the two editions' bodies are byte-identical for these functions. The only edits are mechanical:
 * the globals/superglobals they read ($config_settings_enc_key, $_SESSION, $_COOKIE) are parameters, and mysqli is not involved.
 * `generate.php` runs them to produce `legacy_samples.json`; the unit test then checks that the Core readers open the editions' real output.
 * Never include this file from src/.
 */
final class LegacyEditionFunctions
{
    public static function randomString(int $length = 16): string
    {
        $bytes = random_bytes((int) ceil($length * 3 / 4));
        return substr(
            rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='),
            0,
            $length
        );
    }

    /** encryptSetting() */
    public static function encryptSetting(string $plaintext, string $config_settings_enc_key): string
    {
        if ($plaintext === '') return $plaintext;

        if (empty($config_settings_enc_key)) {
            throw new \RuntimeException('Refusing to store a secret in cleartext');
        }

        $key   = hash('sha256', $config_settings_enc_key, true); // 32 raw bytes
        $nonce = random_bytes(12);
        $tag   = '';
        $ct    = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ct === false) {
            throw new \RuntimeException('Refusing to store a secret in cleartext: openssl_encrypt() failed.');
        }

        return 'ENC2:' . base64_encode($nonce . $tag . $ct);
    }

    /** The legacy aes-128-cbc ENC: writer (reconstructed from decryptSetting's reader: iv[16] . ct, base64, key = first 16 bytes of sha256). */
    public static function encryptSettingCbc(string $plaintext, string $config_settings_enc_key): string
    {
        $key = substr(hash('sha256', $config_settings_enc_key, true), 0, 16);
        $iv  = random_bytes(16);
        return 'ENC:' . base64_encode($iv . openssl_encrypt($plaintext, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv));
    }

    /** decryptSetting(), the editions' reader, kept so the fixture can prove the samples are what the editions themselves open. */
    public static function decryptSetting(string $ciphertext, string $config_settings_enc_key): string
    {
        if ($ciphertext === '') return $ciphertext;

        $is_gcm = str_starts_with($ciphertext, 'ENC2:');
        $is_cbc = str_starts_with($ciphertext, 'ENC:');

        if (!$is_gcm && !$is_cbc) return $ciphertext;

        if (empty($config_settings_enc_key)) return '';

        if ($is_gcm) {
            $data = base64_decode(substr($ciphertext, 5), true);
            if ($data === false || strlen($data) <= 28) return '';
            $key   = hash('sha256', $config_settings_enc_key, true);
            $nonce = substr($data, 0, 12);
            $tag   = substr($data, 12, 16);
            $ct    = substr($data, 28);
            $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            return $pt === false ? '' : $pt;
        }

        $data = base64_decode(substr($ciphertext, 4));
        if (strlen($data) <= 16) return '';
        $key = substr(hash('sha256', $config_settings_enc_key, true), 0, 16);
        $iv  = substr($data, 0, 16);
        $ct  = substr($data, 16);
        $pt  = openssl_decrypt($ct, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $pt === false ? '' : $pt;
    }

    /** setupFirstUserSpecificKey() */
    public static function setupFirstUserSpecificKey(string $user_password, string $site_encryption_master_key): string
    {
        $iv = self::randomString();
        $salt = self::randomString();

        $user_password_kdhash = hash_pbkdf2('sha256', $user_password, $salt, 100000, 16, true);

        $ciphertext = openssl_encrypt($site_encryption_master_key, 'aes-128-cbc', $user_password_kdhash, 0, $iv);

        return 'V2:' . $salt . $iv . $ciphertext;
    }

    /** The V1 writer (before the PBKDF2 fix), reconstructed from decryptUserSpecificKey()'s V1 branch. */
    public static function setupFirstUserSpecificKeyV1(string $user_password, string $site_encryption_master_key): string
    {
        $iv = self::randomString();
        $salt = self::randomString();
        $key = hash_pbkdf2('sha256', $user_password, $salt, 100000, 16);
        return $salt . $iv . openssl_encrypt($site_encryption_master_key, 'aes-128-cbc', $key, 0, $iv);
    }

    /** decryptUserSpecificKey() */
    public static function decryptUserSpecificKey(string $user_encryption_ciphertext, string $user_password): string|false
    {
        if (substr($user_encryption_ciphertext, 0, 3) === 'V2:') {
            $payload = substr($user_encryption_ciphertext, 3);
            $salt     = substr($payload, 0, 16);
            $iv       = substr($payload, 16, 16);
            $ciphertext = substr($payload, 32);
            $key = hash_pbkdf2('sha256', $user_password, $salt, 100000, 16, true);
        } else {
            $salt     = substr($user_encryption_ciphertext, 0, 16);
            $iv       = substr($user_encryption_ciphertext, 16, 16);
            $ciphertext = substr($user_encryption_ciphertext, 32);
            $key = hash_pbkdf2('sha256', $user_password, $salt, 100000, 16);
        }

        return openssl_decrypt($ciphertext, 'aes-128-cbc', $key, 0, $iv);
    }

    /** encryptCredentialEntryWithKey() */
    public static function encryptCredentialEntryWithKey(string $credential_password_cleartext, string $master_key): string
    {
        $iv = self::randomString();
        $ciphertext = openssl_encrypt($credential_password_cleartext, 'aes-128-cbc', $master_key, 0, $iv);
        return $iv . $ciphertext;
    }

    /** decryptCredentialEntryWithKey() */
    public static function decryptCredentialEntryWithKey(string $credential_ciphertext, string $master_key): string|false
    {
        $iv = substr($credential_ciphertext, 0, 16);
        $ciphertext = substr($credential_ciphertext, 16);
        return openssl_decrypt($ciphertext, 'aes-128-cbc', $master_key, 0, $iv);
    }

    /** encryptOtpSecret(), with the session master key passed in. */
    public static function encryptOtpSecret(string $otp_plain, string $master_key): string
    {
        if (empty($otp_plain)) return '';
        $iv = self::randomString();
        $ct = openssl_encrypt($otp_plain, 'aes-128-cbc', $master_key, 0, $iv);
        return 'enc:' . $iv . $ct;
    }

    /** decryptOtpSecret(), with the session master key passed in. */
    public static function decryptOtpSecret(string $otp_stored, string $master_key): string
    {
        if (empty($otp_stored) || !str_starts_with($otp_stored, 'enc:')) return $otp_stored;
        $payload = substr($otp_stored, 4);
        $iv = substr($payload, 0, 16);
        $ct = substr($payload, 16);
        return openssl_decrypt($ct, 'aes-128-cbc', $master_key, 0, $iv) ?: '';
    }
}
