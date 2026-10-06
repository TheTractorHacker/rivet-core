<?php

declare(strict_types=1);

namespace RivetCore\Tests\Security;

use RivetCore\Audit\AuditReader;
use RivetCore\Audit\AuditService;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Database\ExecutionResult;
use RivetCore\Mcp\ToolPipeline;
use RivetCore\Redis\RateLimiter;
use RivetCore\Support\ErrorLogLogger;
use RivetCore\Support\NullRequestContext;

/** Audit and MCP-pipeline surface: RC-SR2-03, -07, -08, -09, -10, -11, -12. */
final class AuditSurfaceTest extends SecurityTestCase
{
    /**
     * Documents RC-SR2-07: AuditService::redact() stops recursing at depth 8 and returns the remaining value UNCHANGED,
     * so a secret key nested nine levels deep is stored in clear in metadata_json. Flip: assert '[redacted]' (or drop
     * the subtree) at every depth. PayloadFormatter::redact() already drops deep subtrees; the same pattern fits.
     */
    public function testRc07SecretKeyBelowDepthEightIsStoredInClear(): void
    {
        $db = $this->db('audit_events');
        $deep = ['password' => 'hunter2-deep'];
        for ($i = 0; $i < 10; $i++) {
            $deep = ['level' => $deep];
        }
        (new AuditService($db, new NullRequestContext()))->log('t.e', 1, 't', 1, 'a', 's', ['wrapper' => $deep, 'password' => 'hunter2-top']);
        $json = (string) $db->fetchOne('SELECT metadata_json FROM audit_events')['metadata_json'];
        $this->assertStringNotContainsString('hunter2-top', $json, 'top-level keys are redacted');
        $this->assertStringContainsString('hunter2-deep', $json, 'RC-SR2-07: a deep one is not');
    }

    /**
     * Documents RC-SR2-08: the redaction list is an exact, anchored match of 14 key names. Common variants are stored
     * in clear: new_password, current_password, webhook_secret, mfa_secret, api-key (hyphen), x-api-key, bearer, cookie,
     * session_id. (PayloadFormatter uses a much broader substring match for the same purpose.) Flip: assert each is
     * '[redacted]'.
     */
    public function testRc08SecretKeyVariantsAreNotRedacted(): void
    {
        $db = $this->db('audit_events');
        $meta = ['new_password' => 'v1', 'current_password' => 'v2', 'webhook_secret' => 'v3', 'mfa_secret' => 'v4', 'api-key' => 'v5', 'x-api-key' => 'v6', 'bearer' => 'v7', 'cookie' => 'v8', 'session_id' => 'v9', 'Password' => 'caught-case-insensitively'];
        (new AuditService($db, new NullRequestContext()))->log('t.e', 1, 't', 1, 'a', 's', $meta);
        $stored = json_decode((string) $db->fetchOne('SELECT metadata_json FROM audit_events')['metadata_json'], true);
        foreach (['new_password', 'current_password', 'webhook_secret', 'mfa_secret', 'api-key', 'x-api-key', 'bearer', 'cookie', 'session_id'] as $k) {
            $this->assertSame($meta[$k], $stored[$k], $k . ' is stored in clear');
        }
        $this->assertSame('[redacted]', $stored['Password']);
    }

    /**
     * Documents RC-SR2-09: the afterLog fan-out hook receives the ORIGINAL metadata and summary, not the redacted copy
     * that is stored. An edition that forwards audit events to webhooks (the intended use) sends secrets that the audit
     * row itself hides, and WebhookDispatcher then keeps the body in webhook_deliveries.request_payload_json. Flip:
     * assert the hook sees '[redacted]'.
     */
    public function testRc09AfterLogHookReceivesUnredactedMetadata(): void
    {
        $db = $this->db('audit_events');
        $seen = null;
        $audit = new AuditService($db, new NullRequestContext(), function (string $type, ?int $actor, ?string $et, ?string $eid, string $action, ?string $summary, array $metadata) use (&$seen): void {
            $seen = $metadata;
        });
        $audit->log('t.e', 1, 't', 1, 'a', 's', ['password' => 'hunter2']);
        $this->assertSame('hunter2', $seen['password']);
        $this->assertStringContainsString('[redacted]', (string) $db->fetchOne('SELECT metadata_json FROM audit_events')['metadata_json']);
    }

