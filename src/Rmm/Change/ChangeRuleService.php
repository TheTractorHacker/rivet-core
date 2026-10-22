<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Change;

use RivetCore\Rmm\Checks\CheckEvaluator;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * Change rules: "when a recorded change matches, raise an alert". A rule is a plain filter (watch, kind, target contains, item contains, kind of change) plus a count within
 * a window; it never uses a regular expression, so a careless pattern cannot cost the server more than a substring search.
 *
 * A rule drives ONE check per device, `change.rule_<id>`, through the existing {@see CheckEvaluator} (so debounce, the Phase 3 alerting engine, maintenance windows,
 * escalation and the edition's alert and ticket path all apply unchanged): a match reports the check as `warn` or `fail`, and {@see sweep()} reports it `ok` once the rule has
 * not matched on that device for `resolve_after_min` minutes (or at once when the rule was disabled or deleted). Evaluation is bounded by the batch (at most 100 records) times
 * the number of enabled rules (at most {@see MAX_RULES}). A change is a fact, not a flaky reading, so a match is reported enough times to pass the failure debounce at once.
 *
 * @api
 */
final class ChangeRuleService
{
    public const MAX_RULES = 50;

    /** @var list<array<string,mixed>>|null */
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
        $rows = $this->sql->all('SELECT * FROM endpoint_agent_change_rules ORDER BY rule_id');
        $rows = array_values(array_filter($rows, static fn (array $r): bool => $visibleClientIds === null || ((int) $r['client_id'] !== 0 && in_array((int) $r['client_id'], $visibleClientIds, true))));

        return array_map(self::present(...), $rows);
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $r = $this->sql->one('SELECT * FROM endpoint_agent_change_rules WHERE rule_id = ?', [$id]);

        return $r === null ? null : self::present($r);
    }

    /**
     * @param array<string,mixed> $in
     * @return array{0:?int,1:list<string>}
     */
    public function create(array $in, int $userId, string $recommended = ''): array
    {
        if ((int) $this->sql->val('SELECT COUNT(*) FROM endpoint_agent_change_rules') >= self::MAX_RULES) {
            return [null, ['At most ' . self::MAX_RULES . ' change rules.']];
        }
        [$v, $err] = self::validate($in, null);
        if ($err !== []) {
            return [null, $err];
        }
        $now = $this->sql->utcNow();
        $id = $this->sql->insert('INSERT INTO endpoint_agent_change_rules (name, enabled, client_id, watch, kind, target_contains, item_contains, change_type, threshold, window_min, severity, resolve_after_min, recommended, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$v['name'], $v['enabled'], $v['client_id'], $v['watch'], $v['kind'], $v['target_contains'], $v['item_contains'], $v['change_type'],
            $v['threshold'], $v['window_min'], $v['severity'], $v['resolve_after_min'], $recommended, $userId, $now, $now]);
        $this->enabled = null;

        return [$id, []];
    }

    /**
     * @param array<string,mixed> $in
     * @return list<string>
     */
    public function update(int $id, array $in): array
    {
        $cur = $this->sql->one('SELECT * FROM endpoint_agent_change_rules WHERE rule_id = ?', [$id]);
        if ($cur === null) {
            return ['No such rule.'];
        }
        [$v, $err] = self::validate($in, $cur);
        if ($err !== []) {
            return $err;
        }
        $this->sql->run('UPDATE endpoint_agent_change_rules SET name = ?, enabled = ?, client_id = ?, watch = ?, kind = ?, target_contains = ?, item_contains = ?, change_type = ?, threshold = ?, window_min = ?,
            severity = ?, resolve_after_min = ?, updated_at = ? WHERE rule_id = ?', [$v['name'], $v['enabled'], $v['client_id'], $v['watch'], $v['kind'], $v['target_contains'], $v['item_contains'],
            $v['change_type'], $v['threshold'], $v['window_min'], $v['severity'], $v['resolve_after_min'], $this->sql->utcNow(), $id]);
        $this->enabled = null;

        return [];
    }

    public function delete(int $id): bool
    {
        $n = $this->sql->run('DELETE FROM endpoint_agent_change_rules WHERE rule_id = ?', [$id]);
        $this->enabled = null;

        return $n > 0;   // alerts the rule had open are closed by the next sweep()
    }

    /**
     * The recommended rules: one per preset that suggests one (any change to the startup entries and the Defender exclusions, an added local administrator, any change to sshd,
     * sudo and the account files). Created once each (matched by preset), never duplicated, never touching a rule an administrator already edited.
     *
     * @return array{created:list<int>,existing:int}
     */
    public function installRecommended(int $userId, int $clientId = 0): array
    {
        $have = [];
        foreach ($this->sql->all("SELECT recommended FROM endpoint_agent_change_rules WHERE recommended <> '' AND client_id = ?", [$clientId]) as $r) {
            $have[(string) $r['recommended']] = true;
        }
        $created = [];
        foreach (ChangePresets::catalog() as $id => $p) {
            if ($p['rule'] === null || isset($have[$id])) {
                continue;
            }
            $added = $p['rule'] === 'added';
            [$rid] = $this->create(['name' => 'Change: ' . $p['title'] . ($added ? ' (member added)' : ''), 'client_id' => $clientId, 'watch' => $id, 'change' => $added ? 'added' : 'any',
                'severity' => $added || $id === 'win.defender' ? 'fail' : 'warn'], $userId, $id);
            if ($rid !== null) {
                $created[] = $rid;
            }
        }

        return ['created' => $created, 'existing' => count($have)];
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed>|null $cur
     * @return array{0:array<string,mixed>,1:list<string>}
     */
    public static function validate(array $in, ?array $cur): array
    {
        $err = [];
        $known = ['name', 'enabled', 'client_id', 'watch', 'kind', 'target_contains', 'item_contains', 'change', 'threshold', 'window_min', 'severity', 'resolve_after_min'];
        foreach (array_diff(array_keys($in), $known) as $k) {
            $err[] = 'Unknown field ' . mb_substr((string) $k, 0, 40) . '.';
        }
        $curChange = $cur === null ? 'any' : ((int) $cur['change_type'] === 0 ? 'any' : ChangeProtocol::changeName((int) $cur['change_type']));
        $get = static fn (string $k, mixed $default): mixed => array_key_exists($k, $in) ? $in[$k] : ($cur !== null ? ($k === 'change' ? $curChange : $cur[$k]) : $default);
        $name = $get('name', '');
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            $err[] = 'A rule needs a name of 1 to 100 characters.';
        }
        $watch = $get('watch', '');
        if (!is_string($watch) || ($watch !== '' && (preg_match(ChangeProtocol::WATCH_ID_RE, $watch) !== 1))) {
            $err[] = 'watch is empty (any watch) or a preset or custom watch id.';
        }
        $kind = $get('kind', '');
        if (!is_string($kind) || ($kind !== '' && !in_array($kind, ChangeProtocol::KINDS, true))) {
            $err[] = 'kind is empty (any) or one of ' . implode(', ', ChangeProtocol::KINDS) . '.';
        }
        $texts = [];
        foreach (['target_contains', 'item_contains'] as $k) {
            $t = $get($k, '');
            if (!is_string($t) || mb_strlen($t) > 200 || preg_match('/[\x00-\x1f\x7f]/', $t) === 1) {
                $err[] = "$k is at most 200 printable characters.";
            }
            $texts[$k] = $t;
        }
        $change = $get('change', 'any');
        $changeNo = $change === 'any' ? 0 : (is_string($change) ? ChangeProtocol::changeNumber($change) : null);
        if ($changeNo === null) {
            $err[] = 'change is any, added, modified or removed.';
        }
        $client = $get('client_id', 0);
        if (!is_int($client) && !(is_string($client) && ctype_digit($client)) || (int) $client < 0) {
            $err[] = 'client_id must be 0 (all) or a client id.';
        }
        $ints = [];
        foreach ([['threshold', 1, 1, 1000], ['window_min', 5, 1, 1440], ['resolve_after_min', 60, 1, 10080]] as [$k, $def, $min, $max]) {
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
        if ($err === [] && $watch === '' && $kind === '' && trim((string) $texts['target_contains']) === '' && trim((string) $texts['item_contains']) === '') {
            $err[] = 'A rule needs a watch, a kind, or a target or item phrase, or it would match every change on every device.';
        }
        if ($err !== []) {
            return [[], $err];
        }

        return [['name' => trim((string) $name), 'enabled' => $enabled ? 1 : 0, 'client_id' => (int) $client, 'watch' => (string) $watch, 'kind' => (string) $kind,
            'target_contains' => trim((string) $texts['target_contains']), 'item_contains' => trim((string) $texts['item_contains']), 'change_type' => (int) $changeNo,
            'threshold' => $ints['threshold'], 'window_min' => $ints['window_min'], 'severity' => $sev, 'resolve_after_min' => $ints['resolve_after_min']], []];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function present(array $r): array
    {
        return ['rule_id' => (int) $r['rule_id'], 'name' => (string) $r['name'], 'enabled' => (int) $r['enabled'] === 1, 'client_id' => (int) $r['client_id'], 'watch' => (string) $r['watch'],
            'kind' => (string) $r['kind'], 'target_contains' => (string) $r['target_contains'], 'item_contains' => (string) $r['item_contains'],
            'change' => (int) $r['change_type'] === 0 ? 'any' : ChangeProtocol::changeName((int) $r['change_type']), 'threshold' => (int) $r['threshold'], 'window_min' => (int) $r['window_min'],
            'severity' => (string) $r['severity'], 'resolve_after_min' => (int) $r['resolve_after_min'], 'recommended' => (string) $r['recommended'],
            'check_key' => ChangeProtocol::CHECK_PREFIX . $r['rule_id'], 'created_at' => Sql::iso((string) $r['created_at']), 'updated_at' => Sql::iso((string) $r['updated_at'])];
    }

    // ------------------------------------------------------------------ matching

    /**
     * Does a cleaned record match a rule's filter (the client scope is the caller's concern)?
     *
     * @param array<string,mixed> $rule a stored rule row
     * @param array{kind:string,watch:string,change:int,target:string,item:string} $c
     */
    public static function matches(array $rule, array $c): bool
    {
        if ($rule['watch'] !== '' && $rule['watch'] !== $c['watch']) {
            return false;
        }
        if ($rule['kind'] !== '' && $rule['kind'] !== $c['kind']) {
            return false;
        }
        if ((int) $rule['change_type'] !== 0 && (int) $rule['change_type'] !== $c['change']) {
            return false;
        }
        if ($rule['target_contains'] !== '' && mb_stripos($c['target'], (string) $rule['target_contains']) === false) {
            return false;
        }
        if ($rule['item_contains'] !== '' && mb_stripos($c['item'], (string) $rule['item_contains']) === false) {
            return false;
        }

        return true;
    }

    /** @return list<array<string,mixed>> */
    private function enabledRules(): array
    {
        return $this->enabled ??= $this->sql->all('SELECT * FROM endpoint_agent_change_rules WHERE enabled = 1 ORDER BY rule_id LIMIT ' . self::MAX_RULES);
    }

    /**
     * Evaluate the records of an accepted batch.
     *
     * @param array<string,mixed> $dev
     * @param list<array<string,mixed>> $records cleaned records
     */
    public function evaluate(array $dev, array $records): void
    {
        $rules = array_values(array_filter($this->enabledRules(), static fn (array $r): bool => (int) $r['client_id'] === 0 || (int) $r['client_id'] === (int) $dev['client_id']));
        if ($rules === [] || $records === []) {
            return;
        }
        $copies = max(1, (int) $this->settings->get()['failure_debounce']);
        $at = $this->sql->utcNow();
        $results = [];
        foreach ($rules as $rule) {
            $hits = array_values(array_filter($records, static fn (array $c): bool => self::matches($rule, $c)));
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
            $h = $hits[0];
            $sample = mb_substr(self::sample($h), 0, 200);
            $key = ChangeProtocol::CHECK_PREFIX . $rule['rule_id'];
            $detail = mb_substr((string) $rule['name'] . ': ' . $count . ' ' . ($count === 1 ? 'change' : 'changes') . '; latest: ' . $sample, 0, 400);
            for ($i = 0; $i < $copies; ++$i) {
                $results[] = ['key' => $key, 'status' => (string) $rule['severity'], 'detail' => $detail, 'at' => $at];
            }
            $this->events?->emit(RmmEvent::CHANGE_RULE_MATCHED, $dev, ['rule_id' => (int) $rule['rule_id'], 'rule_name' => (string) $rule['name'], 'match_count' => $count,
                'watch' => $h['watch'], 'target' => $h['target'], 'item' => $h['item'], 'change' => ChangeProtocol::changeName($h['change']), 'sample' => $sample, 'alert_check_key' => $key]);
        }
        if ($results !== []) {
            $this->checks->apply($dev, $results);
        }
    }

    /** @param array<string,mixed> $c */
    private static function sample(array $c): string
    {
        // everything below came from a device: markup characters, quotes, backticks and bidi marks are removed before it reaches an alert, a ticket or a webhook
        $t = static fn (mixed $v): string => ChangeProtocol::alertText((string) $v, ChangeProtocol::VALUE_MAX);
        $where = $t($c['target']) . ($c['item'] !== '' ? ($c['kind'] === 'group' ? ' ' : ' \\ ') . $t($c['item']) : '');
        $what = ChangeProtocol::changeName((int) $c['change']);
        $detail = '';
        if ($c['old'] !== '' || $c['new'] !== '') {
            $detail = ': ' . ($c['old'] !== '' ? $t($c['old']) . ' -> ' : '') . ($c['new'] !== '' ? $t($c['new']) : '(none)');
        }

        return $what . ' ' . $where . ($c['summary'] !== '' ? ' (' . $t($c['summary']) . ')' : '') . $detail;
    }

    /**
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $rule
     */
    private function windowCount(array $dev, array $rule): int
    {
        $where = ['device_id = ?', 'occurred_at >= ?'];
        $params = [(int) $dev['device_id'], $this->sql->utcAt(-(int) $rule['window_min'] * 60)];
        foreach (['watch' => 'watch', 'kind' => 'kind'] as $k => $col) {
            if ($rule[$k] !== '') {
                $where[] = "$col = ?";
                $params[] = (string) $rule[$k];
            }
        }
        if ((int) $rule['change_type'] !== 0) {
            $where[] = 'change_type = ?';
            $params[] = (int) $rule['change_type'];
        }
        foreach (['target_contains' => 'target', 'item_contains' => 'item'] as $k => $col) {
            if ($rule[$k] !== '') {
                $where[] = "$col LIKE ? ESCAPE '!'";
                $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $rule[$k]) . '%';
            }
        }

        return (int) $this->sql->val('SELECT COUNT(*) FROM (SELECT 1 FROM endpoint_agent_change_records WHERE ' . implode(' AND ', $where) . ' LIMIT ' . ((int) $rule['threshold'] + 1) . ') t', $params);
    }

    // ------------------------------------------------------------------ recovery

    /**
     * Report `ok` for the change checks that have been quiet long enough (or whose rule is gone or disabled). Bounded: at most 200 open checks per pass.
     *
     * @return int checks recovered
     */
    public function sweep(): int
    {
        $open = $this->sql->all("SELECT device_id, check_key, last_reported_at FROM endpoint_agent_checks WHERE check_key LIKE 'change.rule!_%' ESCAPE '!' AND status IN ('warn','fail') ORDER BY last_reported_at LIMIT 200");
        if ($open === []) {
            return 0;
        }
        $rules = [];
        foreach ($this->sql->all('SELECT rule_id, enabled, resolve_after_min FROM endpoint_agent_change_rules') as $r) {
            $rules[(int) $r['rule_id']] = $r;
        }
        $copies = max(1, (int) $this->settings->get()['recovery_debounce']);
        $now = $this->sql->time();
        $perDevice = [];
        foreach ($open as $c) {
            $id = (int) substr((string) $c['check_key'], strlen(ChangeProtocol::CHECK_PREFIX));
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
                    $results[] = ['key' => $key, 'status' => 'ok', 'detail' => 'No matching changes for a while.', 'at' => $at];
                }
            }
            $this->checks->apply($dev, $results);
            $done += count($keys);
        }

        return $done;
    }
}
