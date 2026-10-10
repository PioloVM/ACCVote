<?php
// Router for the deployed runtime (Render runs `php -S ... router.php`,
// see the Dockerfile CMD). Real files -- PHP pages, CSS, JS, images --
// are served by the built-in server itself (return false); everything
// else gets the branded 404, which php -S would otherwise answer with
// its own bare error page.
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = urldecode((string) parse_url($uri, PHP_URL_PATH));

// The app root serves index.php.
if ($path === '' || $path === '/') {
    require __DIR__ . '/index.php';
    return true;
}

// Only serve files that really exist inside the app folder (blocks
// traversal); returning false hands the request to php -S, which runs
// PHP scripts normally.
$file = realpath(__DIR__ . $path);
$root = realpath(__DIR__);
if ($file && $root && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

http_response_code(404);
require __DIR__ . '/404.php';
return true;
