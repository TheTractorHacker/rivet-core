<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Compliance\Backup\BackupFactsProviderInterface;
use RivetCore\Compliance\Backup\BackupStore;
use RivetCore\Compliance\Backup\RestoreTestRecord;
use RivetCore\Compliance\Backup\RestoreTestStore;
use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\RequirementsProviderInterface;
use RivetCore\Compliance\Profile\RequirementType;
use RivetCore\Contracts\ClockInterface;

/**
 * Was a backup restored and checked within the profile's number of days? The evidence is the newest test, recorded in the compliance module
 * ({@see RestoreTestStore}) or reported by the edition's own scheduled restore test ({@see \RivetCore\Compliance\Backup\BackupFacts::$lastRestoreTest}). It passes only when
 * that test passed, is recent, restored every data store in use, and compared row counts that matched. A test that passed without row counts, or that
 * left out the PostgreSQL store, is a warning ("incomplete evidence"); a failed or missing test, or an old one, is a failure.
 *
 * @api
 */
final class RestoreTestRecencyCheck extends AbstractProfileCheck
{
    /** @param \Closure(): string $dataStoreKind the active RMM data store */
    public function __construct(RequirementsProviderInterface $profiles, private RestoreTestStore $tests, private ?BackupFactsProviderInterface $facts, ClockInterface $clock, private \Closure $dataStoreKind, string $settingsPath = '')
    {
        parent::__construct($profiles, $clock, $settingsPath);
    }

    public function id(): string
    {
        return 'restore_test_recency';
    }

    public function title(): string
    {
        return 'A restore test was done recently';
    }

    public function category(): string
    {
        return 'Backup and recovery';
    }

    public function why(): string
    {
        return 'A backup nobody has restored is a hope, not a backup. The test proves the files are readable and the data is all there.';
    }

    public function controls(): array
    {
        return ['iso27001' => ['A.8.13'], 'soc2' => ['A1.3'], 'hipaa' => ['164.308(a)(7)(ii)(D)']];
    }

    public function requirementTypes(): array
    {
        return [RequirementType::RESTORE_TEST_MAX_AGE];
    }

    protected function evaluate(FrameworkProfile $profile): CheckResult
    {
        $days = $profile->requirement(RequirementType::RESTORE_TEST_MAX_AGE)?->value ?? 90;
        $latest = $this->latest();
        if ($latest === null) {
            return CheckResult::fail('No restore test has been recorded.', 'Not met: the framework requires a restore test every ' . $days . ' days and none is on record.', $this->settingsPath ?: null, ['required_days' => $days]);
        }
        ['at' => $at, 'passed' => $passed, 'stores' => $stores, 'compared' => $compared, 'match' => $match, 'who' => $who, 'extra' => $extra] = $latest;
        $age = $this->ageSeconds($at);
        $m = ['age_days' => round($age / 86400, 1), 'max_days' => $days, 'passed' => $passed, 'counts_compared' => $compared];
        $what = 'Evidence: restore test ' . self::utc($at) . ($who !== '' ? ' by ' . $who : '') . ' (' . self::span($age) . ' ago), restored ' . ($stores === [] ? 'no store reported' : implode(', ', array_map(static fn (string $s): string => BackupStore::LABELS[$s], $stores))) . '.';
        if (!$passed) {
            return CheckResult::fail('The last restore test failed.', "Not met: the newest restore test did not succeed.\n" . $what, $this->settingsPath ?: null, $m);
        }
        if ($age > $days * 86400) {
            return CheckResult::fail('The last restore test was ' . self::span($age) . ' ago; the limit is ' . $days . ' days.', "Not met: the restore test is too old.\n" . $what, $this->settingsPath ?: null, $m);
        }
        $required = BackupStore::required(($this->dataStoreKind)());
        $missing = array_values(array_diff($required, $stores));
        $lines = ['Met: a restore test passed within ' . $days . ' days.', $what];
        $problems = [];
        if ($compared && $match) {
            $lines[] = 'Met: the row counts of the restored data match the source.';
        } elseif ($compared) {
            $problems[] = 'the row counts differ';
        } else {
            $problems[] = 'no row counts were compared';
        }
        if ($missing !== []) {
            $problems[] = 'the restore did not include ' . implode(', ', array_map(static fn (string $s): string => BackupStore::LABELS[$s], $missing));
        }
        foreach ($extra as $line) {
            $lines[] = $line;
        }
        if ($problems === []) {
            return CheckResult::pass('A restore test passed ' . self::span($age) . ' ago with matching row counts.', self::lines($lines), $m);
        }
        foreach ($problems as $p) {
            $lines[] = 'Not met: ' . $p . '.';
        }

        return CheckResult::warn('The restore test is recent but its evidence is incomplete: ' . implode('; ', $problems) . '.', self::lines($lines), $this->settingsPath ?: null, $m);
    }

    /** @return array{at:\DateTimeImmutable, passed:bool, stores:list<string>, compared:bool, match:bool, who:string, extra:list<string>}|null */
    private function latest(): ?array
    {
        $best = null;
        $rec = $this->tests->latest();
        if ($rec instanceof RestoreTestRecord) {
            $extra = [];
            if ($rec->verify !== null) {
                $n = array_sum($rec->verify['finding_codes']);
                $extra[] = 'Evidence: reconciliation (rmm-store-verify) ' . ($rec->verify['clean'] ? 'was clean.' : 'reported ' . $n . ' finding(s): ' . implode(', ', array_keys($rec->verify['finding_codes'])) . '.');
            }
            if ($rec->counts !== []) {
                $extra[] = 'Evidence: ' . count($rec->counts) . ' table(s) compared' . ($rec->countsMatch() ? ', all rows matched.' : ', ' . count($rec->mismatches()) . ' differ.');
            }
            $best = ['at' => $rec->testedAt, 'passed' => $rec->passed, 'stores' => $rec->stores, 'compared' => $rec->countsCompared(), 'match' => $rec->countsMatch(), 'who' => $rec->recordedByName, 'extra' => $extra];
        }
        if ($this->facts !== null) {
            try {
                $f = $this->facts->backupFacts()->lastRestoreTest;
            } catch (\Throwable) {
                $f = null;
            }
            if ($f !== null && ($best === null || $f->testedAt > $best['at'])) {
                $best = ['at' => $f->testedAt, 'passed' => $f->passed, 'stores' => $f->stores, 'compared' => $f->rowCountsMatch !== null, 'match' => $f->rowCountsMatch === true, 'who' => 'the edition\'s scheduled test', 'extra' => []];
            }
        }

        return $best;
    }
}
