<?php

declare(strict_types=1);

/*
 * Mints a MeshCentral login cookie of the kind the Rivet Console redeem hands out (the random `once` claim included, no `ip` and no `x` claim), for testing a Rivet Console or a
 * MeshCentral by hand. The cookie is valid about 58 minutes (MeshCentral's own window; its `time` claim is back-dated 120 seconds like Core's).
 *
 *   MESH_LOGIN_KEY=<hex key> php scripts/mint-test-cookie.php [domain] [account]
 *
 *   domain   the MeshCentral domain id ('' or '-' for the default domain)
 *   account  the MeshCentral account name, letters, digits, '.', '_' and '-' only (default rivetit-support)
 *
 * The login key is read from the environment variable MESH_LOGIN_KEY ONLY. It is never accepted on the command line (a command line is visible to every user of the machine
 * and lands in shell history) and the script refuses an argument that looks like a key. The cookie goes to standard output, nothing else does; the key is never printed.
 * Use a MeshCentral you own: this signs in as the named account.
 */

use RivetCore\Rmm\Mesh\MeshCookie;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$args = array_slice($argv, 1);
foreach ($args as $a) {
    if (str_starts_with($a, '--') || (ctype_xdigit($a) && strlen($a) >= 64)) {
        fwrite(STDERR, "The login key is read from the environment variable MESH_LOGIN_KEY only; it is never accepted as an argument.\n");
        exit(2);
    }
}
if (count($args) > 2) {
    fwrite(STDERR, "Usage: MESH_LOGIN_KEY=<hex> php scripts/mint-test-cookie.php [domain] [account]\n");
    exit(2);
}
$key = getenv('MESH_LOGIN_KEY');
if (!is_string($key) || $key === '') {
    fwrite(STDERR, "Set MESH_LOGIN_KEY in the environment (the MeshCentral login key, hex).\n");
    exit(2);
}
$domain = $args[0] ?? '';
$domain = $domain === '-' ? '' : $domain;
$account = $args[1] ?? 'rivetit-support';
if (preg_match('/^[A-Za-z0-9_-]{0,32}$/D', $domain) !== 1 || preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $account) !== 1) {
    fwrite(STDERR, "The domain may hold letters, digits, '_' and '-' (32 at most); the account letters, digits, '.', '_' and '-'.\n");
    exit(2);
}
$cookie = MeshCookie::login(trim($key), $domain, $account, time(), null, true);
if ($cookie === null) {
    fwrite(STDERR, "The login key is not usable (it must be hex, at least 32 bytes).\n");
    exit(1);
}
echo $cookie, "\n";
