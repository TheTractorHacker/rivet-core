<?php

declare(strict_types=1);

namespace RivetCore\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * The default logger: writes to PHP's error_log(), which is where Core's messages went before it accepted a PSR-3
 * logger. Editions that want structured logs inject their own {@see LoggerInterface}.
 *
 * @api
 */
final class ErrorLogLogger extends AbstractLogger
{
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof \Stringable) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }
        error_log(strtr((string) $message, $replace));
    }

    /**
     * Accepts what the older constructors took (a closure receiving the message), a PSR-3 logger, or null for the default.
     *
     * @internal
     */
    public static function resolve(\Closure|LoggerInterface|null $logger): LoggerInterface
    {
        if ($logger instanceof LoggerInterface) {
            return $logger;
        }
        if ($logger instanceof \Closure) {
            return new class ($logger) extends AbstractLogger {
                public function __construct(private \Closure $sink)
                {
                }

                public function log($level, \Stringable|string $message, array $context = []): void
                {
                    ($this->sink)((string) $message);
                }
            };
        }

        return new self();
    }
}
