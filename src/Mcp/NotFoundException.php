<?php

declare(strict_types=1);

namespace RivetCore\Mcp;

/** Raised inside a tool body when the requested record does not exist or is outside the caller's scope. */
class NotFoundException extends \RuntimeException
{
}
