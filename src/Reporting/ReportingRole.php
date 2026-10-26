<?php

declare(strict_types=1);

namespace RivetCore\Reporting;

/**
 * SQL that creates (or rotates, or drops) the read-only reporting account of the `rpt_` views (#91 phase 2).
 *
 * This class RETURNS statements and executes nothing: the edition runs them with its own administrative database credentials (or shows them to the administrator).
 * It never stores a password: {@see generatePassword()} returns a new one for the caller to show once and to pass back in; nothing here writes it anywhere.
 *
 * What the account can do: connect, `SELECT` on the `rpt_` views of the database it is created for (MariaDB / MySQL: the views of one schema; PostgreSQL: the views
 * of one schema), nothing else. It has no grant on any base table, no `INSERT`/`UPDATE`/`DELETE`/DDL anywhere, a small connection limit, and (PostgreSQL) a read-only
 * default transaction and a statement timeout. The views run with their owner's rights, so the account needs nothing on the tables behind them.
 *
 * Statements are idempotent: running them again rotates the password and re-grants exactly the listed views, and a grant that was added by hand to the same account
 * is revoked first (`REVOKE ALL`), so the account always ends in the documented state.
 *
 * Every identifier and the password are validated against a strict pattern before they are put into SQL, so no quoting trick can break out of a literal.
 *
 * @api
 */
final class ReportingRole
{
    public const DEFAULT_USER = 'rivet_reporting';
    public const DEFAULT_MAX_CONNECTIONS = 5;
    /** PostgreSQL only: seconds before a reporting statement is cancelled (a dashboard that scans the raw history should fail, not pin the server). */
    public const DEFAULT_STATEMENT_TIMEOUT_S = 120;

    private const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_.~+';

    /**
     * A new random password of $length characters from an alphabet that needs no quoting in SQL, in a connection string or in Power BI's credential dialog.
     * Returned to the caller; never stored here.
     */
    public static function generatePassword(int $length = 32): string
    {
        if ($length < 20 || $length > 128) {
            throw new \InvalidArgumentException('A reporting password must be 20 to 128 characters.');
        }
        $a = self::PASSWORD_ALPHABET;
        $out = '';
        for ($i = 0; $i < $length; ++$i) {
            $out .= $a[random_int(0, strlen($a) - 1)];
        }

        return $out;
    }

    /**
     * MariaDB / MySQL.
     *
     * @param string $database the control database (schema) that holds the views
     * @param string $host the account's host part: `%` (any), a name, an address or a pattern such as `10.0.5.%`. Prefer a specific address (the Power BI gateway).
     * @param bool $requireTls `REQUIRE SSL`: the server refuses this account over a connection without TLS
     * @param list<string>|null $views the view names to grant (default: every view of the control database)
     * @return list<string>
     */
    public static function mariadbStatements(string $database, string $password, string $user = self::DEFAULT_USER, string $host = '%', bool $requireTls = true, ?array $views = null, int $maxConnections = self::DEFAULT_MAX_CONNECTIONS): array
    {
        self::ident($database, 64, 'database');
        self::ident($user, 32, 'user');
        self::password($password);
        if (preg_match('/^[A-Za-z0-9_.%:\-]{1,60}$/', $host) !== 1) {
            throw new \InvalidArgumentException('The host part may contain letters, digits and . _ % : - only.');
        }
        if ($maxConnections < 1 || $maxConnections > 100) {
            throw new \InvalidArgumentException('Connection limit must be 1 to 100.');
        }
        $views = self::views($views, 'mariadb');
        $acct = "'" . $user . "'@'" . $host . "'";
        $tls = $requireTls ? 'REQUIRE SSL' : 'REQUIRE NONE';
        $s = [
            "CREATE USER IF NOT EXISTS $acct IDENTIFIED BY '" . $password . "'",
            "ALTER USER $acct IDENTIFIED BY '" . $password . "' $tls WITH MAX_USER_CONNECTIONS $maxConnections",
            "REVOKE ALL PRIVILEGES, GRANT OPTION FROM $acct",
        ];
        foreach ($views as $v) {
            $s[] = 'GRANT SELECT ON `' . $database . '`.`' . $v . "` TO $acct";
        }

        return $s;
    }

