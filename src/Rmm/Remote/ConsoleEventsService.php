<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Support\RmmEventPublisher;

/**
 * Receives the activity a Rivet Console reports about its own session (`POST /api/v1/endpoint_console/events`, see {@see \RivetCore\Rmm\Http\ConsoleApi}), authenticated by the
 * report token the redeem returned (stored only as its SHA-256; valid until the session ends plus 5 minutes, at most 12 hours).
 *
 * THE DATA IS UNVERIFIED. The console says what it did; Core cannot check. Every stored event is therefore one audit row marked "reported by console (unverified)", with Core's own
 * receive time beside the claimed time, the technician and the device taken from Core's own session record (never from the request), and text stripped of control characters.
 * A file path is stored only while the `console_log_file_paths` setting is on (default off), capped at 256 characters. Unknown event types and the reserved ones (`chat_*`,
 * `background_*`) are dropped silently and not counted. At most {@see SESSION_CAP} events are stored per session: the rest are dropped and one `Console Events Truncated` row
 * says so (a `session_end` is always recorded). The audit rows cannot be switched off. Clipboard, chat and file contents, cookies, codes and the token are never present.
 *
 * @api
 */
final class ConsoleEventsService
{
    public const EVENT_TYPES = ['session_start', 'session_end', 'file_upload', 'file_download', 'file_delete', 'file_rename', 'file_mkdir', 'clipboard_sync_on', 'clipboard_sync_off'];
    public const RESULTS = ['ok', 'error', 'cancelled'];
    public const MAX_EVENTS = 100;
    public const MAX_BYTES = 32768;
    public const SESSION_CAP = 500;
    public const PATH_MAX = 256;
    public const RATE_LIMIT = 60;
    public const RATE_WINDOW_S = 60;

