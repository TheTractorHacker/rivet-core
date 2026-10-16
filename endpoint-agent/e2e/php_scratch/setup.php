<?php

declare(strict_types=1);

/**
 * Prepares the scratch database for the real-agent proof: Core's migrations, the module switched on with the Phase 6 and 8 features, one client, the
 * folders and log sources the proof uses, a log rule, and one enrollment token. Prints one JSON line {token, admin_api_token}.
 *
 *   RIVETCORE_TEST_DB_* (scratch)   php setup.php <state dir> <service url>
 */

use RivetCore\Tests\Support\GoldenEdition;
use RivetCore\Tests\Support\ScratchDb;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$K = require $root . '/scripts/rmm-golden/constants.php';
[$state, $url, $meshUrl] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
if ($state === '' || !str_starts_with($url, 'https://')) {
    fwrite(STDERR, "usage: setup.php <state dir> <https service url> [<https mesh url>]\n");
    exit(2);
}
if (!str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
    fwrite(STDERR, "Refusing: RIVETCORE_TEST_DB_NAME must name a scratch database\n");
    exit(2);
}
$m = ScratchDb::connect();
if ($m === null) {
    exit(2);
}
GoldenEdition::migrate($m);
// the patch-management proof (run_patch_proof.sh): the Phase 4 tables and the `patching` switch; the golden replay (older agents) leaves both off
$patching = getenv('RMM_PROOF_PATCHING') === '1';
if ($patching) {
    // a housekeeping pass (run_patch_proof.sh calls it) also touches the job queue of Core and the later RMM tables
    $db = new \RivetCore\Tests\Support\MysqliDatabase($m);
    foreach ([new \RivetCore\Jobs\Migration\Migration0002IntegrationJobs(), new \RivetCore\Jobs\Migration\Migration0012JobHeartbeat(), new \RivetCore\Rmm\Migration\Migration0028Patching()] as $mig) {
        $mig->up($db);
    }
}
$e = new GoldenEdition($state, false);
$client = $e->tenancy->addClient('Proof Dept');
$s = $e->module->settings();
$s->enable();
$errors = $s->update(['service_url' => $url, 'unmatched_policy' => 'auto_create',
    'features_json' => ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true, 'alerting' => true, 'remote_tools' => true, 'filetransfer' => true, 'logs' => true] + ($patching ? ['patching' => true] : [])]);
if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
// short intervals so the proof does not wait minutes (the server's own settings floor is for production; this is a scratch fixture)
$s->set(['check_in_interval_s' => 5, 'collect_interval_s' => 5, 'job_ack_timeout_s' => 60]);
if ($meshUrl !== '') {
    // the pushed Mesh agent install (rc.16): MeshCentral on, pointing at the mock Mesh server; the device group, pin and tables are set through the REST API by the proof itself
    $s->set(['mesh_enabled' => 1, 'mesh_url' => $meshUrl]);
}
$errors = $e->module->remoteSettings()->update([
    'transfer_allow' => ['linux' => ['/srv/proof'], 'windows' => ['C:\\Proof']],
    'logs_collect' => ['linux' => [['units' => ['rivetproof-noisy.service'], 'min_level' => 'warning', 'contains' => ['failed password', 'segfault']]], 'windows' => []],
    'limits' => ['logs_interval_s' => 30, 'logs_rate_per_min' => 120],
], 1);
if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
[$rid, $rerr] = $e->module->logRules()->create(['name' => 'Failed password', 'min_level' => 'warning', 'contains' => 'failed password', 'severity' => 'fail', 'resolve_after_min' => 30], 1);
if ($rid === null) {
    fwrite(STDERR, implode("\n", $rerr) . "\n");
    exit(1);
}
$tok = $e->module->enrollment()->createToken($client, 0, 'stable', 24, 5, 'real agent proof', 1);
$e->save();
echo json_encode(['token' => $tok['token'], 'admin_api_token' => $K['admin_api_token'], 'client_id' => $client, 'rule_id' => $rid]), "\n";
