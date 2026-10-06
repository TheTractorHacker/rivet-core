<?php

declare(strict_types=1);

namespace RivetCore\Support;

use RivetCore\Contracts\SettingsInterface;

/** Settings backed by a plain array; handy for adapters that preload settings, and for tests.
 *
 * @api
 */
final class ArraySettings implements SettingsInterface
{
    /** @param array<string,mixed> $values */
    public function __construct(private array $values = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }
}
