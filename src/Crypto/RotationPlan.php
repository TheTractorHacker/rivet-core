<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Pure planning for a key rotation or a format migration: given how many stored values carry each label (see
 * {@see EnvelopeInterface::label()}) and the key ring, says what has to be rewrapped, what cannot be read, and which retired keys are
 * safe to remove. It touches no data and no clock, so it is the same on the rotation panel, in a dry run and in tests.
 *
 * @api
 */
final class RotationPlan
{
    /**
     * @param array<string,int> $inventory label => count
     * @param list<string> $pending labels that need a rewrap, with counts in $inventory
     * @param array<string,int> $unreadable
     * @param list<string> $retirable
     */
    private function __construct(
        private string $activeKid,
        private array $inventory,
        private array $pending,
        private array $unreadable,
        private array $retirable,
    ) {
    }

    /**
     * @param array<string,int> $inventory label => number of stored values ("v3:k1", "legacy:ENC2", "unknown", ...); 'empty' is ignored
     * @throws KeyUnavailable the ring has no active key, so there is nothing to rotate to
     */
    public static function build(KeyRing $ring, array $inventory): self
    {
        $active = $ring->activeKid() ?? throw new KeyUnavailable('The ring has no active key; there is nothing to rotate to.');
        unset($inventory['empty']);
        ksort($inventory);
        $pending = [];
        $unreadable = [];
        foreach ($inventory as $label => $count) {
            if ($count <= 0) {
                unset($inventory[$label]);
                continue;
            }
            if (str_starts_with($label, 'v3:')) {
                $kid = substr($label, 3);
                if (!$ring->has($kid)) {
                    $unreadable[$label] = $count;
                    continue;
                }
                if ($kid === $active) {
                    continue;
                }
            } elseif ($label === 'unknown') {
                $unreadable[$label] = $count;
                continue;
            }
            $pending[] = $label;
        }
        sort($pending);
        $retirable = [];
        foreach ($ring->kids() as $kid) {
            if ($kid !== $active && ($inventory['v3:' . $kid] ?? 0) === 0) {
                $retirable[] = $kid;
            }
        }

        return new self($active, $inventory, $pending, $unreadable, $retirable);
    }

    /**
     * Counts labels over stored values (streamed; nothing is kept).
     *
     * @param iterable<string|null> $stored
     * @return array<string,int>
     */
    public static function inventory(iterable $stored, EnvelopeInterface $envelope): array
    {
        $counts = [];
        foreach ($stored as $value) {
            $label = $envelope->label((string) $value);
            if ($label !== 'empty') {
                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    public function activeKid(): string
    {
        return $this->activeKid;
    }

    public function total(): int
    {
        return array_sum($this->inventory);
    }

    /** Values already v3 under the active key. */
    public function current(): int
    {
        return $this->inventory['v3:' . $this->activeKid] ?? 0;
    }

    /** @return array<string,int> label => count of values a rewrap would change */
    public function pending(): array
    {
        return array_intersect_key($this->inventory, array_flip($this->pending));
    }

    public function pendingCount(): int
    {
        return array_sum($this->pending());
    }

    /** @return array<string,int> values that name a key the ring does not have, or that no reader recognises: a rewrap cannot fix these */
    public function unreadable(): array
    {
        return $this->unreadable;
    }

    /** @return list<string> non-active keys that no stored value uses (still keep one backup cycle before deleting them) */
    public function retirable(): array
    {
        return $this->retirable;
    }

    public function isComplete(): bool
    {
        return $this->pending === [] && $this->unreadable === [];
    }

    public function hasBlockers(): bool
    {
        return $this->unreadable !== [];
    }

    /** @return list<string> the next steps, in order, in plain words */
    public function steps(): array
    {
        $steps = [];
        if ($this->unreadable !== []) {
            $steps[] = 'Restore the missing key or investigate the unreadable values (' . self::summary($this->unreadable) . ') before anything else.';
        }
        if ($this->pending !== []) {
            $steps[] = 'Dry-run the rewrap, then run it: ' . $this->pendingCount() . ' value(s) move to key "' . $this->activeKid . '" (' . self::summary($this->pending()) . ').';
            $steps[] = 'Run the rewrap again until it reports 0 rewrapped (concurrent edits are picked up on the second pass).';
        }
        if ($steps === []) {
            $steps[] = 'Nothing to do: every stored value is on key "' . $this->activeKid . '".';
        }
        if ($this->retirable !== [] && $this->pending === [] && $this->unreadable === []) {
            $steps[] = 'Keep retired key(s) ' . implode(', ', $this->retirable) . ' for one backup cycle, then remove them from the key file and take a fresh offline copy.';
        }

        return $steps;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'active_kid' => $this->activeKid,
            'total' => $this->total(),
            'current' => $this->current(),
            'pending' => $this->pending(),
            'unreadable' => $this->unreadable,
            'retirable' => $this->retirable,
            'complete' => $this->isComplete(),
        ];
    }

    /** @param array<string,int> $counts */
    private static function summary(array $counts): string
    {
        $parts = [];
        foreach ($counts as $label => $n) {
            $parts[] = $label . ': ' . $n;
        }

        return implode(', ', $parts);
    }
}
