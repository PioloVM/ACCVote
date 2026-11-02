<?php
/**
 * admin/api/next-school-year.php
 *
 * "Next School Year Election" — archives every current election to clear
 * the board for a new cycle.
 *
 * IMPORTANT: this does NOT touch any student's year_level or
 * account_status. Earlier versions of this action bulk-recomputed every
 * student's year level when this ran — that was wrong. Students vote as
 * whatever year level they currently are, not as a level projected
 * forward to match an election's target school year (an election held
 * during 2026-2027 targeting 2027-2028 is still voted on by TODAY's
 * 2nd-years, 3rd-years, etc. — not by who they'll be next year). Year
 * level tracking is handled entirely elsewhere:
 *   - account_status = 'normal': auto-computed live on every login
 *     (see login.php + computeYearLevel() in includes/functions.php).
 *   - any other account_status: fully student-managed via
 *     update-account.php, whenever the student updates it themselves.
 * Nothing here should ever mass-update either of those.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

startSecureSession();
requireAdminLogin();
header('Content-Type: application/json');

$pdo = getDbConnection();

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(['error' => 'Invalid request body.'], 400);
}
if (!verifyCsrfToken($input['csrf_token'] ?? null)) {
    respond(['error' => 'Your session expired. Please refresh and try again.'], 403);
}

$adminWriteKey = 'admin_write:admin:' . (int) ($_SESSION['admin_id'] ?? 0);
if (isRateLimitedKey($pdo, $adminWriteKey, 120, 60)) {
    respond(['error' => 'Too many requests. Please slow down.'], 429);
}
recordAttemptKey($pdo, $adminWriteKey);

try {
    $pdo->beginTransaction();

    $archiveStmt = $pdo->prepare("UPDATE elections SET status = 'archived' WHERE status != 'archived'");
    $archiveStmt->execute();
    $archivedCount = $archiveStmt->rowCount();

    $currentSchoolYear = getActiveSchoolYear($pdo);

    $summary = "Ran Next School Year Election: archived $archivedCount election" . ($archivedCount === 1 ? '' : 's')
        . " (current school year: \"{$currentSchoolYear['label']}\"). Student year levels were not changed.";
    $pdo->prepare('INSERT INTO activity_logs (admin_username, action) VALUES (:username, :action)')
        ->execute(['username' => $_SESSION['admin_username'] ?? 'admin', 'action' => $summary]);

    $pdo->commit();

    respond([
        'success' => true,
        'archived_elections' => $archivedCount,
        'current_school_year' => $currentSchoolYear['label'],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Next School Year Election failed: ' . $e->getMessage());
    respond(['error' => 'Could not complete the archive. No changes were made.'], 500);
}
