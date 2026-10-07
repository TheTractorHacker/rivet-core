<?php

declare(strict_types=1);

/**
 * Helper commands of the S1 load measurement (scratch database only), used by scripts/rmm-load/run.php and by hand.
 *
 *   php scripts/rmm-load/load.php setup <devices> [mode=sync|queued] [json-settings]   migrate, wipe, enable, mint an enrollment token (printed)
 *   php scripts/rmm-load/load.php worker [seconds]                                       the queued-ingest worker (drain loop)
 *   php scripts/rmm-load/load.php stats                                                  table sizes, queue and shed state as JSON
 *   php scripts/rmm-load/load.php shed                                                   shed level and queue backlog (cheap, for polling)
 *   php scripts/rmm-load/load.php counters                                               the database server's own counters as JSON
 */

use RivetCore\Tests\Support\LoadEdition;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
date_default_timezone_set('UTC');
$cmd = $argv[1] ?? '';
$e = new LoadEdition();

switch ($cmd) {
    case 'setup':
        $n = (int) ($argv[2] ?? 100);
        LoadEdition::migrate($e->db);
        LoadEdition::wipe($e->db);
        $s = $e->module->settings();
        $s->enable();
        $settings = ['unmatched_policy' => 'auto_create', 'ingest_mode' => ($argv[3] ?? 'sync')];
        $settings += json_decode($argv[4] ?? '{}', true) ?: [];
        $err = $s->update($settings);
        if ($err !== []) {
            fwrite(STDERR, implode("\n", $err) . "\n");
            exit(1);
        }
        // the server caps one token at 5,000 uses: one token per 5,000 devices, printed comma-separated for rmm-sim
        $tokens = [];
        for ($left = $n + 10; $left > 0; $left -= 5000) {
            $tokens[] = $e->module->enrollment()->createToken(1, 0, 'stable', 24, min(5000, $left), 'load', 1)['token'];
        }
        echo implode(',', $tokens), "\n";
        break;
    case 'worker':
        $until = time() + (int) ($argv[2] ?? 60);
        $q = $e->module->ingestQueue();
        $total = 0;
        while (time() < $until) {
            $r = $q->drain();
            $total += $r['completed'];
            if ($r['claimed'] === 0) {
                usleep(300000);
            }
        }
        echo json_encode(['completed' => $total]), "\n";
        break;
    case 'stats':
        echo json_encode([
            'capacity' => array_intersect_key($e->module->capacity()->build(), array_flip(['devices', 'checkins', 'queue', 'shed', 'tables'])),
            'metrics_rows' => (int) $e->db->fetchOne('SELECT COUNT(*) AS c FROM load_metrics')['c'],
            'checkin_rows' => (int) $e->db->fetchOne('SELECT COUNT(*) AS c FROM endpoint_agent_checkins')['c'],
            'duplicate_seq' => (int) $e->db->fetchOne('SELECT COUNT(*) AS c FROM (SELECT device_id, seq FROM endpoint_agent_checkins GROUP BY device_id, seq HAVING COUNT(*) > 1) d')['c'],
            'device_seq_mismatch' => (int) $e->db->fetchOne('SELECT COUNT(*) AS c FROM endpoint_agent_devices d WHERE d.last_seq <> COALESCE((SELECT MAX(seq) FROM endpoint_agent_checkins c WHERE c.device_id = d.device_id), 0)')['c'],
        ]), "\n";
        break;
    case 'shed':
        $row = $e->db->fetchOne('SELECT shed_level, ingest_mode FROM endpoint_agent_settings WHERE id = 1');
        $q = $e->module->ingestQueue()->backlog();
        echo json_encode(['level' => (int) $row['shed_level'], 'backlog' => $q['pending'], 'oldest_s' => $q['oldest_pending_age_s']]), "\n";
        break;
    case 'counters':
        $out = [];
        foreach ($e->db->fetchAll("SHOW GLOBAL STATUS WHERE Variable_name IN ('Questions','Com_select','Com_insert','Com_update','Com_delete','Com_commit','Handler_write','Handler_update','Handler_delete','Connections','Threads_connected')") as $r) {
            $out[$r['Variable_name']] = (int) $r['Value'];
        }
        echo json_encode($out), "\n";
        break;
    default:
        fwrite(STDERR, "usage: load.php setup|worker|stats|counters\n");
        exit(2);
}
