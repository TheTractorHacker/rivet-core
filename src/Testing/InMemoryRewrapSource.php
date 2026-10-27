<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Crypto\RewrapItem;
use RivetCore\Crypto\RewrapSource;

/**
 * Reference {@see RewrapSource} over an array, in insertion order. Used by the conformance kit and by edition tests of their
 * Reencryptor wiring.
 *
 * @api
 */
class InMemoryRewrapSource implements RewrapSource
{
    /** @var array<string,array{string,string}> id => [ciphertext, context] */
    protected array $rows = [];

    /** @param array<string|int,array{ciphertext:string,context:string}|array{string,string}> $rows id => row */
    public function __construct(private string $name = 'in-memory', array $rows = [])
    {
        foreach ($rows as $id => $row) {
            $this->rows[(string) $id] = isset($row['ciphertext']) ? [$row['ciphertext'], $row['context']] : [$row[0], $row[1]];
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function next(?string $afterId, int $limit): array
    {
        $out = [];
        $passed = $afterId === null;
        foreach ($this->rows as $id => $row) {
            $id = (string) $id;
            if (!$passed) {
                $passed = $id === $afterId;
                continue;
            }
            $out[] = new RewrapItem($id, $row[0], $row[1]);
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function replace(string $id, string $expected, string $replacement): bool
    {
        if (!isset($this->rows[$id]) || $this->rows[$id][0] !== $expected) {
            return false;
        }
        $this->rows[$id][0] = $replacement;

        return true;
    }

    public function get(string $id): ?string
    {
        return $this->rows[$id][0] ?? null;
    }

    /** Overwrites a value as another writer would (a concurrent edit in a test). */
    public function set(string $id, string $ciphertext): void
    {
        $this->rows[$id][0] = $ciphertext;
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
