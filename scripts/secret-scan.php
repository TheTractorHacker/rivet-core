<?php

declare(strict_types=1);

/**
 * Secret-shape scanner for this repository (no external download, no network).
 *
 *   php scripts/secret-scan.php                 scan every tracked file in the working tree
 *   php scripts/secret-scan.php --history       also scan every line ever added on any ref (git log --all -p)
 *   php scripts/secret-scan.php --history --rev=HEAD   limit the history scan to the commits reachable from a revision (CI: other branches are not this change's business)
 *   php scripts/secret-scan.php --path=src      limit the working-tree scan to a path prefix (repeatable)
 *   php scripts/secret-scan.php --no-allowlist  show the allowlisted fixtures too (audit mode)
 *
 * Exit status 1 when a real-looking secret remains outside secret-scan-allowlist.json. The report NEVER prints a matched value: a hit is
 * path:line, rule, length and the first 4 hex characters of the SHA-256 of the value (enough to tell two hits apart, useless to recover it).
 *
 * The allowlist is path-scoped on purpose: every intentional fixture is listed with the reason it is not a credential, and a new fixture
 * cannot hide by carrying a magic comment. .gitleaks.toml and .semgrepignore mirror the same paths for the external scanners;
 * tests/Unit/SecretScanTest.php fails when the three drift apart.
 */

/** @return list<array{id:string,re:string,min_entropy:float,group:int}> */
function secret_scan_rules(): array
{
    return [
        ['id' => 'private-key-block', 're' => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |ENCRYPTED |PGP )?PRIVATE KEY(?: BLOCK)?-----(?:\\\\n|\\s)*([A-Za-z0-9+\/=]{64,})/', 'min_entropy' => 0.0, 'group' => 1],
        ['id' => 'aws-access-key-id', 're' => '/\b((?:AKIA|ASIA)[0-9A-Z]{16})\b/', 'min_entropy' => 0.0, 'group' => 1],
        ['id' => 'github-token', 're' => '/\b(gh[pousr]_[A-Za-z0-9]{36,255})\b/', 'min_entropy' => 0.0, 'group' => 1],
        ['id' => 'github-fine-grained-pat', 're' => '/\b(github_pat_[A-Za-z0-9_]{50,})\b/', 'min_entropy' => 0.0, 'group' => 1],
        ['id' => 'slack-token', 're' => '/\b(xox[abprs]-[A-Za-z0-9-]{10,})/', 'min_entropy' => 0.0, 'group' => 1],
        ['id' => 'slack-webhook', 're' => '#(hooks\.slack\.com/services/T[A-Z0-9]{6,}/B[A-Z0-9]{6,}/[A-Za-z0-9]{20,})#', 'min_entropy' => 0.0, 'group' => 1],
        ['id' => 'stripe-key', 're' => '/\b([sr]k_(?:live|test)_[A-Za-z0-9]{16,})\b/', 'min_entropy' => 0.0, 'group' => 1],
        ['id' => 'google-api-key', 're' => '/\b(AIza[0-9A-Za-z_-]{35})\b/', 'min_entropy' => 0.0, 'group' => 1],
        ['id' => 'jwt', 're' => '/\b(eyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{10,})\b/', 'min_entropy' => 0.0, 'group' => 1],
        ['id' => 'url-with-password', 're' => '#\b[a-z][a-z0-9+.-]{1,15}://[^\s:/@\'"<>${}]{1,64}:([^\s@\'"/<>${}]{4,128})@[A-Za-z0-9._\[\]-]+#i', 'min_entropy' => 2.5, 'group' => 1],
        ['id' => 'secret-assignment', 're' => '/(?:key|secret|token|passw(?:or)?d|credential|private)[A-Za-z0-9_]*[\'"]?\s*(?:=>|[:=])\s*[\'"]([A-Za-z0-9+\/=_-]{24,})[\'"]/i', 'min_entropy' => 3.5, 'group' => 1],
        ['id' => 'base64-key-blob', 're' => '/(?:key|secret|seed)[^\n]{0,40}?[\'"`:= ]([A-Za-z0-9+\/]{43}=|[A-Za-z0-9+\/]{86}==)(?![A-Za-z0-9+\/=])/i', 'min_entropy' => 4.0, 'group' => 1],
        ['id' => 'hex-key-blob', 're' => '/(?:key|secret|seed)[^\n]{0,40}?[\'"`:= ]([0-9a-f]{64})(?![0-9a-zA-Z])/i', 'min_entropy' => 3.0, 'group' => 1],
    ];
}

function secret_scan_entropy(string $s): float
{
    $n = strlen($s);
    if ($n === 0) {
        return 0.0;
    }
    $h = 0.0;
    foreach (count_chars($s, 1) as $c) {
        $p = $c / $n;
        $h -= $p * log($p, 2);
    }
    return $h;
}

/** Values that are placeholders by construction (documented examples, template variables, repeated characters). */
function secret_scan_is_placeholder(string $v): bool
{
    if (preg_match('/EXAMPLE|PLACEHOLDER|CHANGEME|changeme|xxxxx|your[-_ ]|<[^>]*>|\$\{|\{\{|%s|\*{4,}/i', $v) === 1) {
        return true;
    }
    if (preg_match('/^[a-z]+(?:[_-][a-z]+)+$/', $v) === 1) {
        return true;   // a snake_case or kebab-case identifier of lower-case words (a registry key, a slug), never a generated secret
    }

    return count(array_unique(str_split($v))) <= 3;   // aaaa…, 0000…, abab…
}

/**
 * @return list<array{rule:string,col:int,len:int,fp:string}>
 */
