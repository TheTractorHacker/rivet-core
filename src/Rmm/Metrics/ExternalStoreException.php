<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Metrics;

/**
 * The one exception an external metric store adapter throws. {@see $permanent} tells the outbox whether retrying the same batch can
 * ever succeed: true for a rejected request (HTTP 4xx other than 429, a batch the store refuses), false for everything that may heal on
 * its own (network, timeout, 429, 5xx, a URL the policy refuses until the configuration is fixed).
 *
 * The message is safe to log and to store: adapters never put credentials, URLs with a query or response bodies in it.
 *
 * @api
 */
final class ExternalStoreException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function isPermanent(): bool
    {
        return $this->permanent;
    }
}
