<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * {@see Reencryptor} for credential columns, used with the Rewrapper to run `vault-rewrap`:
 *
 * - legacy to v3: `new VaultFieldReencryptor($newCipher, legacyDek: $oldMasterKey)`
 * - DEK rotation: `new VaultFieldReencryptor($newCipher, from: $oldCipher)`
 *
 * A value that already opens under the target DEK is left alone, so an interrupted run can be resumed or repeated safely. The
 * RewrapItem context must be {@see VaultCipher::contextFor()}.
 *
 * @api
 */
final class VaultFieldReencryptor implements Reencryptor
{
    public function __construct(
        private VaultCipher $to,
        private ?VaultCipher $from = null,
        #[\SensitiveParameter] private ?string $legacyDek = null,
    ) {
    }

    public function reencrypt(string $stored, string $context): ?string
    {
        if ($stored === '') {
            return null;
        }
        if (VaultCipher::isV3($stored)) {
            try {
                $this->to->openWithContext($stored, $context);

                return null;
            } catch (DecryptionFailed|UnknownKeyId $e) {
                if ($this->from === null) {
                    throw $e;
                }
            }

            return $this->to->sealWithContext($this->from->openWithContext($stored, $context), $context);
        }
        if ($this->legacyDek === null) {
            throw new DecryptionFailed('A legacy value was found but no legacy key was given.');
        }
        $reader = new LegacyVaultFieldReader($this->legacyDek);
        if (!$reader->supports($stored)) {
            throw new DecryptionFailed('The value is not a legacy vault field.');
        }

        return $this->to->sealWithContext($reader->read($stored, $context), $context);
    }
}
