<?php

declare(strict_types=1);

namespace RivetCore\Audit;

use RivetCore\Contracts\RequestContextInterface;
use RivetCore\Database\DatabaseInterface;

/**
 * Append-only structured audit trail. Nothing here reads, updates or deletes
 * rows; edition pages that display the trail query audit_events themselves.
 *
 * Request facts (IP, user agent, request id) come from the injected
 * RequestContextInterface, never from superglobals.
 *
 * @api
 */
final class AuditService
{
    private const SUMMARY_MAX = 500;
    private const USER_AGENT_MAX = 255;
    // Column widths of audit_events, so an over-long value is clamped instead of throwing (strict SQL) or truncating silently.
    private const EVENT_TYPE_MAX = 100;
    private const ENTITY_TYPE_MAX = 100;
    private const ENTITY_ID_MAX = 64;
    private const ACTION_MAX = 50;
    private const REQUEST_ID_MAX = 64;
    private const IP_MAX = 64;
    /** Metadata keys whose values are never stored. */
    private const SECRET_KEY = '/^(password|passwd|pwd|secret|client_secret|token|access_token|refresh_token|id_token|api_key|apikey|authorization|private_key)$/i';
    /** Compared after lower-casing and dropping every non-alphanumeric character, so "X-Api-Key", "db_password" and "Client Secret" match. */
    private const SECRET_NAMES = ['pwd', 'authorization', 'bearer', 'cookie', 'setcookie', 'otp', 'totp', 'credential', 'credentials', 'xapikey'];
    private const SECRET_SUFFIXES = ['password', 'passwd', 'passphrase', 'secret', 'apikey', 'privatekey', 'token'];
    /** audit_events.metadata_json is a TEXT column (65535 bytes); a larger value makes the INSERT fail and the event would be lost. */
    private const METADATA_MAX_BYTES = 60000;

    /**
     * @param (\Closure(string,?int,?string,?string,string,?string,array<string,mixed>):void)|null $afterLog called after a row is recorded
     *        with (eventType, actorUserId, entityType, entityId, action, summary, metadata); lets an edition fan the event out to
     *        webhooks and automation rules. Whatever it does or throws, the audit write and the caller are unaffected.
     */
    public function __construct(
        private DatabaseInterface $database,
        private RequestContextInterface $request,
        private ?\Closure $afterLog = null,
    ) {
    }

    /**
     * @param mixed $entityId int|string|null; stored as a string
     * @param array<string,mixed> $metadata
     */
    public function log(
        string $eventType,
        ?int $actorUserId,
        ?string $entityType,
        mixed $entityId,
        string $action,
        ?string $summary = null,
        array $metadata = [],
    ): void {
        $userAgent = $this->request->userAgent();
        $entityIdStr = $entityId === null ? null : self::clamp((string) $entityId, self::ENTITY_ID_MAX);

        $this->database->execute(
            'INSERT INTO audit_events
                (event_type, actor_user_id, entity_type, entity_id, action, summary, metadata_json, ip_address, user_agent, request_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::clamp($eventType, self::EVENT_TYPE_MAX),
                $actorUserId,
                $entityType === null ? null : self::clamp($entityType, self::ENTITY_TYPE_MAX),
                $entityIdStr,
                self::clamp($action, self::ACTION_MAX),
                $summary === null ? null : mb_substr($summary, 0, self::SUMMARY_MAX),
                $metadata ? self::encodeMetadata($metadata) : null,
                self::clampNullable($this->request->ipAddress(), self::IP_MAX),
                $userAgent === null ? null : mb_substr($userAgent, 0, self::USER_AGENT_MAX),
                self::clampNullable($this->request->requestId(), self::REQUEST_ID_MAX),
            ]
        );

        if ($this->afterLog !== null) {
            try {
                // the redacted copy: the fan-out leaves the database's trust boundary (webhooks, automation rules)
                ($this->afterLog)($eventType, $actorUserId, $entityType, $entityId === null ? null : (string) $entityId, $action, $summary, self::redact($metadata));
            } catch (\Throwable) {
                // fan-out is best effort
            }
        }
    }

    private static function clamp(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }

    private static function clampNullable(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }

    /** @param array<string,mixed> $metadata */
    private static function encodeMetadata(array $metadata): string
    {
        try {
            $json = json_encode(self::redact($metadata), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            if (strlen($json) > self::METADATA_MAX_BYTES) {
                // Never lose the event because its metadata is large (an oversized value would make the INSERT fail):
                // keep the small top-level values, replace the big ones, and as a last resort store only a marker.
                $shrunk = [];
                foreach (self::redact($metadata) as $k => $v) {
                    $enc = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                    $shrunk[$k] = $enc !== false && strlen($enc) <= 1000 ? $v : '[truncated: ' . ($enc === false ? 'unencodable' : strlen($enc) . ' bytes') . ']';
                }
                $shrunk['_truncated'] = true;
                $json = json_encode($shrunk, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                if (strlen($json) > self::METADATA_MAX_BYTES) {
                    return json_encode(['_truncated' => true, '_original_bytes' => strlen($json)], JSON_THROW_ON_ERROR);
                }
            }

            return $json;
        } catch (\Throwable) {
            return '{"_error":"metadata could not be encoded"}';
        }
    }

    private static function redact(mixed $value, int $depth = 0): mixed
    {
        if (is_array($value) && $depth > 8) {
            return '[truncated]'; // deeper than we inspect: never store what we did not check for secrets
        }
        if (!is_array($value)) {
            return is_resource($value) ? '[unserializable]' : $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = is_string($k) && self::isSecretKey($k) ? '[redacted]' : self::redact($v, $depth + 1);
        }

        return $out;
    }

    private static function isSecretKey(string $key): bool
    {
        if (preg_match(self::SECRET_KEY, $key) === 1) {
            return true;
        }
        $n = preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? '';
        if (in_array($n, self::SECRET_NAMES, true)) {
            return true;
        }
        foreach (self::SECRET_SUFFIXES as $suffix) {
            if (str_ends_with($n, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
