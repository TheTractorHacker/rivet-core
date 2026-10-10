<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use RivetCore\Audit\AuditService;
use RivetCore\Crypto\CryptoException;
use RivetCore\Crypto\Envelope;
use RivetCore\Crypto\EnvelopeReencryptor;
use RivetCore\Crypto\FileRewrapStateStore;
use RivetCore\Crypto\KeyRing;
use RivetCore\Crypto\LegacyReaders;
use RivetCore\Crypto\RewrapOptions;
use RivetCore\Crypto\RewrapState;
use RivetCore\Crypto\Rewrapper;
use RivetCore\Crypto\RotationPlan;
use RivetCore\Support\NullRequestContext;
use RivetCore\Testing\InMemoryRewrapSource;
use RivetCore\Testing\InMemoryRewrapStateStore;
use RivetCore\Tests\Fixtures\LegacyEditions\LegacyEditionFunctions as Editions;
use RivetCore\Tests\Support\FakeDatabase;

require_once __DIR__ . '/../../Fixtures/crypto/LegacyEditionFunctions.php';

final class RewrapperTest extends TestCase
{
    private string $legacyKey;
    private Envelope $envelope;

    protected function setUp(): void
    {
        $this->legacyKey = bin2hex(random_bytes(32));
        $this->envelope = new Envelope(KeyRing::single(random_bytes(32), 'k2'), LegacyReaders::settings($this->legacyKey));
    }

