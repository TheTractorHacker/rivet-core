<?php

declare(strict_types=1);

/*
 * Public-API guard (issue #30). Reflects over every type in src/ and builds a snapshot of the declared public surface:
 *
 *   - types tagged @api: kind, parents, constants (with values), every public method signature;
 *   - types tagged @internal: only their names (so promoting or demoting a type is a visible change);
 *   - migration ids and, when a scratch database is configured, the columns and indexes of every Core table;
 *   - persisted vocabularies editions store or send: webhook event ids, destination ids, date-range preset ids.
 *
 *   php scripts/api-surface-check.php            compare with tests/api-surface.json; exit 1 on ANY difference
 *   php scripts/api-surface-check.php --update   rewrite the snapshot (review the diff, then say why in the changelog)
 *   php scripts/api-surface-check.php --print    print the current snapshot
 *
 * Differences are labelled BREAKING (something an edition may use was removed or changed) or ADDITIVE (something was
 * added). Both fail the check: an unreviewed surface change is exactly what this guard exists to stop; additive changes
 * are fine once the snapshot is updated in the same pull request, breaking ones need a major version (see ADR-004).
 *
 * The table section is built from RIVETCORE_TEST_DB_* when set (a scratch database; Core tables are created in it and
 * left in place) and is then compared; without a database the section is skipped, not failed.
 */

require __DIR__ . '/../vendor/autoload.php';

use RivetCore\Migration\CoreMigrations;

$snapshotFile = getenv('RIVETCORE_API_SNAPSHOT') ?: __DIR__ . '/../tests/api-surface.json';   // the override exists for the script's own tests
$mode = $argv[1] ?? '';

$srcRoot = (string) realpath(__DIR__ . '/../src');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $files[] = $f->getRealPath();
    }
}
sort($files);

// PHP before 8.4 reports `self` where later versions print the class name; normalise so the snapshot is the same on every PHP.
$ty = static fn (?ReflectionType $t, ReflectionClass $c): string => $t === null ? '' : (string) preg_replace('/\bself\b/', $c->getName(), (string) $t);
$sig = static function (ReflectionMethod $m) use ($ty): string {
    $cls = $m->getDeclaringClass();
    $params = array_map(static function (ReflectionParameter $p) use ($ty, $cls): string {
        $s = ($p->getType() !== null ? $ty($p->getType(), $cls) . ' ' : '') . ($p->isPassedByReference() ? '&' : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName();
        if ($p->isDefaultValueAvailable()) {
            $d = $p->getDefaultValue();
            $s .= ' = ' . (is_array($d) ? ($d === [] ? '[]' : 'array') : (is_object($d) ? get_class($d) : var_export($d, true)));
        }

        return $s;
    }, $m->getParameters());

    return ($m->isStatic() ? 'static ' : '') . ($m->isAbstract() && !$m->getDeclaringClass()->isInterface() ? 'abstract ' : '') . ($m->isFinal() ? 'final ' : '')
        . $m->getName() . '(' . implode(', ', $params) . ')' . ($m->getReturnType() !== null ? ': ' . $ty($m->getReturnType(), $cls) : '');
};
$constValue = static function (mixed $v): mixed {
    return is_scalar($v) || $v === null ? $v : (is_array($v) ? json_decode(json_encode($v, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'null', true) : (is_object($v) ? get_class($v) . (($v instanceof BackedEnum) ? ':' . $v->value : '') : null));
};

$snap = ['format' => 1, 'types' => [], 'internal' => [], 'untagged' => [], 'migrations' => [], 'tables' => [], 'vocabularies' => []];
foreach ($files as $file) {
    $rel = substr($file, strlen($srcRoot) + 1);
    $fqcn = 'RivetCore\\' . str_replace(['/', '.php'], ['\\', ''], $rel);
    if (!class_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn) && !enum_exists($fqcn)) {
        continue;
    }
    $r = new ReflectionClass($fqcn);
    $doc = (string) $r->getDocComment();
    if (str_contains($doc, '@internal')) {
        $snap['internal'][] = $fqcn;
        continue;
    }
    if (!str_contains($doc, '@api')) {
        $snap['untagged'][] = $fqcn;
        continue;
    }
    $kind = $r->isInterface() ? 'interface' : ($r->isEnum() ? 'enum' : ($r->isTrait() ? 'trait' : ($r->isAbstract() ? 'abstract class' : ($r->isFinal() ? 'final class' : 'class'))));
    $entry = ['kind' => $kind . ($r->isReadOnly() ? ' readonly' : '')];
    if ($r->getParentClass() !== false) {
        $entry['extends'] = $r->getParentClass()->getName();
    }
    $impl = array_map(static fn (string $i): string => $i, array_values($r->getInterfaceNames()));
    sort($impl);
    if ($impl !== []) {
        $entry['implements'] = $impl;
    }
    $consts = [];
    foreach ($r->getReflectionConstants() as $c) {
        if ($c->isPublic() && $c->getDeclaringClass()->getName() === $fqcn) {
            $consts[$c->getName()] = $constValue($c->getValue());
        }
    }
    ksort($consts);
    if ($consts !== []) {
        $entry['constants'] = $consts;
    }
    $methods = [];
    foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->getDeclaringClass()->getName() !== $fqcn || (str_starts_with($m->getName(), '__') && $m->getName() !== '__construct')) {
            continue;
        }
        $methods[$m->getName()] = $sig($m);
    }
    ksort($methods);
    $entry['methods'] = $methods;
    $props = [];
    foreach ($r->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
        if ($p->getDeclaringClass()->getName() === $fqcn) {
            $props[$p->getName()] = ($p->isReadOnly() ? 'readonly ' : '') . ($p->getType() ?? 'mixed');
        }
    }
    ksort($props);
    if ($props !== []) {
        $entry['properties'] = $props;
    }
    $snap['types'][$fqcn] = $entry;
}
sort($snap['internal']);
sort($snap['untagged']);
ksort($snap['types']);
$snap['migrations'] = array_map(static fn ($m): string => $m->id(), CoreMigrations::all());