    /**
     * PostgreSQL. Run as a role that may create roles and that owns the views (or is a member of the owner).
     *
     * @param string $schema the schema that holds the views (default `public`)
     * @param list<string>|null $views the view names to grant (default: every view that exists in the PostgreSQL store)
     * @return list<string>
     */
    public static function postgresStatements(string $database, string $password, string $user = self::DEFAULT_USER, string $schema = 'public', ?array $views = null, int $maxConnections = self::DEFAULT_MAX_CONNECTIONS, int $statementTimeoutSeconds = self::DEFAULT_STATEMENT_TIMEOUT_S): array
    {
        self::ident($database, 63, 'database');
        self::ident($user, 63, 'user');
        self::ident($schema, 63, 'schema');
        self::password($password);
        if ($maxConnections < 1 || $maxConnections > 100 || $statementTimeoutSeconds < 1 || $statementTimeoutSeconds > 3600) {
            throw new \InvalidArgumentException('Connection limit must be 1 to 100 and the statement timeout 1 to 3600 seconds.');
        }
        $views = self::views($views, 'postgres');
        $u = '"' . $user . '"';
        $q = '"' . $schema . '"';
        $s = [
            "DO \$do\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '" . $user . "') THEN CREATE ROLE $u LOGIN; END IF; END \$do\$",
            "ALTER ROLE $u WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION CONNECTION LIMIT $maxConnections PASSWORD '" . $password . "'",
            "ALTER ROLE $u SET default_transaction_read_only = on",
            "ALTER ROLE $u SET statement_timeout = '" . $statementTimeoutSeconds . "s'",
            "ALTER ROLE $u SET idle_in_transaction_session_timeout = '60s'",
            "ALTER ROLE $u SET TimeZone = 'UTC'",
            "REVOKE ALL ON ALL TABLES IN SCHEMA $q FROM $u",
            'GRANT CONNECT ON DATABASE "' . $database . "\" TO $u",
            "GRANT USAGE ON SCHEMA $q TO $u",
        ];
        foreach ($views as $v) {
            $s[] = "GRANT SELECT ON $q.\"" . $v . "\" TO $u";
        }

        return $s;
    }

    /** @return list<string> */
    public static function dropMariadbStatements(string $user = self::DEFAULT_USER, string $host = '%'): array
    {
        self::ident($user, 32, 'user');
        if (preg_match('/^[A-Za-z0-9_.%:\-]{1,60}$/', $host) !== 1) {
            throw new \InvalidArgumentException('The host part may contain letters, digits and . _ % : - only.');
        }

        return ["DROP USER IF EXISTS '" . $user . "'@'" . $host . "'"];
    }

    /**
     * Dropping a PostgreSQL role fails while it holds grants; the REVOKEs come first.
     *
     * @return list<string>
     */
    public static function dropPostgresStatements(string $database, string $user = self::DEFAULT_USER, string $schema = 'public'): array
    {
        self::ident($database, 63, 'database');
        self::ident($user, 63, 'user');
        self::ident($schema, 63, 'schema');
        $u = '"' . $user . '"';

        return ["REVOKE ALL ON ALL TABLES IN SCHEMA \"$schema\" FROM $u", "REVOKE USAGE ON SCHEMA \"$schema\" FROM $u", 'REVOKE CONNECT ON DATABASE "' . $database . "\" FROM $u", "DROP ROLE IF EXISTS $u"];
    }

    /**
     * What SQL cannot enforce, to show next to the statements.
     *
     * @return list<string>
     */
    public static function notes(string $dialect): array
    {
        $common = [
            'Reporting data is a copy of your whole fleet\'s inventory and health. Open the database port only to the Power BI gateway or to a named address, never to the internet.',
            'Use a dedicated account for reporting; do not reuse the application account. Rotate the password by running the statements again with a new one.',
        ];
        if ($dialect === 'postgres') {
            return [...$common,
                'TLS cannot be required by SQL on PostgreSQL: use `hostssl` (not `host`) for this role in pg_hba.conf, e.g. `hostssl <database> ' . self::DEFAULT_USER . ' <gateway address>/32 scram-sha-256`, and set ssl = on in postgresql.conf.',
                'On PostgreSQL 14 and older every role may CREATE objects in schema public by default; run `REVOKE CREATE ON SCHEMA public FROM PUBLIC` (PostgreSQL 15 and newer already do).',
            ];
        }

        return [...$common,
            'The account is created with REQUIRE SSL by default: the server must have TLS configured (ssl_cert / ssl_key, or the automatic certificates of MySQL 8).',
            'Bind the host part to the gateway\'s address instead of `%` wherever you can.',
        ];
    }

    private static function ident(string $v, int $max, string $what): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $v) !== 1 || strlen($v) > $max) {
            throw new \InvalidArgumentException("The $what name must start with a letter and contain letters, digits and underscores only (at most $max characters).");
        }
    }

    private static function password(string $p): void
    {
        if (preg_match('/^[A-Za-z0-9\-_.~+]{20,128}$/D', $p) !== 1) {
            throw new \InvalidArgumentException('The password must be 20 to 128 characters from A-Z a-z 0-9 - _ . ~ + (use ReportingRole::generatePassword()).');
        }
    }

    /**
     * @param list<string>|null $views
     * @return list<string>
     */
    private static function views(?array $views, string $dialect): array
    {
        $known = ReportingSchema::viewNames($dialect);
        $views ??= $known;
        if ($views === []) {
            throw new \InvalidArgumentException('Grant at least one view.');
        }
        foreach ($views as $v) {
            if (!in_array($v, $known, true)) {
                throw new \InvalidArgumentException('Not a reporting view of this store: ' . $v);
            }
        }

        return array_values(array_unique($views));
    }
}
