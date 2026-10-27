<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Compliance\AttestationProviderInterface;

/**
 * Conformance kit for {@see AttestationProviderInterface}. The edition records reviews through its own store
 * ({@see self::storeAttestation()}), the case reads them back through the provider.
 *
 * Checks: latestPerItem() is keyed by item id, holds one entry per item, the entry is the LAST review recorded for that item,
 * and every entry has exactly the documented shape (reviewed_on and next_due_on as Y-m-d strings or null, reviewer_name a
 * string, note a string or null). Unknown items are simply absent.
 *
 * @api
 */
abstract class AttestationProviderConformanceTestCase extends TestCase
{
    use UntypedValues;

    /** @var list<string> */
    private array $items = [];

    abstract protected function provider(): AttestationProviderInterface;

    /**
     * Record one review, newest last, the way the edition does. $itemId matches [a-z0-9_]{1,64}; dates are Y-m-d.
     */
    abstract protected function storeAttestation(string $itemId, string $reviewedOn, ?string $nextDueOn, string $reviewer, ?string $note): void;

    /**
     * Remove every review recorded for these item ids.
     *
     * @param list<string> $itemIds
     */
    abstract protected function deleteAttestations(array $itemIds): void;

    final protected function item(): string
    {
        return $this->items[] = 'conf_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        try {
            $this->deleteAttestations($this->items);
        } catch (\Throwable) {
            // best effort
        }
        $this->items = [];
    }

    public function testAnItemNeverReviewedIsAbsent(): void
    {
        $this->assertArrayNotHasKey($this->item(), $this->provider()->latestPerItem());
    }

    public function testAReviewIsReturnedWithTheDocumentedShape(): void
    {
        $id = $this->item();
        $this->storeAttestation($id, '2026-03-04', '2027-03-04', 'Ada Lovelace', 'Looked at everything');
        $all = $this->provider()->latestPerItem();
        $this->assertArrayHasKey($id, $all);
        $r = $all[$id];
        $this->assertSame(['next_due_on', 'note', 'reviewed_on', 'reviewer_name'], $this->sortedKeys($r), 'entry keys');
        $this->assertSame('2026-03-04', $r['reviewed_on']);
        $this->assertSame('2027-03-04', $r['next_due_on']);
        $this->assertSame('Ada Lovelace', $r['reviewer_name']);
        $this->assertSame('Looked at everything', $r['note']);
    }

    public function testMissingNextDueAndNoteAreNullNotEmptyStrings(): void
    {
        $id = $this->item();
        $this->storeAttestation($id, '2026-03-04', null, 'Grace Hopper', null);
        $r = $this->provider()->latestPerItem()[$id] ?? null;
        $this->assertIsArray($r);
        $this->assertNull($r['next_due_on']);
        $this->assertNull($r['note']);
        $this->assertIsString(self::untyped($r['reviewed_on']));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $r['reviewed_on']);
    }

    public function testTheLastReviewRecordedWins(): void
    {
        $id = $this->item();
        $this->storeAttestation($id, '2026-01-10', '2026-07-10', 'First Reviewer', 'first');
        $this->storeAttestation($id, '2026-02-20', '2026-08-20', 'Second Reviewer', 'second');
        $r = $this->provider()->latestPerItem()[$id];
        $this->assertSame('Second Reviewer', $r['reviewer_name']);
        $this->assertSame('2026-02-20', $r['reviewed_on']);
        $this->assertSame('second', $r['note']);
    }

    public function testTwoReviewsOnTheSameDayStillPickTheLastRecorded(): void
    {
        $id = $this->item();
        $this->storeAttestation($id, '2026-02-20', null, 'Morning', 'am');
        $this->storeAttestation($id, '2026-02-20', null, 'Evening', 'pm');
        $this->assertSame('Evening', $this->provider()->latestPerItem()[$id]['reviewer_name']);
    }

    public function testItemsAreIndependentAndOnePerItem(): void
    {
        $a = $this->item();
        $b = $this->item();
        $this->storeAttestation($a, '2026-01-01', null, 'A1', null);
        $this->storeAttestation($a, '2026-01-02', null, 'A2', null);
        $this->storeAttestation($b, '2026-01-03', null, 'B1', null);
        $all = $this->provider()->latestPerItem();
        $this->assertSame('A2', $all[$a]['reviewer_name']);
        $this->assertSame('B1', $all[$b]['reviewer_name']);
        $this->assertSame(1, count(array_keys($all, $all[$a], true)), 'one entry per item');
        foreach (array_keys($all) as $key) {
            $this->assertIsString(self::untyped($key));
        }
    }

    /**
     * @param array<string,mixed> $a
     * @return list<string>
     */
    private function sortedKeys(array $a): array
    {
        $k = array_map('strval', array_keys($a));
        sort($k);

        return $k;
    }
}
