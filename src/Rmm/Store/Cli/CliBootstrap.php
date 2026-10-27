<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Cli;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Rmm\Store\Postgres\Pdo\PdoPostgresConnection;
use RivetCore\Rmm\Store\Postgres\PostgresConfig;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;

/**
 * What the command line tools of the RMM data store (`scripts/rmm-store-migrate.php`, `rmm-store-verify.php`) need from the edition: the control database, the clock and,
 * optionally, the SecretBox (to unseal the stored PostgreSQL password), an audit sink and an explicit PostgreSQL connection. Core cannot construct these (the database
 * adapter, the box key and the audit table belong to the edition), so the edition gives the tools ONE small PHP file that returns them:
 *
 *   <?php return [
 *       'database'  => $db,              // RivetCore\Database\DatabaseInterface, required (the control database)
 *       'clock'     => $clock,           // RivetCore\Contracts\ClockInterface, required
 *       'secretBox' => $box,             // SecretBoxInterface, needed only to use the password stored by the data store setting
 *       'audit'     => $audit,           // RmmAuditInterface, optional (default: none)
 *       'postgres'  => $conn,            // PostgresConnectionInterface | PostgresConfig | Closure returning either; optional, overrides the stored connection
 *   ];
 *
 * The file is `require`d, so it is code: keep it readable by the service account only, exactly like the edition's own configuration.
 *
 * @api
 */
final class CliBootstrap
{
    public function __construct(
        public readonly DatabaseInterface $database,
        public readonly ClockInterface $clock,
        public readonly ?SecretBoxInterface $secretBox = null,
        public readonly ?RmmAuditInterface $audit = null,
        public readonly ?PostgresConnectionInterface $postgres = null,
    ) {
    }

    /** @throws \InvalidArgumentException when the file is missing or does not return what is described above */
    public static function load(string $file): self
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new \InvalidArgumentException('The bootstrap file ' . $file . ' does not exist or is not readable.');
        }
        $cfg = (static function (string $f): mixed {
            return require $f;
        })($file);

        return self::fromArray($cfg);
    }

    /** @throws \InvalidArgumentException */
    public static function fromArray(mixed $cfg): self
    {
        if (!is_array($cfg) || !($cfg['database'] ?? null) instanceof DatabaseInterface || !($cfg['clock'] ?? null) instanceof ClockInterface) {
            throw new \InvalidArgumentException('The bootstrap file must return an array with "database" (DatabaseInterface) and "clock" (ClockInterface); see CliBootstrap.');
        }
        $pg = $cfg['postgres'] ?? null;
        if ($pg instanceof \Closure) {
            $pg = $pg();
        }
        if ($pg instanceof PostgresConfig) {
            $pg = PdoPostgresConnection::fromConfig($pg);
        }
        if ($pg !== null && !$pg instanceof PostgresConnectionInterface) {
            throw new \InvalidArgumentException('The bootstrap "postgres" entry must be a PostgresConnectionInterface, a PostgresConfig or a closure returning one.');
        }
        $box = $cfg['secretBox'] ?? null;
        $audit = $cfg['audit'] ?? null;

        return new self($cfg['database'], $cfg['clock'], $box instanceof SecretBoxInterface ? $box : null, $audit instanceof RmmAuditInterface ? $audit : null, $pg);
    }
}
