<?php
/**
 * GET photo.php?c=<candidate_id> — candidate photo bytes.
 *
 * Replaces base64-in-JSON: dashboard-data.php and the ballot now reference
 * this URL instead of embedding up-to-2MB data-URLs per candidate, so the
 * browser downloads each photo once (cached for a day) and JSON payloads
 * stay small. Read-only and id-scoped, so no CSRF token is required.
 *
 * Contract: 200 + image bytes + Cache-Control on a hit; bare 404 when the
 * candidate/photo is missing or malformed. Errors go to error_log only —
 * never to the response body.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

/**
 * Emit a stored `data:image/...;base64,...` value as real image bytes
 * with cache headers, or a bare 404 when absent/malformed. Exits either way.
 */
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

$id = filter_input(INPUT_GET, 'c', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(404);
    exit;
}

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT photo FROM candidates WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $photo = $stmt->fetchColumn();
    serveImageDataUrl($photo === false ? null : (string) $photo);
} catch (Throwable $e) {
    error_log('photo.php: ' . $e->getMessage());
    http_response_code(500);
    exit;
}
