<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Remote\ConsoleEventsService;
use RivetCore\Rmm\Remote\ConsoleLink;
use RivetCore\Rmm\Remote\ConsoleStore;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Tests\Support\ConsoleTestCase;

/** The events half of the Rivet Console contract: the report token, the identical 401, validation, caps, the unverified audit rows and the retention of what is stored. */
final class ConsoleEventsTest extends ConsoleTestCase
{
    private const UNAUTH = '{"ok":false,"error":"unauthorized"}';

    private function ev(string $type = 'file_upload', array $over = []): array
    {
        return $over + ['type' => $type, 'ts' => '2026-11-12T10:00:00Z', 'kind' => 'files', 'node' => 'node//xxxxxxxxxxxxxxxxxxxxxxxxxx', 'result' => 'ok', 'bytes' => 1234, 'count' => 1, 'path' => 'C:\\Users\\bob\\report.docx'];
    }

    /** @return array{0:string,1:string} [session id, token] */
    private function open(): array
    {
        $b = $this->session($this->tech, $this->devA, 'files');

        return [$b['session_id'], $b['report']['token']];
    }

    public function testAcceptsEventsAndWritesOneUnverifiedAuditRowEach(): void
    {
        [$sid, $tok] = $this->open();
        $r = $this->report($tok, $sid, [$this->ev('session_start'), $this->ev('file_upload'), $this->ev('file_download'), $this->ev('file_delete'), $this->ev('file_rename'), $this->ev('file_mkdir'), $this->ev('clipboard_sync_on'), $this->ev('clipboard_sync_off')]);
        $this->assertSame(200, $r->status);
        $this->assertSame('{"ok":true,"accepted":8}', $r->body);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $rows = $this->auditOf('Console Event Reported');
        $this->assertCount(8, $rows);
        foreach ($rows as $row) {
            $this->assertStringContainsString('reported by console, unverified', $row['description']);
            $this->assertStringContainsString("in session $sid (files) of user 18 (Tess) on device {$this->devA}", $row['description'], 'the technician and device come from Core\'s own session record');
            $this->assertStringContainsString('claimed time 2026-11-12T10:00:00Z, received ', $row['description']);
            $this->assertMatchesRegularExpression('/received \d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\)$/', $row['description']);
            $this->assertSame([$this->h->clientA], [$row['client_id']]);
        }
        $this->assertStringContainsString('file_upload', $rows[1]['description']);
        $this->assertStringContainsString('result ok, bytes 1234, count 1', $rows[1]['description']);
        $this->assertSame(8, (int) $this->h->module->sql()->val('SELECT events_count FROM rmm_console_sessions WHERE session_id = ?', [$sid]));
        $ev = $this->bus->of(RmmEvent::CONSOLE_EVENTS_REPORTED);
        $this->assertCount(1, $ev);
        $this->assertSame([$sid, 8, 0, false], [$ev[0]['session_id'], $ev[0]['accepted'], $ev[0]['dropped'], $ev[0]['truncated']]);
        $this->assertSame(['device_id', 'asset_id', 'client_id', 'hostname', 'occurred_at', 'session_id', 'accepted', 'dropped', 'truncated'], array_keys($ev[0]), 'counts only');
    }

    public function testTheTechnicianIsNeverTakenFromTheRequest(): void
    {
        [$sid, $tok] = $this->open();
        $raw = (string) json_encode(['session_id' => $sid, 'user_id' => 1, 'technician' => 'root', 'events' => [$this->ev('session_start', ['user' => 'root', 'technician' => ['username' => 'root'], 'device_id' => $this->devB])]]);
        $this->assertSame(200, $this->report($tok, $sid, [], '203.0.113.50', $raw)->status);
        $text = json_encode($this->auditOf('Console Event Reported'));
        $this->assertStringContainsString('user 18 (Tess)', (string) $text);
        $this->assertStringNotContainsString('root', (string) $text);
        $this->assertStringContainsString("on device {$this->devA}", (string) $text);
    }

