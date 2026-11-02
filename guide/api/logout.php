<?php
/**
 * Clears the local demo session. Session files only; no database rows.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
guide_require_local();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    guide_json(['ok' => false, 'error' => 'POST required.'], 405);
}
if (($_SERVER['HTTP_X_GUIDE_DEMO'] ?? '') !== '1') {
    guide_json(['ok' => false, 'error' => 'Missing guide demo header.'], 400);
}

require_once __DIR__ . '/../../includes/functions.php';
startSecureSession();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();

guide_json(['ok' => true]);
