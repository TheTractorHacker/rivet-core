<?php

declare(strict_types=1);

/*
 * Prints a normalised JSON fingerprint of a database's schema (tables, columns, indexes, engine, collation; no data).
 *   php scripts/schema-fingerprint.php <scratch-database>      (credentials: RIVETCORE_TEST_DB_HOST/USER/PASS)
 * Column order is reported separately ("order") because an upgraded table gets new columns at the end while a fresh
 * install may place them elsewhere; that is cosmetic and not part of the equality check.
 */

$database = $argv[1] ?? '';
if (!preg_match('/scratch/i', $database)) {
    fwrite(STDERR, "usage: schema-fingerprint.php <scratch-database>\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$m = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: '127.0.0.1', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $database);
$q = static function (string $sql) use ($m, $database): array {
    $st = $m->prepare($sql);
    $st->bind_param('s', $database);
    $st->execute();

    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
};
$out = ['tables' => [], 'columns' => [], 'indexes' => [], 'order' => []];
foreach ($q('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = "BASE TABLE" ORDER BY TABLE_NAME') as $r) {
    $out['tables'][$r['TABLE_NAME']] = $r['ENGINE'] . '/' . $r['TABLE_COLLATION'];
}
foreach ($q('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME, ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION') as $r) {
    // MariaDB and MySQL spell defaults differently; keep the raw text, it is compared like for like on the same server.
    $out['columns'][$r['TABLE_NAME'] . '.' . $r['COLUMN_NAME']] = implode('|', [$r['COLUMN_TYPE'], $r['IS_NULLABLE'], $r['COLUMN_DEFAULT'] ?? 'NULL', $r['EXTRA'], $r['COLLATION_NAME'] ?? '-']);
    $out['order'][$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
}
$idx = [];
foreach ($q('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX') as $r) {
    $idx[$r['TABLE_NAME'] . '.' . $r['INDEX_NAME']]['u'] = $r['NON_UNIQUE'] ? 'index' : 'unique';
    $idx[$r['TABLE_NAME'] . '.' . $r['INDEX_NAME']]['c'][] = $r['COLUMN_NAME'] . ($r['SUB_PART'] ? '(' . $r['SUB_PART'] . ')' : '');
}
foreach ($idx as $k => $v) {
    $out['indexes'][$k] = $v['u'] . ':' . implode(',', $v['c']);
}
ksort($out['columns']);
ksort($out['indexes']);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
