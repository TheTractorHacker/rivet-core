<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Crypto;

use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Rmm\RmmProtocol;

/**
 * Ed25519 (libsodium detached) signing of jobs, check definitions and update manifests. Keys are stored as base64 of the 64-byte
 * secret key and the 32-byte public key; signatures are base64. The private key is encrypted at rest through the edition's
 * SecretBoxInterface (sealSecretKey/openSecretKey); Core never owns that key. Agents pin the public key received at enrollment,
 * so a stored key must never be rotated or re-derived.
 *
 * @api
 */
final class Signer
{
    /** @return array{0:string,1:string} [public key base64, secret key base64] */
    public static function generateKeypair(): array
    {
        $kp = sodium_crypto_sign_keypair();

        return [base64_encode(sodium_crypto_sign_publickey($kp)), base64_encode(sodium_crypto_sign_secretkey($kp))];
    }

    /**
     * Deterministic keypair from a 32-byte seed (test vectors only).
     *
     * @return array{0:string,1:string} [public key base64, secret key base64]
     */
    public static function keypairFromSeed(string $seed): array
    {
        $kp = sodium_crypto_sign_seed_keypair($seed);

        return [base64_encode(sodium_crypto_sign_publickey($kp)), base64_encode(sodium_crypto_sign_secretkey($kp))];
    }

    /** The key id: the first 16 hex characters of SHA-256 over the base64 TEXT of the public key (not the raw key). */
    public static function keyId(string $publicKeyB64): string
    {
        return substr(hash('sha256', $publicKeyB64), 0, RmmProtocol::SIGNING_KEY_ID_LENGTH);
    }

    /**
     * Mint a signing key for storage.
     *
     * @return array{signing_key_id:string,signing_public_key:string,signing_private_key_enc:string}
     */
    public static function generateStoredKey(SecretBoxInterface $box): array
    {
        [$pub, $sec] = self::generateKeypair();

        return ['signing_key_id' => self::keyId($pub), 'signing_public_key' => $pub, 'signing_private_key_enc' => self::sealSecretKey($box, $sec)];
    }

    public static function sealSecretKey(SecretBoxInterface $box, string $secretKeyB64): string
    {
        return $box->encrypt($secretKeyB64);
    }

    /** @throws \RuntimeException when the key is empty, from another key, or not a valid Ed25519 secret key */
    public static function openSecretKey(SecretBoxInterface $box, string $ciphertext): string
    {
        $sec = $ciphertext === '' ? '' : $box->decrypt($ciphertext);
        $raw = base64_decode($sec, true);
        if ($sec === '' || $raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('endpoint agent signing key is not available');
        }

        return $sec;
    }

    /** @throws \RuntimeException for an unusable secret key */
    public static function sign(string $message, string $secretKeyB64): string
    {
        $sk = base64_decode($secretKeyB64, true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('invalid signing key');
        }

        return base64_encode(sodium_crypto_sign_detached($message, $sk));
    }

    public static function verify(string $message, string $sigB64, string $publicKeyB64): bool
    {
        $sig = base64_decode($sigB64, true);
        $pk = base64_decode($publicKeyB64, true);
        if ($sig === false || $pk === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, $message, $pk);
    }

    /**
     * The signed content of a job: the canonical form of the job object without "signature".
     *
     * @param array<string,mixed> $job
     */
    public static function jobMessage(array $job): string
    {
        unset($job['signature']);

        return CanonicalJson::encode(CanonicalJson::toObject($job));
    }

    /**
     * The signed content of a check definition (key, type, params, interval_s); a "signature" member is ignored. An empty params
     * array is signed as {}.
     *
     * @param array<string,mixed> $check
     */
    public static function checkMessage(array $check): string
    {
        unset($check['signature']);
        if (isset($check['params']) && is_array($check['params']) && $check['params'] === []) {
            $check['params'] = new \stdClass();
        }

        return CanonicalJson::encode(CanonicalJson::toObject(json_decode((string) json_encode($check), false)));
    }

    /** An update manifest is signed over the lowercase hex SHA-256 TEXT of the package. */
    public static function signManifest(string $sha256Hex, string $secretKeyB64): string
    {
        return self::sign($sha256Hex, $secretKeyB64);
    }

    public static function verifyManifest(string $sha256Hex, string $sigB64, string $publicKeyB64): bool
    {
        return self::verify($sha256Hex, $sigB64, $publicKeyB64);
    }
}
