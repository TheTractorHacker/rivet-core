<?php

declare(strict_types=1);

/**
 * Report of a soak run (scripts/rmm-load/soak.sh): reads DIR/{run.kv,samples.csv,sim.jsonl,sim.log,herd_plan.txt,dashboard.csv,status_*.txt}
 * and writes DIR/summary.md and DIR/summary.json, then prints the PASS/FAIL table against the targets of docs/rmm/SCALING.md
 * ("Targets for the 10,000-device validation run").
 *
 *   php scripts/rmm-load/soak-report.php DIR
 *
 * Exit code: 0 = PASS, 1 = at least one FAIL, 3 = no FAIL but a required row is NOT MEASURED. A missing metric is never PASS.
 * Latency note: rmm-sim measures CLIENT-SIDE time per request (from sending to the full response, over the real network, TLS and the
 * simulator's own scheduling), failed requests included; it is not the server's processing time.
 */

if (!isset($argv[1]) || !is_dir($argv[1])) {
    fwrite(STDERR, "usage: php soak-report.php DIR\n");
    exit(2);
}
$dir = rtrim($argv[1], '/');

// ------------------------------------------------------------------ inputs
$kv = [];
foreach (@file("$dir/run.kv", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    if (($p = strpos($l, '=')) !== false) {
        $kv[substr($l, 0, $p)] = substr($l, $p + 1);
    }
}
$devices = (int) ($kv['devices'] ?? 0);
$intervalS = (float) ($kv['interval_s'] ?? 300);
$plannedS = (int) ($kv['duration_planned_s'] ?? 0);
$measStart = isset($kv['meas_start_epoch']) ? (int) $kv['meas_start_epoch'] : null;
$outStart = isset($kv['outage_start_epoch']) ? (int) $kv['outage_start_epoch'] : null;
$outEnd = isset($kv['outage_end_epoch']) ? (int) $kv['outage_end_epoch'] : null;
$hasWindow = $measStart !== null && $outStart !== null && $outEnd !== null;
$planned = (int) ($kv['outage_s'] ?? 0) > 0;
$interrupted = (int) ($kv['interrupted'] ?? 0) > 0;
$GRACE = 900;   // seconds after the outage in which errors, shedding and latency are the expected herd recovery, not steady state

$samples = [];
if (($fh = @fopen("$dir/samples.csv", 'rb')) !== false) {
    $hdr = fgetcsv($fh, 0, ',', '"', '');
    while ($hdr && ($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if (count($r) === count($hdr)) {
            $row = array_combine($hdr, $r);
            $row['_t'] = (int) strtotime($row['ts_utc'] . '');
            $samples[] = $row;
        }
    }
    fclose($fh);
}
$inWindow = static fn (int $t): bool => $hasWindow && $t >= $outStart - 5 && $t <= $outEnd + $GRACE;
/** values of a column over the samples accepted by $keep */
$col = static function (string $name, ?callable $keep = null) use ($samples): array {
    $out = [];
    foreach ($samples as $s) {
        if (($s[$name] ?? '') === '' || !is_numeric($s[$name]) || ($keep && !$keep($s))) {
            continue;
        }
        $out[] = (float) $s[$name];
    }

    return $out;
};
$steady = static fn (array $s): bool => !in_array($s['event'], ['enrolling', 'drain'], true) && !(($s['event'] === 'outage' || $s['event'] === 'recovery'));
$notEnrolling = static fn (array $s): bool => $s['event'] !== 'enrolling';

// simulator: final JSON summary + periodic progress lines
$sum = null;
foreach (@file("$dir/sim.jsonl", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    $j = json_decode($l, true);
    if (is_array($j) && isset($j['requests'])) {
        $sum = $j;
    }
}
$durMs = static function (string $s): float {   // Go duration -> ms
    $ms = 0.0;
    if (preg_match_all('/(\d+(?:\.\d+)?)(ns|µs|us|ms|s|m|h)/u', $s, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $ms += (float) $x[1] * ['ns' => 1e-6, 'µs' => 1e-3, 'us' => 1e-3, 'ms' => 1.0, 's' => 1000.0, 'm' => 60000.0, 'h' => 3600000.0][$x[2]];
        }
    }

    return $ms;
};
$periods = [];   // each: t (end, s), len, rate, p95, p99 (ms), cum (code => n)
$prevT = 0.0;
foreach (@file("$dir/sim.log", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    if (preg_match('/^t=(\S+)\s+([\d.]+) req\/s\s+p50=(\S+)\s+p95=(\S+)\s+p99=(\S+)\s+max=(\S+)\s+inflight=(\d+)\s*(.*)$/u', $l, $m)) {
        $t = $durMs($m[1]) / 1000;
        $cum = [];
        foreach (preg_split('/\s+/', trim($m[8])) ?: [] as $tok) {
            if (preg_match('/^(.+)=(\d+)$/', $tok, $c)) {
                $cum[$c[1]] = (int) $c[2];
            }
        }
        $periods[] = ['t' => $t, 'len' => max(0.001, $t - $prevT), 'rate' => (float) $m[2], 'p95' => $durMs($m[4]), 'p99' => $durMs($m[5]), 'cum' => $cum];
        $prevT = $t;
    }
}
$codes = $sum['codes'] ?? ($periods ? end($periods)['cum'] : []);
$measuredS = (float) ($sum['duration_s'] ?? ($periods ? end($periods)['t'] : 0));
$ws = $hasWindow ? $outStart - $measStart : null;
$we = $hasWindow ? $outEnd - $measStart : null;
$periodExcluded = static fn (array $p, float $prevEnd): bool => $hasWindow && $p['t'] > $ws - 1 && $prevEnd < $we + $GRACE;

// per-period increments, split inside / outside the herd window
$outside = [];
$inside = [];
$outsideSeconds = 0.0;
$outsideP95w = $outsideP99w = $outsideW = 0.0;
$prevCum = [];
$prevEnd = 0.0;
foreach ($periods as $p) {
    $isIn = $periodExcluded($p, $prevEnd);
    foreach ($p['cum'] as $k => $n) {
        $d = max(0, $n - ($prevCum[$k] ?? 0));
        if ($isIn) {
            $inside[$k] = ($inside[$k] ?? 0) + $d;
        } else {
            $outside[$k] = ($outside[$k] ?? 0) + $d;
        }
    }
    if (!$isIn) {
        $outsideSeconds += $p['len'];
        $w = $p['rate'] * $p['len'];
        $outsideP95w += $p['p95'] * $w;
        $outsideP99w += $p['p99'] * $w;
        $outsideW += $w;
    }
    $prevCum = $p['cum'];
    $prevEnd = $p['t'];
}
if ($periods && $sum) {   // requests after the last progress line (the final partial period): outside
    foreach ($codes as $k => $n) {
        $d = max(0, $n - ($prevCum[$k] ?? 0));
        if ($d > 0) {
            $outside[$k] = ($outside[$k] ?? 0) + $d;
        }
    }
    $outsideSeconds += max(0.0, $measuredS - $prevEnd);
}
$usePeriods = $periods !== [];
if (!$hasWindow) {   // no window: the whole run is "outside"
    $outside = $codes;
    $outsideSeconds = $measuredS;
}

$short = $devices < 10000 || max($measuredS, 0.0) < 86395;
$rows = [];
$add = static function (string $id, string $name, string $target, string $status, string $value, bool $required = false) use (&$rows): void {
    $rows[] = ['id' => $id, 'name' => $name, 'target' => $target, 'status' => $status, 'value' => $value, 'required' => $required];
};
$fm = static fn (float $v, int $d = 1): string => number_format($v, $d, '.', '');

// ------------------------------------------------------------------ 0. did the simulator run to the end
if (isset($kv['sim_exit_code']) && (int) $kv['sim_exit_code'] !== 0 && !$interrupted) {
    $add('sim', 'Simulator completed', 'exit code 0 (enrolled every device, ran to the end)', 'FAIL', 'rmm-sim exited with ' . $kv['sim_exit_code'] . '; see sim.log (enrollment failed, server unreachable, or it crashed)', true);
}

// ------------------------------------------------------------------ 1. sustained check-ins
$expectedRate = $devices > 0 && $intervalS > 0 ? $devices / $intervalS : 0.0;   // check-ins per second
$acc = (int) ($outside['200'] ?? 0);
if ($codes === [] || $measuredS <= 0) {
    $add('sustained', 'Sustained check-ins', 'accepted/h within 5 % of devices x 3600/interval', 'NOT MEASURED', 'no simulator result (sim.jsonl and sim.log are empty)', true);
} else {
    $secs = max(1.0, $outsideSeconds);
    $perH = $acc / $secs * 3600;
    $expH = $expectedRate * 3600;
    $dev = $expH > 0 ? ($perH / $expH - 1) * 100 : 0;
    $st = abs($dev) <= 5.0 ? 'PASS' : 'FAIL';
    $enr = (int) ($sum['enrolled'] ?? 0);
    $add('sustained', 'Sustained check-ins', 'accepted/h within 5 % of devices x 3600/interval', $st,
        sprintf('%s accepted/h vs %s expected (%+.1f %%) over %s s%s%s', $fm($perH, 0), $fm($expH, 0), $dev, $fm($secs, 0), $hasWindow ? ' outside the herd window' : '',
            $sum && $enr < $devices ? "; ONLY $enr of $devices devices enrolled" : ''), true);
}

// ------------------------------------------------------------------ 2. errors
$hist = [];
foreach ($codes as $k => $n) {
    $hist[] = "$k=$n";
}
$bad5 = $badOther = 0;
$badKeys = [];
foreach ($outside as $k => $n) {
    if ((string) $k === '200' || $n === 0) {
        continue;
    }
    $badKeys[] = "$k=$n";
    if ((string) $k === 'transport' || preg_match('/^5\d\d/', (string) $k)) {
        $bad5 += $n;
    } else {
        $badOther += $n;
    }
}
if ($codes === []) {
    $add('errors', 'Errors (non-200 outside the injected window)', 'zero 5xx / transport / other non-200', 'NOT MEASURED', 'no simulator result', true);
} elseif ($hasWindow && !$usePeriods) {
    $add('errors', 'Errors (non-200 outside the injected window)', 'zero 5xx / transport / other non-200', 'NOT MEASURED', 'an outage was injected but sim.log has no progress lines to separate it', true);
} else {
    $st = ($bad5 + $badOther) === 0 ? 'PASS' : 'FAIL';
    $add('errors', 'Errors (non-200 outside the injected window)', 'zero 5xx / transport / other non-200', $st,
        $st === 'PASS' ? 'none outside the window; histogram: ' . implode(' ', $hist) : sprintf('%d 5xx/transport, %d other non-200 (%s)', $bad5, $badOther, implode(' ', $badKeys)), true);
}

// ------------------------------------------------------------------ 3/4. latency
$p95 = $p99 = null;
$src = '';
if ($hasWindow && $outsideW > 0) {
    $p95 = $outsideP95w / $outsideW;
    $p99 = $outsideP99w / $outsideW;
    $src = 'request-weighted mean of the 10 s period percentiles outside the herd window (approximation)';
} elseif ($sum) {
    $p95 = (float) $sum['p95_ms'];
    $p99 = (float) $sum['p99_ms'];
    $src = 'simulator summary over the whole run';
} elseif ($usePeriods && $outsideW > 0) {
    $p95 = $outsideP95w / $outsideW;
    $p99 = $outsideP99w / $outsideW;
    $src = 'request-weighted mean of the 10 s period percentiles (no final summary: the simulator did not finish)';
}
$cs = 'client-side, ' . ($src ?: 'n/a');
if ($p95 === null) {
    $add('p95', 'Check-in latency p95', '< 250 ms', 'NOT MEASURED', 'no simulator latency data', true);
    $add('p99', 'Check-in latency p99', '< 1000 ms', 'NOT MEASURED', 'no simulator latency data', true);
} else {
    $add('p95', 'Check-in latency p95', '< 250 ms', $p95 < 250 ? 'PASS' : 'FAIL', $fm($p95) . " ms ($cs)", true);
    $add('p99', 'Check-in latency p99', '< 1000 ms', $p99 < 1000 ? 'PASS' : 'FAIL', $fm($p99) . ' ms', true);
}

// ------------------------------------------------------------------ 5. database CPU
$cpuSteady = $col('db_cpu_pct', $steady);
$cpuAll = $col('db_cpu_pct', $notEnrolling);
$cpuBasis = $cpuSteady ?: $cpuAll;
if ($cpuBasis === []) {
    $add('dbcpu', 'Database CPU', 'average < 60 % (of all cores)', 'NOT MEASURED', 'no CPU readings (no --db-container / --db-pid / local database process)');
} else {
    $avg = array_sum($cpuBasis) / count($cpuBasis);
    $add('dbcpu', 'Database CPU', 'average < 60 % (of all cores)', $avg < 60 ? 'PASS' : 'FAIL',
        sprintf('avg %s %%, max %s %% (n=%d samples; %% of one core divided by %s cores; outage, recovery, enrollment and drain samples excluded from the average)', $fm($avg), $fm(max($cpuAll)), count($cpuBasis), $kv['db_cores'] ?? '?'));
}

// ------------------------------------------------------------------ 6. lock-wait timeouts
$lto = $col('db_lock_wait_timeouts_delta');
if ($lto === []) {
    $add('locks', 'Lock-wait timeouts', '= 0 in the check-in path', 'NOT MEASURED', 'counter not available or database not sampled');
} else {
    $sumT = (int) array_sum($lto);
    $lw = (int) array_sum($col('db_lock_waits_delta'));
    $lt = (int) array_sum($col('db_lock_time_ms_delta'));
    $add('locks', 'Lock-wait timeouts', '= 0 in the check-in path', $sumT === 0 ? 'PASS' : 'FAIL', "$sumT timeouts; row lock waits $lw, total wait $lt ms");
}

// ------------------------------------------------------------------ 7. backlog
$ages = $col('queue_oldest_pending_age_s', $notEnrolling);
$last = null;
foreach ($samples as $s) {
    if ($s['queue_pending'] !== '' && $s['queue_running'] !== '') {
        $last = $s;
    }
}
if ($ages === [] || $last === null) {
    $add('backlog', 'Backlog', 'oldest pending < 120 s and drains to 0', 'NOT MEASURED', 'queue not sampled (database not reachable or integration_jobs missing)', true);
} else {
    $maxAge = max($ages);
    $left = (int) $last['queue_pending'] + (int) $last['queue_running'];
    $dead = (int) ($last['queue_dead_letter'] ?: 0);
    $maxPending = max($col('queue_pending') ?: [0]);
    $st = ($maxAge < 120 && $left === 0 && $dead === 0) ? 'PASS' : 'FAIL';
    $note = $maxPending === 0.0 ? '; queue was always empty: confirm ingest_mode=queued, otherwise this row says nothing' : '';
    $add('backlog', 'Backlog', 'oldest pending < 120 s and drains to 0', $st,
        sprintf('max oldest pending %s s, max pending %d, left at end %d, dead letter %d%s', $fm($maxAge, 0), $maxPending, $left, $dead, $note), true);
}

// ------------------------------------------------------------------ 8. pruning / slow statements
$slowSum = (int) array_sum($col('db_slow_queries_delta'));
$slowFromFiles = null;
$statusVal = static function (string $file, string $name): ?int {
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        $p = explode("\t", $l);
        if (($p[0] ?? '') === $name && isset($p[1]) && ctype_digit($p[1])) {
            return (int) $p[1];
        }
    }

    return null;
};
$b = $statusVal("$dir/status_before.txt", 'Slow_queries');
$a = $statusVal("$dir/status_after.txt", 'Slow_queries');
if ($a !== null && $b !== null && $a >= $b) {
    $slowFromFiles = $a - $b;
}
$slowIn = 0;
$slowOut = 0;
foreach ($samples as $s) {
    if ($s['db_slow_queries_delta'] !== '' && is_numeric($s['db_slow_queries_delta'])) {
        if ($inWindow($s['_t'])) {
            $slowIn += (int) $s['db_slow_queries_delta'];
        } else {
            $slowOut += (int) $s['db_slow_queries_delta'];
        }
    }
}
// with an injected outage the herd recovery is judged by its own row; the pruning row counts the rest of the run
$slowTotal = $hasWindow ? $slowOut : ($slowFromFiles ?? $slowSum);
$haveSlow = $slowFromFiles !== null || $col('db_slow_queries_delta') !== [];
$lqt = $kv['long_query_time'] ?? '';
$maxStmt = $col('db_max_stmt_ms_seen');
$msTxt = $maxStmt ? 'max statement seen (performance_schema digest, since its last reset) ' . $fm(max($maxStmt)) . ' ms' : 'max statement time not available (performance_schema off)';
if (!$haveSlow) {
    $add('slow', 'Pruning: no statement over 1 s', 'Slow_queries delta = 0 (long_query_time=1)', 'NOT MEASURED', 'Slow_queries counter not read');
} elseif ($lqt === '' || !is_numeric($lqt) || (float) $lqt > 1.0) {
    $add('slow', 'Pruning: no statement over 1 s', 'Slow_queries delta = 0 (long_query_time=1)', 'NOT MEASURED', 'long_query_time is ' . ($lqt === '' ? 'unknown' : $lqt) . ' (must be <= 1, set it before the run); Slow_queries delta was ' . $slowTotal);
} else {
    $add('slow', 'Pruning: no statement over 1 s', 'Slow_queries delta = 0 (long_query_time=1)', $slowTotal === 0 ? 'PASS' : 'FAIL', "$slowTotal slow statements (long_query_time=$lqt)" . ($hasWindow ? "; $slowIn more inside the herd window and its recovery, reported under the herd row" : '') . "; $msTxt");
}

// ------------------------------------------------------------------ 9. table growth (information only: SCALING.md names no number)
$tables = ['tbl_checkins_mb', 'tbl_devices_mb', 'tbl_check_history_mb', 'tbl_metric_hourly_mb', 'tbl_metric_daily_mb', 'tbl_ingest_jobs_mb'];
$growth = [];
foreach ($tables as $t) {
    $first = $lastv = null;
    foreach ($samples as $s) {
        if ($s[$t] !== '' && is_numeric($s[$t])) {
            $first ??= ['v' => (float) $s[$t], 't' => $s['_t']];
            $lastv = ['v' => (float) $s[$t], 't' => $s['_t']];
        }
    }
    if ($first && $lastv) {
        $h = ($lastv['t'] - $first['t']) / 3600;
        $perH = $h > 0 ? ($lastv['v'] - $first['v']) / $h : null;
        $growth[$t] = ['first_mb' => $first['v'], 'last_mb' => $lastv['v'], 'hours' => round($h, 2), 'mb_per_h' => $perH, 'mb_30d' => $perH !== null ? $perH * 720 : null];
    }
}
if ($growth === []) {
    $add('growth', 'Table growth', 'information (no numeric target)', 'NOT MEASURED', 'sizes not sampled');
} else {
    $tot = array_sum(array_map(static fn ($g) => $g['mb_30d'] ?? 0, $growth));
    $add('growth', 'Table growth', 'information (no numeric target)', 'INFO', sprintf('linear extrapolation %s MB over 30 days for the hot tables; see the table below (not valid when retention pruning flattens it, and weak under ~1 h)', $fm($tot, 0)));
}

// ------------------------------------------------------------------ 10. shed level
$shed = $col('shed_level', static fn (array $s) => $notEnrolling($s) && !(in_array($s['event'], ['outage', 'recovery'], true)));
if ($shed === []) {
    $add('shed', 'Load shedding', 'level 0 outside the herd window', 'NOT MEASURED', 'shed_level not sampled');
} else {
    $add('shed', 'Load shedding', 'level 0 outside the herd window', max($shed) <= 0 ? 'PASS' : 'FAIL', 'max level outside the window ' . (int) max($shed));
}

// ------------------------------------------------------------------ 11. thundering herd
$herd = null;
if (!$planned) {
    $add('herd', 'Thundering herd', 'caught up within 15 min of a 30 min outage', 'NOT MEASURED', 'no outage injected (--herd-after-minutes / --outage-minutes)');
} elseif (!$hasWindow) {
    $add('herd', 'Thundering herd', 'caught up within 15 min of a 30 min outage', 'NOT MEASURED', 'the outage window was planned but the run ended before it started');
} else {
    $recoverS = null;
    $coverage = 0;
    $shedMax = 0.0;
    $pend0 = null;
    foreach ($samples as $s) {
        if ($s['_t'] >= $outEnd) {
            $coverage = max($coverage, $s['_t'] - $outEnd);
            if ($s['shed_level'] !== '') {
                $shedMax = max($shedMax, (float) $s['shed_level']);
            }
            if ($s['herd_pending_devices'] !== '') {
                $pend0 ??= (int) $s['herd_pending_devices'];
                if ($recoverS === null && (int) $s['herd_pending_devices'] === 0) {
                    $recoverS = $s['_t'] - $outEnd;
                }
            }
        }
    }
    $herd = ['outage_s' => $outEnd - $outStart, 'recovered_after_s' => $recoverS, 'coverage_s' => $coverage, 'max_shed_level_after' => $shedMax];
    if ($recoverS !== null) {
        $add('herd', 'Thundering herd', 'caught up within 15 min of a 30 min outage', $recoverS <= 900 ? 'PASS' : 'FAIL',
            sprintf('outage %d s; all devices checked in again after %d s; max shed level during recovery %d (visible: %s); slow statements (> 1 s) in the window and recovery: %d', $outEnd - $outStart, $recoverS, $shedMax, $shedMax > 0 ? 'yes' : 'no', $slowIn));
    } elseif ($pend0 !== null && $coverage >= 900) {
        $add('herd', 'Thundering herd', 'caught up within 15 min of a 30 min outage', 'FAIL', "devices still not caught up $coverage s after the outage ($pend0 pending at first sample)");
    } else {
        $add('herd', 'Thundering herd', 'caught up within 15 min of a 30 min outage', 'NOT MEASURED', "catch-up not observed ($coverage s of samples after the outage; needs the database sampled and 15 min of run after the outage)");
    }
}

// ------------------------------------------------------------------ manual rows + dashboard timing
$dash = [];
foreach (@file("$dir/dashboard.csv", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    $p = str_getcsv($l, ',', '"', '');
    if (count($p) >= 5 && is_numeric($p[4])) {
        $dash[$p[1]][] = (float) $p[4];
    }
}
$dtxt = static function (string $kind, float $limit) use ($dash, $fm): string {
    if (empty($dash[$kind])) {
        return 'not timed (see the runbook, section 9)';
    }
    $mx = max($dash[$kind]);

    return sprintf('timed %d GETs: max %s s, median %s s (limit %s s: %s)', count($dash[$kind]), $fm($mx, 3), $fm(median($dash[$kind]), 3), $fm($limit, 0), $mx < $limit ? 'within' : 'OVER');
};
function median(array $a): float
{
    sort($a);
    $n = count($a);

    return $n ? ($n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2) : 0.0;
}
$add('dash', 'Fleet dashboard and device list', '< 2 s with 10,000 devices', 'MANUAL', $dtxt('dashboard', 2));
$add('graph', 'Device page graphs', '< 1 s', 'MANUAL', $dtxt('graph', 1));
$add('off', 'Disabled module = zero queries', '0 queries when the module is off', 'MANUAL', 'Questions delta check, runbook section 9');
$add('lag', 'Replication / flush lag', 'within limits', 'MANUAL', 'SHOW REPLICA STATUS / checkpoint age, runbook section 9');
$add('loss', 'No data loss for buffered samples after the outage', 'no gaps in seq per device', 'MANUAL', 'seq gap query, runbook section 9');

// ------------------------------------------------------------------ verdict
$fail = $nm = [];
foreach ($rows as $r) {
    if ($r['status'] === 'FAIL') {
        $fail[] = $r['name'];
    }
    if ($r['status'] === 'NOT MEASURED' && $r['required']) {
        $nm[] = $r['name'];
    }
}
$verdict = $fail ? 'FAIL' : ($nm ? 'INCOMPLETE' : 'PASS');
$exit = $fail ? 1 : ($nm ? 3 : 0);

// ------------------------------------------------------------------ output
$md = [];
$md[] = '# RMM soak report';
$md[] = '';
if ($short) {
    $md[] = '> **SHORT RUN, NOT A VALIDATION.** Issue #87 needs 10,000 devices for 24 hours on the reference hardware. This run does not count towards it.';
    $md[] = '';
}
$md[] = sprintf('- Devices: **%d** (enrolled %s), interval %s s (%s check-ins/s expected)', $devices, isset($sum['enrolled']) ? (string) $sum['enrolled'] : 'unknown', $fm($intervalS, 0), $fm($expectedRate, 1));
$md[] = sprintf('- Duration: **%s h** measured (%s planned)%s', $fm($measuredS / 3600, 2), $fm($plannedS / 3600, 2), $interrupted ? ' - INTERRUPTED' : '');
$md[] = sprintf('- Started %s, ended %s; target %s', $kv['start_utc'] ?? '?', $kv['end_utc'] ?? '?', $kv['url'] ?? '?');
$md[] = sprintf('- Simulator exit code %s; final summary %s', $kv['sim_exit_code'] ?? '?', $sum ? 'present' : '**MISSING** (figures come from progress lines)');
$md[] = sprintf('- Samples: %d rows in samples.csv; database CPU source: %s', count($samples), $kv['db_cpu_source'] ?? '?');
if ($hasWindow) {
    $md[] = sprintf('- Herd window: outage from +%d s to +%d s of the measurement (%d s); errors, shedding and latency within %d s after it are excluded from the steady-state rows', $ws, $we, $we - $ws, $GRACE);
}
$md[] = '';
$md[] = "## Verdict: **$verdict**" . ($short ? ' (SHORT RUN, NOT A VALIDATION)' : '');
$md[] = '';
if ($fail) {
    $md[] = 'FAIL: ' . implode('; ', $fail);
}
if ($nm) {
    $md[] = 'Required rows NOT MEASURED: ' . implode('; ', $nm);
}
$md[] = 'PASS needs no FAIL row and every required row (sustained check-ins, errors, p95, p99, backlog) measured. A missing metric is NOT MEASURED, never PASS.';
$md[] = '';
$md[] = '| Target | Requirement | Result | Measured |';
$md[] = '|---|---|---|---|';
foreach ($rows as $r) {
    $md[] = sprintf('| %s%s | %s | **%s** | %s |', $r['name'], $r['required'] ? ' (required)' : '', $r['target'], $r['status'], str_replace('|', '/', $r['value']));
}
$md[] = '';
$md[] = '## Details';
$md[] = '';
$md[] = '- Latency is **client-side**: rmm-sim times each request from sending to the full response, so it includes the network, TLS and the simulator host scheduling, and it includes failed requests (a transport error counts with its time-to-failure). It is a ceiling on the server-side latency, not the server\'s processing time.';
$md[] = '- Status histogram (whole run, from the simulator): ' . ($hist ? implode(' ', $hist) : 'n/a');
$md[] = '- Database CPU = CPU time of the database server process(es) over the sample interval as a percentage of one core, divided by the core count (' . ($kv['db_cores'] ?? '?') . '), so 100 means every core fully busy.';
$md[] = '- Table sizes come from information_schema (InnoDB statistics, refreshed lazily, roughly after 10 % of a table changed): run `ANALYZE TABLE` on the hot tables before and after the run for exact numbers; growth over short runs is not meaningful.';
$md[] = '- Sampled `Questions` include the sampler\'s own few statements per sample (negligible).';
if ($sum) {
    $md[] = sprintf('- Simulator summary: %d requests, %s req/s, p50 %s / p95 %s / p99 %s / max %s ms, error %s %%', $sum['requests'], $fm((float) $sum['req_per_s'], 1), $fm((float) $sum['p50_ms']), $fm((float) $sum['p95_ms']), $fm((float) $sum['p99_ms']), $fm((float) $sum['max_ms']), $fm((float) $sum['error_pct'], 2));
}
if ($growth) {
    $md[] = '';
    $md[] = '### Table sizes (MB)';
    $md[] = '';
    $md[] = '| Table | First | Last | Span (h) | MB/h | 30-day extrapolation (MB) |';
    $md[] = '|---|---|---|---|---|---|';
    foreach ($growth as $t => $g) {
        $md[] = sprintf('| %s | %s | %s | %s | %s | %s |', substr($t, 4, -3), $fm($g['first_mb'], 2), $fm($g['last_mb'], 2), $fm($g['hours'], 2), $g['mb_per_h'] === null ? 'n/a' : $fm($g['mb_per_h'], 2), $g['mb_30d'] === null ? 'n/a' : $fm($g['mb_30d'], 0));
    }
}
$md[] = '';
$md[] = '### Manual steps still open';
$md[] = '';
$md[] = '- Fleet dashboard and device list under 2 s, device graphs under 1 s with the 10,000-device data set: `curl -sS -o /dev/null -b "$COOKIE" -w "%{time_total}\n" URL` (runbook section 9).';
$md[] = '- Disabled module issues zero queries: compare `SHOW GLOBAL STATUS LIKE \'Questions\'` before and after N requests with the module off (runbook section 9).';
$md[] = '- Replication or flush lag, and no data loss for buffered samples after the outage (seq gap query): runbook section 9.';
$md[] = '';
$md[] = 'Files: samples.csv, sim.jsonl, sim.log, env.txt, run.kv' . (is_file("$dir/herd_plan.txt") ? ', herd_plan.txt' : '') . (is_file("$dir/dashboard.csv") ? ', dashboard.csv' : '') . ', status_before.txt, status_after.txt.';
$text = implode("\n", $md) . "\n";
file_put_contents("$dir/summary.md", $text);
file_put_contents("$dir/summary.json", json_encode([
    'verdict' => $verdict, 'short_run_not_a_validation' => $short, 'devices' => $devices, 'interval_s' => $intervalS, 'measured_s' => $measuredS, 'planned_s' => $plannedS,
    'interrupted' => $interrupted, 'rows' => $rows, 'status_histogram' => $codes, 'herd' => $herd, 'table_growth' => $growth, 'sim_summary' => $sum, 'run' => $kv,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo $text;
exit($exit);
