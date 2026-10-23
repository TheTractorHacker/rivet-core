<?php

declare(strict_types=1);

namespace RivetCore\Panel;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Support\Sql;

/**
 * The per-client panel switch: may a CLIENT-LEVEL device (one enrolled without a contact) create tickets? Default OFF. With it off such a device can
 * read the category list and nothing else.
 *
 * @api
 */
final class PanelSettings
{
    public function __construct(private readonly Sql $sql, private readonly RmmAuditInterface $audit)
    {
    }

    public function allowClientLevelTickets(int $clientId): bool
    {
        return (int) $this->sql->val('SELECT allow_client_level_tickets FROM panel_client_settings WHERE client_id = ?', [$clientId]) === 1;
    }

    public function setAllowClientLevelTickets(int $clientId, bool $allow, int $userId): void
    {
        $this->sql->run('INSERT INTO panel_client_settings (client_id, allow_client_level_tickets, updated_at, updated_by) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE allow_client_level_tickets = VALUES(allow_client_level_tickets), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$clientId, $allow ? 1 : 0, $this->sql->utcNow(), $userId]);
        $this->audit->record('Panel Setting Changed', 'Tickets from client-level panel devices ' . ($allow ? 'allowed' : 'disallowed') . " for client $clientId by user $userId", $clientId, 0);
    }
}
