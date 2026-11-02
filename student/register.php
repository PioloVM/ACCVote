<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

startSecureSession();

// If already logged in, go to dashboard (root)
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$pdo = getDbConnection();

// Registration asks for the year the student ACTUALLY STARTED (a plain
// year picker), not a self-reported "Year Level" — asking for Year Level
// and working backwards was unsafe for repeating students: someone
// really in their 6th year but still academically "3rd Year" would have
// had their true tenure understated, defeating the whole point of
// tracking it. Asking for the real start year first, then computing year
// level FORWARD from it, is the only version of this that's accurate for
// everyone.
//
// If that computation lands past the normal 4-year window, the form
// reveals two more fields (via plain JS, no page reload) asking the
// student to declare their status and their ACTUAL current year level —
// this is exactly the "repeating student" case, handled once at
// registration instead of guessing. Once declared, account_status is no
// longer 'normal', which means (see login.php) their year level becomes
// student-controlled from then on — computeYearLevel() is never used to
// silently overwrite it again, and they are never forced through a
// repeat "confirm your status" prompt on every future login.
//
// Students who already have an account never register again — they just
// log in. See SCHOOL_YEAR_OVERHAUL_PLAN.md.
$departments = $pdo->query('SELECT code, name FROM departments ORDER BY sort_order, code')->fetchAll();
$yearLevelNames = $pdo->query('SELECT name FROM year_levels ORDER BY sort_order, name')->fetchAll(PDO::FETCH_COLUMN);
syncElectionStatuses($pdo);

// Year Started is derived entirely from the first four digits of the
// Student ID (e.g. 202410001 -> 2024-2025). The student never picks it.
$currentStartYear = currentSchoolYearStartYear();
$yearStartedEarliest = $currentStartYear - 20;
$postedStudentId = trim($_POST['student_id'] ?? '');
$idYearPrefix = preg_match('/^(\d{4})\d{5}$/', $postedStudentId)
    ? (int) substr($postedStudentId, 0, 4)
    : null;

