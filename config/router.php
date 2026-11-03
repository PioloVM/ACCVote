<?php
// Router for the deployed runtime (Render runs `php -S ... config/router.php`,
// see the Dockerfile CMD). Real files -- PHP pages, CSS, JS, images --
// are served by the built-in server itself (return false); everything
// else gets the branded 404, which php -S would otherwise answer with
// its own bare error page.
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = urldecode((string) parse_url($uri, PHP_URL_PATH));

// Security headers: php -S never reads .htaccess, so the production
// runtime has to set the block itself. Keep in sync with .htaccess
// (Apache side) - the smoke suite pins both copies.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
// 'unsafe-inline' for scripts/styles is deliberate (inline dashboard
// scripts, no build step); cdnjs is the student dashboard's SRI-pinned
// SheetJS + Chart.js host. See .htaccess for the full rationale.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000');
}

// Old root URLs -> new locations (keep in sync with .htaccess).
// Production serves at the domain root, so targets are root-absolute.
$oldMap = [
    '/login.php' => '/student/login.php',
    '/logout.php' => '/student/logout.php',
    '/dashboard.php' => '/student/dashboard.php',
    '/dashboard-data.php' => '/student/dashboard-data.php',
    '/vote.php' => '/student/vote.php',
    '/draft.php' => '/student/draft.php',
    '/update-account.php' => '/student/update-account.php',
    '/change-password.php' => '/student/change-password.php',
    '/photo.php' => '/student/photo.php',
    '/icon.php' => '/student/icon.php',
    '/index.php' => '/public/index.php',
    '/landingpage.php' => '/public/landingpage.php',
    '/results.php' => '/public/results.php',
    '/404.php' => '/public/404.php',
    '/health.php' => '/public/health.php',
];
$query = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');
if (isset($oldMap[$path])) {
    header('Location: ' . $oldMap[$path] . ($query !== '' ? '?' . $query : ''), true, 302);
    return true;
}

// The app root serves index.php.
if ($path === '' || $path === '/') {
    require __DIR__ . '/../public/index.php';
    return true;
}

// Only serve files that really exist inside the app folder (blocks
// traversal); returning false hands the request to php -S, which runs
// PHP scripts normally. router.php lives in config/, so the app root
// is one level up.
$appRoot = dirname(__DIR__);
$file = realpath($appRoot . $path);
$root = realpath($appRoot);
if ($file && $root && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

http_response_code(404);
require __DIR__ . '/../public/404.php';
return true;
