<?php

declare(strict_types=1);

namespace RivetCore\Panel\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;
use RivetCore\Rmm\Migration\Migration0015EndpointAgentConverge;

/**
 * The RivetPanel device-token API (1.0.0-rc.34): `kind` ('agent' default | 'panel') and `contact_id` (NULL default) on
 * `endpoint_agent_enrollment_tokens` and `endpoint_agent_devices`, plus `panel_client_settings` and `panel_ticket_owner`
 * ({@see PanelSchema}). Purely additive and idempotent (guarded ADD COLUMN, CREATE TABLE IF NOT EXISTS, no row written). The RMM
 * device protocol is untouched: every existing row keeps kind 'agent' and the agent endpoints answer exactly as before.
 *
 * @internal
 */
final class Migration0040PanelApi implements MigrationInterface
{
    public function id(): string
    {
        return '0040_panel_api';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (PanelSchema::columns() as $key => $ddl) {
            [$table, $column] = explode('.', $key, 2);
            if (!Migration0015EndpointAgentConverge::hasColumn($database, $table, $column)) {
                $database->execute($ddl);
            }
        }
        foreach (PanelSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