const LIFECYCLE_LABELS = [
    'repeating' => 'Repeating a year',
    'undergrad_repeating' => 'Undergraduate — still completing requirements',
    'suspended_repeating' => 'Previously suspended, but currently enrolled',
];

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Session expired. Please try again.';
    } else {
        // Tighter than the login limiter on purpose: this endpoint lets
        // anyone who knows a student ID claim that account by setting its
        // password, so it's the one most worth slowing down against
        // someone scripting through sequential/guessable student IDs.
        if (isRateLimited($pdo, 'register', 5, 600)) {
            $error = 'Too many attempts. Please wait a few minutes and try again, or contact the election administrator.';
        } else {
        $studentId = trim($_POST['student_id'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $department = trim($_POST['department'] ?? '');
        // Server is authoritative: the start year comes from the ID prefix,
        // never from a client-supplied field.
        $yearStarted = preg_match('/^(\d{4})\d{5}$/', $studentId) ? (int) substr($studentId, 0, 4) : 0;
        $lifecycleStatus = trim($_POST['lifecycle_status'] ?? '');
        $claimedYearLevel = trim($_POST['year_level'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($studentId === '' || $fullName === '' || $department === '' || $yearStarted <= 0 || $password === '' || $confirm === '') {
            $error = 'Please fill in all fields.';
        } elseif (!validateStudentIdFormat($studentId)) {
            $error = 'Student ID must be exactly 9 digits.';
        } elseif (mb_strlen($fullName) > 100) {
            $error = 'Full name is too long (max 100 characters).';
        } elseif (($policyError = passwordPolicyError($password)) !== null) {
            $error = $policyError;
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            recordAttempt($pdo, 'register');

            $stmt = $pdo->prepare('SELECT id, registration_status FROM users WHERE student_id = :student_id');
            $stmt->execute(['student_id' => $studentId]);
            $existing = $stmt->fetch();

            if ($existing && $existing['registration_status'] === 'suspended') {
                $error = 'This account has been suspended. Please contact the election administrator.';
            } elseif ($existing && $existing['registration_status'] !== 'unregistered') {
                $error = 'This Student ID is already registered. Please log in.';
            } elseif ($existing) {
                // Admin pre-created this row (e.g. individually via the
                // admin panel) and left it unregistered — this is an
                // ACTIVATION, not a fresh signup. The admin's Name/Faculty/
                // School Year on file are trusted over whatever was typed
                // here, so a student can't quietly overwrite their own
                // official record at this step — only the password is applied.
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = :password, registration_status = 'active' WHERE id = :id")
                    ->execute(['password' => $hash, 'id' => $existing['id']]);
                $success = true;
                header('Refresh: 2; URL=../login.php?registered=1');
            } else {
                // Brand-new self-registration.
                $deptValid = false;
                foreach ($departments as $d) {
                    if ($d['code'] === $department) { $deptValid = true; break; }
                }
                if (!$deptValid) {
                    $error = 'Please select a valid faculty.';
                } elseif ($yearStarted > $currentStartYear || $yearStarted < $yearStartedEarliest) {
                    $error = 'The first four digits of your Student ID must be a school year between ' . $yearStartedEarliest . ' and ' . $currentStartYear . '. Please check your Student ID.';
                } else {
                    $schoolYear = getOrCreateSchoolYear($pdo, $yearStarted);

                    // Server is authoritative: the helper decides normal vs.
                    // repeater, rejects "higher than start year allows", and
                    // requires a status whenever the level doesn't match
                    // (or the student is past 4 years).
                    $decl = resolveYearDeclaration($yearStarted, $claimedYearLevel, $lifecycleStatus, $yearLevelNames, $currentStartYear);
                    $accountStatus = 'normal';
                    $yearLevel = null;
                    if (!$decl['ok']) {
                        $error = $decl['error'];
                    } else {
                        $accountStatus = $decl['account_status'];
                        $yearLevel = $decl['year_level'];
                    }

                    if ($error === '') {
                        try {
                            $hash = password_hash($password, PASSWORD_DEFAULT);
                            $insert = $pdo->prepare("
                                INSERT INTO users (student_id, name, department, school_year_id, year_level, password, registration_status, account_status, has_voted)
                                VALUES (:student_id, :name, :department, :school_year_id, :year_level, :password, 'active', :account_status, 0)
                            ");
                            $insert->execute([
                                'student_id' => $studentId,
                                'name' => $fullName,
                                'department' => $department,
                                'school_year_id' => $schoolYear['id'],
                                'year_level' => $yearLevel,
                                'password' => $hash,
                                'account_status' => $accountStatus,
                            ]);
                            $success = true;
                            header('Refresh: 2; URL=../login.php?registered=1');
                        } catch (Throwable $e) {
                            // Most likely a race on the unique student_id constraint.
                            error_log('Self-registration insert failed: ' . $e->getMessage());
                            $error = 'That Student ID is already registered. Please log in, or try again.';
                        }
                    }
                }
            }
        }
        }
    }
}

$csrfToken = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Register | School Elections</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        body { margin: 0; min-height: 100vh; }

        .bg-photo {
            position: fixed; inset: 0; z-index: -1;
            background-image: url('../assets/school-bg.jpg');
            background-size: cover; background-position: center;
            filter: blur(6px); transform: scale(1.1);
        }
        .bg-overlay { position: fixed; inset: 0; z-index: -1; background: rgba(0, 0, 0, 0.35); }

        .page-center {
            position: relative; min-height: 100vh;
            display: flex; align-items: center; justify-content: center; padding: 1rem;
        }

        .page-center .card {
            background: rgba(255, 255, 255, 0.16);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.35);
            border-radius: 16px;
            padding: 2rem 2.25rem 2.25rem;
            width: 100%;
            max-width: 420px;
        }

        .page-center .logo-area { text-align: center; margin-bottom: 0.5rem; }
        .page-center .logo { width: 110px; height: 110px; object-fit: contain; }

        .page-center .card-title,
        .page-center label {
            color: #ffffff;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
        }
        .page-center .card-title { text-align: center; margin-top: 0; }
        .page-center .subtitle {
            color: rgba(255,255,255,0.85);
            text-align: center;
            font-size: 0.88rem;
            margin: -0.5rem 0 1.25rem;
            text-shadow: 0 1px 3px rgba(0,0,0,0.4);
        }


        .page-center label {
            font-size: 1rem; font-weight: 600; display: block;
            margin-top: 1rem; margin-bottom: 0.35rem;
        }

        .page-center input[type="text"],
        .page-center input[type="password"],
        .page-center select {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.4);
            width: 100%; padding: 0.6rem 0.75rem; border-radius: 8px;
            font-size: 16px; box-sizing: border-box; color: #1b1f24;
            min-height: 44px; /* comfortable tap target on phones */
        }
        .page-center input[type="text"]:focus,
        .page-center input[type="password"]:focus,
        .page-center select:focus {
            outline: none; border-color: #84cc16;
            box-shadow: 0 0 0 3px rgba(132, 204, 22, 0.4);
        }
        .page-center .pw-hint {
            font-size: 0.78rem; color: rgba(255, 255, 255, 0.85);
            text-shadow: 0 1px 2px rgba(0,0,0,0.4); margin: 0.35rem 0 0;
        }

        /* Kill native browser reveal-password icons so only our custom
           eye toggle shows (same fix as the login page). */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear { display: none; width: 0; height: 0; }
        input[type="password"]::-webkit-credentials-auto-fill-button,
        input[type="password"]::-webkit-strong-password-auto-fill-button {
            visibility: hidden; display: none !important; pointer-events: none;
            position: absolute; right: 0;
        }

        .password-field { position: relative; display: flex; align-items: stretch; }
        .password-field input { flex: 1; padding-right: 2.5rem; }
        .toggle-password-btn {
            position: absolute; right: 0.5rem; top: 50%; transform: translateY(-50%);
            background: none; border: none; padding: 0.25rem; cursor: pointer;
            display: flex; align-items: center; justify-content: center; line-height: 0;
        }
        .toggle-password-btn svg { width: 20px; height: 20px; stroke: #333333; }
        .toggle-password-btn .icon-eye { display: none; }
        .toggle-password-btn .icon-eye-off { display: block; }
        .toggle-password-btn.is-visible .icon-eye { display: block; }
        .toggle-password-btn.is-visible .icon-eye-off { display: none; }

        .page-center .btn-primary {
            background: #84cc16; border-color: #84cc16; color: #052e16;
            width: 100%; margin-top: 1.5rem;
        }
        .page-center .btn-primary:hover { background: #65a30d; border-color: #65a30d; }

        .page-center a { color: #bef264; }
        .page-center a:hover { color: #d9f99d; }

        .footer-links { margin-top: 1.25rem; text-align: center; font-size: 0.85rem; }

        .success-msg {
            text-align: center; color: #d9f99d; font-weight: 600;
            padding: 1rem 0; text-shadow: 0 1px 3px rgba(0,0,0,0.4);
        }

        /* ----- Password strength meter ----- */
        .pw-meter { margin-top: 0.5rem; }
        .pw-meter-bar {
            height: 6px; border-radius: 999px; overflow: hidden;
            background: rgba(255, 255, 255, 0.25);
        }
        .pw-meter-fill {
            height: 100%; width: 0%; border-radius: 999px;
            background: #ef4444; transition: width 0.25s ease, background-color 0.25s ease;
        }
        .pw-meter-fill.pw-1 { background: #ef4444; }
        .pw-meter-fill.pw-2 { background: #f59e0b; }
        .pw-meter-fill.pw-3 { background: #84cc16; }
        .pw-meter-fill.pw-4 { background: #22c55e; }
        .pw-meter-label {
            display: block; margin-top: 0.3rem; font-size: 0.72rem;
            color: rgba(255, 255, 255, 0.85); text-shadow: 0 1px 2px rgba(0,0,0,0.4);
        }

        /* ----- Year Started / Year Level (stacked vertically) ----- */
        .page-center .auto-section-label {
            font-size: 1rem; font-weight: 600; color: #ffffff;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
            display: block; margin-top: 1rem; margin-bottom: 0.5rem;
        }
        .page-center .auto-year-block {
            display: flex; flex-direction: column; gap: 0.45rem; margin-top: 0.35rem;
        }
        .page-center .auto-year-label {
            font-size: 0.72rem; font-weight: 600;
            color: rgba(255, 255, 255, 0.8);
            text-transform: uppercase; letter-spacing: 0.06em;
            text-shadow: 0 1px 2px rgba(0,0,0,0.4);
        }
        .page-center .auto-year-value {
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 8px;
            padding: 0.6rem 0.8rem;
            font-size: 1rem; font-weight: 700; color: #1b1f24;
            min-height: 44px;
            display: flex; align-items: center; gap: 0.5rem;
            box-sizing: border-box;
        }
        .page-center .auto-year-value.empty { color: rgba(27, 31, 36, 0.45); }
        .page-center select#year_level {
            width: 100%; padding: 0.6rem 0.75rem; border-radius: 8px;
            font-size: 16px; box-sizing: border-box; color: #1b1f24;
            min-height: 44px;
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }
        .page-center select#year_level:focus {
            outline: none; border-color: #84cc16;
            box-shadow: 0 0 0 3px rgba(132, 204, 22, 0.4);
        }
        .page-center .auto-year-block .pw-hint {
            font-size: 0.75rem; color: rgba(255, 255, 255, 0.8);
            text-shadow: 0 1px 2px rgba(0,0,0,0.4); margin-top: 0.15rem;
        }

        /* ----- Mobile ----- */
        html, body { overflow-x: hidden; }
        .page-center { padding-top: max(1rem, env(safe-area-inset-top)); padding-bottom: max(1rem, env(safe-area-inset-bottom)); }
        .toggle-password-btn { min-width: 44px; min-height: 44px; justify-content: center; right: 0; }
        .page-center select { max-width: 100%; text-overflow: ellipsis; }
        @media (max-width: 480px) {
            .page-center { align-items: flex-start; padding: 0.75rem; }
            .page-center .card { padding: 1.5rem 1.15rem 1.75rem; border-radius: 14px; }
            .page-center .logo { width: 84px; height: 84px; }
        }
    </style>
</head>
<body>
<div class="bg-photo"></div>
<div class="bg-overlay"></div>
<div class="page-center">
    <div class="card">
        <div class="logo-area">
            <img src="../assets/logo.png" alt="FICT Logo" class="logo">
        </div>
        <h2 class="card-title">Register to Vote</h2>
        <p class="subtitle">First time here? Fill in your details and set a password. Already have an account? <a href="../login.php">Log in instead</a>.</p>


        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success-msg">✅ Account activated! Redirecting you to the login page…</div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

                <label for="student_id">Student ID</label>
                <input type="text" id="student_id" name="student_id" required autofocus autocomplete="username" inputmode="numeric" pattern="\d{9}" maxlength="9" placeholder="9-digit Student ID" value="<?= h($_POST['student_id'] ?? '') ?>">

                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" required maxlength="100" autocomplete="name" value="<?= h($_POST['full_name'] ?? '') ?>">

                <label for="department">Faculty</label>
                <select id="department" name="department" required>
                    <option value="">Select your faculty</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= h($d['code']) ?>" <?= (($_POST['department'] ?? '') === $d['code']) ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$departments): ?>
                    <p class="pw-hint" style="color:#fecaca;">No faculties have been set up yet — contact the election administrator.</p>
                <?php endif; ?>

                <label class="auto-section-label">Year Started &middot; Year Level</label>
                <div class="auto-year-block">
                    <div class="auto-year-label">Year Started (auto from ID)</div>
                    <div class="auto-year-value<?= $idYearPrefix === null ? ' empty' : '' ?>" id="autoYearStarted">
                        <span class="auto-year-icon" aria-hidden="true">&#128197;</span>
                        <?= $idYearPrefix !== null ? h($idYearPrefix . '-' . ($idYearPrefix + 1)) : '&mdash;' ?>
                    </div>
                    <div class="auto-year-label">Year Level</div>
                    <select id="year_level" name="year_level" onchange="onYearLevelChange()">
                        <option value="">Auto-filled</option>
                        <?php foreach ($yearLevelNames as $i => $name): ?>
                            <option value="<?= h($name) ?>" data-index="<?= $i + 1 ?>" <?= (($_POST['year_level'] ?? '') === $name) ? 'selected' : '' ?>><?= h($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="pw-hint" id="yearLevelHint">Year level fills in from your Student ID. Change it if you're repeating a year.</p>
                </div>

                <div id="repeatingFields" style="display:none;">
                    <label for="lifecycle_status">Current Enrollment Status</label>
                    <select id="lifecycle_status" name="lifecycle_status">
                        <option value="">Select your status</option>
                        <?php foreach (LIFECYCLE_LABELS as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= (($_POST['lifecycle_status'] ?? '') === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="pw-hint">Your level doesn't match your start year, so we need to know why. You won't be asked again on future logins.</p>
                </div>

                <label for="password">New Password</label>
                <div class="password-field">
                    <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
                    <button type="button" class="toggle-password-btn" data-target="password" aria-label="Show password">
                        <svg class="icon-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg class="icon-eye-off" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a20.3 20.3 0 0 1 5.06-5.94M9.9 4.24A10.4 10.4 0 0 1 12 4c7 0 11 7 11 7a20.3 20.3 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </button>
                </div>
                <div class="pw-meter" id="pwMeter" aria-hidden="true">
                    <div class="pw-meter-bar"><div class="pw-meter-fill" id="pwMeterFill"></div></div>
                    <span class="pw-meter-label" id="pwMeterLabel">Min. 8 characters with letters &amp; numbers</span>
                </div>

                <label for="confirm_password">Confirm Password</label>
                <div class="password-field">
                    <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                    <button type="button" class="toggle-password-btn" data-target="confirm_password" aria-label="Show password">
                        <svg class="icon-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg class="icon-eye-off" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a20.3 20.3 0 0 1 5.06-5.94M9.9 4.24A10.4 10.4 0 0 1 12 4c7 0 11 7 11 7a20.3 20.3 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </button>
                </div>

                <button type="submit" class="btn btn-primary">Register & Log In</button>
            </form>
        <?php endif; ?>

        <div class="footer-links">
            <a href="../login.php">Back to Login</a>
        </div>
    </div>
</div>
<script>
    // Year Started is auto-computed from the first 4 digits of the Student ID.
    // It is shown as a label (not editable); the server derives the same
    // value again on submit. Year Level auto-fills from it but stays
    // editable for repeating students.
    const idInput = document.getElementById('student_id');
    const autoYearDisplay = document.getElementById('autoYearStarted');
    const levelSel = document.getElementById('year_level');
    const statusWrap = document.getElementById('repeatingFields');
    const statusSel = document.getElementById('lifecycle_status');
    const levelHint = document.getElementById('yearLevelHint');
    const CURRENT_START_YEAR = <?= (int) $currentStartYear ?>;
    const EARLIEST_START_YEAR = <?= (int) $yearStartedEarliest ?>;

    function yearStartedFromId() {
        const m = idInput.value.trim().match(/^(\d{4})\d{5}$/);
        return m ? parseInt(m[1], 10) : null;
    }
    function computedIndex(startYear) {
        return (CURRENT_START_YEAR - startYear) + 1;
    }
    function clearLevelAndStatus(message) {
        autoYearDisplay.textContent = '—';
        levelSel.value = '';
        Array.from(levelSel.options).forEach(function (o) { if (o.value) o.disabled = false; });
        statusWrap.style.display = 'none';
        statusSel.required = false;
        statusSel.value = '';
        if (message) levelHint.textContent = message;
    }
    function updateFromId() {
        const startYear = yearStartedFromId();
        if (startYear === null) {
            clearLevelAndStatus('Enter a 9-digit Student ID to fill in Year Started and Year Level.');
            return;
        }
        if (startYear < EARLIEST_START_YEAR || startYear > CURRENT_START_YEAR) {
            clearLevelAndStatus('The first four digits of that Student ID are not a valid school year. Please check your ID.');
            return;
        }

        autoYearDisplay.textContent = startYear + '-' + (startYear + 1);
        const idx = computedIndex(startYear);
        const blocked = idx >= 5;
        // Disable levels ABOVE the computed one (repeaters are behind, never ahead).
        Array.from(levelSel.options).forEach(function (o) {
            if (!o.value) return;
            o.disabled = !blocked && parseInt(o.dataset.index, 10) > idx;
        });
        // Keep a level the student picked after a failed submit (or a
        // deliberate repeat choice); otherwise auto-fill the computed one.
        const selected = levelSel.options[levelSel.selectedIndex];
        if (!selected || !selected.value || selected.disabled) {
            const match = levelSel.querySelector('option[data-index="' + idx + '"]');
            levelSel.value = (!blocked && match) ? match.value : '';
        }
        levelHint.textContent = blocked
            ? 'Started ' + startYear + ' — more than 4 years ago, so pick your current year level and status below.'
            : 'Filled in automatically from your Student ID. Change it if you are repeating a year.';
        updateStatusVisibility();
    }
    function updateStatusVisibility() {
        const startYear = yearStartedFromId();
        const idx = startYear ? computedIndex(startYear) : 0;
        const lvlOpt = levelSel.options[levelSel.selectedIndex];
        const lvl = lvlOpt && lvlOpt.value ? parseInt(lvlOpt.dataset.index || '0', 10) : 0;
        const show = idx > 0 && (idx >= 5 || (lvl > 0 && lvl !== idx));
        statusWrap.style.display = show ? 'block' : 'none';
        statusSel.required = show;
        if (!show) statusSel.value = '';
    }
    function onYearLevelChange() { updateStatusVisibility(); }
    idInput.addEventListener('input', updateFromId);
    levelSel.addEventListener('change', updateStatusVisibility);
    updateFromId(); // restores state after a failed submit

    document.querySelectorAll('.toggle-password-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById(this.dataset.target);
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            this.classList.toggle('is-visible', isHidden);
            this.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    });

    // Live strength meter — same rules the server enforces (8+ chars,
    // letters and numbers). Purely advisory; the server re-checks.
    (function () {
        const input = document.getElementById('password');
        const fill = document.getElementById('pwMeterFill');
        const label = document.getElementById('pwMeterLabel');
        if (!input || !fill || !label) return;

        input.addEventListener('input', function () {
            const v = input.value;
            let score = 0;
            if (v.length >= 8) score++;
            if (/[A-Za-z]/.test(v) && /\d/.test(v)) score++;
            if (v.length >= 12) score++;
            if (/[^A-Za-z0-9]/.test(v) && v.length >= 8) score++;
            if (v.length === 0) {
                fill.style.width = '0%';
                fill.className = 'pw-meter-fill';
                label.textContent = 'Min. 8 characters with letters & numbers';
                return;
            }
            const labels = { 1: 'Weak', 2: 'Okay', 3: 'Good', 4: 'Strong' };
            fill.style.width = (score * 25) + '%';
            fill.className = 'pw-meter-fill pw-' + Math.max(score, 1);
            const meetsPolicy = v.length >= 8 && /[A-Za-z]/.test(v) && /\d/.test(v);
            label.textContent = (labels[Math.max(score, 1)] || 'Weak') + (meetsPolicy ? ' — meets requirements' : ' — needs 8+ characters with letters & numbers');
        });
    })();
</script>
</body>
</html>