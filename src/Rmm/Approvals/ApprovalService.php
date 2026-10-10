<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Approvals;

use RivetCore\Rmm\Crypto\CanonicalJson;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Two-person approval (table rmm_approvals). A request freezes WHAT will run (the script, its version and body hash, the parameters, the target,
 * the timeout) as canonical JSON with its SHA-256; a second user decides it. The states are `pending_approval`, `approved`, `rejected`,
 * `cancelled` and `expired`; a request lapses after the `approval_expiry_h` limit. The requester can never decide their own request, and a
 * decision is made exactly once (the transition is a conditional UPDATE, so two approvers cannot both win).
 *
 * This class owns the state machine; deciding who may decide, running what was approved and the audit lines are the caller's
 * ({@see \RivetCore\Rmm\Technician\ScriptActions}).
 *
 * @api
 */
final class ApprovalService
{
    public const PENDING = 'pending_approval';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    public function __construct(private readonly Sql $sql, private readonly RmmSettings $settings)
    {
    }

    /**
     * @param 'run'|'schedule' $kind
     * @param array<string,mixed> $request what will run; frozen
     * @return array<string,mixed>
     */
    public function request(string $kind, array $request, string $summary, int $deviceCount, ?int $scriptId, ?int $scriptVersion, int $userId): array
    {
        $json = CanonicalJson::encode($request);
        $now = $this->sql->time();
        $expiry = (int) $this->settings->limits()['approval_expiry_h'] * 3600;
        $id = $this->sql->insert('INSERT INTO rmm_approvals (kind, state, summary, request_json, request_sha256, device_count, script_id, script_version, requested_by, requested_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$kind, self::PENDING, mb_substr($summary, 0, 300), $json, hash('sha256', $json), $deviceCount, $scriptId, $scriptVersion, $userId,
            gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now + $expiry)]);

        return $this->get($id) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function get(int $approvalId): ?array
    {
        $r = $this->sql->one('SELECT * FROM rmm_approvals WHERE approval_id = ?', [$approvalId]);

        return $r === null ? null : self::row($r);
    }

    /**
     * The frozen request of an approval, after checking that it still matches its recorded hash (an edit in the database is refused).
     *
     * @return array<string,mixed>|null
     */
    public function requestOf(int $approvalId): ?array
    {
        $r = $this->sql->one('SELECT request_json, request_sha256 FROM rmm_approvals WHERE approval_id = ?', [$approvalId]);
        if ($r === null || !hash_equals((string) $r['request_sha256'], hash('sha256', (string) $r['request_json']))) {
            return null;
        }
        $d = json_decode((string) $r['request_json'], true);

        /** @var array<string,mixed>|null $out */
        $out = is_array($d) ? $d : null;

        return $out;
    }

    /**
     * @param array{state?:string,kind?:string,requested_by?:int,limit?:int,offset?:int} $f
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function all(array $f = []): array
    {
        $where = [];
        $p = [];
        foreach (['state', 'kind'] as $k) {
            if (!empty($f[$k])) {
                $where[] = "$k = ?";
                $p[] = (string) $f[$k];
            }
        }
        if (!empty($f['requested_by'])) {
            $where[] = 'requested_by = ?';
            $p[] = (int) $f['requested_by'];
        }
        $w = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $limit = max(1, min(200, (int) ($f['limit'] ?? 50)));
        $offset = max(0, (int) ($f['offset'] ?? 0));
        $items = [];
        foreach ($this->sql->all("SELECT * FROM rmm_approvals$w ORDER BY approval_id DESC LIMIT $limit OFFSET $offset", $p) as $r) {
            $items[] = self::row($r);
        }

        return ['items' => $items, 'total' => (int) $this->sql->val("SELECT COUNT(*) FROM rmm_approvals$w", $p)];
    }

    /**
     * Approve or reject. The row is returned in its new state on success.
     *
     * @return array{ok:bool,code?:string,error?:string,approval?:array<string,mixed>}
     */
    public function decide(int $approvalId, int $deciderId, bool $approve, string $note = ''): array
    {
        return $this->sql->transaction(function () use ($approvalId, $deciderId, $approve, $note): array {
            $r = $this->sql->one('SELECT * FROM rmm_approvals WHERE approval_id = ? FOR UPDATE', [$approvalId]);
            if ($r === null) {
                return ['ok' => false, 'code' => 'not_found', 'error' => 'Approval not found.'];
            }
            if ($r['state'] !== self::PENDING) {
                return ['ok' => false, 'code' => 'conflict', 'error' => 'That request was already ' . str_replace('_', ' ', (string) $r['state']) . '.'];
            }
            if ((string) $r['expires_at'] <= $this->sql->utcNow()) {
                $this->sql->run('UPDATE rmm_approvals SET state = ?, decided_at = ? WHERE approval_id = ?', [self::EXPIRED, $this->sql->utcNow(), $approvalId]);

                return ['ok' => false, 'code' => 'expired', 'error' => 'That request has expired. Ask for it again.'];
            }
            if ((int) $r['requested_by'] === $deciderId) {
                return ['ok' => false, 'code' => 'own_request', 'error' => 'A request must be approved by someone other than the person who made it.'];
            }
            $this->sql->run('UPDATE rmm_approvals SET state = ?, decided_by = ?, decided_at = ?, decision_note = ? WHERE approval_id = ? AND state = ?',
                [$approve ? self::APPROVED : self::REJECTED, $deciderId, $this->sql->utcNow(), mb_substr($note, 0, 300), $approvalId, self::PENDING]);

            return ['ok' => true, 'approval' => $this->get($approvalId) ?? []];
        });
    }

    /** The requester (or an administrator, the caller decides) withdraws a pending request. */
    public function cancel(int $approvalId, int $userId): bool
    {
        return $this->sql->run('UPDATE rmm_approvals SET state = ?, decided_by = ?, decided_at = ? WHERE approval_id = ? AND state = ?',
            [self::CANCELLED, $userId, $this->sql->utcNow(), $approvalId, self::PENDING]) === 1;
    }

    /**
     * Record what an approved request did (devices reached, jobs created, errors).
     *
     * @param array<string,mixed> $result
     */
    public function recordResult(int $approvalId, array $result): void
    {
        $this->sql->run('UPDATE rmm_approvals SET result_json = ? WHERE approval_id = ?', [(string) json_encode($result), $approvalId]);
    }

    /**
     * Mark pending requests past their expiry as expired.
     *
     * @return list<array<string,mixed>> the requests that just lapsed
     */
    public function expireDue(): array
    {
        $now = $this->sql->utcNow();
        $due = $this->sql->all('SELECT * FROM rmm_approvals WHERE state = ? AND expires_at <= ? ORDER BY approval_id LIMIT 200', [self::PENDING, $now]);
        $out = [];
        foreach ($due as $r) {
            if ($this->sql->run('UPDATE rmm_approvals SET state = ?, decided_at = ? WHERE approval_id = ? AND state = ?', [self::EXPIRED, $now, $r['approval_id'], self::PENDING]) === 1) {
                $out[] = self::row($r) + ['state' => self::EXPIRED];
            }
        }

        return $out;
    }

    /** Remove decided requests older than `$days` days. */
    public function prune(int $days): int
    {
        return $this->sql->run('DELETE FROM rmm_approvals WHERE state <> ? AND requested_at < ?', [self::PENDING, $this->sql->utcAt(-$days * 86400)]);
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function row(array $r): array
    {
        $res = is_string($r['result_json'] ?? null) ? json_decode($r['result_json'], true) : null;

        return ['approval_id' => (int) $r['approval_id'], 'kind' => (string) $r['kind'], 'state' => (string) $r['state'], 'summary' => (string) $r['summary'], 'device_count' => (int) $r['device_count'],
            'script_id' => $r['script_id'] === null ? null : (int) $r['script_id'], 'script_version' => $r['script_version'] === null ? null : (int) $r['script_version'],
            'requested_by' => (int) $r['requested_by'], 'requested_at' => Sql::iso((string) $r['requested_at']), 'expires_at' => Sql::iso((string) $r['expires_at']),
            'decided_by' => $r['decided_by'] === null ? null : (int) $r['decided_by'], 'decided_at' => Sql::iso($r['decided_at'] === null ? null : (string) $r['decided_at']),
            'decision_note' => (string) $r['decision_note'], 'result' => is_array($res) ? $res : null];
    }
}
