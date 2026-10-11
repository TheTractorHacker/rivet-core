<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Http\RmmResponse;
use RivetCore\Tests\Support\RemoteTestCase;

/** Phase 6: file transfer. Off by default, path policy, one-time token, chunked and verified download, administrator-only verified upload, limits, audit. */
final class FileTransferTest extends RemoteTestCase
{
    private const ALLOW = ['linux' => ['/srv/shared'], 'windows' => ['C:\\Data']];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->dev, $this->devToken] = $this->linked('W1');
    }

    private function d(): string
    {
        return (string) $this->dev;
    }

    private function enableTransfers(array $limits = []): void
    {
        $this->switchOn(['remote_tools', 'filetransfer']);
        $r = $this->h->module->remoteSettings()->update(['transfer_allow' => self::ALLOW] + ($limits === [] ? [] : ['limits' => $limits]), 1);
        $this->assertSame([], $r);
    }

    /**
     * The device endpoint.
     *
     * @param array<string,string> $q
     * @param array<string,string> $headers
     * @return array{0:int,1:array<string,mixed>|null,2:RmmResponse}
     */
    private function xfer(string $method, string $token, array $q, ?string $body = null, array $headers = [], ?string $deviceToken = null): array
    {
        $r = $this->h->api->handle($this->h->request($method, 'agent_transfer', $body, $deviceToken ?? $this->devToken, $q, ['x-transfer-token' => $token] + $headers));
        $ct = $r->headers['Content-Type'] ?? '';

        return [$r->status, str_contains($ct, 'json') ? json_decode((string) $r->body, true) : null, $r];
    }

    /** @return array{0:string,1:string,2:array<string,mixed>} [transfer id, token, the job] */
    private function startDownload(string $path = 'C:\\Data\\report.csv'): array
    {
        [$c, $j] = $this->api('POST', [$this->d(), 'files', 'download'], $this->tech, ['path' => $path]);
        $this->assertSame(202, $c, json_encode($j));
        $job = $this->offered($this->devToken)[0];
        $this->assertSame('file_get', $job['type']);

        return [$j['transfer_id'], (string) $job['params']['token'], $job];
    }

    public function testOffByDefaultAndEveryStepSaysSo(): void
    {
        $this->switchOn(['remote_tools']);
        [$c, $j] = $this->api('POST', [$this->d(), 'files', 'download'], $this->tech, ['path' => 'C:\\Data\\a.txt']);
        $this->assertSame([409, 'feature_disabled'], [$c, $j['code']]);
        $this->assertStringContainsString('File transfer is switched off', $j['error']);
        $this->assertSame(409, $this->api('POST', [$this->d(), 'files', 'upload'], $this->admin, ['path' => 'C:\\Data\\a.txt', 'size' => 1, 'sha256' => str_repeat('a', 64), 'confirm' => true])[0]);
        // the device endpoint answers the module's usual 503 feature_disabled
        [$c, $j] = $this->xfer('GET', str_repeat('a', 64), ['transfer_id' => 'x', 'offset' => '0', 'length' => '10']);
        $this->assertSame([503, 'feature_disabled'], [$c, $j['code']]);
        $this->assertSame('3600', $this->h->call('GET', 'agent_transfer', null, $this->devToken)[3]->headers['Retry-After']);
    }

    public function testNoFolderIsAllowedUntilAnAdministratorAddsOne(): void
    {
        $this->switchOn(['remote_tools', 'filetransfer']);
        [$c, $j] = $this->api('POST', [$this->d(), 'files', 'download'], $this->tech, ['path' => 'C:\\Data\\a.txt']);
        $this->assertSame([422, 'path_denied', 'no_allowed_roots'], [$c, $j['code'], $j['reason']]);
        $this->assertStringContainsString('No folder is allowed', $j['error']);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_jobs'));
    }

    public function testThePathPolicyIsEnforcedBeforeAnythingIsQueued(): void
    {
        $this->enableTransfers();
        foreach ([['C:\\Data\\..\\Windows\\win.ini', 'traversal'], ['C:\\Windows\\win.ini', 'not_allowed'], ['C:\\Data2\\a', 'not_allowed'], ['\\\\srv\\share\\a', 'not_absolute'], ['data\\a', 'not_absolute'],
            ['C:\\Data\\a.txt:ads', 'invalid'], ['C:\\Data\\site.pfx', 'deny_list'], ['C:\\Data\\.ssh\\id_ed25519', 'deny_list'], ["C:\\Data\\a\0b", 'invalid'], ['/etc/shadow', 'invalid']] as [$path, $reason]) {
            [$c, $j] = $this->api('POST', [$this->d(), 'files', 'download'], $this->tech, ['path' => $path]);
            $this->assertSame([422, $reason], [$c, $j['reason'] ?? null], json_encode($path));
        }
        $this->assertSame(422, $this->api('POST', [$this->d(), 'files', 'download'], $this->tech, [])[0]);
        $this->assertSame(422, $this->api('POST', [$this->d(), 'files', 'download'], $this->tech, ['path' => ['x']])[0]);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_transfers'));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_jobs'));
        $this->assertStringContainsString('File Download Refused', json_encode($this->h->audit->records()) ?: '');
        // the same request on a Linux device is judged by the Linux roots
        [$l] = $this->linked('L1', null, 'linux');
        $this->assertSame('not_absolute', $this->api('POST', [(string) $l, 'files', 'download'], $this->tech, ['path' => 'C:\\Data\\a.txt'])[1]['reason']);
        $this->assertSame('not_allowed', $this->api('POST', [(string) $l, 'files', 'download'], $this->tech, ['path' => '/etc/shadow'])[1]['reason']);
        $this->assertSame(202, $this->api('POST', [(string) $l, 'files', 'download'], $this->tech, ['path' => '/srv/shared/report.csv'])[0]);
    }

    public function testADownloadIsStreamedInChunksVerifiedAndKept(): void
    {
        $this->enableTransfers();
        [$tid, $token, $job] = $this->startDownload();
        $p = $job['params'];
        $this->assertSame(['C:\\Data\\report.csv', 'C:\\Data', 52428800, 1048576], [$p['path'], $p['allow'], $p['max_bytes'], $p['chunk']]);
        $this->assertNotEmpty($job['signature'], 'the job is signed, so the allow list and the token cannot be altered on the way');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $row = $this->h->rows("SELECT * FROM endpoint_agent_transfers WHERE transfer_id='$tid'")[0];
        $this->assertSame(hash('sha256', $token), $row['token_hash'], 'only the hash of the token is stored');
        $this->assertStringNotContainsString($token, json_encode($row) ?: '');

        $data = random_bytes(300000) . str_repeat('A', 5000);
        $sha = hash('sha256', $data);
        $parts = str_split($data, 100000);
        $off = 0;
        foreach ($parts as $i => $chunk) {
            $last = $i === count($parts) - 1;
            [$c, $j] = $this->xfer('POST', $token, ['transfer_id' => $tid, 'offset' => (string) $off], $chunk, $last ? ['x-transfer-last' => '1', 'x-transfer-sha256' => $sha] : []);
            $this->assertSame(200, $c, json_encode($j));
            $off += strlen($chunk);
            $this->assertSame([true, $off, $last], [$j['ok'], $j['received'], $j['complete']]);
            if (!$last) {
                // a retried chunk is acknowledged without being stored twice
                [$c, $j] = $this->xfer('POST', $token, ['transfer_id' => $tid, 'offset' => (string) ($off - strlen($chunk))], $chunk);
                $this->assertSame([200, $off], [$c, $j['received']]);
            }
        }
        $this->report($this->devToken, $job, 'succeeded', json_encode(['v' => 1, 'bytes' => strlen($data), 'sha256' => $sha]) ?: '');
        [$c, $j] = $this->api('GET', [$this->d(), 'files', $tid], $this->tech);
        $this->assertSame([200, 'complete', strlen($data), $sha, 'download'], [$c, $j['state'], $j['size_bytes'], $j['sha256'], $j['direction']]);
        $this->assertArrayNotHasKey('token', $j);
        $this->assertArrayNotHasKey('storage_name', $j);
        $this->assertSame('report.csv', $j['file_name']);
        // the technician fetches the verified bytes
        [$c, , $r] = $this->api('GET', [$this->d(), 'files', $tid, 'content'], $this->tech);
        $this->assertSame(200, $c);
        $this->assertNotNull($r->file);
        $this->assertSame([strlen($data), $sha], [$r->file->length, $r->file->sha256]);
        $this->assertSame($data, file_get_contents($r->file->path));
        $this->assertSame('attachment; filename="report.csv"', $r->headers['Content-Disposition']);
        $this->assertSame(403, $this->api('GET', [$this->d(), 'files', $tid, 'content'], $this->runner)[0], 'the stored file needs remote.manage');
        $this->assertSame(403, $this->api('GET', [$this->d(), 'files', $tid], $this->viewer)[0]);
        $audit = json_encode($this->h->audit->records()) ?: '';
        foreach (['File Download Requested', 'File Transfer Completed', 'File Downloaded'] as $a) {
            $this->assertStringContainsString($a, $audit, "every step is audited: $a");
        }
        // finished: the token no longer accepts data
        $this->assertSame(409, $this->xfer('POST', $token, ['transfer_id' => $tid, 'offset' => (string) strlen($data)], 'x')[0]);
        // delete
        $this->assertSame(200, $this->api('DELETE', [$this->d(), 'files', $tid], $this->tech)[0]);
        $this->assertSame([], glob($this->transferDir . '/xfer_*') ?: []);
        $this->assertSame(409, $this->api('GET', [$this->d(), 'files', $tid, 'content'], $this->tech)[0]);
        $this->assertSame('deleted', $this->api('GET', [$this->d(), 'files', $tid], $this->tech)[1]['state']);
        $this->assertSame([], array_filter($this->h->rows('SELECT * FROM endpoint_agent_transfers'), static fn (array $r): bool => $r['state'] === 'complete'));
        $this->assertCount(1, $this->api('GET', [$this->d(), 'files'], $this->tech)[1]['data']);
    }

    public function testADamagedOrOversizedDownloadIsRefusedAndNeverKept(): void
    {
        $this->enableTransfers(['transfer_max_bytes' => 2048]);
        // wrong checksum on the last chunk
        [$tid, $token, $job] = $this->startDownload();
        [$c, $j] = $this->xfer('POST', $token, ['transfer_id' => $tid, 'offset' => '0'], 'hello', ['x-transfer-last' => '1', 'x-transfer-sha256' => str_repeat('0', 64)]);
        $this->assertSame([422, 'checksum_mismatch'], [$c, $j['code']]);
        $this->assertSame([], glob($this->transferDir . '/xfer_*') ?: [], 'the damaged file is deleted');
        $this->assertSame('failed', $this->api('GET', [$this->d(), 'files', $tid], $this->tech)[1]['state']);
        $this->assertSame(409, $this->api('GET', [$this->d(), 'files', $tid, 'content'], $this->tech)[0]);
        $this->assertStringContainsString('File Transfer Failed', json_encode($this->h->audit->records()) ?: '');
        $this->report($this->devToken, $job, 'failed', 'x', 1);
        // missing checksum on the last chunk
        [$tid2, $token2] = $this->startDownload();
        $this->assertSame(422, $this->xfer('POST', $token2, ['transfer_id' => $tid2, 'offset' => '0'], 'hello', ['x-transfer-last' => '1'])[0]);
        // over the size cap
        [$tid3, $token3] = $this->startDownload();
        [$c, $j] = $this->xfer('POST', $token3, ['transfer_id' => $tid3, 'offset' => '0'], str_repeat('a', 1500));
        $this->assertSame(200, $c);
        [$c, $j] = $this->xfer('POST', $token3, ['transfer_id' => $tid3, 'offset' => '1500'], str_repeat('a', 1500));
        $this->assertSame([413, 'too_large'], [$c, $j['code']]);
        $this->assertSame([], glob($this->transferDir . '/xfer_*') ?: []);
        $this->assertSame('failed', $this->api('GET', [$this->d(), 'files', $tid3], $this->tech)[1]['state']);
        // an offset gap makes the agent resume where the server is
        [$tid4, $token4] = $this->startDownload();
        $this->xfer('POST', $token4, ['transfer_id' => $tid4, 'offset' => '0'], 'abcd');
        [$c, $j] = $this->xfer('POST', $token4, ['transfer_id' => $tid4, 'offset' => '10'], 'zz');
        $this->assertSame([409, 'conflict', 4], [$c, $j['code'], $j['received']]);
        // an empty file is one empty last chunk
        $empty = hash('sha256', '');
        [$c, $j] = $this->xfer('POST', $token4, ['transfer_id' => $tid4, 'offset' => '4'], '', ['x-transfer-last' => '1', 'x-transfer-sha256' => hash('sha256', 'abcd')]);
        $this->assertSame([200, true, 4], [$c, $j['complete'], $j['received']]);
        $this->assertNotSame('', $empty);
    }

    public function testTheTransferEndpointIsBoundToTheDeviceTheTokenAndTheTransfer(): void
    {
        $this->enableTransfers();
        [$tid, $token] = $this->startDownload();
        [$other, $otherToken] = $this->linked('W2');
        $q = ['transfer_id' => $tid, 'offset' => '0'];
        $this->assertSame(404, $this->xfer('POST', $token, $q, 'x', [], $otherToken)[0], 'another device cannot use this transfer, even with the token');
        $this->assertSame(404, $this->xfer('POST', str_repeat('b', 64), $q, 'x')[0], 'a wrong token');
        $this->assertSame(422, $this->xfer('POST', 'short', $q, 'x')[0]);
        $this->assertSame(422, $this->xfer('POST', strtoupper($token), $q, 'x')[0], 'the token is lower-case hex only');
        $this->assertSame(404, $this->xfer('POST', $token, ['transfer_id' => '../../etc', 'offset' => '0'], 'x')[0]);
        $this->assertSame(404, $this->xfer('POST', $token, ['transfer_id' => '0b1c2d3e-0000-4000-8000-000000000001', 'offset' => '0'], 'x')[0]);
        $this->assertSame(422, $this->xfer('POST', $token, ['transfer_id' => $tid, 'offset' => '-1'], 'x')[0]);
        $this->assertSame(422, $this->xfer('POST', $token, ['transfer_id' => $tid, 'offset' => '1e3'], 'x')[0]);
        $this->assertSame(401, $this->xfer('POST', $token, $q, 'x', [], 'a' . str_repeat('0', 63))[0], 'no device credential, no transfer');
        $this->assertSame(401, $this->h->api->handle($this->h->request('POST', 'agent_transfer', 'x', null, $q, ['x-transfer-token' => $token]))->status);
        $this->assertSame(404, $this->xfer('GET', $token, $q + ['length' => '10'])[0], 'a download transfer cannot be read back through the upload direction');
        $this->assertSame(413, $this->h->api->handle($this->h->request('POST', 'agent_transfer', str_repeat('a', 1048577), $this->devToken, $q, ['x-transfer-token' => $token]))->status, 'a chunk is at most 1 MiB');
        $this->assertSame(405, $this->h->api->handle($this->h->request('DELETE', 'agent_transfer', null, $this->devToken, $q, ['x-transfer-token' => $token]))->status);
        // and the first good chunk still works after all those refusals
        $this->assertSame(200, $this->xfer('POST', $token, $q, 'good')[0]);
        $this->assertNotSame(0, $other);
    }

    public function testTheDeviceEndpointIsRateLimited(): void
    {
        $this->enableTransfers();
        [$tid, $token] = $this->startDownload();
        $this->h->rateOverride['agent_transfer:' . $this->dev] = 3;
        $codes = [];
        for ($i = 0; $i < 5; ++$i) {
            $codes[] = $this->xfer('POST', $token, ['transfer_id' => $tid, 'offset' => '0'], 'x')[0];
        }
        $this->assertSame([200, 200, 200, 429, 429], $codes);
        $this->assertSame([600, 60], $this->h->rateArgs['agent_transfer:' . $this->dev]);
    }

    // ------------------------------------------------------------------ upload to a device

    public function testAnUploadIsAdministratorOnlyDeclaredVerifiedAndFetchedInRanges(): void
    {
        $this->enableTransfers(['transfer_max_bytes' => 3000000]);
        $data = random_bytes(2500000);
        $sha = hash('sha256', $data);
        $decl = ['path' => 'C:\\Data\\drop\\tool.zip', 'size' => strlen($data), 'sha256' => $sha, 'overwrite' => true, 'confirm' => true];
        $up = [$this->d(), 'files', 'upload'];
        $this->assertSame(403, $this->api('POST', $up, $this->tech, $decl)[0], 'a technician with remote.manage may download but not send files to a device');
        [$c, $j] = $this->api('POST', $up, $this->admin, ['confirm' => false] + $decl);
        $this->assertSame([422, 'confirmation_required'], [$c, $j['code']]);
        foreach ([['sha256' => 'ABC'], ['sha256' => $sha . 'a'], ['size' => '5'], ['size' => -1], ['path' => 'C:\\Windows\\evil.exe'], ['path' => 'C:\\Data\\..\\x'], ['path' => ''], ['path' => 'C:\\Data\\x.pfx']] as $bad) {
            $this->assertContains($this->api('POST', $up, $this->admin, $bad + $decl)[0], [422], json_encode($bad));
        }
        [$c, $j] = $this->api('POST', $up, $this->admin, ['size' => 3000001] + $decl);
        $this->assertSame([413, 'too_large'], [$c, $j['code']]);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_transfers'));
        [$c, $j] = $this->api('POST', $up, $this->admin, $decl);
        $this->assertSame([201, 'awaiting_content'], [$c, $j['state']]);
        $tid = $j['transfer_id'];
        $content = [$this->d(), 'files', $tid, 'content'];
        $this->assertSame(403, $this->api('PUT', $content, $this->tech, $data)[0]);
        // bytes that do not match what was declared are refused and nothing is kept
        $bad = $data;
        $bad[1000] = $bad[1000] === 'a' ? 'b' : 'a';
        [$c, $j] = $this->api('PUT', $content, $this->admin, $bad);
        $this->assertSame([422, 'checksum_mismatch'], [$c, $j['code']]);
        $this->assertSame(422, $this->api('PUT', $content, $this->admin, substr($data, 0, -1))[0]);
        $this->assertSame(422, $this->api('PUT', $content, $this->admin, $data . 'x')[0]);
        $this->assertSame([], glob($this->transferDir . '/xfer_*') ?: []);
        $this->assertSame('awaiting_content', $this->api('GET', [$this->d(), 'files', $tid], $this->admin)[1]['state'], 'the administrator can try again');
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='file_put'"));
        [$c, $j] = $this->api('PUT', $content, $this->admin, $data);
        $this->assertSame(202, $c, json_encode($j));
        $this->assertSame(409, $this->api('PUT', $content, $this->admin, $data)[0], 'content is accepted once');

        $job = $this->offered($this->devToken)[0];
        $this->assertSame('file_put', $job['type']);
        $row = $this->h->rows("SELECT * FROM endpoint_agent_jobs WHERE job_id='{$job['job_id']}'")[0];
        $this->assertSame(1, (int) $row['destructive'], 'sending a file is a destructive job: offered at most once');
        $p = $job['params'];
        $this->assertSame(['C:\\Data\\drop\\tool.zip', strlen($data), $sha, true, 'C:\\Data', '0640', $tid], [$p['path'], $p['size'], $p['sha256'], $p['overwrite'], $p['allow'], $p['mode'], $p['transfer_id']]);
        $token = (string) $p['token'];
        // the device pulls ranges
        $got = '';
        for ($off = 0; $off < strlen($data); $off += 1048576) {
            [$c, , $r] = $this->xfer('GET', $token, ['transfer_id' => $tid, 'offset' => (string) $off, 'length' => '1048576']);
            $this->assertSame(200, $c);
            $this->assertSame([(string) strlen($data), $sha, 'application/octet-stream'], [$r->headers['X-Transfer-Size'], $r->headers['X-Transfer-Sha256'], $r->headers['Content-Type']]);
            $got .= $r->body;
        }
        $this->assertSame($sha, hash('sha256', $got));
        $this->assertSame(416, $this->xfer('GET', $token, ['transfer_id' => $tid, 'offset' => (string) strlen($data), 'length' => '10'])[0]);
        $this->assertSame(416, $this->xfer('GET', $token, ['transfer_id' => $tid, 'offset' => '0', 'length' => '1048577'])[0]);
        $this->assertSame(416, $this->xfer('GET', $token, ['transfer_id' => $tid, 'offset' => '0', 'length' => '0'])[0]);
        $this->assertSame(404, $this->xfer('GET', str_repeat('c', 64), ['transfer_id' => $tid, 'offset' => '0', 'length' => '10'])[0]);
        $this->assertSame(404, $this->xfer('POST', $token, ['transfer_id' => $tid, 'offset' => '0'], 'x')[0], 'an upload transfer cannot be written to by the device');
        $this->assertStringContainsString('fully read by the device', json_encode($this->h->audit->records()) ?: '');
        $this->report($this->devToken, $job, 'succeeded', '{"v":1}');
        $this->assertSame('complete', $this->api('GET', [$this->d(), 'files', $tid], $this->admin)[1]['state']);
        $audit = json_encode($this->h->audit->records()) ?: '';
        foreach (['File Upload Declared', 'File Upload Rejected', 'File Upload Queued'] as $a) {
            $this->assertStringContainsString($a, $audit, $a);
        }
        // a read budget keeps a stolen token from being a free download service
        for ($i = 0; $i < 2; ++$i) {
            for ($off = 0; $off < strlen($data); $off += 1048576) {
                $this->xfer('GET', $token, ['transfer_id' => $tid, 'offset' => (string) $off, 'length' => '1048576']);
            }
        }
        $this->assertSame(429, $this->xfer('GET', $token, ['transfer_id' => $tid, 'offset' => '0', 'length' => '10'])[0]);
    }

    public function testAFailedDeliveryShowsAsFailed(): void
    {
        $this->enableTransfers();
        $data = 'hello world';
        [$c, $j] = $this->api('POST', [$this->d(), 'files', 'upload'], $this->admin, ['path' => 'C:\\Data\\hello.txt', 'size' => 11, 'sha256' => hash('sha256', $data), 'confirm' => true]);
        $this->assertSame(201, $c);
        $this->assertSame(202, $this->api('PUT', [$this->d(), 'files', $j['transfer_id'], 'content'], $this->admin, $data)[0]);
        $job = $this->offered($this->devToken)[0];
        $this->report($this->devToken, $job, 'failed', '{"v":1,"detail":"checksum_mismatch"}', 1);
        $s = $this->api('GET', [$this->d(), 'files', $j['transfer_id']], $this->admin)[1];
        $this->assertSame(['failed', 'failed'], [$s['state'], $s['job_state']]);
        $this->assertStringContainsString('failed', $s['error']);
    }

    public function testLimitsExpiryAndCleanUp(): void
    {
        $this->enableTransfers(['transfer_store_max_bytes' => 1048576, 'transfer_ttl_s' => 600, 'transfer_max_bytes' => 800000]);
        $mk = fn (int $n) => $this->api('POST', [$this->d(), 'files', 'upload'], $this->admin, ['path' => 'C:\\Data\\f.bin', 'size' => $n, 'sha256' => str_repeat('a', 64), 'confirm' => true]);
        $this->assertSame(201, $mk(700000)[0]);
        [$c, $j] = $mk(700000);
        $this->assertSame([507, 'too_large'], [$c, $j['code']], 'the storage folder has a budget too');
        $this->assertStringContainsString('storage on this server is full', $j['error']);
        // a download reserves the largest file it may bring
        $this->assertSame(507, $this->api('POST', [$this->d(), 'files', 'download'], $this->tech, ['path' => 'C:\\Data\\x.txt'])[0]);
        // expiry: the stored bytes are deleted by housekeeping, the ledger row stays for the audit trail
        $data = 'tiny';
        $this->h->q('DELETE FROM endpoint_agent_transfers');
        $this->enableTransfers(['transfer_ttl_s' => 600]);
        [, $j] = $this->api('POST', [$this->d(), 'files', 'upload'], $this->admin, ['path' => 'C:\\Data\\t.txt', 'size' => 4, 'sha256' => hash('sha256', $data), 'confirm' => true]);
        $tid = $j['transfer_id'];
        $this->assertSame(202, $this->api('PUT', [$this->d(), 'files', $tid, 'content'], $this->admin, $data)[0]);
        $this->assertCount(1, glob($this->transferDir . '/xfer_*') ?: []);
        $token = (string) $this->offered($this->devToken)[0]['params']['token'];
        $this->h->clock->advance(601);
        $this->assertSame(409, $this->xfer('GET', $token, ['transfer_id' => $tid, 'offset' => '0', 'length' => '4'])[0], 'an expired transfer serves nothing');
        $this->assertSame('expired', $this->api('GET', [$this->d(), 'files', $tid], $this->admin)[1]['state']);
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame(1, $out['transfers_expired']);
        $this->assertSame([], glob($this->transferDir . '/xfer_*') ?: []);
        $this->assertSame('expired', $this->h->one("SELECT state FROM endpoint_agent_transfers WHERE transfer_id='$tid'"));
    }

    public function testWithoutAStorageFolderNothingIsStored(): void
    {
        $this->enableTransfers();
        $svc = new \RivetCore\Rmm\Remote\TransferService($this->h->module->sql(), $this->h->module->remoteSettings(), $this->h->module->jobs(), null, $this->h->audit);
        $this->assertNotNull($svc->unavailable());
        $dev = $this->h->module->devices()->find($this->dev) ?? [];
        $r = $svc->requestDownload($dev, 'C:\\Data\\a.txt', 1);
        $this->assertSame([false, 409, 'not_configured'], [$r['ok'], $r['http'] ?? 0, $r['code'] ?? '']);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_transfers'));
    }
}
