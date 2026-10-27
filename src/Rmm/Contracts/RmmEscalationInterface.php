<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * Optional (Phase 3): the edition's side of alert escalation. Alerts and tickets stay in the edition, so Core never sends a message
 * itself. It decides WHEN an escalation step is due ({@see \RivetCore\Rmm\Alerting\EscalationService}, run by housekeeping, never inside a
 * check-in) and WHO is addressed, and hands the notification to this interface; the edition delivers it with whatever it has (email, chat,
 * its notification centre). The default {@see \RivetCore\Rmm\Support\NullRmmEscalation} delivers nothing and says so, which keeps the
 * step due: nothing is silently marked as sent. RmmBridgeInterface is unchanged; an edition that does not escalate implements nothing.
 *
 * Every method may be called at any time, more than once for the same thing, and must be idempotent. None may throw for an unknown
 * alert (return quietly). An exception is logged by Core and counts as "not delivered".
 *
 * @api
 */
interface RmmEscalationInterface
{
    /**
     * Deliver one escalation notification.
     *
     * $notification has exactly these keys: alert_id (int), device_id (int), asset_id (?int), client_id (int), hostname (string),
     * check_key (string), severity ("warning" or "error"), message (string, at most 500 characters), state ("open"), step (int, the
     * 1-based number of the policy step; for a repeat the number of the last step), repeat (bool), policy_id (int), policy_name (string),
     * targets (list of {type:string, ref:string}: type is "user", "group", "email", "chat" or "webhook", ref is the id, address or channel),
     * opened_at (RFC 3339 UTC) and notified_at (RFC 3339 UTC).
     *
     * @param array<string,mixed> $notification
     * @return bool true when the notification was delivered or durably queued; false when it was not (Core retries after a minute, at
     *         most 5 times per step, then moves on)
     */
    public function notify(int $integrationId, array $notification): bool;

    /** An alert was acknowledged in Core (by $userId): mark the alert acknowledged in the edition so its own UI agrees. */
    public function acknowledgeAlert(int $integrationId, int $alertId, int $userId): void;

    /** An open alert became more (or less) severe: $severity is "warning" or "error". Update the edition's alert. */
    public function raiseAlertSeverity(int $integrationId, int $alertId, string $severity): void;
}
