<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Rmm\RmmModule;
use RivetCore\Rmm\Support\Sql;

/**
 * A stand-in for the technician REST API, only so the golden transcripts (which drive device detail/list and job submit/cancel through
 * it) can be replayed against a Core-backed server before the real TechnicianApi exists. One administrator token, no per-ability
 * authorization: the real thing is src/Rmm/Technician + Http/TechnicianApi. Response shapes are those of RivetIT api/v1/endpoint_devices.php.
 */
final class GoldenTechnicianShim
{
    public function __construct(private readonly RmmModule $module, private readonly string $adminToken, private readonly int $adminUserId)
    {
    }

    /**
     * @param list<string> $segments path segments after "endpoint_devices"
     * @param array<string,string> $query
     * @return array{0:int,1:array<string,mixed>}
     */
    public function handle(string $method, array $segments, array $query, ?string $bearer, string $rawBody): array
    {
        if ($bearer === null || !hash_equals($this->adminToken, $bearer)) {
            return [401, ['error' => 'Unauthorized']];
        }
        $settings = $this->module->settings();
        if (!$settings->enabled()) {
            return [404, ['error' => 'The endpoint agent is not enabled.', 'code' => 'disabled']];
        }
        $cfg = $settings->get();
        $sql = $this->module->sql();
        $id = isset($segments[0]) && ctype_digit($segments[0]) ? (int) $segments[0] : null;
        $what = $segments[1] ?? null;
        $job = $segments[2] ?? null;
        $op = $segments[3] ?? null;
        if ($id === null) {
            if ($method !== 'GET' || $segments !== []) {
                return [404, ['error' => 'Not found', 'code' => 'not_found']];
            }
            $limit = max(1, min(200, (int) ($query['limit'] ?? 50)));
            $offset = max(0, (int) ($query['offset'] ?? 0));
            $rows = $sql->all("SELECT * FROM endpoint_agent_devices WHERE retired_at IS NULL ORDER BY hostname, device_id LIMIT $limit OFFSET $offset");
            $items = array_map(fn (array $d): array => $this->summary($d, $cfg), $rows);
            if (isset($query['status'])) {
                $items = array_values(array_filter($items, static fn (array $i): bool => $i['status'] === $query['status'] || $i['link_state'] === $query['status']));
            }

            return [200, ['data' => $items, 'total' => count($items)]];
        }
        $dev = $this->module->devices()->find($id);
        if ($dev === null) {
            return [404, ['error' => 'Device not found.', 'code' => 'not_found']];
        }
        if ($what === null) {
            return $method === 'GET' ? [200, $this->detail($dev, $cfg)] : [405, ['error' => 'Method not allowed', 'code' => 'method_not_allowed']];
        }
        if ($what === 'jobs') {
            if ($job === null && $method === 'GET') {
                return [200, ['data' => $this->jobs($id, (int) ($query['limit'] ?? 50))]];
            }
            if ($job === null && $method === 'POST') {
                return $this->submit($dev, json_decode($rawBody, true) ?: []);
            }
            if ($job !== null && $op === 'cancel' && $method === 'POST') {
                return $this->module->jobs()->cancel($job, $id, $this->adminUserId) ? [200, ['ok' => true]] : [409, ['error' => 'Only a queued job can be cancelled.', 'code' => 'conflict']];
            }
        }

        return [404, ['error' => 'Not found', 'code' => 'not_found']];
    }

    /**
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $in
     * @return array{0:int,1:array<string,mixed>}
     */
    private function submit(array $dev, array $in): array
    {
        $type = (string) ($in['type'] ?? '');
        $def = $this->module->jobs()->registry()->get($type);
        if ($def === null) {
            return [422, ['error' => 'Unknown job type.', 'code' => 'invalid']];
        }
        if ($dev['link_state'] !== 'linked') {
            return [409, ['error' => 'Only a device linked to an asset can receive jobs. Approve it first.', 'code' => 'conflict']];
        }
        $destructive = !empty($in['destructive']);
        if (($def->destructive || $destructive) && empty($in['confirm'])) {
            return [422, ['error' => 'This job is destructive. Confirm it explicitly.', 'code' => 'confirmation_required']];
        }
        $script = isset($in['script']) && is_string($in['script']) ? $in['script'] : null;
        $params = isset($in['params']) && is_array($in['params']) ? $in['params'] : [];
        $r = $this->module->jobs()->create($dev, $type, $script, $params, isset($in['timeout_s']) ? (int) $in['timeout_s'] : null, $destructive, $this->adminUserId);
        if (!$r['ok']) {
            return [422, ['error' => (string) ($r['error'] ?? ''), 'code' => 'invalid']];
        }

        return [201, ['job_id' => $r['job_id'], 'state' => 'queued']];
    }

