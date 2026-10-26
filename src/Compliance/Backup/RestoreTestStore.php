<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Backup;

use RivetCore\Compliance\Profile\ProfileText;
use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Store\RmmDataStoreKind;

/**
 * Evidence that a backup can be restored: who tested, when, which stores, which backup, the row counts before and after, and the reconciliation of
 * `scripts/rmm-store-verify.php`. Append-only (a mistaken record is answered by a newer one, never edited), kept like a snapshot: never pruned.
 *
 * What counts as evidence is decided here, not by the person typing: a test is refused as "passed" when its row counts differ, so a passing record always
 * means the numbers matched. A test recorded without counts is accepted (a person may only have opened the restored application) but the restore-test check
 * reports it as incomplete.
 *
 * @api
 */
final class RestoreTestStore
{
    public const MAX_COUNT_ROWS = 200;
    public const MAX_VERIFY_BYTES = 1048576;
    public const NOTE_MAX = 2000;
    /** A restore test dated later than now plus this many seconds is refused (clock skew allowance). */
    public const FUTURE_TOLERANCE_S = 300;

    public function __construct(private DatabaseInterface $database, private ClockInterface $clock)
    {
    }

    /**
     * @param list<string> $stores {@see BackupStore} ids restored in this test
     * @param list<array{store:string, table:string, expected:int, restored:int}> $counts see {@see self::counts()}
     * @param array{kind:string, checked_at:string, clean:bool, finding_codes:array<string,int>, table_counts:array<string,int>}|null $verify from {@see self::verifySummary()}
     * @throws \InvalidArgumentException with a message safe to show
     */
    public function record(
        \DateTimeImmutable $testedAt,
        bool $passed,
        array $stores,
        string $dataStore,
        ?\DateTimeImmutable $backupTakenAt,
        array $counts,
        ?array $verify,
        ?int $durationS,
        ?string $note,
        ?int $userId,
        string $userName,
    ): int {
        $now = $this->clock->now();
        if ($testedAt->getTimestamp() > $now->getTimestamp() + self::FUTURE_TOLERANCE_S) {
            throw new \InvalidArgumentException('The restore test cannot be dated in the future.');
        }
        if ($testedAt->getTimestamp() < $now->getTimestamp() - 20 * 365 * 86400) {
            throw new \InvalidArgumentException('The restore test date is not plausible.');
        }
        if ($backupTakenAt !== null && $backupTakenAt->getTimestamp() > $testedAt->getTimestamp() + self::FUTURE_TOLERANCE_S) {
            throw new \InvalidArgumentException('The backup that was restored cannot be newer than the test.');
        }
        $stores = array_values(array_unique($stores));
        if ($stores === []) {
            throw new \InvalidArgumentException('Say which data stores were restored.');
        }
        foreach ($stores as $s) {
            if (!is_string($s) || !BackupStore::isValid($s)) {
                throw new \InvalidArgumentException('Unknown data store.');
            }
        }
        if (!in_array($dataStore, RmmDataStoreKind::ALL, true)) {
            throw new \InvalidArgumentException('Unknown RMM data store.');
        }
        if ($durationS !== null && ($durationS < 0 || $durationS > 30 * 86400)) {
            throw new \InvalidArgumentException('The duration is not plausible.');
        }
        $counts = self::counts($counts, $stores);
        $mismatch = array_filter($counts, static fn (array $c): bool => $c['expected'] !== $c['restored']);
        if ($passed && $mismatch !== []) {
            throw new \InvalidArgumentException('The restore test cannot be recorded as passed: ' . count($mismatch) . ' table(s) have different row counts after the restore. Record it as failed, or fix the restore and test again.');
        }
        $person = trim($userName);
        if ($person === '' || mb_strlen($person) > 200 || preg_match('/[\x00-\x1f\x7f]/', $person) === 1) {
            throw new \InvalidArgumentException('Enter who ran the restore test.');
        }
        $errors = [];
        $note = $note === null || trim($note) === '' ? null : ProfileText::clean($note, 'The note', 0, self::NOTE_MAX, true, $errors);
        if ($errors !== []) {
            throw new \InvalidArgumentException($errors[0]);
        }
        $utc = new \DateTimeZone('UTC');
        $fmt = static fn (\DateTimeImmutable $d): string => $d->setTimezone($utc)->format('Y-m-d H:i:s');

        return (int) $this->database->execute(
            'INSERT INTO compliance_restore_tests (tested_at, recorded_at, recorded_by, recorded_by_name, result, stores, data_store, backup_taken_at, duration_s, counts_json, verify_json, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fmt($testedAt), $fmt($now), $userId, $person, $passed ? 'pass' : 'fail', implode(',', $stores), $dataStore, $backupTakenAt === null ? null : $fmt($backupTakenAt), $durationS,
                $counts === [] ? null : json_encode($counts, JSON_UNESCAPED_SLASHES), $verify === null ? null : json_encode($verify, JSON_UNESCAPED_SLASHES), $note,
            ]
        )->insertId;
    }

    public function latest(): ?RestoreTestRecord
    {
        $r = $this->database->fetchOne('SELECT * FROM compliance_restore_tests ORDER BY tested_at DESC, test_id DESC LIMIT 1');

        return $r === null ? null : self::hydrate($r);
    }

    /** @return list<RestoreTestRecord> newest test first */
    public function list(int $limit = 50): array
    {
        return array_map(
            [self::class, 'hydrate'],
            $this->database->fetchAll('SELECT * FROM compliance_restore_tests ORDER BY tested_at DESC, test_id DESC LIMIT ?', [max(1, min(500, $limit))])
        );
    }

    /**
     * Validate and normalise the row counts of a restore test: `expected` is the number of rows in the source before the backup (or at the moment it was
     * taken), `restored` the number in the restored copy. At most {@see self::MAX_COUNT_ROWS} rows, each table once per store.
     *
     * @param array<array-key,mixed> $counts
     * @param list<string> $stores the stores of the test; a count for another store is refused
     * @return list<array{store:string, table:string, expected:int, restored:int}>
     */
    public static function counts(array $counts, array $stores): array
    {
        if (count($counts) > self::MAX_COUNT_ROWS) {
            throw new \InvalidArgumentException('At most ' . self::MAX_COUNT_ROWS . ' tables can be compared in one test.');
        }
        $out = [];
        $seen = [];
        foreach ($counts as $c) {
            if (!is_array($c) || array_diff(array_keys($c), ['store', 'table', 'expected', 'restored']) !== [] || !isset($c['store'], $c['table'], $c['expected'], $c['restored'])) {
                throw new \InvalidArgumentException('Each row count needs a store, a table, the expected and the restored number of rows.');
            }
            if (!is_string($c['store']) || !in_array($c['store'], $stores, true)) {
                throw new \InvalidArgumentException('A row count belongs to a data store that was not part of the test.');
            }
            if (!is_string($c['table']) || preg_match('/^[A-Za-z0-9_]{1,64}\z/D', $c['table']) !== 1) {
                throw new \InvalidArgumentException('A table name is not valid.');
            }
            foreach (['expected', 'restored'] as $k) {
                if (!is_int($c[$k]) || $c[$k] < 0) {
                    throw new \InvalidArgumentException('Row counts are whole numbers, zero or more.');
                }
            }
            $key = $c['store'] . '|' . $c['table'];
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('A table is listed twice in the row counts.');
            }
            $seen[$key] = true;
            $out[] = ['store' => $c['store'], 'table' => $c['table'], 'expected' => $c['expected'], 'restored' => $c['restored']];
        }

        return $out;
    }

    /**
     * Pair the row counts taken BEFORE the backup with those read from the restored copy. A table missing from the restored side counts as 0 rows (so a table
     * that was not restored is a mismatch, not silently skipped).
     *
     * @param array<string,int> $expected table => rows in the source
     * @param array<string,int> $restored table => rows in the restored copy
     * @return list<array{store:string, table:string, expected:int, restored:int}>
     */
    public static function pairCounts(string $store, array $expected, array $restored): array
    {
        $out = [];
        foreach ($expected as $table => $n) {
            $out[] = ['store' => $store, 'table' => (string) $table, 'expected' => (int) $n, 'restored' => (int) ($restored[$table] ?? 0)];
        }

        return $out;
    }

    /**
     * Reduce the JSON printed by `php scripts/rmm-store-verify.php --json` to what is kept as evidence: the store kind, when it ran, whether it was clean, how
     * many findings of each kind, and the row count of every hot table. Example device ids and finding text are dropped.
     *
     * @return array{kind:string, checked_at:string, clean:bool, finding_codes:array<string,int>, table_counts:array<string,int>}
     * @throws \InvalidArgumentException when the text is not that report
     */
    public static function verifySummary(string $json): array
    {
        if (strlen($json) > self::MAX_VERIFY_BYTES) {
            throw new \InvalidArgumentException('The reconciliation report is too large.');
        }
        try {
            $d = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('That is not the JSON printed by rmm-store-verify.php --json.');
        }
        if (!is_array($d) || !isset($d['kind'], $d['checked_at'], $d['findings'], $d['counts'], $d['clean']) || !is_string($d['kind']) || !in_array($d['kind'], RmmDataStoreKind::ALL, true)
            || !is_string($d['checked_at']) || preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\z/D', $d['checked_at']) !== 1 || !is_bool($d['clean']) || !is_array($d['findings']) || !is_array($d['counts'])) {
            throw new \InvalidArgumentException('That is not the JSON printed by rmm-store-verify.php --json.');
        }
        $codes = [];
        foreach ($d['findings'] as $f) {
            $code = is_array($f) ? ($f['code'] ?? null) : null;
            if (!is_string($code) || preg_match('/^[a-z_]{1,40}\z/D', $code) !== 1) {
                throw new \InvalidArgumentException('That is not the JSON printed by rmm-store-verify.php --json.');
            }
            $codes[$code] = ($codes[$code] ?? 0) + 1;
        }
        if ($d['clean'] !== ($d['findings'] === [])) {
            throw new \InvalidArgumentException('The reconciliation report contradicts itself (clean does not match its findings).');
        }
        $tables = [];
        foreach ($d['counts'] as $table => $n) {
            if (is_string($table) && preg_match('/^[a-z0-9_]{1,64}\z/D', $table) === 1 && is_int($n) && $n >= 0 && $table !== 'control_devices') {
                $tables[$table] = $n;
            }
        }

        return ['kind' => $d['kind'], 'checked_at' => $d['checked_at'], 'clean' => $d['clean'], 'finding_codes' => $codes, 'table_counts' => $tables];
    }

    /** @param array<string,mixed> $r */
    private static function hydrate(array $r): RestoreTestRecord
    {
        $utc = new \DateTimeZone('UTC');
        $dt = static fn (mixed $v): ?\DateTimeImmutable => $v === null || $v === '' ? null : new \DateTimeImmutable((string) $v, $utc);
        $counts = is_string($r['counts_json'] ?? null) ? json_decode($r['counts_json'], true) : null;
        $verify = is_string($r['verify_json'] ?? null) ? json_decode($r['verify_json'], true) : null;

        return new RestoreTestRecord(
            (int) $r['test_id'],
            $dt($r['tested_at']) ?? new \DateTimeImmutable('@0'),
            $dt($r['recorded_at']) ?? new \DateTimeImmutable('@0'),
            (string) $r['recorded_by_name'],
            (string) $r['result'] === 'pass',
            array_values(array_filter(explode(',', (string) $r['stores']), static fn (string $s): bool => BackupStore::isValid($s))),
            (string) $r['data_store'],
            $dt($r['backup_taken_at'] ?? null),
            $r['duration_s'] === null ? null : (int) $r['duration_s'],
            is_array($counts) ? $counts : [],
            is_array($verify) ? $verify : null,
            $r['note'] === null ? null : (string) $r['note'],
        );
    }
}
