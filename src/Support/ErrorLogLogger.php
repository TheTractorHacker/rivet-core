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
        error_log(self::oneLine(strtr((string) $message, $replace)));
    }

    /**
     * Core builds messages from exception texts that can carry caller-controlled data; a line break in one would let that
     * data forge extra log lines. Control characters become visible escapes (\n, \r, \xNN) instead.
     *
     * @internal
     */
    public static function oneLine(string $message): string
    {
        return preg_replace_callback('/[\x00-\x08\x0A-\x1F\x7F]/', static fn (array $m): string => match ($m[0]) {
            "\n" => '\\n',
            "\r" => '\\r',
            default => sprintf('\\x%02X', ord($m[0])),
        }, $message) ?? '';
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
                    ($this->sink)(ErrorLogLogger::oneLine((string) $message));
                }
            };
        }

        return new self();
    }
}
