<?php
/**
 * admin/api/admins.php
 *
 * Admin account management (Batch B1): list, create, edit email,
 * change passwords, and remove admin accounts - so adding a co-admin
 * or fixing a forgotten password never needs direct SQL again.
 *
 * Guards:
 *  - you cannot delete your own account;
 *  - you cannot remove the last remaining admin;
 *  - changing YOUR OWN password requires the current password, while
 *    another admin's password can be reset directly (that is exactly
 *    what an on-site admin reset is for).
 *
 * Every mutation is CSRF-checked, per-admin write-throttled, and
 * audit-logged with the acting admin taken from the session - never
 * from the request body.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

startSecureSession();
requireAdminLogin();
header('Content-Type: application/json');

$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

// ---------- GET: list every admin account ----------
if ($method === 'GET') {
    $me = (int) ($_SESSION['admin_id'] ?? 0);
    $stmt = $pdo->query('SELECT id, username, email, role, created_at, last_login FROM admins ORDER BY username');
    $rows = $stmt->fetchAll();
    // is_me lets the UI badge your own row and hide its Delete button.
    foreach ($rows as &$row) {
        $row['is_me'] = ((int) $row['id'] === $me);
    }
    unset($row);
    respond($rows);
}

// ---------- Writes: body + CSRF + per-admin rate limit (same as settings.php) ----------
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    respond(['error' => 'Invalid request body.'], 400);
}
if (!verifyCsrfToken($body['csrf_token'] ?? null)) {
    respond(['error' => 'Your session expired. Please refresh and try again.'], 403);
}

$adminWriteKey = 'admin_write:admin:' . (int) ($_SESSION['admin_id'] ?? 0);
if (isRateLimitedKey($pdo, $adminWriteKey, 120, 60)) {
    respond(['error' => 'Too many requests. Please slow down.'], 429);
}
recordAttemptKey($pdo, $adminWriteKey);

$me = (int) ($_SESSION['admin_id'] ?? 0);
$meName = (string) ($_SESSION['admin_username'] ?? 'admin');

function auditLine(PDO $pdo, string $who, string $action): void
{
    $log = $pdo->prepare('INSERT INTO activity_logs (admin_username, action) VALUES (:username, :action)');
    $log->execute(['username' => $who, 'action' => $action]);
}

function fetchAdmin(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, username, email FROM admins WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function emailError(string $email): ?string
{
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'That email address is not valid.';
    }
    if (mb_strlen($email) > 100) {
        return 'Email is too long (max 100 characters).';
    }
    return null;
}

// ---------- POST: create an admin account ----------
if ($method === 'POST') {
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $email = trim((string) ($body['email'] ?? ''));

    if ($username === '' || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        respond(['error' => 'Username must be 3-50 characters (letters, digits, dot, dash, underscore).'], 400);
    }
    $policy = passwordPolicyError($password);
    if ($policy !== null) {
        respond(['error' => $policy], 400);
    }
    $emailErr = emailError($email);
    if ($emailErr !== null) {
        respond(['error' => $emailErr], 400);
    }

    $dup = $pdo->prepare('SELECT id FROM admins WHERE username = :username');
    $dup->execute(['username' => $username]);
    if ($dup->fetch()) {
        respond(['error' => 'An admin with that username already exists.'], 409);
    }

    $ins = $pdo->prepare('INSERT INTO admins (username, password_hash, email, role) VALUES (:username, :hash, :email, :role)');
    $ins->execute([
        'username' => $username,
        'hash' => password_hash($password, PASSWORD_DEFAULT),
        'email' => $email === '' ? null : $email,
        'role' => 'admin',
    ]);

    auditLine($pdo, $meName, 'Created admin account "' . $username . '"');
    respond(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
}

$id = (int) ($body['id'] ?? 0);
$target = $id > 0 ? fetchAdmin($pdo, $id) : null;
if ($target === null) {
    respond(['error' => 'That admin account does not exist.'], 404);
}

// ---------- PUT: change password and/or email ----------
if ($method === 'PUT') {
    $password = (string) ($body['password'] ?? '');
    $hasEmail = array_key_exists('email', $body);
    $changed = [];

    if ($password !== '') {
        if ($id === $me) {
            // Own password: prove you know the current one first, so a
            // hijacked admin session alone cannot lock the real owner out.
            $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $current = (string) ($stmt->fetchColumn() ?: '');
            if (!password_verify((string) ($body['current_password'] ?? ''), $current)) {
                respond(['error' => 'Current password is incorrect.'], 403);
            }
        }
        $policy = passwordPolicyError($password);
        if ($policy !== null) {
            respond(['error' => $policy], 400);
        }
        $upd = $pdo->prepare('UPDATE admins SET password_hash = :hash WHERE id = :id');
        $upd->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $id]);
        $changed[] = 'password';
        auditLine(
            $pdo,
            $meName,
            $id === $me
                ? 'Changed own password'
                : 'Reset password for admin "' . $target['username'] . '"'
        );
    }

    if ($hasEmail) {
        $email = trim((string) $body['email']);
        $emailErr = emailError($email);
        if ($emailErr !== null) {
            respond(['error' => $emailErr], 400);
        }
        $upd = $pdo->prepare('UPDATE admins SET email = :email WHERE id = :id');
        $upd->execute(['email' => $email === '' ? null : $email, 'id' => $id]);
        $changed[] = 'email';
        auditLine($pdo, $meName, 'Updated email for admin "' . $target['username'] . '"');
    }

    if (!$changed) {
        respond(['error' => 'Nothing to update.'], 400);
    }
    respond(['success' => true, 'changed' => $changed]);
}

// ---------- DELETE: remove an admin account ----------
if ($method === 'DELETE') {
    if ($id === $me) {
        respond(['error' => 'You cannot delete your own account.'], 400);
    }
    $count = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($count <= 1) {
        respond(['error' => 'Cannot remove the last remaining admin account.'], 409);
    }
    $del = $pdo->prepare('DELETE FROM admins WHERE id = :id');
    $del->execute(['id' => $id]);
    auditLine($pdo, $meName, 'Removed admin account "' . $target['username'] . '"');
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed.'], 405);
