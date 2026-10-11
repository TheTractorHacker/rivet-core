<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Logs;

use RivetCore\Rmm\Checks\CheckEvaluator;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Remote\RemoteProtocol;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * Log rules (Phase 8): "when a collected entry matches, raise an alert". A rule is a plain filter (severity or worse, source contains, event id, text contains)
 * plus a count within a window; it never uses a regular expression, so a hostile or careless pattern cannot cost the server more than a substring search.
 *
 * A rule drives ONE check per device, `log.rule_<id>`, through the existing {@see CheckEvaluator} (so debounce, the Phase 3 alerting engine, maintenance
 * windows, escalation and the edition's alert and ticket path all apply unchanged): a match reports the check as `warn` or `fail`, and {@see sweep()} reports it `ok`
 * once the rule has not matched on that device for `resolve_after_min` minutes (or at once when the rule was disabled or deleted). Rules are evaluated on the
 * entries of each accepted batch, so the cost is bounded by the batch (200 entries) times the number of enabled rules (at most {@see MAX_RULES}).
 *
 * @api
 */
final class LogRuleService
{
    public const MAX_RULES = 50;

    /** @var list<array<string,mixed>>|null enabled rules, per instance */
    private ?array $enabled = null;

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly CheckEvaluator $checks,
        private readonly DeviceRepository $devices,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    // ------------------------------------------------------------------ CRUD

    /**
     * @param list<int>|null $visibleClientIds null = unrestricted; a restricted caller sees only the rules of their own clients, never the all-clients ones
     * @return list<array<string,mixed>>
     */
    public function all(?array $visibleClientIds = null): array
    {
        $rows = $this->sql->all('SELECT * FROM endpoint_agent_log_rules ORDER BY rule_id');
        $rows = array_values(array_filter($rows, static fn (array $r): bool => $visibleClientIds === null || ((int) $r['client_id'] !== 0 && in_array((int) $r['client_id'], $visibleClientIds, true))));

        return array_map(self::present(...), $rows);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $r = $this->sql->one('SELECT * FROM endpoint_agent_log_rules WHERE rule_id = ?', [$id]);

        return $r === null ? null : self::present($r);
    }

    /**
     * @param array<string,mixed> $in
     * @return array{0:?int,1:list<string>} [rule id, errors]
     */
    public function create(array $in, int $userId): array
    {
        if ((int) $this->sql->val('SELECT COUNT(*) FROM endpoint_agent_log_rules') >= self::MAX_RULES) {
            return [null, ['At most ' . self::MAX_RULES . ' log rules.']];
        }
        [$v, $err] = self::validate($in, null);
        if ($err !== []) {
            return [null, $err];
        }
        $now = $this->sql->utcNow();
        $id = $this->sql->insert('INSERT INTO endpoint_agent_log_rules (name, enabled, client_id, min_level, source_contains, code, contains, threshold, window_min, severity, resolve_after_min, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$v['name'], $v['enabled'], $v['client_id'], $v['min_level'], $v['source_contains'], $v['code'], $v['contains'], $v['threshold'],
            $v['window_min'], $v['severity'], $v['resolve_after_min'], $userId, $now, $now]);
        $this->enabled = null;

        return [$id, []];
    }

    /**
     * @param array<string,mixed> $in
     * @return list<string> errors; empty when saved
     */
    public function update(int $id, array $in): array
    {
        $cur = $this->sql->one('SELECT * FROM endpoint_agent_log_rules WHERE rule_id = ?', [$id]);
        if ($cur === null) {
            return ['No such rule.'];
        }
        [$v, $err] = self::validate($in, $cur);
        if ($err !== []) {
            return $err;
        }
        $this->sql->run('UPDATE endpoint_agent_log_rules SET name = ?, enabled = ?, client_id = ?, min_level = ?, source_contains = ?, code = ?, contains = ?, threshold = ?, window_min = ?, severity = ?,
            resolve_after_min = ?, updated_at = ? WHERE rule_id = ?', [$v['name'], $v['enabled'], $v['client_id'], $v['min_level'], $v['source_contains'], $v['code'], $v['contains'], $v['threshold'],
            $v['window_min'], $v['severity'], $v['resolve_after_min'], $this->sql->utcNow(), $id]);
        $this->enabled = null;

        return [];
    }

    public function delete(int $id): bool
    {
        $n = $this->sql->run('DELETE FROM endpoint_agent_log_rules WHERE rule_id = ?', [$id]);
        $this->enabled = null;

        return $n > 0;   // alerts the rule had open are closed by the next sweep()
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed>|null $cur the stored row when updating (absent keys keep their value)
     * @return array{0:array<string,mixed>,1:list<string>}
     */
    public static function validate(array $in, ?array $cur): array
    {
        $err = [];
        $known = ['name', 'enabled', 'client_id', 'min_level', 'source_contains', 'code', 'contains', 'threshold', 'window_min', 'severity', 'resolve_after_min'];
        foreach (array_diff(array_keys($in), $known) as $k) {
            $err[] = 'Unknown field ' . mb_substr((string) $k, 0, 40) . '.';
        }
        $get = static fn (string $k, mixed $default): mixed => array_key_exists($k, $in) ? $in[$k] : ($cur !== null ? $cur[$k] : $default);
        $name = $get('name', '');
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            $err[] = 'A rule needs a name of 1 to 100 characters.';
        }
        $level = $get('min_level', 2);
        if (is_string($level)) {
            $level = RemoteProtocol::levelNumber($level);
        }
        if (!is_int($level) || $level < 1 || $level > 5) {
            $err[] = 'min_level must be critical, error, warning, info or debug.';
        }
        $src = $get('source_contains', '');
        if (!is_string($src) || mb_strlen($src) > 100 || preg_match('/[\x00-\x1f\x7f]/', $src) === 1) {
            $err[] = 'source_contains is at most 100 printable characters.';
        }
        $contains = $get('contains', '');
        if (!is_string($contains) || mb_strlen($contains) > 200 || preg_match('/[\x00-\x1f\x7f]/', $contains) === 1) {
            $err[] = 'contains is at most 200 printable characters.';
        }
        $code = $get('code', null);
        if ($code !== null && (!is_int($code) || $code < 0 || $code > 2147483647)) {
            $err[] = 'code must be a whole number or null.';
        }
        $client = $get('client_id', 0);
        if (!is_int($client) && !(is_string($client) && ctype_digit($client)) || (int) $client < 0) {
            $err[] = 'client_id must be 0 (all) or a client id.';
        }
        $ints = [];
        foreach ([['threshold', 1, 1, 1000], ['window_min', 5, 1, 1440], ['resolve_after_min', 30, 1, 10080]] as [$k, $def, $min, $max]) {
            $v = $get($k, $def);
            if (!is_int($v) || $v < $min || $v > $max) {
                $err[] = "$k must be a whole number from $min to $max.";
            }
            $ints[$k] = $v;
        }
        $sev = $get('severity', 'warn');
        if (!is_string($sev) || !in_array($sev, ['warn', 'fail'], true)) {
            $err[] = 'severity must be warn or fail.';
        }
        $enabled = $get('enabled', 1);
        if (!is_bool($enabled) && !is_int($enabled)) {
            $err[] = 'enabled must be true or false.';
        }
        if ($err === [] && trim((string) $src) === '' && trim((string) $contains) === '' && $code === null && (int) $level >= 4) {
            $err[] = 'A rule on info or debug entries needs a source, an event id or a phrase, or it would match nearly everything.';
        }
        if ($err !== []) {
            return [[], $err];
        }

        return [['name' => trim((string) $name), 'enabled' => $enabled ? 1 : 0, 'client_id' => (int) $client, 'min_level' => (int) $level, 'source_contains' => trim((string) $src),
            'code' => $code, 'contains' => trim((string) $contains), 'threshold' => $ints['threshold'], 'window_min' => $ints['window_min'], 'severity' => $sev,
            'resolve_after_min' => $ints['resolve_after_min']], []];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function present(array $r): array
    {
        return ['rule_id' => (int) $r['rule_id'], 'name' => (string) $r['name'], 'enabled' => (int) $r['enabled'] === 1, 'client_id' => (int) $r['client_id'],
            'min_level' => RemoteProtocol::levelName((int) $r['min_level']), 'source_contains' => (string) $r['source_contains'], 'code' => $r['code'] === null ? null : (int) $r['code'],
            'contains' => (string) $r['contains'], 'threshold' => (int) $r['threshold'], 'window_min' => (int) $r['window_min'], 'severity' => (string) $r['severity'],
            'resolve_after_min' => (int) $r['resolve_after_min'], 'check_key' => RemoteProtocol::LOG_CHECK_PREFIX . $r['rule_id'], 'created_at' => Sql::iso((string) $r['created_at']),
            'updated_at' => Sql::iso((string) $r['updated_at'])];
    }

    // ------------------------------------------------------------------ matching

    /**
     * Does a cleaned entry match a rule's filter (the client scope is the caller's concern)?
     *
     * @param array<string,mixed> $rule a stored rule row
     * @param array{level:int,source:string,code:?int,message:string} $e
     */
    public static function matches(array $rule, array $e): bool
    {
        if ($e['level'] > (int) $rule['min_level']) {
            return false;
        }
        if ($rule['source_contains'] !== '' && mb_stripos($e['source'], (string) $rule['source_contains']) === false) {
            return false;
        }
        if ($rule['code'] !== null && $e['code'] !== (int) $rule['code']) {
            return false;
        }
        if ($rule['contains'] !== '' && mb_stripos($e['message'], (string) $rule['contains']) === false) {
            return false;
        }

        return true;
    }

    /** @return list<array<string,mixed>> */
    private function enabledRules(): array
    {
        return $this->enabled ??= $this->sql->all('SELECT * FROM endpoint_agent_log_rules WHERE enabled = 1 ORDER BY rule_id LIMIT ' . self::MAX_RULES);
    }

    /**
     * Evaluate the entries of an accepted batch.
     *
     * @param array<string,mixed> $dev
     * @param list<array{ts:int,level:int,source:string,code:?int,message:string,hash:string}> $entries
     */
    public function evaluate(array $dev, array $entries): void
    {
        $rules = array_values(array_filter($this->enabledRules(), static fn (array $r): bool => (int) $r['client_id'] === 0 || (int) $r['client_id'] === (int) $dev['client_id']));
        if ($rules === [] || $entries === []) {
            return;
        }
        $cfg = $this->settings->get();
        $copies = max(1, (int) $cfg['failure_debounce']);   // a log match is a fact, not a flaky reading: report it enough times to pass the debounce at once
        $at = $this->sql->utcNow();
        $results = [];
        foreach ($rules as $rule) {
            $hits = array_values(array_filter($entries, static fn (array $e): bool => self::matches($rule, $e)));
            if ($hits === []) {
                continue;
            }
            $count = count($hits);
            if ((int) $rule['threshold'] > 1) {
                $count = $this->windowCount($dev, $rule);
            }
            if ($count < (int) $rule['threshold']) {
                continue;
            }
            $sample = mb_substr($hits[0]['message'], 0, 200);
            $key = RemoteProtocol::LOG_CHECK_PREFIX . $rule['rule_id'];
            $detail = mb_substr((string) $rule['name'] . ': ' . $count . ' matching log ' . ($count === 1 ? 'entry' : 'entries') . ($sample !== '' ? '; latest: ' . $sample : ''), 0, 400);
            for ($i = 0; $i < $copies; ++$i) {
                $results[] = ['key' => $key, 'status' => (string) $rule['severity'], 'detail' => $detail, 'at' => $at];
            }
            $this->events?->emit(RmmEvent::LOG_RULE_MATCHED, $dev, ['rule_id' => (int) $rule['rule_id'], 'rule_name' => (string) $rule['name'], 'match_count' => $count, 'sample' => $sample, 'alert_check_key' => $key]);
        }
        if ($results !== []) {
            $this->checks->apply($dev, $results);
        }
    }

    /**
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $rule
     */
    private function windowCount(array $dev, array $rule): int
    {
        $where = ['device_id = ?', 'occurred_at >= ?', 'level <= ?'];
        $params = [(int) $dev['device_id'], $this->sql->utcAt(-(int) $rule['window_min'] * 60), (int) $rule['min_level']];
        if ($rule['source_contains'] !== '') {
            $where[] = "source LIKE ? ESCAPE '!'";
            $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $rule['source_contains']) . '%';
        }
        if ($rule['code'] !== null) {
            $where[] = 'code = ?';
            $params[] = (int) $rule['code'];
        }
        if ($rule['contains'] !== '') {
            $where[] = "message LIKE ? ESCAPE '!'";
            $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $rule['contains']) . '%';
        }

        return (int) $this->sql->val('SELECT COUNT(*) FROM (SELECT 1 FROM endpoint_agent_log_events WHERE ' . implode(' AND ', $where) . ' LIMIT ' . ((int) $rule['threshold'] + 1) . ') t', $params);
    }

    // ------------------------------------------------------------------ recovery

    /**
     * Report `ok` for the log checks that have been quiet long enough (or whose rule is gone or disabled). Bounded: at most 200 open checks per pass.
     *
     * @return int checks recovered
     */
    public function sweep(): int
    {
        $open = $this->sql->all("SELECT device_id, check_key, last_reported_at FROM endpoint_agent_checks WHERE check_key LIKE 'log.rule!_%' ESCAPE '!' AND status IN ('warn','fail') ORDER BY last_reported_at LIMIT 200");
        if ($open === []) {
            return 0;
        }
        $rules = [];
        foreach ($this->sql->all('SELECT rule_id, enabled, resolve_after_min FROM endpoint_agent_log_rules') as $r) {
            $rules[(int) $r['rule_id']] = $r;
        }
        $copies = max(1, (int) $this->settings->get()['recovery_debounce']);
        $now = $this->sql->time();
        $perDevice = [];
        foreach ($open as $c) {
            $id = (int) substr((string) $c['check_key'], strlen(RemoteProtocol::LOG_CHECK_PREFIX));
            $rule = $rules[$id] ?? null;
            $quiet = $rule === null || (int) $rule['enabled'] !== 1 || Sql::ts((string) $c['last_reported_at']) + (int) $rule['resolve_after_min'] * 60 <= $now;
            if ($quiet) {
                $perDevice[(int) $c['device_id']][] = (string) $c['check_key'];
            }
        }
        $done = 0;
        $at = $this->sql->utcNow();
        foreach ($perDevice as $deviceId => $keys) {
            $dev = $this->devices->find($deviceId);
            if ($dev === null) {
                continue;
            }
            $results = [];
            foreach ($keys as $key) {
                for ($i = 0; $i < $copies; ++$i) {
                    $results[] = ['key' => $key, 'status' => 'ok', 'detail' => 'No matching log entries for a while.', 'at' => $at];
                }
            }
            $this->checks->apply($dev, $results);
            $done += count($keys);
        }

        return $done;
    }
}