    /**
     * @param \Closure(string,int,int):bool $rateLimit (bucket, limit, windowSeconds): true when within budget
     */
    public function __construct(
        private readonly ConsoleStore $store,
        private readonly ConsoleSettings $settings,
        private readonly RmmAuditInterface $audit,
        private readonly \Closure $rateLimit,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    /**
     * The session a bearer token belongs to, or null (missing, unknown, expired: all the same to the caller).
     *
     * @return array{session_id:string,user_id:int,user_name:string,device_id:int,client_id:int,asset_id:int,node_id:string,kind:string,ended:bool,events_count:int,truncated:bool}|null
     */
    public function authenticate(?string $authorization): ?array
    {
        if ($authorization === null || preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/D', trim($authorization), $m) !== 1) {
            return null;
        }

        return $this->store->sessionForToken(hash('sha256', $m[1]));
    }

    /** True while the session is within its request budget (60 a minute). */
    public function withinRate(string $sessionId): bool
    {
        return ($this->rateLimit)('console_events:' . $sessionId, self::RATE_LIMIT, self::RATE_WINDOW_S);
    }

    /**
     * @param array{session_id:string,user_id:int,user_name:string,device_id:int,client_id:int,asset_id:int,node_id:string,kind:string,ended:bool,events_count:int,truncated:bool} $session
     * @return array{http:int,body:array<string,mixed>}
     */
    public function accept(array $session, string $raw): array
    {
        $bad = ['http' => 400, 'body' => ['ok' => false, 'error' => 'invalid_request']];
        if (strlen($raw) > self::MAX_BYTES) {
            return ['http' => 413, 'body' => ['ok' => false, 'error' => 'too_large']];
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || array_is_list($d) || ($d['session_id'] ?? null) !== $session['session_id'] || !is_array($d['events'] ?? null) || !array_is_list($d['events'])) {
            return $bad;
        }
        if (count($d['events']) > self::MAX_EVENTS) {
            return ['http' => 413, 'body' => ['ok' => false, 'error' => 'too_large']];
        }
        // Validate everything first: a request with a bad field stores nothing.
        $todo = [];
        $dropped = 0;
        foreach ($d['events'] as $e) {
            if (!is_array($e) || array_is_list($e) || !is_string($e['type'] ?? null)) {
                return $bad;
            }
            if (!in_array($e['type'], self::EVENT_TYPES, true)) {
                ++$dropped;   // unknown and reserved types (chat_*, background_*): dropped silently
                continue;
            }
            $v = $this->validate($e);
            if ($v === null) {
                return $bad;
            }
            $todo[] = $v;
        }
        $paths = $this->settings->get()['log_file_paths'];
        $now = $this->store->isoNow();
        $accepted = 0;
        $truncated = false;
        $count = $session['events_count'];
        foreach ($todo as $v) {
            if ($v['type'] !== 'session_end' && $count >= self::SESSION_CAP) {
                ++$dropped;
                $truncated = true;
                continue;
            }
            if ($v['type'] === 'session_end') {
                if (!$this->store->endSession($session['session_id'])) {
                    ++$dropped;   // already ended: nothing new to record
                    continue;
                }
                $this->audit->record('Console Session Ended', 'Rivet Console session ' . $session['session_id'] . ' of ' . $this->who($session) . ' on device ' . $session['device_id']
                    . ' ended (reported by console, unverified' . ($v['result'] !== null ? ', result ' . $v['result'] : '') . '; claimed time ' . $v['ts'] . ', received ' . $now . ')', $session['client_id'], $session['asset_id']);
                $this->emit(RmmEvent::CONSOLE_SESSION_ENDED, $session, ['session_id' => $session['session_id'], 'user_id' => $session['user_id'], 'kind' => $session['kind'], 'result' => $v['result']]);
            } else {
                $extra = ($v['result'] !== null ? ', result ' . $v['result'] : '') . ($v['bytes'] !== null ? ', bytes ' . $v['bytes'] : '') . ($v['count'] !== null ? ', count ' . $v['count'] : '')
                    . ($paths && $v['path'] !== null ? ', path ' . $v['path'] : '');
                $this->audit->record('Console Event Reported', 'Rivet Console event ' . $v['type'] . ' in session ' . $session['session_id'] . ' (' . $session['kind'] . ') of ' . $this->who($session) . ' on device ' . $session['device_id']
                    . $extra . ' (reported by console, unverified; claimed time ' . $v['ts'] . ', received ' . $now . ')', $session['client_id'], $session['asset_id']);
            }
            ++$accepted;
            ++$count;
        }
        if ($accepted > 0) {
            $this->store->addEvents($session['session_id'], $accepted);
        }
        if ($truncated && $this->store->markTruncated($session['session_id'])) {
            $this->audit->record('Console Events Truncated', 'Rivet Console session ' . $session['session_id'] . ' of ' . $this->who($session) . ' on device ' . $session['device_id']
                . ' reported more than ' . self::SESSION_CAP . ' events: the rest were not stored (reported by console, unverified)', $session['client_id'], $session['asset_id']);
        }
        $this->emit(RmmEvent::CONSOLE_EVENTS_REPORTED, $session, ['session_id' => $session['session_id'], 'accepted' => $accepted, 'dropped' => $dropped, 'truncated' => $truncated]);

        return ['http' => 200, 'body' => ['ok' => true, 'accepted' => $accepted]];
    }

    /**
     * @param array<mixed> $e
     * @return array{type:string,ts:string,result:?string,bytes:?int,count:?int,path:?string}|null null when a field has the wrong type
     */
    private function validate(array $e): ?array
    {
        $ts = $e['ts'] ?? null;
        if (!is_string($ts)) {
            return null;
        }
        $result = $e['result'] ?? null;
        if ($result !== null && (!is_string($result) || !in_array($result, self::RESULTS, true))) {
            return null;
        }
        if (isset($e['kind']) && (!is_string($e['kind']) || !ConsoleLink::validKind($e['kind']))) {
            return null;
        }
        if (isset($e['node']) && !is_string($e['node'])) {
            return null;
        }
        $n = [];
        foreach (['bytes', 'count'] as $k) {
            $v = $e[$k] ?? null;
            if ($v !== null && (!is_int($v) || $v < 0)) {
                return null;
            }
            $n[$k] = $v;
        }
        $path = $e['path'] ?? null;
        if ($path !== null && !is_string($path)) {
            return null;
        }

        return ['type' => (string) $e['type'], 'ts' => ConsoleText::clean($ts, 32), 'result' => $result, 'bytes' => $n['bytes'], 'count' => $n['count'], 'path' => $path === null ? null : ConsoleText::clean($path, self::PATH_MAX)];
    }

    /**
     * "user 12 (Alice)", from Core's own session record.
     *
     * @param array{user_id:int,user_name:string,...} $session
     */
    private function who(array $session): string
    {
        return 'user ' . $session['user_id'] . ' (' . ConsoleText::name($session['user_name']) . ')';
    }

    /**
     * @param array{device_id:int,client_id:int,asset_id:int} $session
     * @param array<string,mixed> $fields
     */
    private function emit(string $event, array $session, array $fields): void
    {
        $this->events?->emit($event, ['device_id' => $session['device_id'], 'asset_id' => $session['asset_id'], 'client_id' => $session['client_id'], 'hostname' => ''], $fields);
    }
}
