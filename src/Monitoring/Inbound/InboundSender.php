<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * Who sent a delivery, as {@see InboundAuthenticator::identify()} worked it out from the secret.
 *
 *  - The shared sender ({@see self::shared()}, id ''): single-secret mode. Whoever holds a shared secret is trusted for EVERY monitor and every client:
 *    the incidents are keyed by (source, monitor id) as before, any holder can resolve or silence any monitor's incident and route a new ticket to any client
 *    the map knows. Use it only when every sender is operated by the MSP itself.
 *  - A named sender ({@see self::named()}): a secret of its own. Its incidents are keyed by (source, sender, monitor id), so it can neither see, resolve nor
 *    flap another sender's incidents, even with the same monitor ids. With a client list it may only open tickets for those clients: a monitor the map sends
 *    elsewhere is refused (`out_of_scope`), an unmapped one goes to its only client (or the default target when that is one of its clients, else unassigned).
 *    With no client list (null) it may report for any client but still only touches its own incidents.
 *
 * Red team 2026-11-21 Chain A / NEW-1. The id is part of the incident key, `"<id>/<monitor id>"`, which fits the editions' 32-character key column.
 *
 * @api
 */
final class InboundSender
{
    /** Lower-case letters, digits and '-', at most 12 characters (so "<id>/<18-digit monitor id>" stays within 32). */
    public const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,11}$/D';

    /** @param list<int>|null $clientIds */
    private function __construct(public readonly string $id, public readonly ?array $clientIds)
    {
    }

    /** Single-secret mode: trusted for every monitor and every client. */
    public static function shared(): self
    {
        return new self('', null);
    }

    /**
     * @param array<mixed>|null $clientIds the clients this sender may report for (positive integers); null = any client (its own incidents only); [] = none (unassigned only)
     * @throws \InvalidArgumentException for an id outside {@see self::ID_PATTERN} or a client id that is not a positive integer
     */
    public static function named(string $id, ?array $clientIds = null): self
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException('A sender id is 1 to 12 lower-case letters, digits or dashes.');
        }
        if ($clientIds === null) {
            return new self($id, null);
        }
        $ids = [];
        foreach ($clientIds as $c) {
            if (!is_int($c) || $c < 1) {
                throw new \InvalidArgumentException('A sender\'s client ids must be positive integers.');
            }
            $ids[$c] = $c;
        }

        return new self($id, array_values($ids));
    }

    public function isShared(): bool
    {
        return $this->id === '';
    }

    /** Whether this sender may open a ticket for the client (always true for the shared sender and a named sender with no client list). */
    public function mayReportFor(int $clientId): bool
    {
        return $this->clientIds === null || in_array($clientId, $this->clientIds, true);
    }

    /** The incident key of one of this sender's monitors: the monitor id itself for the shared sender (unchanged keys), else "<id>/<monitor id>". */
    public function incidentKey(string $monitorKey): string
    {
        return $this->isShared() ? $monitorKey : $this->id . '/' . $monitorKey;
    }
}
