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
                ($this->afterLog)($eventType, $actorUserId, $entityType, $entityId === null ? null : (string) $entityId, $action, $summary, $metadata);
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
            return json_encode(self::redact($metadata), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return '{"_error":"metadata could not be encoded"}';
        }
    }

    private static function redact(mixed $value, int $depth = 0): mixed
    {
        if (!is_array($value) || $depth > 8) {
            return is_resource($value) ? '[unserializable]' : $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = is_string($k) && preg_match(self::SECRET_KEY, $k) ? '[redacted]' : self::redact($v, $depth + 1);
        }

        return $out;
    }
}