    private function source(int $n = 25): InMemoryRewrapSource
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $stored = match ($i % 3) {
                0 => Editions::encryptSetting("secret-$i", $this->legacyKey),
                1 => Editions::encryptSettingCbc("secret-$i", $this->legacyKey),
                default => $this->envelope->seal("secret-$i", "rec:$i"),
            };
            $rows[$i] = ['ciphertext' => $stored, 'context' => "rec:$i"];
        }

        return new InMemoryRewrapSource('t', $rows);
    }

    /** @return list<string> */
    private function plaintexts(InMemoryRewrapSource $s): array
    {
        $out = [];
        foreach ($s->next(null, 1000) as $item) {
            $out[] = $this->envelope->open($item->ciphertext, $item->context);
        }

        return $out;
    }

    public function testRewrapsEverythingAndTheSecondRunChangesNothing(): void
    {
        $s = $this->source();
        $before = $this->plaintexts($s);
        $r = new Rewrapper(new InMemoryRewrapStateStore());
        $rep = $r->run($s, new EnvelopeReencryptor($this->envelope));
        $this->assertTrue($rep->isClean());
        $this->assertSame(25, $rep->scanned);
        $this->assertSame(17, $rep->rewrapped); // 17 are legacy; 8 were already v3 on k2
        $this->assertSame(8, $rep->skipped);
        $this->assertSame($before, $this->plaintexts($s));
        foreach ($s->next(null, 100) as $item) {
            $this->assertStringStartsWith('v3:k2:', $item->ciphertext);
        }
        $again = $r->run($s, new EnvelopeReencryptor($this->envelope));
        $this->assertSame(0, $again->rewrapped);
        $this->assertSame(25, $again->skipped);
        $this->assertTrue($again->isClean());
    }

    public function testDryRunWritesNothingAndSavesNoProgress(): void
    {
        $s = $this->source();
        $snapshot = $s->next(null, 100);
        $states = new InMemoryRewrapStateStore();
        $rep = (new Rewrapper($states))->run($s, new EnvelopeReencryptor($this->envelope), new RewrapOptions(batchSize: 4, dryRun: true));
        $this->assertTrue($rep->dryRun);
        $this->assertSame(17, $rep->rewrapped, 'reports what it would do');
        $this->assertEquals($snapshot, $s->next(null, 100));
        $this->assertNull($states->load('t'));
    }

    public function testInterruptedRunResumesFromTheSavedCursor(): void
    {
        $s = $this->source();
        $states = new InMemoryRewrapStateStore();
        $r = new Rewrapper($states);
        $re = new EnvelopeReencryptor($this->envelope);
        $first = $r->run($s, $re, new RewrapOptions(batchSize: 5, maxBatches: 2));
        $this->assertFalse($first->completed);
        $this->assertSame(10, $first->scanned);
        $this->assertSame('10', $first->cursor);
        $this->assertSame('10', $states->load('t')?->cursor);
        $this->assertSame(RewrapState::RUNNING, $states->load('t')?->status);
        $second = $r->run($s, $re, new RewrapOptions(batchSize: 5));
        $this->assertTrue($second->completed);
        $this->assertSame(25, $second->scanned, 'counters carry over a resumed run');
        $this->assertSame(17, $second->rewrapped);
        $this->assertSame(RewrapState::COMPLETE, $states->load('t')?->status);
        $this->assertNull($states->load('t')?->cursor);
    }

    public function testResumeContinuesAfterACrashMidBatch(): void
    {
        $s = $this->source();
        $states = new InMemoryRewrapStateStore();
        $r = new Rewrapper($states);
        $boom = new class($this->envelope) implements \RivetCore\Crypto\Reencryptor {
            public int $calls = 0;

            public function __construct(private Envelope $e)
            {
            }

            public function reencrypt(string $stored, string $context): ?string
            {
                if (++$this->calls === 13) {
                    throw new \Error('process killed');
                }

                return $this->e->needsRewrap($stored) ? $this->e->rewrap($stored, $context) : null;
            }
        };
        try {
            $r->run($s, $boom, new RewrapOptions(batchSize: 5));
            $this->fail();
        } catch (\Error) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('10', $states->load('t')?->cursor, 'the last COMPLETED batch is the resume point');
        $rep = $r->run($s, new EnvelopeReencryptor($this->envelope), new RewrapOptions(batchSize: 5));
        $this->assertTrue($rep->isClean());
        foreach ($s->next(null, 100) as $item) {
            $this->assertStringStartsWith('v3:k2:', $item->ciphertext);
        }
    }

    public function testNoResumeStartsOver(): void
    {
        $s = $this->source();
        $states = new InMemoryRewrapStateStore();
        $r = new Rewrapper($states);
        $first = $r->run($s, new EnvelopeReencryptor($this->envelope), new RewrapOptions(batchSize: 5, maxBatches: 1));
        $rep = $r->run($s, new EnvelopeReencryptor($this->envelope), new RewrapOptions(resume: false));
        $this->assertSame(25, $rep->scanned, 'a non-resumed run walks the whole column again');
        $this->assertSame(17 - $first->rewrapped, $rep->rewrapped, 'and only changes what is still old');
    }

    public function testConcurrentEditIsAConflictNotACorruption(): void
    {
        $s = new class('t', ['1' => ['ciphertext' => 'placeholder', 'context' => 'rec:1']]) extends InMemoryRewrapSource {
            public bool $raced = false;

            public function replace(string $id, string $expected, string $replacement): bool
            {
                if (!$this->raced) {
                    $this->raced = true;
                    $this->set($id, 'someone-else-wrote-this'); // an edit between our read and our write
                }

                return parent::replace($id, $expected, $replacement);
            }
        };
        $s->set('1', Editions::encryptSetting('v', $this->legacyKey));
        $rep = (new Rewrapper(new InMemoryRewrapStateStore()))->run($s, new EnvelopeReencryptor($this->envelope));
        $this->assertSame(1, $rep->conflicts);
        $this->assertSame(0, $rep->rewrapped);
        $this->assertFalse($rep->isClean());
        $this->assertSame('someone-else-wrote-this', $s->get('1'));
    }

    public function testUnreadableValuesAreCountedAndLeftUntouched(): void
    {
        $s = $this->source(6);
        $s->set('2', 'ENC2:tampered');
        $s->set('4', 'v3:gone:AAAA');
        $untouched2 = $s->get('2');
        $rep = (new Rewrapper(new InMemoryRewrapStateStore()))->run($s, new EnvelopeReencryptor($this->envelope));
        $this->assertSame(2, $rep->failed);
        $this->assertSame(['2', '4'], $rep->failedIds);
        $this->assertFalse($rep->isClean());
        $this->assertSame($untouched2, $s->get('2'));
        $this->assertSame(4, $rep->scanned - $rep->failed);
    }

    public function testStopOnErrorStopsBeforeTheBadRowSoAResumeRetriesIt(): void
    {
        $s = $this->source(6);
        $s->set('3', 'ENC2:tampered');
        $states = new InMemoryRewrapStateStore();
        $r = new Rewrapper($states);
        $rep = $r->run($s, new EnvelopeReencryptor($this->envelope), new RewrapOptions(batchSize: 10, stopOnError: true));
        $this->assertFalse($rep->completed);
        $this->assertSame('2', $rep->cursor);
        $this->assertSame(['3'], $rep->failedIds);
    }

    public function testEmptyValuesAreSkippedAndLimitsStopCleanly(): void
    {
        $s = new InMemoryRewrapSource('e', ['1' => ['ciphertext' => '', 'context' => 'c'], '2' => ['ciphertext' => '', 'context' => 'c'], '3' => ['ciphertext' => '', 'context' => 'c']]);
        $rep = (new Rewrapper(new InMemoryRewrapStateStore()))->run($s, new EnvelopeReencryptor($this->envelope), new RewrapOptions(batchSize: 1, maxItems: 2));
        $this->assertFalse($rep->completed);
        $this->assertSame(2, $rep->scanned);
        $this->assertSame(2, $rep->skipped);
        $dl = (new Rewrapper(new InMemoryRewrapStateStore()))->run($s, new EnvelopeReencryptor($this->envelope), new RewrapOptions(batchSize: 1, deadlineSeconds: 0.0));
        $this->assertFalse($dl->completed);
        $this->assertSame(1, $dl->scanned);
    }

    public function testAuditEventPerRunWithCountsAndNoValues(): void
    {
        $db = new FakeDatabase();
        $audit = new AuditService($db, new NullRequestContext());
        $s = $this->source(4);
        (new Rewrapper(new InMemoryRewrapStateStore(), $audit))->run($s, new EnvelopeReencryptor($this->envelope), new RewrapOptions(dryRun: true, actorUserId: 9));
        (new Rewrapper(new InMemoryRewrapStateStore(), $audit))->run($s, new EnvelopeReencryptor($this->envelope), new RewrapOptions(actorUserId: 9));
        $this->assertCount(2, $db->calls);
        $this->assertSame('crypto.rewrap', $db->calls[0]['params'][0]);
        $this->assertSame(9, $db->calls[0]['params'][1]);
        $this->assertSame('rewrap_job', $db->calls[0]['params'][2]);
        $this->assertSame('dry_run', $db->calls[0]['params'][4]);
        $this->assertSame('complete', $db->calls[1]['params'][4]);
        $this->assertStringNotContainsString('secret-', json_encode($db->calls));
        $this->assertStringNotContainsString('ENC', json_encode($db->calls));
    }

    public function testAuditFailureDoesNotFailTheRun(): void
    {
        $db = new FakeDatabase();
        $db->failWith = new \RuntimeException('audit db down');
        $rep = (new Rewrapper(new InMemoryRewrapStateStore(), new AuditService($db, new NullRequestContext())))->run($this->source(3), new EnvelopeReencryptor($this->envelope));
        $this->assertTrue($rep->isClean());
    }

    public function testFileStateStoreRoundTrip(): void
    {
        $dir = sys_get_temp_dir() . '/rc_rw_' . bin2hex(random_bytes(4));
        $store = new FileRewrapStateStore($dir);
        $this->assertNull($store->load('a/b'));
        $store->save(new RewrapState('a/b', RewrapState::RUNNING, '42', 10, 5, 4, 1, 0, '2026-10-10T00:00:00+00:00'));
        $loaded = $store->load('a/b');
        $this->assertSame('42', $loaded?->cursor);
        $this->assertSame(5, $loaded?->rewrapped);
        $store->clear('a/b');
        $this->assertNull($store->load('a/b'));
        @rmdir($dir);
    }

    public function testBatchSizeBounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RewrapOptions(batchSize: 0);
    }

    public function testInventoryBeforeAndAfterShowsTheRotationFinished(): void
    {
        $s = $this->source();
        $values = fn () => array_map(fn ($i) => $i->ciphertext, $s->next(null, 100));
        $plan = RotationPlan::build($this->envelope->ring(), RotationPlan::inventory($values(), $this->envelope));
        $this->assertFalse($plan->isComplete());
        (new Rewrapper(new InMemoryRewrapStateStore()))->run($s, new EnvelopeReencryptor($this->envelope));
        $this->assertTrue(RotationPlan::build($this->envelope->ring(), RotationPlan::inventory($values(), $this->envelope))->isComplete());
    }
}