    /**
     * Documents RC-SR2-10: every audit field is clamped to its column except metadata_json (TEXT, 64 KB). ToolPipeline
     * shortens top-level string arguments to 100 characters but passes nested arrays through, so an argument of about
     * 70 KB makes the audit INSERT fail under strict SQL mode. record() swallows the failure by design ("an audit
     * failure must never turn a permitted read into an error"), so the call succeeds and NO audit row exists. An
     * authenticated caller who can send nested arguments can therefore suppress the audit trail of a read. Flip: assert
     * one audit row (bound the metadata size, store a truncated marker on overflow).
     */
    public function testRc10OversizeNestedArgumentSuppressesTheAuditRow(): void
    {
        $db = $this->db('audit_events');
        $errors = [];
        $pipeline = new ToolPipeline(new RateLimiter($this->downRedis(), 'p:'), new AuditService($db, new NullRequestContext()), new NullRequestContext(), 60, 60, 'mcp', function (string $m) use (&$errors): void {
            $errors[] = $m;
        });
        $result = $pipeline->run(5, 'search_tickets', ['filter' => ['q' => str_repeat('A', 70000)]], fn () => true, fn () => ['row 1', 'row 2']);
        $this->assertTrue($result['success'], 'the read was served');
        $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) AS c FROM audit_events')['c'], 'and left no audit row');
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('MCP audit failed', $errors[0]);

        // a normal call is audited
        $pipeline->run(5, 'search_tickets', ['q' => 'printer'], fn () => true, fn () => ['row']);
        $this->assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) AS c FROM audit_events')['c']);
    }

    /**
     * Documents RC-SR2-11: ToolPipeline writes one audit row for EVERY rate-limited request, so the limiter bounds the
     * work done but not the audit growth: a caller with a valid token can add unbounded rows (and, with retention, push
     * real events out of the window). Needs Redis. Flip: assert only the first rejection in a window is recorded.
     */
    public function testRc11RateLimitedRequestsAreEachAudited(): void
    {
        $db = $this->db('audit_events');
        $limiter = new RateLimiter($this->redis(), $this->prefix());
        $pipeline = new ToolPipeline($limiter, new AuditService($db, new NullRequestContext()), new NullRequestContext(), 1, 60);
        for ($i = 0; $i < 6; $i++) {
            $pipeline->run(77, 'search_tickets', ['q' => 'x'], fn () => true, fn () => ['row']);
        }
        $this->assertSame(5, (int) $db->fetchOne("SELECT COUNT(*) AS c FROM audit_events WHERE summary LIKE '%rate_limited'")['c']);
        $this->assertSame(6, (int) $db->fetchOne('SELECT COUNT(*) AS c FROM audit_events')['c']);
    }

    /**
     * Documents RC-SR2-12: ErrorLogLogger writes the message to error_log() verbatim. Exception messages (database
     * errors that echo values, poppler stderr that echoes names from the PDF) can contain CR/LF, so a message can forge
     * extra log lines. Flip: assert a single line (strip or escape control characters).
     */
    public function testRc12ErrorLogLoggerAllowsLogForging(): void
    {
        $file = $this->tmpFile('errorlog.txt');
        @unlink($file);
        $previous = ini_set('error_log', $file);
        try {
            (new ErrorLogLogger())->error("MCP search failed: boom\n[06-Oct-2026 00:00:00 UTC] PHP Notice: admin login ok");
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));
        @unlink($file);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('admin login ok', $lines[1]);
    }

    /**
     * Documents RC-SR2-03 (performance): audit_events has no index that starts with created_at, so the retention DELETE
     * (created_at < ?) and the AuditReader date filters cannot use one, AuditReader::page() pages with LIMIT/OFFSET and
     * runs a COUNT(*) per page, and `search` is LIKE '%x%' over summary and the TEXT metadata column. Measured numbers
     * are in docs/SECURITY-REVIEW-2.md. Flip: assert an index with created_at as its first column exists.
     */
    public function testRc03AuditEventsHasNoCreatedAtLeadingIndexAndPagesWithOffset(): void
    {
        $db = $this->db();
        $leading = [];
        foreach ($db->fetchAll('SHOW INDEX FROM audit_events') as $ix) {
            if ((int) $ix['Seq_in_index'] === 1) {
                $leading[(string) $ix['Column_name']] = true;
            }
        }
        $this->assertArrayNotHasKey('created_at', $leading);

        $sql = [];
        $spy = new class ($db, $sql) implements DatabaseInterface {
            /** @param list<string> $log */
            public function __construct(private DatabaseInterface $inner, public array &$log)
            {
            }

            public function fetchOne(string $sql, array $params = []): ?array
            {
                $this->log[] = $sql;

                return $this->inner->fetchOne($sql, $params);
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                $this->log[] = $sql;

                return $this->inner->fetchAll($sql, $params);
            }

            public function execute(string $sql, array $params = []): ExecutionResult
            {
                return $this->inner->execute($sql, $params);
            }

            public function transaction(callable $callback): mixed
            {
                return $this->inner->transaction($callback);
            }
        };
        (new AuditReader($spy))->page(['search' => 'x'], 1, 50);
        $this->assertNotEmpty(array_filter($sql, static fn (string $q): bool => str_contains($q, 'OFFSET')));
        $this->assertNotEmpty(array_filter($sql, static fn (string $q): bool => str_contains($q, 'COUNT(*)')));
    }
}
