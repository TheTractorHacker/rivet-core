<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Rmm\Contracts\RmmModuleStateInterface;

/**
 * Reference implementation (not API; tests may extend it to build a deliberately broken variant) of {@see RmmModuleStateInterface}.
 *
 * @internal
 */
class InMemoryRmmModuleState implements RmmModuleStateInterface
{
    public function __construct(protected bool $allows = true, protected ?string $directory = null)
    {
    }

    public function editionAllows(): bool
    {
        return $this->allows;
    }

    public function stateDirectory(): ?string
    {
        return $this->directory;
    }
}
