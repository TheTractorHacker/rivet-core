<?php

declare(strict_types=1);

/**
 * Core-backed edition adapter for the golden-transcript driver (the counterpart of adapter-rivetit.php): resets and seeds the scratch
 * database and the in-memory edition state served by core-router.php, runs state hooks and takes snapshots.
 *
 *   php adapter-core.php <state-dir> <command> [<json-args>]       (prints one JSON document on stdout)
 *
 * Environment: RIVETCORE_TEST_DB_* (the database name must contain "scratch"), RMM_GOLDEN_STATE is the <state-dir> argument,
 * optional RIVETCORE_TEST_REDIS_PORT (flushed between runs).
 */

use RivetCore\Rmm\RmmProtocol;
use RivetCore\Tests\Support\GoldenEdition;
use RivetCore\Tests\Support\RmmHarness;

if ($argc < 3) {
    fwrite(STDERR, "usage: adapter-core.php <state-dir> <command> [<json-args>]\n");
    exit(2);
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$state = rtrim($argv[1], '/');
$cmd = $argv[2];
$args = isset($argv[3]) ? (json_decode($argv[3], true) ?: []) : [];
$K = require __DIR__ . '/constants.php';
date_default_timezone_set('UTC');

if (!str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
    fwrite(STDERR, "Refusing: RIVETCORE_TEST_DB_NAME must name a scratch database\n");
    exit(2);
}
if (!is_dir($state) && !mkdir($state, 0700, true)) {
    fwrite(STDERR, "cannot create $state\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_OFF);
$db = mysqli_init();
$db->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);   // integer columns come back as integers (stable snapshots), like the RivetIT adapter
$db->real_connect(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', (string) getenv('RIVETCORE_TEST_DB_USER'), (string) getenv('RIVETCORE_TEST_DB_PASS'), (string) getenv('RIVETCORE_TEST_DB_NAME'));
if ($db->connect_errno) {
    fwrite(STDERR, "connect failed\n");
    exit(2);
}
$db->set_charset('utf8mb4');
GoldenEdition::migrate($db);

$q = static function (string $sql) use ($db) {
    $r = $db->query($sql);
    if ($r === false) {
        fwrite(STDERR, "SQL failed: {$db->error}\n$sql\n");
        exit(1);
    }

    return $r;
};
$esc = static fn (string $s): string => $db->real_escape_string($s);

$flushRedis = static function (): void {
    $port = (int) (getenv('RIVETCORE_TEST_REDIS_PORT') ?: 0);
    if ($port <= 0) {
        return;
    }
    $s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 2);
    if ($s) {
        fwrite($s, "*1\r\n\$8\r\nFLUSHALL\r\n");
        fgets($s);
        fclose($s);
    }
};

$out = [];
switch ($cmd) {
    case 'reset':
        // Wipe the endpoint tables and the serialised edition (DELETE, not TRUNCATE: ids keep growing, so per-device rate-limit buckets
        // can never collide across runs).
        foreach (['endpoint_agent_checkins', 'endpoint_agent_checks', 'endpoint_agent_jobs', 'endpoint_agent_mesh_nodes', 'endpoint_agent_releases', 'endpoint_agent_enroll_attempts',
            'endpoint_agent_enrollment_tokens', 'endpoint_agent_devices', 'endpoint_agent_binaries'] as $t) {
            $q("DELETE FROM `$t`");
        }
        $q('DELETE FROM endpoint_agent_settings');
        $q('INSERT INTO endpoint_agent_settings (id) VALUES (1)');
        @unlink("$state/edition.ser");
        foreach (glob("$state/bin/*") ?: [] as $f) {
            @unlink($f);
        }
        $ed = new GoldenEdition($state, true);
        foreach ($K['clients'] as $id => $name) {
            $got = $ed->tenancy->addClient($name);
            if ($got !== $id) {
                fwrite(STDERR, "client ids must be 1 and 2, got $got\n");
                exit(1);
            }
        }
        $assetIds = [];
        foreach ($K['assets'] as $k => $a) {
            $assetIds[$k] = $ed->assets->addAsset(['name' => $a['name'], 'client_id' => $a['client_id'], 'serial' => $a['serial'], 'make' => 'Dell']);
        }
        $base = (string) ($args['base_url'] ?? '');
        // The instance signing key is the fixed public test key, so every deterministic signature is stable across runs.
        $kid = substr(hash('sha256', $K['signing_public_b64']), 0, 16);
        $q("UPDATE endpoint_agent_settings SET signing_key_id='$kid', signing_public_key='" . $esc($K['signing_public_b64']) . "', signing_private_key_enc='"
            . $esc($ed->box->encrypt($K['signing_secret_b64'])) . "', signing_key_created_at=UTC_TIMESTAMP(), service_url='" . $esc($base) . "' WHERE id=1");
        $ed->module->settings()->refresh();
        $ed->module->settings()->integrationId();
        foreach ($K['tokens'] as $name => $t) {
            $q("INSERT INTO endpoint_agent_enrollment_tokens (token_selector, token_hash, label, client_id, location_id, ring, expires_at, max_uses, use_count, revoked_at, created_by, created_at)
                VALUES ('" . explode('.', $t['token'])[1] . "', '" . hash('sha256', explode('.', $t['token'])[2]) . "', 'golden-$name', {$t['client_id']}, 0, '{$t['ring']}', '"
                . gmdate('Y-m-d H:i:s', time() + $t['ttl_h'] * 3600) . "', {$t['max_uses']}, {$t['used']}, " . ($t['revoked'] ? 'UTC_TIMESTAMP()' : 'NULL') . ", 1, UTC_TIMESTAMP())");
        }
        if (!empty($args['enabled'])) {
            $ed->module->settings()->set(['enabled' => 1]);
        }
        // Hosted binaries, as the admin publish path stores them: file under the binary dir, the binary row, and for a ring the release row.
        $bins = [];
        foreach ($K['binaries'] as $b) {
            $bytes = RmmHarness::fakePe(RmmProtocol::ARCHS[$b['arch']], $b['size'], $b['fill']);
            $name = 'bin_' . bin2hex(random_bytes(16)) . '.bin';
            file_put_contents("$state/bin/$name", $bytes);
            $sha = hash('sha256', $bytes);
            $q("INSERT INTO endpoint_agent_binaries (version, arch, sha256, size_bytes, storage_name, uploaded_by, active, is_current, created_at) VALUES ('{$b['version']}', '{$b['arch']}', '$sha', " . strlen($bytes) . ", '$name', 1, 1, " . ($b['current'] ? 1 : 0) . ', UTC_TIMESTAMP())');
            $binaryId = $db->insert_id;
            if ($b['release']) {
                $url = $ed->module->updates()->updateUrl($b['arch'], $b['version']);
                $q("INSERT INTO endpoint_agent_releases (version, url, sha256, min_version, ring, rollout_pct, notes, created_by, created_at, arch, binary_id) VALUES ('{$b['version']}', '" . $esc((string) $url) . "', '$sha', '0.0.0', '{$b['release']}', 100, 'golden', 1, UTC_TIMESTAMP(), '{$b['arch']}', $binaryId)");
            }
            $bins[] = ['version' => $b['version'], 'arch' => $b['arch'], 'size' => strlen($bytes), 'sha256' => $sha];
        }
        $ed->save();
        $flushRedis();
        $out = ['admin_user_id' => 2, 'asset_ids' => $assetIds, 'binaries' => $bins, 'signing_key_id' => $kid];
        break;

    case 'hook':
        $h = (string) ($args['hook'] ?? '');
        switch ($h) {
            case 'enable':
                $q('UPDATE endpoint_agent_settings SET enabled=1 WHERE id=1');
                break;
            case 'disable':
                $q('UPDATE endpoint_agent_settings SET enabled=0 WHERE id=1');
                break;
            case 'clear_attempts':
                $q('DELETE FROM endpoint_agent_enroll_attempts');
                break;
            case 'flush_redis':
                $flushRedis();
                break;
            case 'revoke_device':
                $q("UPDATE endpoint_agent_devices SET revoked_at=UTC_TIMESTAMP(), revoked_reason='golden' WHERE device_id=" . (int) $args['device_id']);
                break;
            case 'expire_device_token':
                $when = !empty($args['restore']) ? gmdate('Y-m-d H:i:s', time() + 3600) : gmdate('Y-m-d H:i:s', time() - 5);
                $q("UPDATE endpoint_agent_devices SET token_expires_at='$when' WHERE device_id=" . (int) $args['device_id']);
                break;
            case 'set_setting':
                $allowed = ['failure_debounce', 'recovery_debounce', 'unmatched_policy', 'check_in_interval_s', 'job_ack_timeout_s', 'job_max_attempts'];
                $sets = [];
                foreach ((array) ($args['values'] ?? []) as $k => $v) {
                    if (!in_array($k, $allowed, true)) {
                        fwrite(STDERR, "setting $k not allowed\n");
                        exit(1);
                    }
                    $sets[] = "`$k`='" . $esc((string) $v) . "'";
                }
                if ($sets) {
                    $q('UPDATE endpoint_agent_settings SET ' . implode(',', $sets) . ' WHERE id=1');
                }
                break;
            default:
                fwrite(STDERR, "unknown hook $h\n");
                exit(2);
        }
        $out = ['ok' => true];
        break;

    case 'snapshot':
        // Row-level state of the endpoint_agent_* tables, projected to the columns RivetIT 2.6.146 had: the five module-switch columns of
        // migration 0016 are not part of the baseline and are left out of the settings row.
        $tables = [
            'endpoint_agent_devices' => 'device_id',
            'endpoint_agent_checks' => 'device_id, check_key',
            'endpoint_agent_checkins' => 'device_id, seq',
            'endpoint_agent_jobs' => 'type, script, state',
            'endpoint_agent_enrollment_tokens' => 'token_id',
            'endpoint_agent_releases' => 'release_id',
            'endpoint_agent_binaries' => 'binary_id',
        ];
        foreach ($tables as $t => $order) {
            $rows = [];
            $res = $q("SELECT * FROM `$t` ORDER BY $order");
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
            $out[$t] = $rows;
        }
        $res = $q('SELECT reason, success, COUNT(*) n FROM endpoint_agent_enroll_attempts GROUP BY reason, success ORDER BY reason, success');
        $out['endpoint_agent_enroll_attempts_by_reason'] = $res->fetch_all(MYSQLI_ASSOC);
        $settings = $q('SELECT * FROM endpoint_agent_settings WHERE id=1')->fetch_assoc();
        foreach (['features_json', 'limits_json', 'shed_level', 'ingest_mode', 'max_devices'] as $newColumn) {
            unset($settings[$newColumn]);
        }
        $out['endpoint_agent_settings'] = $settings;
        break;

    default:
        fwrite(STDERR, "unknown command $cmd\n");
        exit(2);
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
