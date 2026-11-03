<?php
/**
 * Schema parity test: schema.mysql.sql (MariaDB) vs schema.sql (Postgres).
 *
 * The two files are the fresh-install sources for local dev and for the
 * production Neon database, so they must describe the same app schema:
 * same tables, ballot_submissions present with its three columns, and the
 * two perf indexes (votes.position_id, elections.status) in both.
 *
 * Run: php tests/schema_parity.php   (CLI only)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$mysqlFile = __DIR__ . '/../database/schema.mysql.sql';
$pgFile    = __DIR__ . '/../database/schema.sql';

if (!is_readable($mysqlFile) || !is_readable($pgFile)) {
    fwrite(STDERR, "FAIL: cannot read schema files\n");
    exit(1);
}

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "PASS: $name\n";
    } else {
        $failures++;
        echo "FAIL: $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/** @return array<string,string> table name => block body up to first ';' */
function parseTables(string $sql): array
{
    $tables = [];
    if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?\s*\((.*?)^\)[^;]*;/ims', $sql, $m, PREG_SET_ORDER)) {
        foreach ($m as $one) {
            $tables[strtolower($one[1])] = $one[2];
        }
    }
    return $tables;
}

/** @return string[] index/key names declared anywhere in the file */
function parseIndexes(string $sql): array
{
    $names = [];
    if (preg_match_all('/(?:CREATE\s+(?:UNIQUE\s+)?INDEX\s+|(?:UNIQUE\s+)?KEY\s+|INDEX\s+)[`"]?(\w+)[`"]?/i', $sql, $m)) {
        foreach ($m[1] as $n) {
            $names[] = strtolower($n);
        }
    }
    return $names;
}

/** @return string[] column names inside a CREATE TABLE body */
function parseColumns(string $body): array
{
    $cols = [];
    foreach (preg_split('/\r?\n/', $body) as $line) {
        if (preg_match('/^\s*[`"]?([a-z_][a-z0-9_]*)[`"]?\s+(?:int|integer|bigint|smallserial|serial|bigserial|timestamp|date|varchar|char|text|boolean|bool|numeric|decimal|json)/i', $line, $m)) {
            $cols[] = strtolower($m[1]);
        }
    }
    return $cols;
}

$mysql = file_get_contents($mysqlFile);
$pg    = file_get_contents($pgFile);

$mysqlTables = parseTables($mysql);
$pgTables    = parseTables($pg);

// (a) identical table-name sets
$onlyMysql = array_diff(array_keys($mysqlTables), array_keys($pgTables));
$onlyPg    = array_diff(array_keys($pgTables), array_keys($mysqlTables));
check(
    'identical table sets',
    $onlyMysql === [] && $onlyPg === [],
    'mysql-only: ' . implode(',', $onlyMysql) . ' | pg-only: ' . implode(',', $onlyPg)
);

// (b) ballot_submissions present in both with the three columns
foreach (['mysql' => $mysqlTables, 'pg' => $pgTables] as $dialect => $set) {
    $has = isset($set['ballot_submissions']);
    check("ballot_submissions exists in $dialect schema", $has);
    if ($has) {
        $cols = parseColumns($set['ballot_submissions']);
        foreach (['user_id', 'election_id', 'submitted_at'] as $c) {
            check("ballot_submissions.$c in $dialect schema", in_array($c, $cols, true), 'found: ' . implode(',', $cols));
        }
    }
}

// (c)/(d) perf indexes present in both
foreach (['mysql' => $mysql, 'pg' => $pg] as $dialect => $sql) {
    $idx = parseIndexes($sql);
    check("idx_votes_position in $dialect schema", in_array('idx_votes_position', $idx, true));
    check("idx_elections_status in $dialect schema", in_array('idx_elections_status', $idx, true));
}

if ($failures > 0) {
    echo "\n$failures assertion(s) failed\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
