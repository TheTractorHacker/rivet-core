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
    /** MariaDB or MySQL, the same database as everything else. The default. */
    public const MARIADB = 'mariadb';
    /** PostgreSQL (optionally TimescaleDB), a second database the edition passes a connection to (docs/rmm/POSTGRES.md). */
    public const POSTGRES = 'postgres';

    public const ALL = [self::MARIADB, self::POSTGRES];

    /** Whether this release can build the store (PostgreSQL needs a connection from the edition on top). */
    public static function available(string $kind): bool
    {
        return in_array($kind, self::ALL, true);
    }

    /**
     * Whether an administrator may SELECT it through {@see \RivetCore\Rmm\Settings\RmmSettings::update()}. Phase 2 of #89 ships the PostgreSQL stores; choosing them from the
     * settings (with the connection setup, a connection test and the migration command) is phase 3, so only MariaDB is selectable there for now.
     */
    public static function selectable(string $kind): bool
    {
        return $kind === self::MARIADB;
    }
}