function secret_scan_line(string $line): array
{
    $hits = [];
    if (strlen($line) > 20000) {
        $line = substr($line, 0, 20000);   // minified bundles: bound the work, the rules look at short windows
    }
    foreach (secret_scan_rules() as $rule) {
        if (preg_match_all($rule['re'], $line, $m, PREG_OFFSET_CAPTURE) === false) {
            continue;
        }
        foreach ($m[$rule['group']] as [$val, $off]) {
            if ($val === '' || secret_scan_is_placeholder($val)) {
                continue;
            }
            if ($rule['min_entropy'] > 0 && secret_scan_entropy($val) < $rule['min_entropy']) {
                continue;
            }
            // A public key is not a secret: the generic "key" rules ignore a value introduced by a name that says so.
            if (in_array($rule['id'], ['secret-assignment', 'base64-key-blob', 'hex-key-blob'], true)
                && preg_match('/public[_ -]?key|pub[_ -]?key/i', substr($line, max(0, (int) $off - 48), min(48, (int) $off))) === 1) {
                continue;
            }
            $hits[] = ['rule' => $rule['id'], 'col' => (int) $off, 'len' => strlen($val), 'fp' => substr(hash('sha256', $val), 0, 4)];
        }
    }
    return $hits;
}

/** @return array{rules:list<array{path:string,reason:string,rules?:list<string>}>} */
function secret_scan_load_allowlist(string $file): array
{
    $j = json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
    foreach ($j['rules'] as $r) {
        if (!isset($r['path'], $r['reason']) || strlen(trim((string) $r['reason'])) < 20) {
            throw new RuntimeException('every allowlist entry needs a path and a reason of at least 20 characters');
        }
    }
    return $j;
}

function secret_scan_glob_to_regex(string $glob): string
{
    $re = '';
    for ($i = 0, $n = strlen($glob); $i < $n; $i++) {
        $c = $glob[$i];
        if ($c === '*' && ($glob[$i + 1] ?? '') === '*') {
            $re .= '.*';
            $i++;
        } elseif ($c === '*') {
            $re .= '[^\/]*';
        } elseif ($c === '?') {
            $re .= '[^\/]';
        } else {
            $re .= preg_quote($c, '#');
        }
    }
    return '#^' . $re . '$#';
}

/** @param array{rules:list<array{path:string,reason:string,rules?:list<string>}>} $allow */
function secret_scan_allowed(array $allow, string $path, string $rule): bool
{
    foreach ($allow['rules'] as $r) {
        if (preg_match(secret_scan_glob_to_regex($r['path']), $path) === 1 && (!isset($r['rules']) || in_array($rule, $r['rules'], true))) {
            return true;
        }
    }
    return false;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $root = dirname(__DIR__);
    chdir($root);
    $opts = getopt('', ['history', 'no-allowlist', 'path::', 'rev::']);
    $allow = isset($opts['no-allowlist']) ? ['rules' => []] : secret_scan_load_allowlist($root . '/secret-scan-allowlist.json');
    $prefixes = isset($opts['path']) ? (array) $opts['path'] : [];
    $bad = 0;
    $suppressed = 0;
    $report = static function (string $where, array $h, bool $isAllowed) use (&$bad, &$suppressed): void {
        if ($isAllowed) {
            $suppressed++;
            return;
        }
        $bad++;
        fwrite(STDOUT, sprintf("%s [%s] len=%d fp=%s\n", $where, $h['rule'], $h['len'], $h['fp']));
    };

    $files = array_filter(explode("\0", (string) shell_exec('git ls-files -z')));
    foreach ($files as $f) {
        if ($prefixes !== [] && !array_filter($prefixes, static fn(string $p): bool => str_starts_with($f, $p))) {
            continue;
        }
        if (!is_file($f) || is_link($f)) {
            continue;
        }
        $fh = fopen($f, 'rb');
        if ($fh === false) {
            continue;
        }
        $first = (string) fread($fh, 4096);
        if (str_contains($first, "\0")) {   // binary
            fclose($fh);
            continue;
        }
        rewind($fh);
        for ($ln = 1; ($line = fgets($fh)) !== false; $ln++) {
            foreach (secret_scan_line($line) as $h) {
                $report("$f:$ln", $h, secret_scan_allowed($allow, $f, $h['rule']));
            }
        }
        fclose($fh);
    }

    if (isset($opts['history'])) {
        $scope = isset($opts['rev']) ? escapeshellarg((string) $opts['rev']) : '--all';
        $p = popen('git log ' . $scope . ' --no-color --no-ext-diff --unified=0 --format="@@commit %h" -p', 'r');
        $commit = '';
        $file = '';
        $ln = 0;
        while ($p !== false && ($line = fgets($p)) !== false) {
            if (str_starts_with($line, '@@commit ')) {
                $commit = trim(substr($line, 9));
            } elseif (str_starts_with($line, '+++ b/')) {
                $file = rtrim(substr($line, 6));
            } elseif (str_starts_with($line, '+++ ')) {
                $file = '';
            } elseif (preg_match('/^@@ -\S+ \+(\d+)/', $line, $mm)) {
                $ln = (int) $mm[1];
            } elseif ($line !== '' && $line[0] === '+' && $file !== '') {
                foreach (secret_scan_line(substr($line, 1)) as $h) {
                    $report("history:$commit:$file:$ln", $h, secret_scan_allowed($allow, $file, $h['rule']));
                }
                $ln++;
            }
        }
        if ($p !== false) {
            pclose($p);
        }
    }

    fwrite(STDERR, sprintf("secret-scan: %d finding(s) outside the allowlist, %d allowlisted fixture hit(s)\n", $bad, $suppressed));
    exit($bad > 0 ? 1 : 0);
}
