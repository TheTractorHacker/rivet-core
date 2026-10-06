<?php

declare(strict_types=1);

namespace RivetCore\Support;

use RivetCore\Contracts\RequestContextInterface;

/** @api */
final class NullRequestContext implements RequestContextInterface
{
    public function ipAddress(): ?string
    {
        return null;
    }

    public function userAgent(): ?string
    {
        return null;
    }

    public function requestId(): ?string
    {
        return null;
    }
}
