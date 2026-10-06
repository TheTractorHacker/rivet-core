<?php

declare(strict_types=1);

namespace RivetCore\Database;

/** @api */
final readonly class ExecutionResult
{
    public function __construct(
        public int $affectedRows,
        public ?int $insertId = null,
    ) {
    }
}
