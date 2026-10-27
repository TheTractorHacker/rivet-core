<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * File transfer between a device and this server, always through the server and never directly:
 *
 *  - DOWNLOAD (device to server): a technician asks for a path; the server records a transfer, queues a signed `file_get` job and the agent streams the file back
 *    in chunks of at most 1 MiB to the device endpoint `agent_transfer`. The server checks the byte count against the size cap, hashes the whole file when the
 *    last chunk arrives and keeps it only when the agent's SHA-256 matches. The technician then fetches the verified file.
 *  - UPLOAD (server to device, administrators only): the administrator declares the destination, the size and the SHA-256, then sends the bytes; the server verifies
 *    them, queues a signed `file_put` job and the agent pulls the bytes in chunks, verifies the hash itself and swaps the file in atomically.
 *
 * AUTHORITY. A transfer is reachable only with the device's own credential AND the one-time token carried in the signed job (the token's hash is all the server
 * keeps), only for the device the transfer belongs to, only until it expires, and only within its byte budget. The paths are checked against the administrator's
 * allowed folders and the never-transfer list here, and again by the agent, which also resolves symbolic links.
 *
 * @api
 */
final class TransferService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly Sql $sql,
        private readonly RemoteSettings $remote,
        private readonly JobService $jobs,
        private readonly ?string $dir,
        private readonly RmmAuditInterface $audit,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    /** Null when transfers can be stored, else a plain-language reason. */
    public function unavailable(): ?string
    {
        if ($this->dir === null || $this->dir === '') {
            return 'No storage folder is configured for file transfers on this server (the edition must give the module a transfer_dir).';
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            return 'The file transfer storage folder cannot be created.';
        }
        if (!is_writable($this->dir)) {
            return 'The file transfer storage folder is not writable.';
        }

        return null;
    }

    private function pathOf(string $storage): string
    {
        return rtrim((string) $this->dir, '/\\') . '/' . $storage;
    }

    // ------------------------------------------------------------------ technician side

    /**
     * @param array<string,mixed> $dev the device row
     * @return array{ok:bool,http?:int,code?:string,error?:string,reason?:string,transfer_id?:string,job_id?:string}
     */
    public function requestDownload(array $dev, string $path, int $userId): array
    {
        $os = (string) ($dev['os'] ?? 'windows');
        if (($why = $this->unavailable()) !== null) {
            return self::fail(409, 'not_configured', $why);
        }
        $roots = $this->remote->allowRootsFor($os);
        if (($code = PathPolicy::check($path, $os, $roots)) !== null) {
            return self::fail(422, 'path_denied', PathPolicy::explain($code), $code);
        }
        $limits = $this->remote->limits();
        if (($full = $this->storeFull($limits, $limits['transfer_max_bytes'])) !== null) {
            return $full;
        }
        $tid = JobService::uuid();
        $token = bin2hex(random_bytes(32));
        $now = $this->sql->utcNow();
        $storage = 'xfer_' . bin2hex(random_bytes(16)) . '.bin';
        try {
            $result = $this->sql->transaction(function () use ($dev, $path, $userId, $tid, $token, $storage, $limits, $now, $roots): array {
            $this->sql->run("INSERT INTO endpoint_agent_transfers (transfer_id, device_id, client_id, asset_id, direction, remote_path, file_name, state, size_bytes, received_bytes, max_bytes,
                token_hash, storage_name, created_by, created_at, updated_at, expires_at) VALUES (?, ?, ?, ?, 'download', ?, ?, 'queued', 0, 0, ?, ?, ?, ?, ?, ?, ?)",
                [$tid, $dev['device_id'], (int) $dev['client_id'], empty($dev['asset_id']) ? null : $dev['asset_id'], $path, self::baseName($path), $limits['transfer_max_bytes'],
                    hash('sha256', $token), $storage, $userId, $now, $now, gmdate('Y-m-d H:i:s', $this->sql->time() + $limits['transfer_ttl_s'])]);
            $job = $this->jobs->create($dev, RemoteProtocol::JOB_FILE_GET, null, ['transfer_id' => $tid, 'token' => $token, 'path' => $path, 'max_bytes' => $limits['transfer_max_bytes'],
                'allow' => implode("\n", $roots), 'chunk' => RemoteProtocol::TRANSFER_CHUNK_MAX], null, false, $userId);
            if (!$job['ok'] || empty($job['job_id'])) {
                throw new JobRefused((string) ($job['error'] ?? 'The job could not be created.'), (string) ($job['code'] ?? 'conflict'));
            }
            $this->sql->run('UPDATE endpoint_agent_transfers SET job_id = ? WHERE transfer_id = ?', [$job['job_id'], $tid]);

            return ['job_id' => $job['job_id']];
            });
        } catch (JobRefused $e) {
            return self::fail(409, $e->reason, $e->getMessage());
        }

        return ['ok' => true, 'transfer_id' => $tid, 'job_id' => $result['job_id']];
    }

    /**
     * Declare an upload. The bytes follow in {@see storeUploadContent()}.
     *
     * @param array<string,mixed> $dev
     * @return array{ok:bool,http?:int,code?:string,error?:string,reason?:string,transfer_id?:string}
     */
    public function createUpload(array $dev, string $path, int $size, string $sha256, bool $overwrite, int $userId): array
    {
        $os = (string) ($dev['os'] ?? 'windows');
        if (($why = $this->unavailable()) !== null) {
            return self::fail(409, 'not_configured', $why);
        }
        $limits = $this->remote->limits();
        if ($size < 0) {
            return self::fail(422, 'invalid', 'size must be the number of bytes in the file.');
        }
        if ($size > $limits['transfer_max_bytes']) {
            return self::fail(413, 'too_large', 'The file is larger than the transfer size limit (' . $limits['transfer_max_bytes'] . ' bytes).');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            return self::fail(422, 'invalid', 'sha256 must be the 64 lower-case hex characters of the file\'s SHA-256.');
        }
        if (($code = PathPolicy::check($path, $os, $this->remote->allowRootsFor($os))) !== null) {
            return self::fail(422, 'path_denied', PathPolicy::explain($code), $code);
        }
        if (($full = $this->storeFull($limits, $size)) !== null) {
            return $full;
        }
        $tid = JobService::uuid();
        $now = $this->sql->utcNow();
        $this->sql->run("INSERT INTO endpoint_agent_transfers (transfer_id, device_id, client_id, asset_id, direction, remote_path, file_name, state, size_bytes, received_bytes, max_bytes, sha256,
            token_hash, storage_name, overwrite, created_by, created_at, updated_at, expires_at) VALUES (?, ?, ?, ?, 'upload', ?, ?, 'awaiting_content', ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$tid, $dev['device_id'], (int) $dev['client_id'], empty($dev['asset_id']) ? null : $dev['asset_id'], $path, self::baseName($path), $size, $limits['transfer_max_bytes'], $sha256,
                hash('sha256', bin2hex(random_bytes(32))), 'xfer_' . bin2hex(random_bytes(16)) . '.bin', $overwrite ? 1 : 0, $userId, $now, $now,
                gmdate('Y-m-d H:i:s', $this->sql->time() + $limits['transfer_ttl_s'])]);

        return ['ok' => true, 'transfer_id' => $tid];
    }

    /**
     * Receive the bytes of a declared upload from the administrator, verify them and queue the `file_put` job.
     *
     * @param array<string,mixed> $dev
     * @param resource $stream
     * @return array{ok:bool,http?:int,code?:string,error?:string,reason?:string,job_id?:string}
     */
    public function storeUploadContent(array $dev, string $tid, mixed $stream, int $userId): array
    {
        $t = $this->find($tid, (int) $dev['device_id']);
        if ($t === null || $t['direction'] !== 'upload') {
            return self::fail(404, 'not_found', 'Transfer not found.');
        }
        if ($t['state'] !== 'awaiting_content' || Sql::ts((string) $t['expires_at']) <= $this->sql->time()) {
            return self::fail(409, 'conflict', 'This transfer is not waiting for its content (it may have expired).');
        }
        if (($why = $this->unavailable()) !== null) {
            return self::fail(409, 'not_configured', $why);
        }
        $size = (int) $t['size_bytes'];
        $file = $this->pathOf((string) $t['storage_name']);
        $out = @fopen($file, 'wb');
        if ($out === false) {
            return self::fail(503, 'unavailable', 'The transfer could not be stored.');
        }
        @chmod($file, 0600);
        $written = 0;
        $tooBig = false;
        while (!feof($stream)) {
            $buf = fread($stream, 65536);
            if ($buf === false || $buf === '') {
                break;
            }
            $written += strlen($buf);
            if ($written > $size) {
                $tooBig = true;   // more bytes than declared: refuse, never keep a prefix
                break;
            }
            fwrite($out, $buf);
        }
        fclose($out);
        if ($tooBig || $written !== $size || hash_file('sha256', $file) !== $t['sha256']) {
            @unlink($file);
            $this->audit->record('File Upload Rejected', "User $userId sent content that does not match the declared size or SHA-256 for transfer $tid on device {$dev['device_id']}", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

            return self::fail(422, 'checksum_mismatch', 'The bytes received do not match the declared size and SHA-256. Nothing was stored.');
        }
        $token = bin2hex(random_bytes(32));
        $os = (string) ($dev['os'] ?? 'windows');
        $jobId = null;
        try {
            $this->sql->transaction(function () use ($dev, $t, $tid, $token, $userId, $os, &$jobId): void {
            $job = $this->jobs->create($dev, RemoteProtocol::JOB_FILE_PUT, null, ['transfer_id' => $tid, 'token' => $token, 'path' => (string) $t['remote_path'], 'size' => (int) $t['size_bytes'],
                'sha256' => (string) $t['sha256'], 'overwrite' => (int) $t['overwrite'] === 1, 'allow' => implode("\n", $this->remote->allowRootsFor($os)), 'mode' => '0640'], null, true, $userId);
            if (!$job['ok'] || empty($job['job_id'])) {
                throw new JobRefused((string) ($job['error'] ?? 'The job could not be created.'), (string) ($job['code'] ?? 'conflict'));
            }
            $jobId = $job['job_id'];
            $this->sql->run("UPDATE endpoint_agent_transfers SET state = 'queued', token_hash = ?, received_bytes = size_bytes, job_id = ?, updated_at = ? WHERE transfer_id = ? AND state = 'awaiting_content'",
                [hash('sha256', $token), $jobId, $this->sql->utcNow(), $tid]);
            });
        } catch (JobRefused $e) {
            return self::fail(409, $e->reason, $e->getMessage());
        }

        return ['ok' => true, 'job_id' => (string) $jobId];
    }

    /**
     * @return array<string,mixed>|null the ledger row (raw), only when it belongs to the device
     */
    public function find(string $tid, int $deviceId): ?array
    {
        if (preg_match(self::UUID_RE, $tid) !== 1) {
            return null;
        }

        return $this->sql->one('SELECT * FROM endpoint_agent_transfers WHERE transfer_id = ? AND device_id = ?', [$tid, $deviceId]);
    }

    /**
     * What a technician sees: the ledger row with the state worked out from the job that carries it. Never the token, never the storage name.
     *
     * @param array<string,mixed> $t
     * @return array<string,mixed>
     */
    public function present(array $t): array
    {
        $state = (string) $t['state'];
        $error = (string) $t['error'];
        $jobState = null;
        if ($t['job_id'] !== null) {
            $j = $this->sql->one('SELECT state, reason FROM endpoint_agent_jobs WHERE job_id = ?', [$t['job_id']]);
            $jobState = $j === null ? null : (string) $j['state'];
            if ($j !== null && in_array($state, ['queued', 'receiving', 'delivering'], true)) {
                if (in_array($jobState, ['failed', 'timed_out', 'cancelled', 'expired'], true)) {
                    $state = 'failed';
                    $error = $error !== '' ? $error : 'The job ended as ' . $jobState . ($j['reason'] ? ' (' . $j['reason'] . ')' : '') . '.';
                } elseif ($jobState === 'succeeded' && $t['direction'] === 'upload') {
                    $state = 'complete';
                }
            }
        }
        if (in_array($state, ['queued', 'receiving', 'delivering', 'awaiting_content'], true) && Sql::ts((string) $t['expires_at']) <= $this->sql->time()) {
            $state = 'expired';
        }

        return [
            'transfer_id' => $t['transfer_id'], 'device_id' => (int) $t['device_id'], 'direction' => $t['direction'], 'path' => $t['remote_path'], 'file_name' => $t['file_name'],
            'state' => $state, 'size_bytes' => (int) ($t['direction'] === 'upload' ? $t['size_bytes'] : $t['received_bytes']), 'sha256' => $t['sha256'], 'job_id' => $t['job_id'],
            'job_state' => $jobState, 'error' => $error, 'created_by' => (int) $t['created_by'], 'created_at' => Sql::iso((string) $t['created_at']),
            'expires_at' => Sql::iso((string) $t['expires_at']), 'completed_at' => Sql::iso($t['completed_at'] === null ? null : (string) $t['completed_at']),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listFor(int $deviceId, int $limit = 50): array
    {
        $rows = $this->sql->all('SELECT * FROM endpoint_agent_transfers WHERE device_id = ? ORDER BY created_at DESC, transfer_id LIMIT ' . max(1, min(200, $limit)), [$deviceId]);

        return array_map(fn (array $r): array => $this->present($r), $rows);
    }

    /**
     * The verified file of a finished download, for the technician's fetch.
     *
     * @param array<string,mixed> $t the ledger row
     * @return array{path:string,length:int,sha256:string,name:string}|null
     */
    public function contentOf(array $t): ?array
    {
        if ($t['direction'] !== 'download' || $t['state'] !== 'complete' || $t['sha256'] === null || Sql::ts((string) $t['expires_at']) <= $this->sql->time()) {
            return null;
        }
        $file = $this->pathOf((string) $t['storage_name']);
        if (!is_file($file) || filesize($file) !== (int) $t['received_bytes']) {
            return null;
        }

        return ['path' => $file, 'length' => (int) $t['received_bytes'], 'sha256' => (string) $t['sha256'], 'name' => (string) $t['file_name']];
    }

    /**
     * Remove the stored bytes now (the ledger row stays for the audit trail).
     *
     * @param array<string,mixed> $t the ledger row
     */
    public function discard(array $t, int $userId): void
    {
        @unlink($this->pathOf((string) $t['storage_name']));
        $this->sql->run("UPDATE endpoint_agent_transfers SET state = 'deleted', updated_at = ? WHERE transfer_id = ?", [$this->sql->utcNow(), $t['transfer_id']]);
        $this->audit->record('File Transfer Deleted', "User $userId deleted the stored copy of transfer {$t['transfer_id']} ({$t['direction']}) on device {$t['device_id']}", (int) $t['client_id'], (int) ($t['asset_id'] ?? 0));
    }

    // ------------------------------------------------------------------ device side

    /**
     * One chunk of a download, from the device.
     *
     * @param array<string,mixed> $dev the authenticated device row
     * @return array{http:int,body:array<string,mixed>}
     */
    public function receiveChunk(array $dev, string $tid, string $token, int $offset, string $bytes, bool $last, ?string $sha256): array
    {
        $failed = null;
        $event = null;
        $reply = $this->sql->transaction(function () use ($dev, $tid, $token, $offset, $bytes, $last, $sha256, &$failed, &$event): array {
            $t = preg_match(self::UUID_RE, $tid) === 1 ? $this->sql->one('SELECT * FROM endpoint_agent_transfers WHERE transfer_id = ? AND device_id = ? FOR UPDATE', [$tid, $dev['device_id']]) : null;
            if ($t === null || $t['direction'] !== 'download' || !hash_equals((string) $t['token_hash'], hash('sha256', $token))) {
                return ['http' => 404, 'body' => ['error' => 'Not found.', 'code' => 'not_found']];
            }
            if (!in_array($t['state'], ['queued', 'receiving'], true) || Sql::ts((string) $t['expires_at']) <= $this->sql->time()) {
                return ['http' => 409, 'body' => ['error' => 'This transfer is not accepting data.', 'code' => 'conflict', 'received' => (int) $t['received_bytes']]];
            }
            $received = (int) $t['received_bytes'];
            $len = strlen($bytes);
            if ($len > RemoteProtocol::TRANSFER_CHUNK_MAX) {
                return ['http' => 413, 'body' => ['error' => 'Chunk too large.', 'code' => 'too_large']];
            }
            if ($offset < $received && $offset + $len <= $received && !$last) {
                return ['http' => 200, 'body' => ['ok' => true, 'received' => $received, 'complete' => false]];   // a retried chunk the server already holds
            }
            if ($offset !== $received) {
                return ['http' => 409, 'body' => ['error' => 'Resume at the offset the server holds.', 'code' => 'conflict', 'received' => $received]];
            }
            if ($received + $len > (int) $t['max_bytes']) {
                $failed = 'The file is larger than the transfer size limit.';
                $this->sql->run("UPDATE endpoint_agent_transfers SET state = 'failed', error = ?, updated_at = ? WHERE transfer_id = ?", [$failed, $this->sql->utcNow(), $tid]);
                @unlink($this->pathOf((string) $t['storage_name']));

                return ['http' => 413, 'body' => ['error' => $failed, 'code' => 'too_large']];
            }
            if (($why = $this->unavailable()) !== null) {
                return ['http' => 503, 'body' => ['error' => $why, 'code' => 'unavailable']];
            }
            $file = $this->pathOf((string) $t['storage_name']);
            $fh = @fopen($file, 'c+b');
            if ($fh === false) {
                return ['http' => 503, 'body' => ['error' => 'The transfer could not be stored.', 'code' => 'unavailable']];
            }
            @chmod($file, 0600);
            fseek($fh, $received);
            if ($len > 0 && fwrite($fh, $bytes) !== $len) {
                fclose($fh);

                return ['http' => 503, 'body' => ['error' => 'The transfer could not be stored.', 'code' => 'unavailable']];
            }
            ftruncate($fh, $received + $len);
            fclose($fh);
            $total = $received + $len;
            $now = $this->sql->utcNow();
            if (!$last) {
                $this->sql->run("UPDATE endpoint_agent_transfers SET state = 'receiving', received_bytes = ?, updated_at = ? WHERE transfer_id = ?", [$total, $now, $tid]);

                return ['http' => 200, 'body' => ['ok' => true, 'received' => $total, 'complete' => false]];
            }
            $actual = hash_file('sha256', $file);
            if ($sha256 === null || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1 || !hash_equals((string) $actual, $sha256)) {
                $failed = 'The file arrived damaged: its SHA-256 does not match what the device reported.';
                $this->sql->run("UPDATE endpoint_agent_transfers SET state = 'failed', error = ?, received_bytes = ?, updated_at = ? WHERE transfer_id = ?", [$failed, $total, $now, $tid]);
                @unlink($file);
                $event = ['transfer' => $t, 'state' => 'failed', 'bytes' => $total, 'sha256' => null];

                return ['http' => 422, 'body' => ['error' => $failed, 'code' => 'checksum_mismatch']];
            }
            $this->sql->run("UPDATE endpoint_agent_transfers SET state = 'complete', received_bytes = ?, size_bytes = ?, sha256 = ?, completed_at = ?, updated_at = ? WHERE transfer_id = ?", [$total, $total, $actual, $now, $now, $tid]);
            $event = ['transfer' => $t, 'state' => 'complete', 'bytes' => $total, 'sha256' => $actual];

            return ['http' => 200, 'body' => ['ok' => true, 'received' => $total, 'complete' => true]];
        });
        if ($event !== null) {
            $this->finished($event['transfer'], $event['state'], $event['bytes'], $event['sha256']);
        } elseif ($failed !== null) {
            $this->audit->record('File Transfer Failed', "Transfer $tid on device {$dev['device_id']}: $failed", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));
        }

        return $reply;
    }

    /**
     * One range of an upload, for the device.
     *
     * @param array<string,mixed> $dev
     * @return array{http:int,body:array<string,mixed>,data?:string,size?:int,sha256?:string}
     */
    public function serveChunk(array $dev, string $tid, string $token, int $offset, int $length): array
    {
        $t = $this->find($tid, (int) $dev['device_id']);
        if ($t === null || $t['direction'] !== 'upload' || !hash_equals((string) $t['token_hash'], hash('sha256', $token))) {
            return ['http' => 404, 'body' => ['error' => 'Not found.', 'code' => 'not_found']];
        }
        $size = (int) $t['size_bytes'];
        if (!in_array($t['state'], ['queued', 'delivering'], true) || Sql::ts((string) $t['expires_at']) <= $this->sql->time()) {
            return ['http' => 409, 'body' => ['error' => 'This transfer is not available.', 'code' => 'conflict']];
        }
        if ($offset < 0 || $length < 1 || $length > RemoteProtocol::TRANSFER_CHUNK_MAX || $offset > $size || ($offset === $size && $size > 0)) {
            return ['http' => 416, 'body' => ['error' => 'The range is outside the file.', 'code' => 'invalid']];
        }
        // A bounded budget: the whole file twice (a retried chunk is normal), never an open-ended read.
        if ((int) $t['served_bytes'] > 2 * $size + RemoteProtocol::TRANSFER_CHUNK_MAX) {
            return ['http' => 429, 'body' => ['error' => 'This transfer has been read too often.', 'code' => 'rate_limited']];
        }
        $file = $this->pathOf((string) $t['storage_name']);
        $fh = @fopen($file, 'rb');
        if ($fh === false || filesize($file) !== $size) {
            return ['http' => 503, 'body' => ['error' => 'The transfer content is unavailable.', 'code' => 'unavailable']];
        }
        fseek($fh, $offset);
        $data = $size === 0 ? '' : (string) fread($fh, min($length, $size - $offset));
        fclose($fh);
        $this->sql->run("UPDATE endpoint_agent_transfers SET state = 'delivering', served_bytes = served_bytes + ?, updated_at = ? WHERE transfer_id = ?", [strlen($data), $this->sql->utcNow(), $tid]);
        if ($offset + strlen($data) >= $size) {
            $this->finished($t, 'delivered', $size, (string) $t['sha256']);
        }

        return ['http' => 200, 'body' => [], 'data' => $data, 'size' => $size, 'sha256' => (string) $t['sha256']];
    }

    /**
     * @param array<string,mixed> $t the ledger row
     */
    private function finished(array $t, string $state, int $bytes, ?string $sha256): void
    {
        $verb = $state === 'failed' ? 'failed' : ($state === 'delivered' ? 'was fully read by the device' : 'completed');
        $this->audit->record($state === 'failed' ? 'File Transfer Failed' : 'File Transfer Completed',
            "Transfer {$t['transfer_id']} ({$t['direction']}, {$t['remote_path']}) on device {$t['device_id']} $verb: $bytes bytes" . ($sha256 !== null ? ', sha256 ' . $sha256 : ''), (int) $t['client_id'], (int) ($t['asset_id'] ?? 0));
        $this->events?->emit(RmmEvent::FILE_TRANSFERRED, ['device_id' => (int) $t['device_id'], 'asset_id' => $t['asset_id'], 'client_id' => (int) $t['client_id'], 'hostname' => ''],
            ['transfer_id' => (string) $t['transfer_id'], 'direction' => (string) $t['direction'], 'path' => (string) $t['remote_path'], 'state' => $state === 'delivered' ? 'delivered' : $state, 'size_bytes' => $bytes, 'sha256' => $sha256]);
    }

    // ------------------------------------------------------------------ housekeeping

    /**
     * Delete stored bytes of expired or finished-and-old transfers, and ledger rows past the job retention.
     *
     * @return array{expired:int,pruned:int}
     */
    public function prune(int $retentionDays, int $batch = 200): array
    {
        $expired = 0;
        if ($this->dir !== null) {
            $rows = $this->sql->all("SELECT * FROM endpoint_agent_transfers WHERE expires_at <= ? AND state NOT IN ('expired','deleted') LIMIT " . max(1, $batch), [$this->sql->utcNow()]);
            foreach ($rows as $t) {
                @unlink($this->pathOf((string) $t['storage_name']));
                $expired += $this->sql->run("UPDATE endpoint_agent_transfers SET state = 'expired', updated_at = ? WHERE transfer_id = ?", [$this->sql->utcNow(), $t['transfer_id']]);
            }
        }
        $pruned = $this->sql->run("DELETE FROM endpoint_agent_transfers WHERE state IN ('expired','deleted','failed','complete') AND expires_at < ? LIMIT " . max(1, $batch), [$this->sql->utcAt(-max(1, $retentionDays) * 86400)]);

        return ['expired' => $expired, 'pruned' => $pruned];
    }

    /**
     * @param array<string,int> $limits
     * @return array{ok:bool,http:int,code:string,error:string}|null
     */
    private function storeFull(array $limits, int $incoming): ?array
    {
        $held = (int) $this->sql->val("SELECT COALESCE(SUM(GREATEST(size_bytes, received_bytes, max_bytes * (direction = 'download' AND state IN ('queued','receiving')))), 0) FROM endpoint_agent_transfers
            WHERE state NOT IN ('expired','deleted','failed') AND expires_at > ?", [$this->sql->utcNow()]);
        if ($held + $incoming > $limits['transfer_store_max_bytes']) {
            return self::fail(507, 'too_large', 'The transfer storage on this server is full. Delete finished transfers or wait for them to expire.');
        }

        return null;
    }

    private static function baseName(string $path): string
    {
        $parts = preg_split('#[\\\\/]+#', $path) ?: [];
        $n = (string) end($parts);
        $n = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $n);
        $n = trim($n, '._');

        return $n === '' ? 'download.bin' : substr($n, 0, 100);
    }

    /**
     * @return array{ok:bool,http:int,code:string,error:string,reason?:string}
     */
    private static function fail(int $http, string $code, string $message, ?string $reason = null): array
    {
        return ['ok' => false, 'http' => $http, 'code' => $code, 'error' => $message] + ($reason === null ? [] : ['reason' => $reason]);
    }
}
