<?php
/**
 * update-account.php  (Agent 5 — hardened)
 *
 * Two ways to land here:
 *
 *   1. FORCED, ONE TIME: login.php redirects here when a 'normal' student
 *      has crossed into year 5+ with no status ever declared. The locks
 *      below do NOT apply in this mode (the student cannot log in without
 *      declaring), but every other rule does, and the change is logged.
 *
 *   2. VOLUNTARY: reached from the small pencil icon on the profile card in
 *      the student dashboard sidebar. This is a self-declaration that
 *      changes which year-restricted positions a student can see and vote
 *      on, so it is guarded:
 *        - Faculty is NOT editable here (admin-only) — changing it would
 *          let a student move into another faculty's DSG election.
 *        - Read-only while any election this student is eligible for is
 *          ongoing or paused (this also covers "already voted").
 *        - One voluntary change per school year (cooldown). Admins are not
 *          bound by it and can correct a record from the Students panel.
 *        - A level HIGHER than the start year allows is rejected.
 *        - Every change is written to activity_logs as "student:<ID>".
 *
 * Rules shared with registration live in resolveYearDeclaration()
 * (includes/functions.php): matching the computed level = 'normal'; any
 * other level (or 5+ years) needs one of the three repeater statuses.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

startSecureSession();
requireLogin();

$pdo = getDbConnection();
syncElectionStatuses($pdo);
$userId = (int) $_SESSION['user_id'];
$isForced = !empty($_SESSION['needs_account_update']);

$stmt = $pdo->prepare('
    SELECT u.id, u.student_id, u.name, u.department, u.account_status, u.year_level, u.school_year_id,
           sy.label AS school_year_label, sy.start_year AS school_year_start
    FROM users u
    LEFT JOIN school_years sy ON sy.id = u.school_year_id
    WHERE u.id = :id
');
$stmt->execute(['id' => $userId]);
$student = $stmt->fetch();

if (!$student) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$yearLevelNames = $pdo->query('SELECT name FROM year_levels ORDER BY sort_order, name')->fetchAll(PDO::FETCH_COLUMN);
$startYear = $student['school_year_start'] !== null ? (int) $student['school_year_start'] : null;
$currentStatus = $student['account_status'] ?: 'normal';
$computed = $startYear !== null ? computeYearLevel($startYear, currentSchoolYearStartYear(), $yearLevelNames) : null;

const REPEATER_LABELS = [
    'repeating' => 'Repeating a year',
    'undergrad_repeating' => 'Undergraduate — still completing requirements',
    'suspended_repeating' => 'Previously suspended, but currently enrolled',
];
const STATUS_LABELS = [
    'normal' => 'Normal',
    'repeating' => 'Repeating a year',
    'undergrad_repeating' => 'Undergraduate (completing requirements)',
    'suspended_repeating' => 'Suspended, currently enrolled',
];

/**
 * Returns a human-readable reason this student may NOT change their
 * year level right now, or null if they can. Forced mode is exempt.
 */