    public function testTheTokenIsStoredHashedAndEveryAuthFailureIsTheSame401(): void
    {
        [$sid, $tok] = $this->open();
        $row = $this->h->module->sql()->one('SELECT token_hash FROM rmm_console_sessions WHERE session_id = ?', [$sid]);
        $this->assertSame(hash('sha256', $tok), $row['token_hash']);
        $this->assertStringNotContainsString($tok, (string) json_encode($this->h->module->sql()->all('SELECT * FROM rmm_console_sessions')));
        [$sid2, $tok2] = $this->open();
        $bad = [
            'missing' => $this->api->handle($this->req('POST', 'events', (string) json_encode(['session_id' => $sid, 'events' => []]), ['content-type' => 'application/json'])),
            'empty bearer' => $this->report('', $sid, [], '203.0.113.50', null, 'Bearer '),
            'not bearer' => $this->report('', $sid, [], '203.0.113.50', null, 'Basic ' . $tok),
            'unknown' => $this->report(str_repeat('A', 43), $sid, []),
            'malformed' => $this->report('short', $sid, []),
            'another sessions token' => $this->report($tok2, $sid, []),
            'code instead of token' => $this->report($this->launch($this->tech, $this->devA)[0], $sid, []),
        ];
        $this->assertSame(200, $this->report($tok2, $sid2, [])->status, 'control: a good token works');
        $this->advance(ConsoleStore::HARD_CAP_S + 1);
        $bad['expired (hard cap)'] = $this->report($tok, $sid, []);
        foreach ($bad as $why => $r) {
            if ($why === 'another sessions token') {
                $this->assertSame(400, $r->status, 'a valid token for another session id: the session id does not match');
                continue;
            }
            $this->assertSame([401, self::UNAUTH], [$r->status, $r->body], $why);
            $this->assertSame(['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], $r->headers, $why);
        }
    }

    public function testSessionEndShortensTheTokenToFiveMinutesAndWritesTheEndedRow(): void
    {
        [$sid, $tok] = $this->open();
        $r = $this->report($tok, $sid, [$this->ev('session_end', ['result' => 'cancelled'])]);
        $this->assertSame('{"ok":true,"accepted":1}', $r->body);
        $ended = $this->auditOf('Console Session Ended');
        $this->assertCount(1, $ended);
        $this->assertStringContainsString("Rivet Console session $sid of user 18 (Tess) on device {$this->devA} ended (reported by console, unverified, result cancelled", $ended[0]['description']);
        $this->assertSame([], $this->auditOf('Console Event Reported'), 'the end has its own row');
        $e = $this->bus->of(RmmEvent::CONSOLE_SESSION_ENDED);
        $this->assertSame([$sid, 18, 'files', 'cancelled'], [$e[0]['session_id'], $e[0]['user_id'], $e[0]['kind'], $e[0]['result']]);
        $this->advance(290);
        $this->assertSame(200, $this->report($tok, $sid, [$this->ev('file_upload')])->status, 'still valid inside the 5 minute grace');
        $again = $this->report($tok, $sid, [$this->ev('session_end')]);
        $this->assertSame('{"ok":true,"accepted":0}', $again->body, 'a second end records nothing');
        $this->assertCount(1, $this->auditOf('Console Session Ended'));
        $this->advance(20);
        $r = $this->report($tok, $sid, []);
        $this->assertSame([401, self::UNAUTH], [$r->status, $r->body], '5 minutes after the end');
    }

    public function testATokenNeverOutlivesTwelveHoursEvenWithoutAnEnd(): void
    {
        [$sid, $tok] = $this->open();
        $this->advance(43200 - 5);
        $this->assertSame(200, $this->report($tok, $sid, [])->status);
        $this->advance(10);
        $this->assertSame(401, $this->report($tok, $sid, [])->status);
    }

    public function testReportingWorksWithTheConsoleSwitchOffSoASessionCanEndOnTheRecord(): void
    {
        [$sid, $tok] = $this->open();
        $this->assertTrue($this->h->module->remoteActions()->updateSettings($this->admin, ['features' => ['remote_console' => false]])->ok);
        $this->assertSame(200, $this->report($tok, $sid, [$this->ev('session_end')])->status);
        $this->assertCount(1, $this->auditOf('Console Session Ended'));
    }

    public function testBadRequestsAre400InvalidRequestAndStoreNothing(): void
    {
        [$sid, $tok] = $this->open();
        $bad = ['{"session_id":', '[]', 'null', '"x"', json_encode(['session_id' => $sid]), json_encode(['events' => []]), json_encode(['session_id' => 'abcdef0123456789', 'events' => []]), json_encode(['session_id' => 5, 'events' => []]),
            json_encode(['session_id' => $sid, 'events' => 'x']), json_encode(['session_id' => $sid, 'events' => ['a' => 1]]), json_encode(['session_id' => $sid, 'events' => ['str']]),
            json_encode(['session_id' => $sid, 'events' => [[$this->ev()['type']]]]), json_encode(['session_id' => $sid, 'events' => [['type' => 5, 'ts' => 'x']]]),
            json_encode(['session_id' => $sid, 'events' => [['type' => 'file_upload']]]), json_encode(['session_id' => $sid, 'events' => [$this->ev('file_upload', ['ts' => 5])]]),
            json_encode(['session_id' => $sid, 'events' => [$this->ev('file_upload', ['bytes' => '5'])]]), json_encode(['session_id' => $sid, 'events' => [$this->ev('file_upload', ['bytes' => -1])]]),
            json_encode(['session_id' => $sid, 'events' => [$this->ev('file_upload', ['bytes' => 1.5])]]), json_encode(['session_id' => $sid, 'events' => [$this->ev('file_upload', ['count' => []])]]),
            json_encode(['session_id' => $sid, 'events' => [$this->ev('file_upload', ['result' => 'maybe'])]]), json_encode(['session_id' => $sid, 'events' => [$this->ev('file_upload', ['kind' => 'background'])]]),
            json_encode(['session_id' => $sid, 'events' => [$this->ev('file_upload', ['path' => ['x']])]]), json_encode(['session_id' => $sid, 'events' => [$this->ev('file_upload'), ['type' => 'file_delete']]])];
        foreach ($bad as $i => $raw) {
            $r = $this->report($tok, $sid, [], '203.0.113.50', (string) $raw);
            $this->assertSame([400, '{"ok":false,"error":"invalid_request"}'], [$r->status, $r->body], "case $i: $raw");
        }
        $this->assertSame([], $this->auditOf('Console Event Reported'));
        $this->assertSame(0, (int) $this->h->module->sql()->val('SELECT events_count FROM rmm_console_sessions WHERE session_id = ?', [$sid]));
    }

    public function testTooManyEventsOrTooManyBytesIs413(): void
    {
        [$sid, $tok] = $this->open();
        $hundred = array_fill(0, 100, $this->ev('clipboard_sync_on', ['path' => null]));
        $this->assertSame(200, $this->report($tok, $sid, $hundred)->status, '100 events are fine');
        $r = $this->report($tok, $sid, array_fill(0, 101, $this->ev('clipboard_sync_on')));
        $this->assertSame([413, '{"ok":false,"error":"too_large"}'], [$r->status, $r->body]);
        $big = (string) json_encode(['session_id' => $sid, 'events' => [$this->ev('file_upload', ['path' => str_repeat('a', 40000)])]]);
        $this->assertGreaterThan(32768, strlen($big));
        $r = $this->report($tok, $sid, [], '203.0.113.50', $big);
        $this->assertSame([413, '{"ok":false,"error":"too_large"}'], [$r->status, $r->body]);
        $exact = (string) json_encode(['session_id' => $sid, 'events' => [], 'pad' => str_repeat('p', 32768 - 60)]);
        $this->assertLessThanOrEqual(32768, strlen($exact));
        $this->assertSame(200, $this->report($tok, $sid, [], '203.0.113.50', $exact)->status);
        // the declared length is enough to refuse without reading
        $req = new \RivetCore\Rmm\Http\RmmRequest('POST', 'endpoint_console', ['events'], [], ['content-type' => 'application/json', 'authorization' => 'Bearer ' . $tok], '203.0.113.50', null, true, 999999, null);
        $this->assertSame(413, $this->api->handle($req)->status);
    }

    public function testRateLimitIs60RequestsAMinutePerSession(): void
    {
        [$sid, $tok] = $this->open();
        [$sid2, $tok2] = $this->open();
        for ($i = 1; $i <= 60; ++$i) {
            $this->assertSame(200, $this->report($tok, $sid, [])->status, "request $i");
        }
        $r = $this->report($tok, $sid, []);
        $this->assertSame([429, '{"ok":false,"error":"rate_limited"}', '60'], [$r->status, $r->body, $r->headers['Retry-After']]);
        $this->assertSame(200, $this->report($tok2, $sid2, [])->status, 'another session has its own budget');
        $this->assertSame(401, $this->report('B' . str_repeat('A', 42), $sid, [])->status, 'an unauthenticated caller does not reach the limiter');
    }

    public function testUnknownAndReservedTypesAreDroppedSilentlyAndNotCounted(): void
    {
        [$sid, $tok] = $this->open();
        $r = $this->report($tok, $sid, [$this->ev('chat_message'), $this->ev('chat_start'), $this->ev('background_start'), $this->ev('background_authorize'), $this->ev('evil'), ['type' => 'SESSION_START'], $this->ev('file_upload'), ['type' => 'weird', 'ts' => []]]);
        $this->assertSame('{"ok":true,"accepted":1}', $r->body);
        $this->assertCount(1, $this->auditOf('Console Event Reported'));
        $this->assertSame(1, (int) $this->h->module->sql()->val('SELECT events_count FROM rmm_console_sessions WHERE session_id = ?', [$sid]));
        $ev = $this->bus->of(RmmEvent::CONSOLE_EVENTS_REPORTED);
        $this->assertSame([1, 7], [$ev[0]['accepted'], $ev[0]['dropped']]);
        $nothing = $this->report($tok, $sid, [$this->ev('chat_message')]);
        $this->assertSame('{"ok":true,"accepted":0}', $nothing->body);
    }

    public function testPathsAreStoredOnlyWhenTheSettingIsOnCappedAndCleaned(): void
    {
        [$sid, $tok] = $this->open();
        $evil = "C:\\Data\\a\nGHOST ROW: Console Link Redeemed\r\x00\x1b[31m\u{2028}\u{2029}\u{0085}x" . str_repeat('p', 400);
        $this->report($tok, $sid, [$this->ev('file_upload', ['path' => $evil])]);
        $row = $this->auditOf('Console Event Reported')[0]['description'];
        $this->assertStringNotContainsString('path', $row);
        $this->assertStringNotContainsString('Data', $row, 'off by default: no path at all');
        $this->assertTrue($this->h->module->remoteActions()->updateSettings($this->admin, ['console_log_file_paths' => true])->ok);
        $b = $this->session($this->tech, $this->devA, 'files');
        $this->assertTrue($b['report']['paths'], 'the redeem tells the console it may send paths');
        $this->report($b['report']['token'], $b['session_id'], [$this->ev('file_upload', ['path' => $evil]), $this->ev('file_delete', ['path' => "ok/\u{202e}name"])]);
        $rows = $this->auditOf('Console Event Reported');
        $this->assertCount(3, $rows);
        $d = $rows[1]['description'];
        $this->assertStringContainsString(', path C:\\Data\\aGHOST ROW: Console Link Redeemed[31mx', $d, 'the control bytes and line breaks are gone, the printable rest stays on one line');
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1f\x7f]|\x{2028}|\x{2029}|\x{0085}/u', $d);
        preg_match('/, path (.*?) \(reported/su', $d, $m);
        $this->assertLessThanOrEqual(256, mb_strlen($m[1]));
        $this->assertStringNotContainsString("\u{202e}", $rows[2]['description'], 'bidi control characters are stripped too');
        $this->assertCount(0, array_filter($this->h->audit->records(), static fn (array $a): bool => str_starts_with($a['description'], 'GHOST')), 'no forged second line');
    }

    public function testControlCharactersAreStrippedFromTheClaimedTimeToo(): void
    {
        [$sid, $tok] = $this->open();
        $this->report($tok, $sid, [$this->ev('file_upload', ['ts' => "2026\n-FORGED row\x00" . str_repeat('t', 100)])]);
        $d = $this->auditOf('Console Event Reported')[0]['description'];
        $this->assertStringNotContainsString("\n", $d);
        preg_match('/claimed time (.*?), received/', $d, $m);
        $this->assertLessThanOrEqual(32, strlen($m[1]));
    }

    public function testTheSessionCapIs500ThenOneTruncatedRowAndAnEndIsStillRecorded(): void
    {
        [$sid, $tok] = $this->open();
        $this->limitOverride['console_events:' . $sid] = 0;
        for ($i = 0; $i < 5; ++$i) {
            $this->assertSame('{"ok":true,"accepted":100}', $this->report($tok, $sid, array_fill(0, 100, $this->ev('clipboard_sync_on')))->body);
        }
        $this->assertCount(500, $this->auditOf('Console Event Reported'));
        $this->assertSame([], $this->auditOf('Console Events Truncated'));
        $r = $this->report($tok, $sid, [$this->ev('file_upload'), $this->ev('file_upload')]);
        $this->assertSame('{"ok":true,"accepted":0}', $r->body, 'over the cap: not stored and not counted');
        $r = $this->report($tok, $sid, [$this->ev('file_upload')]);
        $this->assertSame('{"ok":true,"accepted":0}', $r->body);
        $this->assertCount(500, $this->auditOf('Console Event Reported'));
        $trunc = $this->auditOf('Console Events Truncated');
        $this->assertCount(1, $trunc, 'one row, however many times it happens');
        $this->assertStringContainsString('more than 500 events', $trunc[0]['description']);
        $this->assertStringContainsString('reported by console, unverified', $trunc[0]['description']);
        $r = $this->report($tok, $sid, [$this->ev('session_end')]);
        $this->assertSame('{"ok":true,"accepted":1}', $r->body);
        $this->assertCount(1, $this->auditOf('Console Session Ended'));
        $e = array_values(array_filter($this->bus->of(RmmEvent::CONSOLE_EVENTS_REPORTED), static fn (array $e): bool => $e['truncated']));
        $this->assertNotSame([], $e);
        $this->assertSame(ConsoleEventsService::SESSION_CAP, 500);
    }

    public function testNoEventPayloadRowOrAuditTextCarriesASecret(): void
    {
        $b = $this->session($this->tech, $this->devA, 'files');
        [$sid, $tok, $code, $cookie] = [$b['session_id'], $b['report']['token'], $b['_code'], $b['mesh']['cookie']];
        $this->report($tok, $sid, [$this->ev('session_start'), $this->ev('file_upload'), $this->ev('session_end')]);
        $all = $this->everythingWritten();
        foreach ([$tok, hash('sha256', $tok), $code, ConsoleLink::hashCode($code), $cookie] as $s) {
            $this->assertStringNotContainsString($s, $all);
        }
        $this->assertStringNotContainsString('login=', $all);
        $this->assertStringNotContainsString('Bearer', $all);
    }

    public function testClipboardAndChatContentsCannotEndUpStored(): void
    {
        [$sid, $tok] = $this->open();
        $this->report($tok, $sid, [$this->ev('clipboard_sync_on', ['content' => 'SECRET-CLIPBOARD', 'text' => 'SECRET-CLIPBOARD', 'data' => 'SECRET-CLIPBOARD'])]);
        $this->assertStringNotContainsString('SECRET-CLIPBOARD', $this->everythingWritten());
    }
}
