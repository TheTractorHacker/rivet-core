<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Backup;

/**
 * Result of {@see BackupEncryptionInspector}: `state` is `encrypted`, `plaintext` or `unknown`; `method` a short label (`age`, `gpg`, `openssl`, `rivetcore-v3`) when
 * encrypted; `suffixHint` what the file name claims (informational, never proof); `detail` one short line, never file content.
 *
 * @api
 */
final readonly class BackupEncryptionReport
{
    public function __construct(
        public string $state,
        public ?string $method = null,
        public ?string $suffixHint = null,
        public string $detail = '',
    ) {
    }

    public function isEncrypted(): bool
    {
        return $this->state === BackupEncryptionInspector::ENCRYPTED;
    }

    /** True when the name promises encryption (`.age`, `.gpg`, ...) but the content is not encrypted: worth a loud warning. */
    public function suffixLies(): bool
    {
        return $this->suffixHint !== null && $this->state === BackupEncryptionInspector::PLAINTEXT;
    }
}
