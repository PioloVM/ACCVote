<?php
/**
 * admin/api/logs.php
 *
 * Real DB-backed activity log for the admin panel's Dashboard "Recent
 * Activity" card and full Logs tab. Every admin-triggered action (create/
 * edit/delete election, register/edit/delete student, etc.) posts a short
 * line here from the client after the action succeeds.
 *
 * The admin's username is taken from their own session — never trusted
 * from the request body — so the log stays a reliable "who did what"
 * record even with multiple admins sharing the panel.
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

// ---------- GET: most recent activity first ----------
if ($method === 'GET' && !isset($_GET['throttled'])) {
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
    $limit = max(1, min(500, $limit));

    $stmt = $pdo->prepare('
        SELECT id, admin_username, action, created_at
        FROM activity_logs
        ORDER BY created_at DESC, id DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    respond($stmt->fetchAll());
}

// ---------- GET ?throttled=1: rate-limit buckets hit inside the window --
// Backs the Logs view's "Login throttle" card: shows which buckets are
// currently hot (same 300s window the logins use) so an admin can
// unblock a student who locked themselves out - or a colleague. Only
// the user-facing buckets are listed; the internal admin_write keys
// would just be noise. Cutoff computed in PHP so the SQL stays valid
// on BOTH engines (MySQL NOW() and Postgres NOW() quote intervals
// differently).
if ($method === 'GET' && isset($_GET['throttled'])) {
    $cutoff = date('Y-m-d H:i:s', time() - 300);
    $stmt = $pdo->prepare('
        SELECT identifier, COUNT(*) AS attempts, MAX(attempted_at) AS latest
        FROM login_attempts
        WHERE attempted_at > :cutoff
          AND (identifier LIKE \'student_login:%\'
            OR identifier LIKE \'admin_login:%\'
            OR identifier LIKE \'vote_submit:%\')
        GROUP BY identifier
        ORDER BY MAX(attempted_at) DESC
        LIMIT 50
    ');
    $stmt->execute(['cutoff' => $cutoff]);

    respond($stmt->fetchAll());
}

// ---------- POST: record a new log line ----------
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) respond(['error' => 'Invalid request body.'], 400);
    if (!verifyCsrfToken($input['csrf_token'] ?? null)) {
        respond(['error' => 'Your session expired. Please refresh and try again.'], 403);
    }

    // Per-admin rate limit (same policy as the other admin APIs). This
    // endpoint is called after every admin action, so the cap is the same
    // generous one — it only stops runaway loops.
    $adminWriteKey = 'admin_write:admin:' . (int) ($_SESSION['admin_id'] ?? 0);
    if (isRateLimitedKey($pdo, $adminWriteKey, 120, 60)) {
        respond(['error' => 'Too many requests. Please slow down.'], 429);
    }
    recordAttemptKey($pdo, $adminWriteKey);

    $action = trim((string) ($input['action'] ?? ''));
    if ($action === '') respond(['error' => 'Missing action text.'], 400);
    if (mb_strlen($action) > 500) $action = mb_substr($action, 0, 500);

    $stmt = $pdo->prepare('INSERT INTO activity_logs (admin_username, action) VALUES (:username, :action)');
    try {
        $stmt->execute([
            'username' => $_SESSION['admin_username'] ?? 'admin',
            'action' => $action,
        ]);
    } catch (Throwable $e) {
        error_log('Activity log write failed: ' . $e->getMessage());
        respond(['error' => 'Could not record the log entry.'], 500);
    }

    respond(['success' => true]);
}

// ---------- DELETE: unblock a throttled bucket ------------------------
// Removes every attempt row for one identifier so a locked-out student
// (or admin) can try again immediately. Same CSRF + per-admin write
// throttle + audit logging as the other mutations; the acting admin
// comes from the session, never the body.
if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) respond(['error' => 'Invalid request body.'], 400);
    if (!verifyCsrfToken($input['csrf_token'] ?? null)) {
        respond(['error' => 'Your session expired. Please refresh and try again.'], 403);
    }

    $adminWriteKey = 'admin_write:admin:' . (int) ($_SESSION['admin_id'] ?? 0);
    if (isRateLimitedKey($pdo, $adminWriteKey, 120, 60)) {
        respond(['error' => 'Too many requests. Please slow down.'], 429);
    }
    recordAttemptKey($pdo, $adminWriteKey);

    $identifier = trim((string) ($input['identifier'] ?? ''));
    if ($identifier === '' || mb_strlen($identifier) > 150) {
        respond(['error' => 'Missing or invalid identifier.'], 400);
    }

    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE identifier = :id');
    $stmt->execute(['id' => $identifier]);
    $removed = (int) $stmt->rowCount();

    $log = $pdo->prepare('INSERT INTO activity_logs (admin_username, action) VALUES (:username, :action)');
    $log->execute([
        'username' => $_SESSION['admin_username'] ?? 'admin',
        'action' => 'Unblocked rate-limit bucket ' . $identifier . ' (' . $removed . ' attempt row(s) cleared)',
    ]);

    respond(['success' => true, 'removed' => $removed]);
}

respond(['error' => 'Method not allowed.'], 405);