<?php
/**
 * health.php — unauthenticated uptime probe (Batch D2).
 *
 * Answers a tiny JSON status for the scheduled uptime workflow
 * (.github/workflows/uptime.yml) and for manual checks:
 *
 *   200 {"ok":true,"db":"up","time":"..."}    the database answered
 *   503 {"ok":false,"db":"down","time":"..."} it did not
 *
 * The workflow files a GitHub issue (updating one open incident issue
 * instead of spamming duplicates) whenever this stops returning 200.
 *
 * Security notes — this endpoint is PUBLIC:
 *   - No session, no cookies, no app data, and no error detail. The
 *     response only ever says up/down; the driver message goes to the
 *     server log via error_log().
 *   - getDbConnection() cannot be used here: on connection failure it
 *     calls die() (config/db.php), which is uncatchable and would answer
 *     a non-JSON 500. This probe builds its own PDO with the same driver
 *     resolution (dbDriver()/isMysql() from config/db.php) so the failure
 *     path stays a proper 503 JSON response.
 */

require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex');

/** Connect using the same env-var contract as config/db.php, but throwable. */
function healthCheckPdo(): PDO
{
    if (!isMysql()) {
        $databaseUrl = getenv('DATABASE_URL');
        $parts = $databaseUrl ? parse_url($databaseUrl) : false;
        if ($parts === false || !isset($parts['host'], $parts['user'], $parts['pass'], $parts['path'])) {
            throw new RuntimeException('DATABASE_URL is set but could not be parsed.');
        }
        $host = $parts['host'];
        $port = $parts['port'] ?? 5432;
        $dbName = ltrim($parts['path'], '/');
        $user = urldecode($parts['user']);
        $pass = urldecode($parts['pass']);
        $sslMode = 'require';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            if (!empty($query['sslmode'])) {
                $sslMode = $query['sslmode'];
            }
        }
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbName};sslmode={$sslMode}";
        // Same Neon endpoint-ID fallback as config/db.php (old libpq builds
        // without SNI support); harmless on modern libpq.
        if (preg_match('/^([a-z0-9-]+)\.(.+\.neon\.tech)$/i', $host, $matches)) {
            $dsn .= ";options=endpoint={$matches[1]}";
        }
    } else {
        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '3306';
        $dbName = getenv('DB_NAME') ?: 'voting_app';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
    }

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
}

$payload = ['ok' => false, 'db' => 'down', 'time' => gmdate('c')];

try {
    $pdo = healthCheckPdo();
    $pdo->query('SELECT 1');
    $payload = ['ok' => true, 'db' => 'up', 'time' => gmdate('c')];
} catch (Throwable $e) {
    // Detail stays out of the response — only the server log sees it.
    error_log('health.php: ' . $e->getMessage());
    http_response_code(503);
}

echo json_encode($payload);
