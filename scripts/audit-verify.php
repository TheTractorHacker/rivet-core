<?php

declare(strict_types=1);

/*
 * Verify the audit log hash chain (baseline ERR-04) from the command line. Read-only.
 *
 *   RIVETCORE_DB_HOST=127.0.0.1 RIVETCORE_DB_USER=app RIVETCORE_DB_PASS=... RIVETCORE_DB_NAME=itflow \
 *     php vendor/rivet/rivet-core/scripts/audit-verify.php [--json] [--export-head] [--anchor=FILE | --anchor=ID:HASH]
 *
 *   Environment: RIVETCORE_DB_HOST (default 127.0.0.1), RIVETCORE_DB_PORT, RIVETCORE_DB_USER, RIVETCORE_DB_PASS, RIVETCORE_DB_NAME (required),
 *   RIVETCORE_AUDIT_CHAIN_KEY = the HMAC key as hex (AuditChainHasher::deriveKey(), bin2hex'd) when the chain was started with a key.
 *
 *   --export-head   print the newest chained row as JSON ({"id":..,"hash":..}) to store OFF the database, then exit 0
 *   --anchor=...    also check the chain against a head exported earlier (a JSON file from --export-head, or ID:HASH)
 *   --json          machine-readable result
 *
 * Exit status: 0 verified (or the chain has not started), 1 not intact / unverifiable, 2 usage or connection error.
 */

require is_file(__DIR__ . '/../vendor/autoload.php') ? __DIR__ . '/../vendor/autoload.php' : __DIR__ . '/../../../autoload.php';

use RivetCore\Audit\AuditChainVerifier;
use RivetCore\Database\DatabaseException;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Database\ExecutionResult;

$json = false;
$export = false;
$anchorArg = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--json') {
        $json = true;
    } elseif ($a === '--export-head') {
        $export = true;
    } elseif (str_starts_with($a, '--anchor=')) {
        $anchorArg = substr($a, 9);
    } else {
        fwrite(STDERR, "usage: audit-verify.php [--json] [--export-head] [--anchor=FILE|ID:HASH]\n");
        exit(2);
    }
}
$name = getenv('RIVETCORE_DB_NAME');
if (!is_string($name) || $name === '') {
    fwrite(STDERR, "RIVETCORE_DB_NAME is required\n");
    exit(2);
}
$keyHex = getenv('RIVETCORE_AUDIT_CHAIN_KEY');
$key = null;
if (is_string($keyHex) && $keyHex !== '') {
    $key = ctype_xdigit($keyHex) && strlen($keyHex) % 2 === 0 ? hex2bin($keyHex) : null;
    if ($key === null || $key === false) {
        fwrite(STDERR, "RIVETCORE_AUDIT_CHAIN_KEY must be hex\n");
        exit(2);
    }
}

$anchor = null;
if ($anchorArg !== null && $anchorArg !== '') {
    if (is_file($anchorArg)) {
        $d = json_decode((string) file_get_contents($anchorArg), true);
        $anchor = is_array($d) && isset($d['id'], $d['hash']) ? ['id' => (int) $d['id'], 'hash' => (string) $d['hash']] : null;
    } elseif (preg_match('/^(\d+):([0-9a-f]{64})$/i', $anchorArg, $m) === 1) {
        $anchor = ['id' => (int) $m[1], 'hash' => strtolower($m[2])];
    }
    if ($anchor === null) {
        fwrite(STDERR, "--anchor must be a JSON file from --export-head or ID:HASH\n");
        exit(2);
    }
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli = new mysqli(getenv('RIVETCORE_DB_HOST') ?: '127.0.0.1', getenv('RIVETCORE_DB_USER') ?: 'root', getenv('RIVETCORE_DB_PASS') ?: '', $name, (int) (getenv('RIVETCORE_DB_PORT') ?: 0));
    $mysqli->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not connect to the database: ' . $e->getMessage() . "\n");
    exit(2);
}

/** Minimal read-only DatabaseInterface over mysqli, so this script has no dependency on an edition's adapter. */
$db = new class($mysqli) implements DatabaseInterface {
    public function __construct(private mysqli $m)
    {
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->fetchAll($sql, $params)[0] ?? null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        try {
            $st = $this->m->prepare($sql);
            if ($params !== []) {
                $types = '';
                foreach ($params as $p) {
                    $types .= is_int($p) ? 'i' : 's';
                }
                $st->bind_param($types, ...array_values($params));
            }
            $st->execute();
            $res = $st->get_result();
            $rows = $res === false ? [] : $res->fetch_all(MYSQLI_ASSOC);
            $st->close();

            return $rows;
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public function execute(string $sql, array $params = []): ExecutionResult
    {
        throw new DatabaseException('audit-verify.php is read-only.');
    }

    public function transaction(callable $callback): mixed
    {
        return $callback();
    }
};

$verifier = new AuditChainVerifier($db, $key);
try {
    if ($export) {
        $head = $verifier->exportHead();
        if ($head === null) {
            fwrite(STDERR, "No audit row has been chained yet.\n");
            exit(1);
        }
        echo json_encode($head, JSON_UNESCAPED_SLASHES), "\n";
        exit(0);
    }
    $r = $verifier->verify($anchor);
} catch (Throwable $e) {
    fwrite(STDERR, 'Verification failed to run: ' . $e->getMessage() . "\n");
    exit(2);
}

if ($json) {
    echo json_encode($r->toArray(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
} else {
    echo 'Audit chain: ' . strtoupper($r->status) . "\n";
    echo "  chained rows verified: {$r->chained}\n";
    echo "  unchained legacy rows: {$r->legacyUnchained}\n";
    echo "  unchained rows after the chain started: {$r->unchainedAfterStart}\n";
    if ($r->firstBrokenId !== null) {
        echo "  first broken row id: {$r->firstBrokenId}\n";
    }
    if ($r->headId !== null) {
        echo "  head: row #{$r->headId} {$r->headHash}\n";
    }
    echo '  external anchor: ' . $r->anchor . "\n";
    if ($r->reason !== '') {
        echo '  ' . $r->reason . "\n";
    }
}
exit($r->ok ? 0 : 1);
