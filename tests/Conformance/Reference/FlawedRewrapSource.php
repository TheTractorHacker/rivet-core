<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Crypto\RewrapItem;
use RivetCore\Testing\InMemoryRewrapSource;

/**
 * The reference source with one named flaw: cursor_inclusive, ignores_limit, replace_unconditional, replace_true_unknown, string_cursor,
 * mutates_on_read, reverses_pages, drops_last, replace_creates, replace_touches_all, stale_reads.
 */
final class FlawedRewrapSource extends InMemoryRewrapSource
{
    /** @var array<string,string> */
    private array $snapshot = [];

    /** @param array<string,array{ciphertext:string,context:string}> $rows */
    public function __construct(private ?string $flaw, array $rows)
    {
        parent::__construct('flawed', $rows);
        foreach ($rows as $id => $row) {
            $this->snapshot[(string) $id] = $row['ciphertext'];
        }
    }

    public function next(?string $afterId, int $limit): array
    {
        switch ($this->flaw) {
            case 'cursor_inclusive':
                $all = parent::next(null, PHP_INT_MAX);
                $out = [];
                $on = $afterId === null;
                foreach ($all as $item) {
                    $on = $on || $item->id === $afterId;
                    if ($on) {
                        $out[] = $item;
                    }
                }

                return array_slice($out, 0, $limit);
            case 'ignores_limit':
                return parent::next($afterId, PHP_INT_MAX);
            case 'string_cursor':
                // Orders numerically but compares the cursor as a string: with ids 2, 10, 100, 9, 1 rows are skipped or repeated.
                $items = parent::next(null, PHP_INT_MAX);
                if ($afterId !== null) {
                    $items = array_values(array_filter($items, fn (RewrapItem $i) => strcmp($i->id, $afterId) > 0));
                }

                return array_slice($items, 0, $limit);
            case 'mutates_on_read':
                $items = parent::next($afterId, $limit);
                foreach ($items as $i) {
                    $this->set($i->id, $i->ciphertext . '');
                    if ($i->ciphertext === 'ct-10') {
                        $this->set($i->id, 'touched');
                    }
                }

                return $items;
            case 'reverses_pages':
                return array_reverse(parent::next($afterId, $limit));
            case 'drops_last':
                $items = parent::next($afterId, $limit);
                return count($items) === $limit ? array_slice($items, 0, -1) : $items;
            case 'stale_reads':
                return array_map(fn (RewrapItem $i) => new RewrapItem($i->id, $this->snapshot[$i->id] ?? $i->ciphertext, $i->context), parent::next($afterId, $limit));
            default:
                return parent::next($afterId, $limit);
        }
    }

    public function replace(string $id, string $expected, string $replacement): bool
    {
        switch ($this->flaw) {
            case 'replace_unconditional':
                if ($this->get($id) === null) {
                    return false;
                }
                $this->set($id, $replacement);

                return true;
            case 'replace_true_unknown':
                return parent::replace($id, $expected, $replacement) || $this->get($id) === null;
            case 'replace_creates':
                if ($this->get($id) === null) {
                    $this->set($id, $replacement);

                    return true;
                }

                return parent::replace($id, $expected, $replacement);
            case 'replace_touches_all':
                $ok = parent::replace($id, $expected, $replacement);
                if ($ok) {
                    foreach (parent::next(null, PHP_INT_MAX) as $i) {
                        if ($i->id !== $id && $i->ciphertext === 'ct-1') {
                            $this->set($i->id, $replacement);
                        }
                    }
                }

                return $ok;
            default:
                return parent::replace($id, $expected, $replacement);
        }
    }
}
