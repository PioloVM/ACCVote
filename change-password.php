<?php
/**
 * change-password.php - forced password change gate (Batch B2).
 *
 * Reached only when users.password_reset_pending is set, i.e. after an
 * admin issued a one-time temporary password (admin/api/students.php
 * resetPassword). dashboard.php bounces pending students here, and this
 * page is the ONLY way out: it validates the same password policy as
 * registration, stores the new hash, and clears the flag. A student with
 * a temporary password sees nothing else in the app until they pick a
 * real one - the gate renders standalone, so no dashboard shell, no
 * ballot, no other student data is ever exposed first.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

startSecureSession();
requireLogin();

$pdo = getDbConnection();
$uid = (int) ($_SESSION['user_id'] ?? 0);

$stmt = $pdo->prepare('SELECT id, student_id, name, password_reset_pending FROM users WHERE id = :id');
$stmt->execute(['id' => $uid]);
$gateUser = $stmt->fetch();

if (!$gateUser || empty($gateUser['password_reset_pending'])) {
    // Not pending (or the row vanished): nothing to force here.
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please refresh and try again.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');

        if (($policy = passwordPolicyError($password)) !== null) {
            $error = $policy;
        } elseif ($password !== $confirm) {
            $error = 'The two passwords do not match.';
        } else {
            $upd = $pdo->prepare('UPDATE users SET password = :pw, password_reset_pending = 0 WHERE id = :id');
            $upd->execute([
                'pw' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $uid,
            ]);
            header('Location: dashboard.php');
            exit;
        }
    }
}

$csrfToken = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set a New Password | School Elections</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
    <style>
        :root { --navy:#101828; --lime:#84cc16; --ink:#0f172a; --muted:#64748b; --paper:#f5f7fb; --line:#e2e8f0; --err:#dc2626; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: var(--navy); color: var(--ink);
            font-family: 'Inter', system-ui, sans-serif; padding: 1.5rem;
        }
        .gate-card {
            background: var(--paper); border-radius: 20px; padding: 2.5rem 2.75rem;
            max-width: 440px; width: 100%;
        }
        .eyebrow {
            font-family: 'IBM Plex Mono', monospace; font-size: .72rem; font-weight: 600;
            letter-spacing: .14em; text-transform: uppercase; color: var(--muted); margin: 0 0 .5rem;
        }
        h1 { font-family: 'Sora', sans-serif; font-size: 1.5rem; margin: 0 0 .5rem; }
        .lede { color: var(--muted); font-size: .88rem; line-height: 1.55; margin: 0 0 1.5rem; }
        .lede strong { color: var(--ink); }
        label { display: block; font-size: .78rem; font-weight: 600; margin: 0 0 .3rem; }
        input[type="password"] {
            width: 100%; padding: .7rem .85rem; border: 1px solid var(--line); border-radius: 10px;
            font: inherit; font-size: .92rem; background: #fff; margin-bottom: 1rem;
        }
        input[type="password"]:focus { outline: 2px solid var(--lime); outline-offset: 1px; border-color: var(--lime); }
        .hint { font-size: .74rem; color: var(--muted); margin: -.75rem 0 1rem; }
        .err {
            background: #fef2f2; border: 1px solid #fecaca; color: var(--err);
            border-radius: 10px; padding: .65rem .85rem; font-size: .82rem; margin: 0 0 1rem;
        }
        .btn {
            width: 100%; border: 0; border-radius: 10px; background: var(--lime); color: #1a2e05;
            font-family: 'Sora', sans-serif; font-weight: 700; font-size: .95rem;
            padding: .8rem 1rem; cursor: pointer;
        }
        .btn:hover { filter: brightness(1.05); }
    </style>
</head>
<body>
    <main class="gate-card" id="pw-reset-gate">
        <p class="eyebrow">Account recovery</p>
        <h1>Set a new password</h1>
        <p class="lede">
            A temporary password was issued for <strong><?= h($gateUser['name']) ?></strong>
            (<?= h($gateUser['student_id']) ?>). Choose your own password to finish signing in -
            the temporary one stops working right away.
        </p>
        <?php if ($error !== ''): ?>
            <p class="err"><?= h($error) ?></p>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <label for="pw1">New password</label>
            <input type="password" id="pw1" name="password" required maxlength="128" autofocus>
            <p class="hint">At least 8 characters, with both letters and numbers.</p>
            <label for="pw2">Confirm new password</label>
            <input type="password" id="pw2" name="confirm" required maxlength="128">
            <button type="submit" class="btn">Save password and continue</button>
        </form>
    </main>
</body>
</html>
