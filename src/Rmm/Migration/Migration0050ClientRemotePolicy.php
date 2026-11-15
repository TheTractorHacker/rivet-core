<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * The per-client remote-session policy (allow, notify, approve): one new Core-owned table, see {@see RemotePolicySchema}. Additive and idempotent (CREATE TABLE IF NOT
 * EXISTS). Nothing existing is altered and no setting is switched on. Numbering: 0045 is absent from the list on purpose (it was reserved for the reporting views, which
 * shipped as 0047), so this is 0050 (0049 is the installer "managed by another RMM" column).
 *
 * @internal
 */
final class Migration0050ClientRemotePolicy implements MigrationInterface
{
    public function id(): string
    {
        return '0050_rmm_client_remote_policy';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (RemotePolicySchema::tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
