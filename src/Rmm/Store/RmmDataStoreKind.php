<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * The values of the `rmm_data_store` setting: where the HOT RMM tables live (docs/design/rmm-data-store.md, issue #89).
 *
 * @api
 */
final class RmmDataStoreKind
{
    /** MariaDB or MySQL, the same database as everything else. The default, and the only store that exists in this release. */
    public const MARIADB = 'mariadb';
    /** PostgreSQL (optionally TimescaleDB). Reserved: selecting it is refused with a clear error until the implementation ships. */
    public const POSTGRES = 'postgres';

    public const ALL = [self::MARIADB, self::POSTGRES];

    /** Whether this release can actually run the store. */
    public static function available(string $kind): bool
    {
        return $kind === self::MARIADB;
    }
}
