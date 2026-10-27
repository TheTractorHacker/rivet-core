<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Compliance\AttestationProviderInterface;

/** An attestations log in memory, or one with a named flaw: first_wins, max_date_wins, empty_strings, extra_key. */
final class InMemoryAttestationProvider implements AttestationProviderInterface
{
    /** @var list<array{item:string,on:string,due:?string,who:string,note:?string}> */
    private array $log = [];

    public function __construct(private ?string $flaw = null)
    {
    }

    public function record(string $item, string $on, ?string $due, string $who, ?string $note): void
    {
        $this->log[] = ['item' => $item, 'on' => $on, 'due' => $due, 'who' => $who, 'note' => $note];
    }

    /** @param list<string> $items */
    public function purge(array $items): void
    {
        $this->log = array_values(array_filter($this->log, fn (array $r) => !in_array($r['item'], $items, true)));
    }

    public function latestPerItem(): array
    {
        $pick = [];
        foreach ($this->log as $r) {
            $current = $pick[$r['item']] ?? null;
            $take = match ($this->flaw) {
                'first_wins' => $current === null,
                'max_date_wins' => $current === null || $r['on'] > $current['on'],
                default => true,
            };
            if ($take) {
                $pick[$r['item']] = $r;
            }
        }
        $out = [];
        foreach ($pick as $item => $r) {
            $out[$item] = [
                'reviewed_on' => $r['on'],
                'next_due_on' => $r['due'] ?? ($this->flaw === 'empty_strings' ? '' : null),
                'reviewer_name' => $r['who'],
                'note' => $r['note'] ?? ($this->flaw === 'empty_strings' ? '' : null),
            ];
            if ($this->flaw === 'extra_key') {
                $out[$item]['attestation_id'] = 1;
            }
        }
        return $out;
    }
}
