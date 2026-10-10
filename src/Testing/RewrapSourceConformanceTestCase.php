<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Crypto\RewrapItem;
use RivetCore\Crypto\RewrapSource;

/**
 * Conformance kit for {@see RewrapSource}: the contract the Rewrapper relies on for paging and compare-and-set.
 *
 * Checks: next() pages through every row exactly once in a stable order (also when ids sort differently as numbers and as strings,
 * and for a limit of 1 or larger than the table); the cursor is exclusive; an empty source and a cursor at the end give []; limit
 * is respected; reads have no side effects and show the current value; items carry the stored value and context; replace() is
 * compare-and-set (true and visible on a match, false and no change on a stale expectation or an unknown id); a replaced value
 * shows up in later pages without disturbing the order.
 *
 * @api
 */
abstract class RewrapSourceConformanceTestCase extends TestCase
{
    /**
     * Returns a source whose column holds exactly these rows. The ids are stored as the edition's real key (integers are fine: they
     * arrive here as numeric strings); next() must return them as strings.
     *
     * @param array<int|string,array{ciphertext:string,context:string}> $rows id => row
     */
    abstract protected function source(array $rows): RewrapSource;

    /** The value currently stored for $id, read independently of next() (a SELECT in an edition). */
    abstract protected function stored(RewrapSource $source, string $id): ?string;

    /** @return array<int|string,array{ciphertext:string,context:string}> */
    protected static function rows(): array
    {
        $rows = [];
        foreach ([2, 10, 100, 9, 1] as $id) {
            $rows[(string) $id] = ['ciphertext' => 'ct-' . $id, 'context' => 'rec:' . $id];
        }

        return $rows;
    }

    /** @return list<string> every id, paging with $limit */
    private function page(RewrapSource $s, int $limit): array
    {
        $ids = [];
        $cursor = null;
        for ($i = 0; $i < 1000; $i++) {
            $items = $s->next($cursor, $limit);
            $this->assertLessThanOrEqual($limit, count($items), 'next() must not return more than the limit');
            if ($items === []) {
                return $ids;
            }
            foreach ($items as $item) {
                $this->assertInstanceOf(RewrapItem::class, $item);
                $ids[] = $item->id;
            }
            $cursor = $items[count($items) - 1]->id;
        }
        $this->fail('paging did not terminate');
    }

    public function testNameIsStable(): void
    {
        $s = $this->source(self::rows());
        $this->assertNotSame('', $s->name());
        $this->assertSame($s->name(), $s->name());
    }

    public function testPagingVisitsEveryRowExactlyOnce(): void
    {
        $want = array_map('strval', array_keys(self::rows()));
        foreach ([1, 2, 3, 100] as $limit) {
            $ids = $this->page($this->source(self::rows()), $limit);
            $sorted = $ids;
            sort($sorted, SORT_STRING);
            $expected = $want;
            sort($expected, SORT_STRING);
            $this->assertSame($expected, $sorted, "limit $limit must visit every row exactly once");
        }
    }

    public function testOrderIsStableAcrossPagesAndLimits(): void
    {
        $this->assertSame($this->page($this->source(self::rows()), 1), $this->page($this->source(self::rows()), 100));
    }

    public function testLimitIsRespected(): void
    {
        $this->assertCount(2, $this->source(self::rows())->next(null, 2));
        $this->assertCount(5, $this->source(self::rows())->next(null, 50));
    }

    public function testCursorIsExclusive(): void
    {
        $s = $this->source(self::rows());
        $first = $s->next(null, 2);
        $this->assertCount(2, $first);
        $next = $s->next($first[1]->id, 10);
        $ids = array_map(fn (RewrapItem $i) => $i->id, $next);
        $this->assertNotContains($first[0]->id, $ids);
        $this->assertNotContains($first[1]->id, $ids);
        $this->assertCount(3, $ids);
    }

    public function testEmptySourceAndTheEndGiveAnEmptyList(): void
    {
        $this->assertSame([], $this->source([])->next(null, 10));
        $s = $this->source(self::rows());
        $last = $this->page($s, 100);
        $this->assertSame([], $s->next($last[count($last) - 1], 10));
    }

    public function testItemsCarryTheStoredValueAndContext(): void
    {
        foreach ($this->source(self::rows())->next(null, 10) as $item) {
            $this->assertSame(self::rows()[$item->id]['ciphertext'], $item->ciphertext);
            $this->assertSame(self::rows()[$item->id]['context'], $item->context);
        }
    }

    public function testReadingHasNoSideEffects(): void
    {
        $s = $this->source(self::rows());
        $a = $s->next(null, 10);
        $b = $s->next(null, 10);
        $this->assertEquals($a, $b);
        $this->assertSame('ct-10', $this->stored($s, '10'));
    }

    public function testReplaceSucceedsOnAMatchAndIsVisible(): void
    {
        $s = $this->source(self::rows());
        $this->assertTrue($s->replace('10', 'ct-10', 'new-10'));
        $this->assertSame('new-10', $this->stored($s, '10'));
        /** @var array<int|string,string> $values */
        $values = [];
        foreach ($s->next(null, 10) as $item) {
            $values[$item->id] = $item->ciphertext;
        }
        $this->assertSame('new-10', $values[10]);
        $this->assertSame('ct-2', $values[2]);
    }

    public function testReplaceIsCompareAndSet(): void
    {
        $s = $this->source(self::rows());
        $this->assertFalse($s->replace('10', 'stale', 'new-10'), 'a stale expectation must lose');
        $this->assertSame('ct-10', $this->stored($s, '10'));
        $this->assertTrue($s->replace('10', 'ct-10', 'first'));
        $this->assertFalse($s->replace('10', 'ct-10', 'second'), 'the second writer must lose');
        $this->assertSame('first', $this->stored($s, '10'));
    }

    public function testReplaceOfAnUnknownIdIsFalseAndCreatesNothing(): void
    {
        $s = $this->source(self::rows());
        $this->assertFalse($s->replace('999', 'ct-999', 'x'));
        $this->assertNull($this->stored($s, '999'));
        $this->assertCount(5, $this->page($s, 100));
    }

    public function testReplaceDoesNotTouchOtherRows(): void
    {
        $s = $this->source(self::rows());
        $before = $this->page($s, 100);
        $s->replace('9', 'ct-9', 'changed');
        $this->assertSame($before, $this->page($s, 100));
        $this->assertSame('ct-100', $this->stored($s, '100'));
        $this->assertSame('ct-1', $this->stored($s, '1'));
    }

    public function testReplacingWhilePagingNeitherSkipsNorRepeatsRows(): void
    {
        $s = $this->source(self::rows());
        $seen = [];
        $cursor = null;
        for ($guard = 0; $guard < 50 && ($items = $s->next($cursor, 2)) !== []; $guard++) {
            foreach ($items as $item) {
                $seen[] = $item->id;
                $s->replace($item->id, $item->ciphertext, 'done-' . $item->id);
            }
            $cursor = $items[count($items) - 1]->id;
        }
        $this->assertLessThan(50, $guard, 'paging did not terminate');
        $this->assertCount(5, array_unique($seen));
        $this->assertCount(5, $seen);
    }
}
