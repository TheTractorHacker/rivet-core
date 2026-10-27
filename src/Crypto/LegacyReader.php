<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Reads one pre-v3 format. Readers are registered on an {@see Envelope} in order; the first whose supports() is true opens the value.
 * Legacy formats carry no AAD, so the context is ignored (and the value is re-wrapped into v3 on the next write or by the Rewrapper).
 *
 * @api
 */
interface LegacyReader
{
    /** Stable short name, used in inventories ("legacy:ENC2"). */
    public function name(): string;

    /** Cheap format test on the stored string (a prefix or a shape); must not decrypt. */
    public function supports(string $stored): bool;

    /**
     * @throws DecryptionFailed
     * @throws KeyUnavailable no key material was given to the reader
     */
    public function read(string $stored, string $context): string;
}
