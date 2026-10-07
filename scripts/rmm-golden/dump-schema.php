<?php
declare(strict_types=1);

/**
 * Dump the 10 endpoint_agent_* tables of a (scratch) database as deterministic JSON plus one SHOW CREATE TABLE file per table.
 *
 *   RMM_DB_HOST=localhost RMM_DB_USER=scratch_x RMM_DB_PASS=... php dump-schema.php <database> <out.json> [<ddl-dir>]
 *
 * Refuses a database whose name does not contain "scratch". Output is sorted and contains no row data, no AUTO_INCREMENT counters and
 * no ordinal positions' gaps: two dumps of equal schemas are byte-identical.
 */

if ($argc < 3) {
    fwrite(STDERR, "usage: dump-schema.php <database> <out.json> [<ddl-dir>]\n");
    exit(2);
}
[, $dbName, $outFile] = $argv;
$ddlDir = $argv[3] ?? null;
if (stripos($dbName, 'scratch') === false) {
    fwrite(STDERR, "Refusing: database name must contain 'scratch'\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli(getenv('RMM_DB_HOST') ?: 'localhost', (string) getenv('RMM_DB_USER'), (string) getenv('RMM_DB_PASS'), $dbName);
if ($db->connect_errno) {
    fwrite(STDERR, "connect failed\n");
    exit(2);
}
$db->set_charset('utf8mb4');

$tables = ['endpoint_agent_settings', 'endpoint_agent_enrollment_tokens', 'endpoint_agent_enroll_attempts', 'endpoint_agent_devices', 'endpoint_agent_checkins',
    'endpoint_agent_checks', 'endpoint_agent_jobs', 'endpoint_agent_mesh_nodes', 'endpoint_agent_releases', 'endpoint_agent_binaries'];
sort($tables);

$all = function (string $sql, array $params) use ($db): array {
    $st = $db->prepare($sql);
    $st->bind_param(str_repeat('s', count($params)), ...$params);
    $st->execute();
    $res = $st->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
};

$out = ['format' => 1, 'tables' => []];  // server version is deliberately not part of the file (recorded in endpoint-baseline.md)
foreach ($tables as $t) {
    $tab = $all('SELECT ENGINE, TABLE_COLLATION, ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
    if (count($tab) !== 1) {
        $out['absent'][] = $t;   // e.g. endpoint_agent_binaries does not exist yet at DB 2.6.145
        continue;
    }
    $cols = $all('SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_KEY, GENERATION_EXPRESSION
                  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$t]);
    $columns = [];
    foreach ($cols as $i => $c) {
        $columns[] = [
            'name' => $c['COLUMN_NAME'],
            'position' => $i + 1,
            'type' => $c['COLUMN_TYPE'],
            'nullable' => $c['IS_NULLABLE'] === 'YES',
            'default' => $c['COLUMN_DEFAULT'],
            'extra' => $c['EXTRA'],
            'charset' => $c['CHARACTER_SET_NAME'],
            'collation' => $c['COLLATION_NAME'],
            'key' => $c['COLUMN_KEY'],
        ];
    }
    $idx = $all('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, COLLATION, INDEX_TYPE
                 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$t]);
    $indexes = [];
    foreach ($idx as $r) {
        $n = $r['INDEX_NAME'];
        $indexes[$n] ??= ['name' => $n, 'unique' => (int) $r['NON_UNIQUE'] === 0, 'type' => $r['INDEX_TYPE'], 'columns' => []];
        $indexes[$n]['columns'][] = ['seq' => (int) $r['SEQ_IN_INDEX'], 'column' => $r['COLUMN_NAME'], 'sub_part' => $r['SUB_PART'] === null ? null : (int) $r['SUB_PART'], 'order' => $r['COLLATION']];
    }
    ksort($indexes);
    $cons = $all('SELECT tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS tc WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? ORDER BY tc.CONSTRAINT_NAME', [$t]);
    $kcu = $all('SELECT CONSTRAINT_NAME, ORDINAL_POSITION, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION', [$t]);
    $constraints = [];
    foreach ($cons as $c) {
        $constraints[] = ['name' => $c['CONSTRAINT_NAME'], 'type' => $c['CONSTRAINT_TYPE'],
            'columns' => array_values(array_map(fn($k) => $k['COLUMN_NAME'] . ($k['REFERENCED_TABLE_NAME'] ? '->' . $k['REFERENCED_TABLE_NAME'] . '.' . $k['REFERENCED_COLUMN_NAME'] : ''),
                array_filter($kcu, fn($k) => $k['CONSTRAINT_NAME'] === $c['CONSTRAINT_NAME'])))];
    }
    $show = $db->query('SHOW CREATE TABLE `' . $t . '`')->fetch_row()[1];
    $ddl = preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) $show) . ";\n";
    $out['tables'][$t] = [
        'engine' => $tab[0]['ENGINE'],
        'collation' => $tab[0]['TABLE_COLLATION'],
        'columns' => $columns,
        'indexes' => array_values($indexes),
        'constraints' => $constraints,
    ];
    if ($ddlDir !== null) {
        if (!is_dir($ddlDir)) {
            mkdir($ddlDir, 0775, true);
        }
        file_put_contents("$ddlDir/$t.sql", $ddl);
    }
}
file_put_contents($outFile, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
echo 'wrote ' . $outFile . ' (' . count($out['tables']) . " tables, " . count($out['absent'] ?? []) . " absent)\n";
