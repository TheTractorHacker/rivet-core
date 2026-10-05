<?php

declare(strict_types=1);

namespace RivetCore\Database;

final readonly class ExecutionResult
{
    public function __construct(
        public int $affectedRows,
        public ?int $insertId = null,
    ) {
    }
}
