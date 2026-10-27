<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

/**
 * A point-in-time result: every automatic check, every manual item's state, and a score per framework. Plain arrays all the
 * way down so it serializes to JSON for snapshots and reads back unchanged.
 *
 * @api
 */
final readonly class Assessment
{
    /**
     * @param list<array<string,mixed>> $automatic
     * @param list<array<string,mixed>> $manual
     * @param array<string, array<string,mixed>> $summaries keyed by framework, plus "all"
     * @param array<string,mixed> $meta what the assessment was made against, for example `['profile' => ['key' => 'contract_x', 'label' => 'Contract X', 'version' => 3]]`; left out of
     *        the stored form when empty, so assessments made without it serialize exactly as before
     */
    public function __construct(
        public \DateTimeImmutable $generatedAt,
        public array $automatic,
        public array $manual,
        public array $summaries,
        public array $meta = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = [
            'generated_at' => $this->generatedAt->format('Y-m-d H:i:s'),
            'automatic' => $this->automatic,
            'manual' => $this->manual,
            'summaries' => $this->summaries,
        ];
        if ($this->meta !== []) {
            $out['meta'] = $this->meta;
        }

        return $out;
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            new \DateTimeImmutable((string) ($data['generated_at'] ?? 'now')),
            (array) ($data['automatic'] ?? []),
            (array) ($data['manual'] ?? []),
            (array) ($data['summaries'] ?? []),
            (array) ($data['meta'] ?? []),
        );
    }
}
