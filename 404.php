<?php
// Branded 404 page. Served two ways:
//   1. Apache ErrorDocument (local XAMPP) - SCRIPT_NAME points at this
//      file inside the app folder, so dirname() yields the app base path.
//   2. Render's php -S router (router.php) - the app lives at the webroot,
//      so SCRIPT_NAME is /router.php and the base resolves to ''.
// Deliberately self-contained: no session, no DB, no shared stylesheet -
// an error page must render even when everything else is broken.
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/404.php');
$base = rtrim(dirname($scriptName), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Page Not Found | School Elections</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
    :root { --navy:#101828; --lime:#84cc16; --ink:#0f172a; --muted:#64748b; --paper:#f5f7fb; }
    * { box-sizing: border-box; }
    body {
        margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
        background: var(--navy); color: var(--ink);
        font-family: 'Inter', system-ui, sans-serif; padding: 1.5rem;
    }
    .card {
        background: var(--paper); border-radius: 20px; padding: 3rem 3.5rem;
        max-width: 480px; width: 100%; text-align: center;
    }
    .eyebrow {
        font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: .78rem;
        letter-spacing: .14em; text-transform: uppercase; color: #3f6212;
        border-left: 3px solid var(--lime); padding-left: .6rem; display: inline-block;
    }
    h1 { font-family: 'Sora', system-ui, sans-serif; font-size: 1.8rem; font-weight: 700; margin: .9rem 0 .6rem; color: var(--ink); }
    p { color: var(--muted); font-size: .95rem; line-height: 1.6; margin: 0 0 1.8rem; }
    .actions { display: flex; gap: .7rem; justify-content: center; flex-wrap: wrap; }
    .btn {
        display: inline-block; padding: .68rem 1.4rem; border-radius: 10px;
        font-weight: 600; font-size: .92rem; text-decoration: none;
    }
    .btn-primary { background: var(--lime); color: #1a2e05; }
    .btn-primary:hover { background: #a3e635; }
    .btn-ghost { border: 1px solid #cbd5e1; color: var(--ink); }
    .btn-ghost:hover { border-color: var(--lime); }
</style>
</head>
<body>
    <main class="card">
        <span class="eyebrow">404 &mdash; Page Not Found</span>
        <h1>This page isn't on the ballot.</h1>
        <p>The address you followed doesn&rsquo;t exist or may have moved. Head back and pick up where you left off.</p>
        <div class="actions">
            <a class="btn btn-primary" href="<?= htmlspecialchars($base, ENT_QUOTES) ?>/login.php">Back to login</a>
            <a class="btn btn-ghost" href="<?= htmlspecialchars($base, ENT_QUOTES) ?>/index.php">Go home</a>
        </div>
    </main>
</body>
</html>
