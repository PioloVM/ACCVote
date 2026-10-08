<?php
/**
 * GET icon.php?e=<election_id> — the election's logo bytes.
 *
 * Same purpose as photo.php: keeps base64 logos out of dashboard-data.php.
 * SSG elections use the site-wide `ssg_logo` setting; DSG elections use
 * their department's logo. When neither exists this returns 404, and the
 * client renders the generic placeholder glyph instead (see
 * defaultElectionLogoSvg() in includes/functions.php) — a missing logo
 * is a normal state, not an error.
 *
 * Contract: 200 + image bytes + Cache-Control on a hit; bare 404
 * otherwise. Errors go to error_log only.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

// Same emitter as photo.php (kept local: each endpoint stays a single
// self-contained file; see photo.php for the full contract).
function serveImageDataUrl(?string $dataUrl): void
{
    if (!is_string($dataUrl)
        || !preg_match('#^data:image/([a-z0-9.+-]+);base64,#i', $dataUrl, $m)) {
        http_response_code(404);
        exit;
    }
    $comma = strpos($dataUrl, ',');
    $bytes = base64_decode(substr($dataUrl, (int) $comma + 1), true);
    if ($bytes === false || $bytes === '') {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/' . strtolower($m[1]));
    header('Cache-Control: public, max-age=86400');
    echo $bytes;
    exit;
}

$id = filter_input(INPUT_GET, 'e', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(404);
    exit;
}

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT type, department FROM elections WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $election = $stmt->fetch();
    if (!$election) {
        http_response_code(404);
        exit;
    }

    if ($election['type'] === 'SSG') {
        serveImageDataUrl(getSiteSetting($pdo, 'ssg_logo'));
    }

    $department = $election['department'];
    if ($department === null || $department === '') {
        http_response_code(404);
        exit;
    }
    $stmt = $pdo->prepare('SELECT logo FROM departments WHERE code = :code');
    $stmt->execute(['code' => $department]);
    $logo = $stmt->fetchColumn();
    serveImageDataUrl($logo === false ? null : (string) $logo);
} catch (Throwable $e) {
    error_log('icon.php: ' . $e->getMessage());
    http_response_code(500);
    exit;
}
