<?php
// Branded 404 page. Served two ways:
//   1. Apache ErrorDocument (local XAMPP) - SCRIPT_NAME points at this
//      file inside the app folder, so dirname() yields the app base path.
//      .htaccess MUST route here (not to another copy of the app), else
//      $base resolves to that other app and the links below escape.
//   2. Render's php -S router (router.php) - the app lives at the webroot,
//      so SCRIPT_NAME is /router.php and the base resolves to ''.
// Deliberately self-contained: no session, no DB, no shared stylesheet -
// an error page must render even when everything else is broken.
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/public/404.php');
$base = rtrim(dirname($scriptName), '/');
// 404.php now lives in public/. Under Apache the base ends in
// "/public" and is stripped back to the app root; under the php -S
// router the base is "" or router-shaped, so the fallback "" is
// correct for the domain-root mount.
$root = str_ends_with($base, '/public') ? substr($base, 0, -strlen('/public')) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Page Not Found | School Elections</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
    :root {
        --navy-950:#081428; --navy-900:#0b1f3a;
        --lime:#84cc16; --lime-light:#c8f26a;
        --gold:#d4af37; --gold-light:#f0dca0;
        --paper:#f7f5ef; --paper-dim:#efece3;
        --ink:#0f172a; --muted:#5c6b81;
        --display:'Sora',system-ui,sans-serif;
        --mono:'IBM Plex Mono',ui-monospace,monospace;
        --ease:cubic-bezier(.32,.72,0,1);
    }
    * { box-sizing:border-box; }
    body {
        margin:0; min-height:100dvh; display:flex; align-items:center; justify-content:center;
        padding:1.5rem; font-family:var(--display);
        background:radial-gradient(120% 100% at 15% -10%, #16345c 0%, var(--navy-900) 45%, var(--navy-950) 100%);
        color:var(--ink);
    }

    /* --- Signature element: the ballot stub --- */
    .stub {
        position:relative; width:100%; max-width:500px;
        background:var(--paper); border-radius:20px;
        padding:2.9rem 3rem 2.6rem;
        box-shadow:0 30px 60px -15px rgba(0,0,0,.55);
        opacity:0; transform:translateY(24px) blur(6px);
        animation:rise .9s var(--ease) .05s forwards;
    }
    /* perforated left edge */
    .stub::before {
        content:''; position:absolute; left:0; top:0; bottom:0; width:16px;
        border-right:2px dashed var(--paper-dim);
        background:radial-gradient(circle at 0 8px, transparent 5px, var(--paper) 5px) 0 0 / 16px 16px repeat-y;
        border-radius:20px 0 0 20px;
    }

    .band {
        display:flex; align-items:center; justify-content:space-between;
        padding-bottom:1rem; margin-bottom:1.7rem;
        border-bottom:2px dashed var(--paper-dim);
    }
    .seal {
        display:flex; align-items:center; gap:.6rem;
        font-family:var(--mono); font-size:.7rem; letter-spacing:.1em;
        text-transform:uppercase; color:var(--ink); font-weight:600;
    }
    .seal .dot {
        width:26px; height:26px; border-radius:50%;
        border:2px solid var(--lime); display:grid; place-items:center;
        color:var(--lime); font-size:.6rem;
    }
    .band .ref { font-family:var(--mono); font-size:.66rem; color:var(--muted); letter-spacing:.08em; }

    .eyebrow {
        display:inline-flex; align-items:center; gap:.5rem;
        font-family:var(--mono); font-size:.7rem; letter-spacing:.14em;
        text-transform:uppercase; color:#3f6212;
        border:1px solid rgba(132,204,22,.45); border-radius:999px; padding:.4rem .9rem;
    }
    .eyebrow::before { content:'\25CF'; color:var(--lime); font-size:.55rem; }

    h1 {
        font-family:var(--display); font-weight:800; color:var(--ink);
        font-size:clamp(1.7rem,4.6vw,2.3rem); line-height:1.08;
        letter-spacing:-.02em; margin:1.1rem 0 .7rem;
    }
    p { color:var(--muted); font-size:.95rem; line-height:1.65; margin:0 0 1.9rem; max-width:40ch; }

    .actions { display:flex; gap:.7rem; flex-wrap:wrap; }
    .btn {
        display:inline-flex; align-items:center; gap:.6rem;
        padding:.72rem .8rem .72rem 1.3rem; border-radius:999px;
        font-family:var(--display); font-weight:600; font-size:.9rem;
        text-decoration:none; border:1px solid transparent;
        transition:transform .5s var(--ease), background .3s var(--ease), border-color .3s var(--ease);
    }
    .btn:active { transform:scale(.98); }
    .btn:focus-visible { outline:2px solid var(--lime); outline-offset:3px; }
    .btn .ico {
        width:28px; height:28px; border-radius:50%; display:grid; place-items:center;
        transition:transform .5s var(--ease); flex:none;
    }
    .btn:hover .ico { transform:translate(2px,-2px) scale(1.08); }

    .btn-primary { background:var(--lime); color:#052e16; }
    .btn-primary .ico { background:rgba(5,46,22,.14); }
    .btn-primary:hover { background:var(--lime-light); }

    .btn-ghost { border-color:#d8d3c6; color:var(--ink); padding-right:1.3rem; }
    .btn-ghost:hover { border-color:var(--gold); }
    .btn-ghost .ico { display:none; }

    .foot {
        margin:2rem 0 0; padding-top:1.1rem; border-top:1px dashed var(--paper-dim);
        font-family:var(--mono); font-size:.58rem; letter-spacing:.06em;
        text-transform:uppercase; color:var(--muted);
    }

    @keyframes rise { to { opacity:1; transform:none; filter:none; } }

    @media (max-width:520px) {
        .stub { padding:2.2rem 1.5rem 2rem; }
        .actions { flex-direction:column; }
        .btn { justify-content:space-between; }
    }
    @media (prefers-reduced-motion:reduce) {
        .stub { animation:none; opacity:1; transform:none; }
        * { transition:none !important; }
    }
</style>
</head>
<body>
    <main class="stub">
        <div class="band">
            <span class="seal"><span class="dot">&#10003;</span> School Elections</span>
            <span class="ref">BALLOT &mdash; VOID</span>
        </div>

        <span class="eyebrow">404 &mdash; Page Not Found</span>
        <h1>This page isn't on the ballot</h1>
        <p>The address you followed doesn&rsquo;t exist or may have moved. Head back and pick up where you left off.</p>

        <div class="actions">
            <a class="btn btn-primary" href="<?= htmlspecialchars($root, ENT_QUOTES) ?>/student/login.php">
                Back to login
                <span class="ico" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>
                </span>
            </a>
            <a class="btn btn-ghost" href="<?= htmlspecialchars($root, ENT_QUOTES) ?>/public/index.php">Go home</a>
        </div>

        <p class="foot">Office of the Registrar</p>
    </main>
</body>
</html>
