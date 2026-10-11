<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * RMM Phase 6 and 8: the file-transfer ledger, the remote-tools settings row, and the log-collection tables (events, per-device counters,
 * alert rules). Purely additive and idempotent: every statement is CREATE TABLE IF NOT EXISTS and nothing existing is altered, so a second run,
 * or an install that already has some of the tables, changes nothing. The tables stay empty (and cost nothing) until the `filetransfer` or
 * `logs` sub-switch is turned on and used. (Number 0021 belongs to the RMM scale work.)
 *
 * @internal
 */
final class Migration0022RemoteAndLogs implements MigrationInterface
{
    public function id(): string
    {
        return '0022_rmm_remote_and_logs';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (RemoteSchema::tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
