<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Saved progress of one rewrap job: where it got to and what it did. Contains ids and counts only, never ciphertext.
 *
 * @api
 */
final class RewrapState
{
    public const RUNNING = 'running';
    public const COMPLETE = 'complete';

    public function __construct(
        public readonly string $job,
        public readonly string $status = self::RUNNING,
        public readonly ?string $cursor = null,
        public readonly int $scanned = 0,
        public readonly int $rewrapped = 0,
        public readonly int $skipped = 0,
        public readonly int $conflicts = 0,
        public readonly int $failed = 0,
        public readonly string $updatedAt = '',
    ) {
    }

    /** @param array<string,mixed> $changes */
    public function with(array $changes): self
    {
        $vars = array_merge(get_object_vars($this), $changes);

        return new self(
            (string) $vars['job'],
            (string) $vars['status'],
            $vars['cursor'] === null ? null : (string) $vars['cursor'],
            (int) $vars['scanned'],
            (int) $vars['rewrapped'],
            (int) $vars['skipped'],
            (int) $vars['conflicts'],
            (int) $vars['failed'],
            (string) $vars['updatedAt'],
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return (new self((string) ($data['job'] ?? '')))->with($data);
    }
}
