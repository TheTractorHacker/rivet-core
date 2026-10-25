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
     * Whether {@see \RivetCore\Rmm\Settings\RmmSettings::update()} accepts the value. Only MariaDB: PostgreSQL is chosen through {@see DataStoreSwitch::switchTo()}, which tests the
     * connection and creates the schema first (phase 3 of #89).
     */
    public static function selectable(string $kind): bool
    {
        return $kind === self::MARIADB;
    }
}
