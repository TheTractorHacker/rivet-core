<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

use RivetCore\Rmm\Support\Sql;

/**
 * Phase 3 evaluation of one device's results in one check-in (created by {@see AlertingEngine::begin()}, used by CheckEvaluator).
 *
 * @api
 */
final class CheckPipeline
{
    /** How stale the stored last reading of a check may get while nothing else about it changes. */
    public const READING_REFRESH_S = 900;

    /** @var array<string,array<string,mixed>> */
    private array $evalRows;
    /** @var array<string,true> */
    private array $dirty = [];
    /** @var array{suppress:bool,mute:bool}|null */
    private ?array $maintenance = null;
    /** @var array{device_id:int,hostname:string}|false|null false = none down */
    private array|false|null $ancestor = null;

    /**
     * @param array<string,mixed> $dev
     * @param array<string,array<string,mixed>> $defs check key => definition params
     * @param array<string,array<string,mixed>> $evalRows rows of rmm_check_eval already loaded for this device
     */
    public function __construct(private readonly AlertingEngine $engine, private readonly array $dev, private readonly array $defs, array $evalRows)
    {
        $this->evalRows = $evalRows;
    }

    /**
     * Thresholds and flap detection for one result.
     *
     * @param array{key:string,status:string,detail:string,at:string,value?:?float} $r
     * @return array{status:string,detail:string,hold:bool,fail_n:?int}
     */
    public function adjust(array $r): array
    {
        $key = $r['key'];
        $status = $r['status'];
        $detail = $r['detail'];
        $params = $this->defs[$key] ?? [];
        $out = ['status' => $status, 'detail' => $detail, 'hold' => false, 'fail_n' => null];
        if ($status === 'unknown' || (!isset($params['thresholds']) && !isset($params['flap']))) {
            return $out;
        }
        $known = isset($this->evalRows[$key]);
        $row = $this->evalRows[$key] ?? CheckEvalStore::blank();
        $before = $row;
        $value = $r['value'] ?? null;
        $t = isset($params['thresholds']) ? $this->engine->resolver->resolve($this->dev, $key, $params, $this->overrideOf($row)) : null;
        if ($t !== null && $value !== null) {
            $now = Sql::ts($r['at']);
            $state = ['tier' => (string) $row['tier'], 'cand_tier' => (string) $row['cand_tier'], 'cand_since' => $row['cand_since'] === null ? null : Sql::ts((string) $row['cand_since']), 'cand_count' => (int) $row['cand_count']];
            $raw = Thresholds::rawTier($value, $t, $state['tier']);
            $next = Thresholds::step($state, $raw, $now, $t);
            $row['tier'] = $next['tier'];
            $row['cand_tier'] = $next['cand_tier'];
            $row['cand_since'] = $next['cand_since'] === null ? null : gmdate('Y-m-d H:i:s', $next['cand_since']);
            $row['cand_count'] = $next['cand_count'];
            $row['last_reading'] = $value;
            $tStatus = Thresholds::status($next['tier']);
            // The agent's own verdict can only make it worse, never better.
            if (Thresholds::rank(Thresholds::tierOfStatus($tStatus)) > Thresholds::rank(Thresholds::tierOfStatus($status))) {
                $status = $tStatus;
            }
            $note = Thresholds::describe($value, $next['tier'], $t);
            if ($next['tier'] === 'ok' && $next['cand_tier'] !== 'ok') {
                $note .= ", pending {$next['cand_tier']} ({$next['cand_count']}/" . (int) ($t['for_samples'] ?? 1) . ')';
            }
            $detail = mb_substr(trim($detail === '' ? $note : $detail . ' - ' . $note), 0, 500);
            if ((int) ($t['for_samples'] ?? 1) > 1 || (int) ($t['for_minutes'] ?? 0) > 0) {
                $out['fail_n'] = 1;   // the threshold duration IS the debounce
            }
        }
        if (isset($params['flap'])) {
            [$cfg] = FlapDetector::normalize($params['flap']);
            if ($cfg !== null) {
                $f = FlapDetector::record(['bits' => (int) $row['flap_bits'], 'n' => (int) $row['flap_n'], 'flapping' => (int) $row['flapping'] === 1], in_array($status, ['warn', 'fail'], true), $cfg);
                $row['flap_bits'] = $f['bits'];
                $row['flap_n'] = $f['n'];
                $row['flapping'] = $f['flapping'] ? 1 : 0;
                if ($f['flapping']) {
                    $out['hold'] = true;
                    $detail = mb_substr(trim($detail . ' [flapping ' . $f['pct'] . '%, alert state held]'), 0, 500);
                }
            }
        }
        $out['status'] = $status;
        $out['detail'] = $detail;
        // A write only when the STATE changed (a new row, a tier, the duration counters, the flap history). The last reading alone is not a
        // reason: it changes every sample, so it is written along with a state change or when the stored one is older than READING_REFRESH_S.
        $changed = !$known || Sql::ts((string) ($before['updated_at'] ?? '')) + self::READING_REFRESH_S <= Sql::ts($r['at']);
        foreach (['tier', 'cand_tier', 'cand_since', 'cand_count', 'flap_bits', 'flap_n', 'flapping'] as $c) {
            if (($before[$c] ?? null) != ($row[$c] ?? null)) {
                $changed = true;
                break;
            }
        }
        $this->evalRows[$key] = $row;
        if ($changed) {
            $this->dirty[$key] = true;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function overrideOf(array $row): ?array
    {
        $d = ($row['override_json'] ?? null) === null ? null : json_decode((string) $row['override_json'], true);

        return is_array($d) ? $d : null;
    }

    /** Should this device's checks be left unevaluated right now (a `suppress` window is open)? Looked up lazily, once. */
    public function suppressChecks(): bool
    {
        return $this->maintenanceState()['suppress'];
    }

    /** @return array{suppress:bool,mute:bool} */
    private function maintenanceState(): array
    {
        if ($this->maintenance === null) {
            $s = $this->engine->maintenance->stateFor($this->dev);
            $this->maintenance = ['suppress' => $s['suppress'], 'mute' => $s['mute']];
        }

        return $this->maintenance;
    }

    /**
     * May an alert open for this check now? Null when yes, else the reason it is held back: 'maintenance', 'dependency', 'storm_client' or
     * 'storm_global'. Nothing is stored: the check stays bad and is asked again on its next sample, and the read model derives "held by" live.
     */
    public function openBlock(string $key): ?string
    {
        if ($this->maintenanceState()['mute']) {
            return 'maintenance';
        }
        if (empty(($this->defs[$key] ?? [])['ignore_parent']) && $this->downAncestor() !== null) {
            return 'dependency';
        }

        return $this->engine->storm->check((int) $this->dev['client_id'], empty($this->dev['asset_id']) ? null : (int) $this->dev['asset_id']);
    }

    /** @return array{device_id:int,hostname:string}|null */
    private function downAncestor(): ?array
    {
        if ($this->ancestor === null) {
            $this->ancestor = $this->engine->dependencies->downAncestor((int) $this->dev['device_id']) ?? false;
        }

        return $this->ancestor === false ? null : $this->ancestor;
    }

    /** An alert opened for the check: record it. */
    public function opened(string $key, int $episode, int $alertId, string $status, string $message): void
    {
        $this->engine->alerts->opened($this->dev, $key, $episode, $alertId, $status, $message);
    }

    public function severityChanged(string $key, int $alertId, string $toStatus): void
    {
        $this->engine->alerts->severityChanged($this->dev, $key, $alertId, $toStatus);
    }

    public function resolved(string $key, int $alertId): void
    {
        $this->engine->alerts->resolved($this->dev, $key, $alertId, 'recovered');
    }

    /** Write the evaluation rows whose state changed. */
    public function finish(): void
    {
        foreach (array_keys($this->dirty) as $key) {
            $this->engine->eval->save((int) $this->dev['device_id'], $key, $this->evalRows[$key]);
        }
        $this->dirty = [];
    }
}
