<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * Encrypts secrets with the edition's key (the Ed25519 signing key and the MeshCentral login key are stored this way). Core
 * never owns that key; the ciphertext format is the edition's, so existing rows keep decrypting.
 *
 * @api
 */
interface SecretBoxInterface
{
    public function encrypt(string $plaintext): string;

    /** Returns '' when the ciphertext is empty, damaged or from another key (never throws). */
    public function decrypt(string $ciphertext): string;
}
