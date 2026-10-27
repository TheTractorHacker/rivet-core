<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\Backup\BackupFactsProviderInterface;

/**
 * Conformance kit for {@see BackupFactsProviderInterface}: the edition's report of what its backup did. Extend it, return the edition's provider from
 * {@see self::provider()}, and run it in the edition's test suite against the state of a test installation (any state: a fresh one with no backup, or one
 * after a backup).
 *
 * Checks: the provider returns facts and never throws; two calls in a row agree (no side effects, no flapping); no timestamp is in the future; with no
 * successful backup, or with the facts marked unavailable, nothing else is claimed (no stores, destinations, encryption or retention); an off-site arrival
 * time is only given when `offsite` is a destination; and no text field carries a secret.
 *
 * @api
 */
abstract class BackupFactsProviderConformanceTestCase extends TestCase
{
    use UntypedValues;

    /** Seconds a timestamp may be ahead of this machine's clock (clock skew between the backup host and the web host). */
    private const SKEW_S = 300;

    abstract protected function provider(): BackupFactsProviderInterface;

    private function facts(): BackupFacts
    {
        $f = self::untyped($this->provider()->backupFacts());
        self::assertInstanceOf(BackupFacts::class, $f, 'backupFacts() must return BackupFacts');

        return $f;
    }

    public function testFactsAreReturnedAndNeverThrow(): void
    {
        $this->facts();
        $this->facts();
    }

    public function testTwoCallsAgree(): void
    {
        self::assertSame($this->facts()->toArray(), $this->facts()->toArray(), 'reading the facts must not change them');
    }

    public function testNoTimestampIsInTheFuture(): void
    {
        $f = $this->facts();
        $limit = time() + self::SKEW_S;
        foreach ([$f->lastSuccessAt, $f->lastOffsiteSuccessAt, $f->lastRestoreTest?->testedAt] as $t) {
            if ($t !== null) {
                self::assertLessThanOrEqual($limit, $t->getTimestamp(), 'a backup fact is dated in the future');
            }
        }
    }

    public function testNothingIsClaimedWithoutASuccessfulBackup(): void
    {
        $f = $this->facts();
        if ($f->available && $f->lastSuccessAt !== null) {
            self::addToAssertionCount(1);

            return;
        }
        self::assertSame([], $f->includedStores, 'stores are listed although no backup succeeded');
        self::assertSame([], $f->destinations, 'destinations are listed although no backup succeeded');
        self::assertNull($f->encrypted, 'encryption is claimed although no backup succeeded');
        self::assertNull($f->lastOffsiteSuccessAt, 'an off-site copy is claimed although no backup succeeded');
    }

    public function testUnavailableFactsClaimNothingAtAll(): void
    {
        $f = $this->facts();
        if ($f->available) {
            self::addToAssertionCount(1);

            return;
        }
        self::assertNull($f->lastSuccessAt);
        self::assertSame([], $f->includedStores, 'stores are listed although the facts are unavailable');
        self::assertSame([], $f->destinations, 'destinations are listed although the facts are unavailable');
        self::assertNull($f->encrypted);
        self::assertNull($f->retentionDays);
        self::assertNull($f->lastRestoreTest);
        self::assertSame([], $f->includedTables);
    }

    public function testAnOffsiteArrivalNeedsAnOffsiteDestination(): void
    {
        $f = $this->facts();
        if ($f->lastOffsiteSuccessAt !== null) {
            self::assertTrue($f->hasOffsite(), 'an off-site arrival time is given but "offsite" is not a destination');
        } else {
            self::addToAssertionCount(1);
        }
    }

    public function testNoTextFieldCarriesASecret(): void
    {
        $text = (string) json_encode($this->facts()->toArray());
        self::assertDoesNotMatchRegularExpression('/-----BEGIN [A-Z ]*(KEY|CERTIFICATE)/', $text);
        self::assertDoesNotMatchRegularExpression('/\b(password|passwd|secret|token|api[_-]?key|passphrase)\b\s*[:=]\s*\S+/i', $text);
        self::assertDoesNotMatchRegularExpression('/:\/\/[^\/\s:@"]+:[^\/\s@"]+@/', $text);
        self::assertDoesNotMatchRegularExpression('/\b[A-Za-z0-9+\/_-]{40,}={0,2}/', $text);
    }
}
