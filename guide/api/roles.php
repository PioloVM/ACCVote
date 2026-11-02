<?php
/**
 * Reports which demo sessions are active.
 *
 * The app's own endpoints answer 401 for anonymous visitors, which the
 * browser reports as a console error. This local-only probe reads the same
 * PHP session and answers 200 for everyone.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
guide_require_local();

require_once __DIR__ . '/../../includes/functions.php';
startSecureSession();

guide_json([
    'student' => !empty($_SESSION['user_id']),
    'admin' => !empty($_SESSION['admin_id']),
    'student_name' => $_SESSION['name'] ?? null,
    'admin_name' => $_SESSION['admin_username'] ?? null,
]);
