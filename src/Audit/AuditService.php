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

        $this->database->execute(
            'INSERT INTO audit_events
                (event_type, actor_user_id, entity_type, entity_id, action, summary, metadata_json, ip_address, user_agent, request_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $eventType,
                $actorUserId,
                $entityType,
                $entityId === null ? null : (string) $entityId,
                $action,
                $summary === null ? null : mb_substr($summary, 0, self::SUMMARY_MAX),
                $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
                $this->request->ipAddress(),
                $userAgent === null ? null : mb_substr($userAgent, 0, self::USER_AGENT_MAX),
                $this->request->requestId(),
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
}
