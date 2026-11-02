<?php
/**
 * index.php is just a router now — visiting "/" (or "/index.php" directly,
 * which is what most servers treat as the default document) should send
 * you to the right place rather than straight into the login form.
 *
 *   logged in      -> dashboard.php
 *   not logged in  -> landingpage.php
 *
 * The actual login form now lives at login.php.
 */
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();

$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$dir = dirname($scriptName);
// Served from <approot>/public/index.php (DirectoryIndex or direct hit);
// php -S serves it at the domain root. Absolute targets work from either base.
$appRoot = str_ends_with($dir, '/public') ? substr($dir, 0, -strlen('/public')) : '';
header('Location: ' . $appRoot . (!empty($_SESSION['user_id']) ? '/student/dashboard.php' : '/public/landingpage.php'));
exit;