    /**
     * @param array<string,mixed> $d
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    private function summary(array $d, array $cfg): array
    {
        $st = $this->module->devices()->status($d, $cfg);

        return [
            'device_id' => (int) $d['device_id'],
            'hostname' => $d['hostname'],
            'asset_id' => $d['asset_id'] === null ? null : (int) $d['asset_id'],
            'client_id' => (int) $d['client_id'],
            'link_state' => $d['link_state'],
            'status' => $st['state'],
            'last_checkin_at' => $st['last_checkin_at'],
            'offline_since' => $st['offline_since'],
            'agent_version' => $d['agent_version'],
            'ring' => $d['ring'],
            'os_version' => $d['os_version'],
            'revoked' => $d['revoked_at'] !== null,
            'retired' => $d['retired_at'] !== null,
        ];
    }

    /**
     * @param array<string,mixed> $d
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    private function detail(array $d, array $cfg): array
    {
        $sql = $this->module->sql();
        $out = $this->summary($d, $cfg);
        $out['serial'] = $d['serial'];
        $out['manufacturer'] = $d['manufacturer'];
        $out['model'] = $d['model'];
        $out['arch'] = $d['arch'];
        $out['logged_in_user'] = $d['logged_in_user'];
        $out['pending_reboot'] = $d['pending_reboot'] === null ? null : (bool) $d['pending_reboot'];
        $out['uptime_s'] = $d['uptime_s'] === null ? null : (int) $d['uptime_s'];
        $out['last_collected_at'] = Sql::iso($d['last_collected_at'] === null ? null : (string) $d['last_collected_at']);
        $out['inventory'] = $d['inventory_json'] ? json_decode((string) $d['inventory_json'], true) : null;
        $out['metrics'] = $d['last_metrics_json'] ? json_decode((string) $d['last_metrics_json'], true) : null;
        $out['match_reason'] = $d['match_reason'];
        $out['mesh_mapped'] = $sql->val('SELECT 1 FROM endpoint_agent_mesh_nodes WHERE device_id = ?', [$d['device_id']]) !== null;
        $out['checks'] = array_map(static fn (array $c): array => ['key' => $c['check_key'], 'status' => $c['status'], 'detail' => $c['detail'],
            'consecutive_failures' => (int) $c['consecutive_failures'], 'last_reported_at' => Sql::iso($c['last_reported_at'] === null ? null : (string) $c['last_reported_at'])],
            $sql->all('SELECT * FROM endpoint_agent_checks WHERE device_id = ? ORDER BY check_key', [$d['device_id']]));
        $out['jobs'] = $this->jobs((int) $d['device_id'], 20);

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function jobs(int $deviceId, int $limit): array
    {
        $rows = $this->module->sql()->all('SELECT * FROM endpoint_agent_jobs WHERE device_id = ? ORDER BY created_at DESC, job_id LIMIT ' . max(1, min(200, $limit)), [$deviceId]);

        return array_map(static function (array $j): array {
            return ['job_id' => $j['job_id'], 'type' => $j['type'], 'state' => $j['state'], 'reason' => $j['reason'], 'attempt' => (int) $j['attempt'],
                'destructive' => (bool) $j['destructive'], 'run_as' => $j['run_as'], 'timeout_s' => (int) $j['timeout_s'], 'exit_code' => $j['exit_code'] === null ? null : (int) $j['exit_code'],
                'created_by' => (int) $j['created_by'], 'created_at' => Sql::iso((string) $j['created_at']), 'started_at' => Sql::iso($j['started_at'] === null ? null : (string) $j['started_at']),
                'finished_at' => Sql::iso($j['finished_at'] === null ? null : (string) $j['finished_at']), 'expires_at' => Sql::iso((string) $j['expires_at']),
                'output' => $j['output'], 'output_truncated' => (bool) $j['output_truncated']];
        }, $rows);
    }
}
