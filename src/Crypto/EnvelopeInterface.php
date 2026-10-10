<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * The v3 envelope contract: `v3:<kid>:base64(nonce12 || tag16 || ciphertext)`, AES-256-GCM, the caller's context string as AAD.
 * Editions that wrap {@see Envelope} (for example to add metrics) run {@see \RivetCore\Testing\EnvelopeConformanceTestCase}.
 *
 * Every method that opens data throws a {@see CryptoException} (never returns partial plaintext, never returns '' for a failure) and
 * every method that seals throws {@see KeyUnavailable} when no key is active (never returns the plaintext).
 *
 * @api
 */
interface EnvelopeInterface
{
    /**
     * @param string $context binds the ciphertext to its record, for example "credential:42:password"; must not be empty
     * @throws KeyUnavailable
     * @throws \InvalidArgumentException empty context
     */
    public function seal(#[\SensitiveParameter] string $plaintext, string $context): string;

    /**
     * Opens a v3 value or, through the registered {@see LegacyReader}s, a legacy one.
     *
     * @throws DecryptionFailed wrong key, wrong context, tampered, truncated or unknown format
     * @throws UnknownKeyId     a v3 value names a kid that is not in the ring
     * @throws KeyUnavailable   the ring is empty
     */
    public function open(string $stored, string $context): string;

    /** True when the value is not a v3 value sealed under the ring's active key (so a rewrap would change it). '' is never rewrapped. */
    public function needsRewrap(string $stored): bool;

    /**
     * Opens the value and seals it under the active key, checking that the result opens to the same plaintext.
     *
     * @throws CryptoException
     */
    public function rewrap(string $stored, string $context): string;

    /** `v3:<kid>`, `legacy:<reader name>`, `empty` or `unknown`: the key used by {@see RotationPlan} inventories. */
    public function label(string $stored): string;
}
