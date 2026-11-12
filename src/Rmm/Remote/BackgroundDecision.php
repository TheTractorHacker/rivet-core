<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

/**
 * The answer of {@see BackgroundModePolicy}: allowed or not, a stable reason code, text that is safe to show the caller, and what the person at the device is
 * owed (`none`, `notify`, or `approve`, which Core cannot grant by itself, so an `approve` client is a refusal until an approval flow exists).
 *
 * @api
 */
final class BackgroundDecision
{
    public const NOTICE_NONE = 'none';
    public const NOTICE_NOTIFY = 'notify';
    public const NOTICE_APPROVE = 'approve';

    private function __construct(
        public readonly bool $allowed,
        public readonly string $code,
        public readonly string $message,
        public readonly string $notice = self::NOTICE_NONE,
    ) {
    }

    public static function allow(string $code, string $notice = self::NOTICE_NONE): self
    {
        return new self(true, $code, 'Allowed.', $notice);
    }

    public static function deny(string $code, string $message, string $notice = self::NOTICE_NONE): self
    {
        return new self(false, $code, $message, $notice);
    }
}
