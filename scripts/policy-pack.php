<?php

declare(strict_types=1);

/**
 * Seeds or manages the policy starter pack from the command line, for an edition's deploy script. The edition supplies a bootstrap file that
 * returns its configured RivetCore\Rmm\RmmModule:
 *
 *   php scripts/policy-pack.php --bootstrap=/path/to/module.php list
 *   php scripts/policy-pack.php --bootstrap=... install [--ids=a,b] [--confirm-instance-wide] [--user=ID] [--dry-run]
 *   php scripts/policy-pack.php --bootstrap=... update|reset|uninstall [--ids=a,b] [--include-live] [--user=ID] [--dry-run]
 *
 * Prints JSON. Exit 0 on success, 1 when an item failed, 2 on a usage error. Nothing is enabled or assigned by an install, same as the REST route. The
 * run is written to the edition's audit log as the given user (default 0 = "cli"), by the same code as the REST route.
 */

require __DIR__ . '/../vendor/autoload.php';

use RivetCore\Rmm\Authz\RmmPrincipal;

$opts = [];
$pos = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, '1');
        $opts[$k] = $v;
    } else {
        $pos[] = $a;
    }
}
$op = $pos[0] ?? '';
if (!isset($opts['bootstrap']) || !is_file($opts['bootstrap']) || !in_array($op, ['list', 'install', 'update', 'reset', 'uninstall'], true)) {
    fwrite(STDERR, "usage: php scripts/policy-pack.php --bootstrap=FILE list|install|update|reset|uninstall [--ids=a,b] [--confirm-instance-wide] [--include-live] [--user=ID] [--dry-run]\n");
    exit(2);
}
$module = require $opts['bootstrap'];
if (!$module instanceof RivetCore\Rmm\RmmModule) {
    fwrite(STDERR, "the bootstrap file must return a RivetCore\\Rmm\\RmmModule\n");
    exit(2);
}
if ($op === 'list') {
    echo json_encode($module->policyPackInstaller()->catalog(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}
$sel = [];
if (isset($opts['ids'])) {
    $sel['ids'] = array_values(array_filter(explode(',', $opts['ids']), static fn (string $x): bool => $x !== ''));
}
$sel['confirm_instance_wide'] = isset($opts['confirm-instance-wide']);
$sel['include_live'] = isset($opts['include-live']);
$user = isset($opts['user']) ? (int) $opts['user'] : 0;
// The CLI is the operator's own shell: no ability check (the REST abilities protect the network surface), the audit rows say "CLI".
$res = $module->policyPackActions()->runFromCli($op, $sel, $user, isset($opts['dry-run']));
if (!$res->ok) {
    fwrite(STDERR, $res->message . "\n");
    exit(2);
}
$r = $res->data;
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
exit(isset($r['counts']['failed']) ? 1 : 0);