$snap['vocabularies'] = [
    'webhook_events' => array_map(static fn ($e): string => $e->id, RivetCore\Webhooks\EventCatalog::all()),
    'webhook_destinations' => array_map(static fn ($d): string => $d->id, RivetCore\Webhooks\Destinations::all()),
    'webhook_formats' => RivetCore\Webhooks\PayloadFormatter::formats(),
    'date_range_presets' => array_map(static fn ($p): string => (string) ($p['id'] ?? $p), RivetCore\Ui\DateRange::presets()),
    'compliance_frameworks' => RivetCore\Compliance\Framework::all(),
    'retention_profiles' => array_keys(RivetCore\Compliance\RetentionPolicy::PROFILES),
];
foreach ($snap['vocabularies'] as $k => $v) {
    $snap['vocabularies'][$k] = array_values(array_map('strval', $v));
    sort($snap['vocabularies'][$k]);
}

// Tables: from a scratch database when one is configured.
$dbName = (string) getenv('RIVETCORE_TEST_DB_NAME');
$haveDb = $dbName !== '' && preg_match('/scratch|test|bench/i', $dbName) === 1;
if ($haveDb) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $m = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: '127.0.0.1', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $dbName);
    require_once __DIR__ . '/../tests/Support/MysqliDatabase.php';
    (new RivetCore\Migration\MigrationRunner(new RivetCore\Tests\Support\MysqliDatabase($m), CoreMigrations::all(), new RivetCore\Support\SystemClock()))->run();
    $ownTables = [];
    $q = $m->prepare("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION");
    $q->bind_param('s', $dbName);
    $q->execute();
    $cols = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    // Only tables Core owns: those created by Core migrations are exactly the ones present after running them on an empty
    // database, so keep the known list (a scratch database may hold other tables from other tests).
    $core = ['audit_events', 'integration_jobs', 'mcp_unlinked_identities', 'problems', 'changes', 'webhook_deliveries', 'automation_rules', 'workflow_templates', 'workflow_template_tasks', 'workflow_runs', 'workflow_run_tasks', 'compliance_attestations', 'compliance_snapshots', 'compliance_subjects', 'compliance_shared_report', 'compliance_responsibilities', 'rivet_core_migrations'];
    foreach ($cols as $c) {
        if (in_array($c['TABLE_NAME'], $core, true)) {
            $ownTables[$c['TABLE_NAME']][$c['COLUMN_NAME']] = preg_replace('/\s+/', ' ', strtolower($c['COLUMN_TYPE'])) . ($c['IS_NULLABLE'] === 'YES' ? ' null' : ' not null');
        }
    }
    ksort($ownTables);
    foreach ($ownTables as $t => $c) {
        ksort($c);
        $ownTables[$t] = $c;
    }
    $snap['tables'] = $ownTables;
}

