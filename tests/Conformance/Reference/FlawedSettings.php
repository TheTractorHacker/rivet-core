<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Contracts\SettingsInterface;

/**
 * A SQL-like settings store (values read back as strings) with an optional named flaw: falsy_to_default,
 * coerces_default, ignores_default, throws_odd, flaky, default_leaks.
 */
final class FlawedSettings implements SettingsInterface
{
    /** @var array<string,string> */
    public const SEEDED = ['core.audit.enabled' => '0', 'core.mcp.enabled' => '', 'core.jobs.enabled' => '1', 'core.name' => 'RivetIT'];

    private int $reads = 0;

    public function __construct(private ?string $flaw = null)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->reads++;
        if ($this->flaw === 'throws_odd' && (trim($key) === '' || str_contains($key, "'") || str_contains($key, "\0") || strlen($key) > 255)) {
            throw new \RuntimeException('bad key');
        }
        if (!array_key_exists($key, self::SEEDED)) {
            return match ($this->flaw) {
                'coerces_default' => is_array($default) ? null : (string) $default,
                'ignores_default' => null,
                default => $default,
            };
        }
        $value = self::SEEDED[$key];

        return match ($this->flaw) {
            'falsy_to_default' => $value ?: $default,
            'flaky' => $value . ($this->reads % 2 === 0 ? '' : ' '),
            'default_leaks' => $default ?? $value,
            default => $value,
        };
    }
}
