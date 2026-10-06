<?php

declare(strict_types=1);

namespace RivetCore\Mcp;

use RivetCore\Audit\AuditService;
use RivetCore\Contracts\RequestContextInterface;
use RivetCore\Redis\RateLimiter;

/**
 * The common path every MCP tool call takes: caller -> rate limit -> permission check -> the tool's own
 * scoped query -> audit row -> standard envelope {success, request_id, data, errors}.
 *
 * Authentication (turning the token into a user id) and the permission model stay with the edition: the
 * edition passes the resolved user id and an $allow callable. Audit failures never turn a permitted read into
 * an error. Tool bodies signal "not found or out of scope" by throwing NotFoundException.
 *
 * The constructor has seven parameters that grew over time: named arguments are the supported calling style
 * (`new ToolPipeline(rateLimiter: $rl, audit: $audit, request: $ctx, rateLimit: 30, logError: $logger)`). Positional order
 * is kept for compatibility; new options are only ever appended.
 *
 * Logging: $logError accepts a PSR-3 LoggerInterface (preferred) or null (ErrorLogLogger). The Closure form (receives the
 * message string) is @deprecated: kept working through all of 1.x, removed in 2.0.
 *
 * @api
 */
final class ToolPipeline
{
    public function __construct(
        private RateLimiter $rateLimiter,
        private AuditService $audit,
        private RequestContextInterface $request,
        private int $rateLimit = 60,
        private int $rateWindow = 60,
        private string $source = 'mcp',
        /** @deprecated the Closure form; pass a PSR-3 LoggerInterface (kept through 1.x, removed in 2.0) */
        private \Closure|\Psr\Log\LoggerInterface|null $logError = null,
    ) {
    }

    /**
     * @param int|null $userId the resolved caller, or null when authorization failed
     * @param callable(int):bool $allow does this caller's role allow the tool?
     * @param callable(int):mixed $body runs the tool for the caller and returns its data
     * @param array<string,mixed> $args
     * @return array{success:bool, request_id:string, data:mixed, errors:list<array{code:string,message:string}>}
     */
    public function run(?int $userId, string $tool, array $args, callable $allow, callable $body, string $roleName = 'role'): array
    {
        $requestId = $this->request->requestId() ?? ('req_' . bin2hex(random_bytes(8)));
        if ($userId === null || $userId < 1) {
            return self::envelope($requestId, null, 'PERMISSION_DENIED', 'MCP authorization failed.');
        }

        $limit = $this->rateLimiter->hit("{$this->source}:u$userId", $this->rateLimit, $this->rateWindow);
        if (!$limit['allowed']) {
            $this->record($userId, $tool, $args, 'rate_limited');

            return self::envelope($requestId, null, 'RATE_LIMITED', 'Too many requests. Retry in ' . $limit['retry_after'] . 's.');
        }
        try {
            $allowed = (bool) $allow($userId);
        } catch (\Throwable $e) {
            // Fail closed: a permission check that cannot answer (database down, bad role row) is a "no", never a 500 that
            // an edition might turn into "yes".
            $this->log("MCP $tool permission check failed: " . $e->getMessage());
            $allowed = false;
        }
        if (!$allowed) {
            $this->record($userId, $tool, $args, 'denied');

            return self::envelope($requestId, null, 'PERMISSION_DENIED', "Your $roleName does not allow this.");
        }
        try {
            $data = $body($userId);
        } catch (NotFoundException) {
            $this->record($userId, $tool, $args, 'not_found');

            return self::envelope($requestId, null, 'NOT_FOUND', 'Not found.');
        } catch (\Throwable $e) {
            $this->log("MCP $tool failed: " . $e->getMessage());
            $this->record($userId, $tool, $args, 'error');

            return self::envelope($requestId, null, 'INTERNAL_ERROR', 'The request could not be completed.');
        }
        $this->record($userId, $tool, $args, 'ok', is_array($data) && array_is_list($data) ? count($data) : 1);

        return ['success' => true, 'request_id' => $requestId, 'data' => $data, 'errors' => []];
    }

    /**
     * @internal used by run(); not part of the supported API
     *
     * @return array{success:bool, request_id:string, data:mixed, errors:list<array{code:string,message:string}>}
     */
    public static function envelope(string $requestId, mixed $data, string $code, string $message): array
    {
        return ['success' => false, 'request_id' => $requestId, 'data' => $data, 'errors' => [['code' => $code, 'message' => $message]]];
    }

    /**
     * Best effort: an audit failure must never turn a permitted read into an error.
     *
     * @param array<string,mixed> $args
     */
    private function record(int $userId, string $tool, array $args, string $outcome, ?int $rows = null): void
    {
        try {
            $safe = array_map(static fn ($v) => is_string($v) ? mb_substr($v, 0, 100) : $v, $args);
            $this->audit->log("{$this->source}.tool_call", $userId, "{$this->source}_tool", $tool, 'read',
                strtoupper($this->source) . " $tool: $outcome",
                ['source' => $this->source, 'tool' => $tool, 'outcome' => $outcome, 'args' => $safe, 'rows' => $rows]);
        } catch (\Throwable $e) {
            $this->log('MCP audit failed: ' . $e->getMessage());
        }
    }

    private function log(string $message): void
    {
        \RivetCore\Support\ErrorLogLogger::resolve($this->logError)->error($message);
    }
}
