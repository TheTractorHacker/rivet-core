<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * Why a body was refused. The code is a fixed token and the message a fixed sentence: neither ever contains any part of the body.
 *
 * @api
 */
final class PayloadRejection
{
    public const TOO_LARGE = 'too_large';
    public const INVALID_JSON = 'invalid_json';
    public const NOT_AN_OBJECT = 'not_an_object';
    public const NO_MONITOR = 'no_monitor';
    public const BAD_STATUS = 'bad_status';
    public const BAD_FIELD = 'bad_field';
    public const BAD_TIME = 'bad_time';

    public function __construct(public readonly string $code, public readonly string $message)
    {
    }

    /** The HTTP status the handler answers with. */
    public function httpStatus(): int
    {
        return $this->code === self::TOO_LARGE ? 413 : 400;
    }
}
