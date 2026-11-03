<?php
/**
 * Guided tour configuration and access gate.
 *
 * The tour reads real source files and exposes local demo credentials, so it
 * is local-only by default. Remote access requires GUIDE_ALLOW_REMOTE=1.
 */

declare(strict_types=1);

function guide_root(): string
{
    $root = realpath(__DIR__ . '/..');
    return $root !== false ? $root : dirname(__DIR__);
}

function guide_app_root(): string
{
    // The guide mounts at <app root>/guide/, so the URL prefix above the
    // /guide/ segment is the app root ('/voting-app3/' under XAMPP, '/' when
    // deployed at a domain root). Deriving it keeps the tour's previews and
    // links working in both mountings.
    foreach ([$_SERVER['SCRIPT_NAME'] ?? '', $_SERVER['PHP_SELF'] ?? ''] as $script) {
        $pos = strpos($script, '/guide/');
        if ($pos !== false) {
            return rtrim(substr($script, 0, $pos), '/') . '/';
        }
    }
    return '/';
}

function guide_is_loopback(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($ip, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
}

function guide_is_truly_local(): bool
{
    // Render fronts every request with its own proxy, so REMOTE_ADDR inside
    // the container is loopback even for internet visitors - a loopback check
    // alone cannot distinguish locals there. The injected RENDER variables
    // are the reliable signal that no request is genuinely local.
    if (getenv('RENDER') === 'true' || getenv('RENDER_SERVICE_ID') !== false) {
        return false;
    }
    return guide_is_loopback();
}

function guide_is_local(): bool
{
    if (getenv('GUIDE_ALLOW_REMOTE') === '1') {
        return true;
    }

    return guide_is_loopback();
}

function guide_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function guide_require_local(): void
{
    if (guide_is_local()) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><title>Guide unavailable</title>'
        . '<body style="font-family:system-ui;background:#f6f3ea;color:#0a1a2f;padding:3rem;">'
        . '<h1 style="font-size:1.4rem;">This guide is local-only.</h1>'
        . '<p>Open it from the machine running XAMPP, or set <code>GUIDE_ALLOW_REMOTE=1</code> for the Apache process.</p>'
        . '</body>';
    exit;
}

function guide_annotations(): array
{
    static $data = null;
    if ($data === null) {
        $data = require __DIR__ . '/data/annotations.php';
    }
    return $data;
}

function guide_system(): array
{
    static $data = null;
    if ($data === null) {
        $data = require __DIR__ . '/data/system.php';
    }
    return $data;
}