if ($mode === '--print') {
    echo json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}
if ($snap['untagged'] !== []) {
    fwrite(STDERR, "Untagged types (every type must say @api or @internal):\n  " . implode("\n  ", $snap['untagged']) . "\n");
    exit(1);
}
$old = is_file($snapshotFile) ? json_decode((string) file_get_contents($snapshotFile), true, 512, JSON_THROW_ON_ERROR) : null;
if ($mode === '--update') {
    if (!$haveDb && $old !== null) {
        $snap['tables'] = $old['tables'] ?? [];   // keep the committed table section when no database is available
        fwrite(STDERR, "No scratch database configured: kept the existing table section.\n");
    }
    file_put_contents($snapshotFile, json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "Wrote tests/api-surface.json\n";
    exit(0);
}
if ($old === null) {
    fwrite(STDERR, "tests/api-surface.json is missing; run with --update.\n");
    exit(1);
}

/** @return list<string> */
$diff = static function (array $a, array $b, string $path) use (&$diff): array {
    $out = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
        $p = $path === '' ? (string) $k : $path . '.' . $k;
        if (!array_key_exists($k, $b)) {
            $out[] = "BREAKING  removed   $p";
        } elseif (!array_key_exists($k, $a)) {
            $out[] = "ADDITIVE  added     $p";
        } elseif (is_array($a[$k]) && is_array($b[$k]) && !array_is_list($a[$k]) && !array_is_list($b[$k])) {
            array_push($out, ...$diff($a[$k], $b[$k], $p));
        } elseif ($a[$k] !== $b[$k]) {
            if (is_array($a[$k]) && is_array($b[$k])) {
                foreach (array_diff($a[$k], $b[$k]) as $x) {
                    $out[] = 'BREAKING  removed   ' . $p . ' entry ' . json_encode($x);
                }
                foreach (array_diff($b[$k], $a[$k]) as $x) {
                    $out[] = 'ADDITIVE  added     ' . $p . ' entry ' . json_encode($x);
                }
            } else {
                $out[] = "BREAKING  changed   $p: " . json_encode($a[$k]) . ' -> ' . json_encode($b[$k]);
            }
        }
    }

    return $out;
};
$sections = ['types', 'internal', 'migrations', 'vocabularies'] + ($haveDb ? [4 => 'tables'] : []);
$changes = [];
foreach ($sections as $s) {
    $changes = array_merge($changes, $diff([$s => $old[$s] ?? []], [$s => $snap[$s]], ''));
}
if ($changes === []) {
    echo 'Public API surface unchanged (' . count($snap['types']) . ' @api types, ' . count($snap['internal']) . ' internal, ' . count($snap['migrations']) . ' migrations' . ($haveDb ? ', ' . count($snap['tables']) . ' tables' : ', tables skipped: no database') . ").\n";
    exit(0);
}
fwrite(STDERR, "The public API surface differs from tests/api-surface.json:\n  " . implode("\n  ", $changes) . "\n\nIf this is intended, run: php scripts/api-surface-check.php --update, review the diff, and record it in CHANGELOG.md.\nBREAKING lines need a major version (docs/architecture/ADR-004-versioning-policy.md).\n");
exit(1);
