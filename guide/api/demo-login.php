<?php
/**
 * Opens a local demo session without submitting the real login form.
 *
 * The app's login POST writes login_attempts, last_login, and sometimes
 * users.year_level. The guided tour must leave every database row untouched,
 * so this bridge only reads the demo account id and then sets the same PHP
 * session keys the app expects.
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

$role = $_POST['role'] ?? '';
if (!in_array($role, ['student', 'admin'], true)) {
    guide_json(['ok' => false, 'error' => 'Unknown demo role.'], 400);
}
// The admin bridge opens a REAL full-privilege admin session, so it must
// never be reachable from the public internet - remote visitors only get
// the (read-mostly, no-elections) student demo.
if ($role === 'admin' && !guide_is_loopback()) {
    guide_json(['ok' => false, 'error' => 'The admin demo bridge is only available on localhost.'], 403);
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

startSecureSession();

try {
    $pdo = getDbConnection();
    if ($role === 'student') {
        $stmt = $pdo->prepare('SELECT id, name FROM users WHERE student_id = :sid LIMIT 1');
        $stmt->execute(['sid' => '987654321']);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            guide_json(['ok' => false, 'error' => 'The demo student account is not in this database.'], 404);
        }
        unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['is_admin']);
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $_SESSION['user_id'] = (int) $account['id'];
        $_SESSION['name'] = $account['name'];
        unset($_SESSION['needs_account_update']);
        guide_json(['ok' => true, 'role' => 'student', 'name' => $account['name']]);
    }

    $stmt = $pdo->prepare('SELECT id, username FROM admins WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => 'admin']);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        guide_json(['ok' => false, 'error' => 'The demo admin account is not in this database.'], 404);
    }
    unset($_SESSION['user_id'], $_SESSION['name'], $_SESSION['needs_account_update']);
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    $_SESSION['admin_id'] = (int) $account['id'];
    $_SESSION['admin_username'] = $account['username'];
    $_SESSION['is_admin'] = true;
    guide_json(['ok' => true, 'role' => 'admin', 'name' => $account['username']]);
} catch (Throwable $error) {
    error_log('Guide demo login failed: ' . $error->getMessage());
    guide_json(['ok' => false, 'error' => 'The demo session could not be opened. Check the database connection.'], 500);
}
