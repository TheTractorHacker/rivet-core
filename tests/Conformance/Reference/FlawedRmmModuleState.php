<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Testing\InMemoryRmmModuleState;

/** The reference module state with an optional named flaw: flaky, throws, relative_dir, missing_dir, changing_dir. */
final class FlawedRmmModuleState extends InMemoryRmmModuleState
{
    private int $calls = 0;

    public function __construct(private ?string $flaw = null)
    {
        parent::__construct(true, sys_get_temp_dir());
    }

    public function editionAllows(): bool
    {
        ++$this->calls;
        if ($this->flaw === 'throws') {
            throw new \RuntimeException('settings unavailable');
        }

        return $this->flaw === 'flaky' ? $this->calls % 2 === 0 : true;
    }

    public function stateDirectory(): ?string
    {
        ++$this->calls;

        return match ($this->flaw) {
            'relative_dir' => 'tmp/state',
            'missing_dir' => '/nonexistent/rmm-state-' . bin2hex(random_bytes(3)),
            'changing_dir' => sys_get_temp_dir() . ($this->calls % 2 === 0 ? '/.' : ''),
            default => $this->directory,
        };
    }
}
