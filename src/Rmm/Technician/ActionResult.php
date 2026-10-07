<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Technician;

/**
 * The outcome of a technician or administrator action: whether it worked, the HTTP status the REST API answers with, a stable code
 * (`queued`, `cancelled`, `forbidden`, `not_found`, `conflict`, `invalid`, `confirmation_required`, `device_offline`, ...), a message that
 * is safe to show to the caller, and result data (`job_id`, `url`, `session_id`, `token`, ...). The web handlers and the REST API both
 * render from this one object, so they cannot drift apart.
 *
 * @api
 */
final class ActionResult
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly bool $ok,
        public readonly int $http,
        public readonly string $code,
        public readonly string $message,
        public readonly array $data = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function ok(string $message, int $http = 200, string $code = 'ok', array $data = []): self
    {
        return new self(true, $http, $code, $message, $data);
    }

    /** @param array<string,mixed> $data */
    public static function fail(int $http, string $code, string $message, array $data = []): self
    {
        return new self(false, $http, $code, $message, $data);
    }
}
