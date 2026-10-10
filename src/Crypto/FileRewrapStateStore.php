<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * One JSON file per job in a directory (atomic write). For CLI runs on one host; keep the directory outside the web root.
 *
 * @api
 */
final class FileRewrapStateStore implements RewrapStateStore
{
    public function __construct(private string $directory)
    {
    }

    public function load(string $job): ?RewrapState
    {
        $path = $this->path($job);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? RewrapState::fromArray($data) : null;
    }

    public function save(RewrapState $state): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create the rewrap state directory.');
        }
        $path = $this->path($state->job);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($state->toArray(), JSON_THROW_ON_ERROR), LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot save the rewrap state.');
        }
    }

    public function clear(string $job): void
    {
        @unlink($this->path($job));
    }

    private function path(string $job): string
    {
        return rtrim($this->directory, '/') . '/rewrap-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $job) . '-' . substr(hash('sha256', $job), 0, 8) . '.json';
    }
}