function accountUpdateLockReason(PDO $pdo, array $student, bool $isForced, ?int $startYear): ?string
{
    if ($startYear === null) {
        return "Your record has no start year on file, so it can't be updated here. Please contact the election administrator.";
    }
    if ($isForced) {
        return null;
    }

    // 1) Open election this student is eligible for (SSG = everyone,
    //    DSG = their own faculty). Also covers "already voted".
    $open = $pdo->prepare("
        SELECT name FROM elections
        WHERE status IN ('ongoing', 'paused')
          AND (type = 'SSG' OR department = :dept)
        ORDER BY start_date ASC
        LIMIT 1
    ");
    $open->execute(['dept' => (string) ($student['department'] ?? '')]);
    $openName = $open->fetchColumn();
    if ($openName !== false) {
        return 'Changes are paused while voting is open (' . $openName . '). You can update your details again once it closes.';
    }

    // 2) One voluntary change per school year. Looked up from activity_logs
    //    (no extra column needed): the log line is written on every save.
    $last = $pdo->prepare("
        SELECT created_at FROM activity_logs
        WHERE admin_username = :u AND action LIKE 'Year level update%'
        ORDER BY id DESC LIMIT 1
    ");
    $last->execute(['u' => 'student:' . $student['student_id']]);
    $lastAt = $last->fetchColumn();
    if ($lastAt !== false && $lastAt !== null) {
        // created_at is stored in UTC; the school year starts June 1 (Manila).
        $boundary = new DateTime(sprintf('%d-06-01 00:00:00', currentSchoolYearStartYear()), new DateTimeZone('Asia/Manila'));
        $changed = new DateTime((string) $lastAt, new DateTimeZone('UTC'));
        if ($changed >= $boundary) {
            return "You've already updated your year level this school year. If something is wrong, please contact the election administrator.";
        }
    }

    return null;
}

$lockReason = accountUpdateLockReason($pdo, $student, $isForced, $startYear);

$error = '';
$postedLevel = $_POST['year_level'] ?? ($student['year_level'] ?? '');
$postedStatus = $_POST['lifecycle_status'] ?? (array_key_exists($currentStatus, REPEATER_LABELS) ? $currentStatus : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($lockReason !== null) {
        $error = $lockReason; // re-checked server-side; the form being hidden is not the guard
    } elseif (isRateLimitedKey($pdo, 'account_update:user:' . $userId, 10, 600)) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        recordAttemptKey($pdo, 'account_update:user:' . $userId);

        $res = resolveYearDeclaration((int) $startYear, $_POST['year_level'] ?? '', $_POST['lifecycle_status'] ?? '', $yearLevelNames);

        if (!$res['ok']) {
            $error = $res['error'];
        } elseif ($res['year_level'] === $student['year_level'] && $res['account_status'] === $currentStatus) {
            // Nothing changed — don't burn the once-a-year change or write a log line.
            unset($_SESSION['needs_account_update']);
            header('Location: dashboard.php');
            exit;
        } else {
            $pdo->prepare('UPDATE users SET year_level = :yl, account_status = :st WHERE id = :id')
                ->execute(['yl' => $res['year_level'], 'st' => $res['account_status'], 'id' => $userId]);

            $logText = sprintf(
                'Year level update: %s (%s) → %s (%s)%s',
                $student['year_level'] ?: '—',
                $currentStatus,
                $res['year_level'] ?: '—',
                $res['account_status'],
                $isForced ? ' [required declaration at login]' : ''
            );
            $pdo->prepare('INSERT INTO activity_logs (admin_username, action) VALUES (:u, :a)')
                ->execute(['u' => 'student:' . $student['student_id'], 'a' => $logText]);

            unset($_SESSION['needs_account_update']);
            header('Location: dashboard.php');
            exit;
        }
    }
}

$csrfToken = csrfToken();
$computedIndex = $computed ? (int) $computed['index'] : 0;
$computedBlocked = $computed ? (bool) $computed['blocked'] : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Enrollment Details | School Elections</title>
<link rel="stylesheet" href="assets/style.css">
<style>
    html, body { margin: 0; min-height: 100vh; min-height: 100dvh; overflow-x: hidden; }
    .page-center {
        min-height: 100vh; min-height: 100dvh;
        padding: max(20px, env(safe-area-inset-top)) 16px max(20px, env(safe-area-inset-bottom));
    }
    .page-center .card { max-width: 460px; text-align: left; }
    .page-center .card-title { text-align: left; }
    .notice-icon {
        width: 48px; height: 48px; border-radius: 50%;
        background: rgba(245, 158, 11, 0.14); color: #b45309;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.4rem; margin-bottom: 1rem;
    }
    .notice-icon.lock { background: rgba(100, 116, 139, 0.14); color: #475569; }
    .student-summary {
        background: var(--gray-bg); border: 1px solid var(--gray-border);
        border-radius: 8px; padding: 0.8rem 1rem; margin-bottom: 1.25rem; font-size: 0.9rem;
    }
    .student-summary div { margin-bottom: 0.3rem; word-break: break-word; }
    .student-summary div:last-child { margin-bottom: 0; }
    .student-summary .label { color: var(--text-muted); }
    .form select { width: 100%; box-sizing: border-box; font-size: 16px; min-height: 44px; }
    .field-hint { font-size: .8rem; color: var(--text-muted); margin: .35rem 0 0; }
    .footer-links { text-align: center; }
    .footer-links a { display: inline-block; padding: .6rem .5rem; min-height: 44px; box-sizing: border-box; }
    .btn-large { min-height: 48px; }
    @media (max-width: 480px) {
        .page-center { align-items: flex-start; }
        .page-center .card { padding: 1.4rem 1.1rem; }
    }
</style>
</head>
<body>
<div class="page-center">
    <div class="card">
        <div class="notice-icon<?= ($lockReason !== null && !$isForced) ? ' lock' : '' ?>"><?= $isForced ? '⚠' : (($lockReason !== null) ? '🔒' : '🎓') ?></div>
        <h2 class="card-title"><?= $isForced ? 'A quick check before you continue' : 'Enrollment Details' ?></h2>
        <p class="muted" style="margin-top: -0.5rem;">
            <?= $isForced
                ? "It's been more than 4 years since your on-file start date — tell us your current year level so your record stays accurate."
                : "Use this only if you're repeating a year or still completing requirements. Most students never need to change anything here." ?>
        </p>

        <div class="student-summary">
            <div><span class="label">Name:</span> <?= h($student['name']) ?></div>
            <div><span class="label">Faculty:</span> <?= h($student['department'] ?: '—') ?></div>
            <div><span class="label">Started:</span> <?= h($student['school_year_label'] ?: '—') ?></div>
            <div><span class="label">Year level by start year:</span> <?= h($computed && $computed['label'] ? $computed['label'] : ($computedBlocked ? 'Past 4 years' : '—')) ?></div>
            <div><span class="label">Year level on file:</span> <?= h($student['year_level'] ?: '—') ?></div>
            <div><span class="label">Status:</span> <?= h(STATUS_LABELS[$currentStatus] ?? $currentStatus) ?></div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($lockReason !== null): ?>
            <div class="alert alert-info"><?= h($lockReason) ?></div>
            <p class="muted" style="font-size:.82rem;">Your faculty can only be changed by the election administrator.</p>
        <?php else: ?>
        <form method="POST" class="form" id="updateAccountForm">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <label for="year_level">Your Current Year Level</label>
            <select id="year_level" name="year_level" required onchange="syncStatusField()">
                <option value="">Select your current year level</option>
                <?php foreach ($yearLevelNames as $i => $name):
                    $idx = $i + 1;
                    // Higher than the start year allows is never valid (repeaters are behind, not ahead).
                    $disabled = (!$computedBlocked && $idx > $computedIndex);
                ?>
                    <option value="<?= h($name) ?>" data-index="<?= $idx ?>" <?= $disabled ? 'disabled' : '' ?> <?= ($postedLevel === $name) ? 'selected' : '' ?>><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint" id="levelHint"><?= $computedBlocked
                ? 'It has been more than 4 years since you started, so please pick where you actually are.'
                : 'Matches your start year? Leave it as is. Behind your start year? Pick your actual level.' ?></p>

            <div id="statusField" style="display:none;">
                <label for="lifecycle_status">Current Enrollment Status</label>
                <select id="lifecycle_status" name="lifecycle_status">
                    <option value="">Select your status</option>
                    <?php foreach (REPEATER_LABELS as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= ($postedStatus === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (!$isForced): ?>
                <p class="field-hint" style="margin-top:1rem;">You can make one change per school year, and not while voting is open.</p>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-large" style="margin-top: 1rem; width:100%;">Save &amp; Continue</button>
        </form>
        <?php endif; ?>

        <p class="footer-links">
            <?= $isForced
                ? '<a href="logout.php">Log out instead</a>'
                : '<a href="dashboard.php">&larr; Back to Dashboard</a>' ?>
        </p>
    </div>
</div>
<script>
    // Show the status field only when the chosen level doesn't match the
    // start-year-computed one (or the student is past 4 years). The server
    // re-validates everything via resolveYearDeclaration().
    const COMPUTED_INDEX = <?= (int) $computedIndex ?>;
    const COMPUTED_BLOCKED = <?= $computedBlocked ? 'true' : 'false' ?>;

    function syncStatusField() {
        const level = document.getElementById('year_level');
        const field = document.getElementById('statusField');
        const status = document.getElementById('lifecycle_status');
        if (!level || !field || !status) return;
        const opt = level.options[level.selectedIndex];
        const idx = opt ? parseInt(opt.dataset.index || '0', 10) : 0;
        const needs = idx > 0 && (COMPUTED_BLOCKED || idx !== COMPUTED_INDEX);
        field.style.display = needs ? 'block' : 'none';
        status.required = needs;
        if (!needs) status.value = '';
    }
    syncStatusField();
</script>
</body>
</html>