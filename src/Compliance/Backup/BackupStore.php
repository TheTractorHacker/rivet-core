<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Backup;

use RivetCore\Rmm\Store\RmmDataStoreKind;

/**
 * The data stores a backup can cover. The ids are part of {@see BackupFacts} and of recorded restore tests: never rename or reuse one.
 *
 * @api
 */
final class BackupStore
{
    /** The control database: tickets, assets, settings, audit events and, when the RMM data store is MariaDB, the hot RMM tables too. */
    public const CONTROL_DB = 'control_db';
    /** The PostgreSQL database of the RMM data store (only when `rmm_data_store` is `postgres`). */
    public const RMM_POSTGRES = 'rmm_postgres';

    public const LABELS = [self::CONTROL_DB => 'Control database', self::RMM_POSTGRES => 'PostgreSQL RMM store'];

    /** @return list<string> every store a backup must cover for the given RMM data store kind ({@see RmmDataStoreKind}) */
    public static function required(string $rmmDataStoreKind): array
    {
        return $rmmDataStoreKind === RmmDataStoreKind::POSTGRES ? [self::CONTROL_DB, self::RMM_POSTGRES] : [self::CONTROL_DB];
    }

    public static function isValid(string $id): bool
    {
        return isset(self::LABELS[$id]);
    }
}
