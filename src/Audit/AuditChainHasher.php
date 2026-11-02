<?php

declare(strict_types=1);

namespace RivetCore\Audit;

use RivetCore\Crypto\KeyPurpose;

/**
 * The canonical encoding and hash of one audit row (ERR-04). Pure functions, shared by the writer and the verifier so they cannot drift.
 *
 * row_hash = SHA-256(prev_hash . "\n" . JSON([audit_id, event_type, actor_user_id, entity_type, entity_id, action, summary, metadata_json,
 * ip_address, user_agent, request_id, created_at])) with every value cast to a string (NULL stays null). When a key is given the hash is
 * HMAC-SHA-256 with that key instead. The encoding is the same one RivetIT's own chain used, so rows it already sealed stay valid.
 *
 * @api
 */
final class AuditChainHasher
{
    /** prev_hash of the first row of a chain. */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Columns covered by the hash, in order. prev_hash is covered separately; row_hash is the output. */
    public const FIELDS = ['audit_id', 'event_type', 'actor_user_id', 'entity_type', 'entity_id', 'action', 'summary', 'metadata_json', 'ip_address', 'user_agent', 'request_id', 'created_at'];

    /** @param array<string,mixed> $row a full audit_events row as read back from the database */
    public static function rowHash(array $row, string $prevHash, ?string $key = null): string
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $v = $row[$field] ?? null;
            $values[] = $v === null ? null : (string) $v;
        }
        $data = $prevHash . "\n" . json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $key !== null && $key !== '' ? hash_hmac('sha256', $data, $key) : hash('sha256', $data);
    }

    /**
     * The HMAC key for an install's master key (the 32-byte KEK of Core's crypto layer), via the same HKDF the other purposes use.
     * Pass the result as the $key of AuditChainWriter and AuditChainVerifier.
     */
    public static function deriveKey(#[\SensitiveParameter] string $kek): string
    {
        return KeyPurpose::derive($kek, KeyPurpose::AUDIT_CHAIN);
    }

    /** 'hmac' when a key is in use, otherwise 'plain'. */
    public static function mode(?string $key): string
    {
        return $key !== null && $key !== '' ? 'hmac' : 'plain';
    }

    /** A short non-reversible tag of the key, stored with the chain so a changed key is reported as that and not as tampering. '' for plain. */
    public static function keyTag(?string $key): string
    {
        return $key !== null && $key !== '' ? substr(hash_hmac('sha256', 'rivetcore-audit-chain-key-tag', $key), 0, 12) : '';
    }
}
