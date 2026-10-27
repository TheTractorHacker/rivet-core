<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * Optional: where the module's `rmm.*` events go. An edition implements it on its event bus (the one that feeds webhooks and
 * automation rules); the default {@see \RivetCore\Rmm\Support\NullRmmEvents} drops them, and while it is in place the module does no
 * extra work to detect them. The ids and payload fields are listed in {@see \RivetCore\Rmm\RmmEvent} and in Core's
 * {@see \RivetCore\Webhooks\EventCatalog} (group "rmm").
 *
 * Delivery is best effort and happens AFTER the database change that caused the event has been committed. An exception thrown by the
 * implementation is logged and swallowed: an event bus can never fail a check-in, a job report or a cron run.
 *
 * @api
 */
interface RmmEventsInterface
{
    /**
     * @param string $event one of the {@see \RivetCore\Rmm\RmmEvent} ids, e.g. "rmm.device.enrolled"
     * @param array<string,mixed> $payload always has device_id, asset_id (int or null), client_id, hostname and occurred_at (RFC 3339 UTC),
     *        plus the event's own fields; values are scalars, null or lists of scalars
     */
    public function publish(string $event, array $payload): void;
}
