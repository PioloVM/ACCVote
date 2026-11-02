<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
guide_require_local();

$annotations = guide_annotations();
$system = guide_system();
$guideData = [
    'appRoot' => GUIDE_APP_ROOT,
    'groups' => $annotations['groups'] ?? [],
    'chapters' => $annotations['chapters'] ?? [],
    'annotations' => $annotations['annotations'] ?? [],
    'system' => $system,
];
$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

// Cache-bust the guide's own assets. A stale tour.js/tour.css served from the
// browser cache renders an older UI (missing system tabs, unpatched layout).
$assetVer = max(
    (int) (@filemtime(__DIR__ . '/assets/tour.css') ?: 0),
    (int) (@filemtime(__DIR__ . '/assets/tour.js') ?: 0),
    (int) (@filemtime(__DIR__ . '/assets/highlight.js') ?: 0),
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Civic Code — voting-app3 guided tour</title>
<link rel="icon" href="../assets/logo.png" type="image/png">
<link rel="stylesheet" href="assets/tour.css?v=<?= $assetVer ?>">
</head>
<body>
<div class="tour-app" id="tourApp" data-inspect="true" data-device="desktop">

  <header class="topbar" id="tour-topbar">
    <button class="icon-btn rail-toggle" id="railToggle" aria-label="Toggle chapter list">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>

    <a class="brand" href="./" aria-label="Civic Code home">
      <span class="brand-mark" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M5 4h14v16H5z" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.6"/></svg>
      </span>
      <span class="brand-text">
        <strong>Civic Code</strong>
        <small>voting-app3 · guided tour</small>
      </span>
    </a>

    <div class="topbar-rule" aria-hidden="true"></div>

    <div class="chapter-current" id="chapterCurrent">
      <span class="eyebrow">Chapter</span>
      <strong id="chapterCurrentTitle">Start Here</strong>
    </div>

    <div class="topbar-spacer"></div>

    <div class="top-actions">
      <button class="ghost-btn search-trigger" id="searchBtn" type="button">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>
        <span>Search</span>
        <kbd>Ctrl K</kbd>
      </button>

      <div class="toggle-group" id="inspectToggle" role="group" aria-label="Click mode">
        <button type="button" data-mode="inspect" class="active" title="Clicking inspects instead of navigating">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v4M12 17v4M3 12h4M17 12h4"/><circle cx="12" cy="12" r="4"/></svg>
          Inspect
        </button>
        <button type="button" data-mode="browse" title="Clicking uses the app normally">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h16M14 6l6 6-6 6"/></svg>
          Browse
        </button>
      </div>

      <div class="toggle-group device-group" id="deviceToggle" role="group" aria-label="Preview width">
        <button type="button" data-device="desktop" class="active" title="Desktop width" aria-label="Desktop width">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/></svg>
        </button>
        <button type="button" data-device="tablet" title="Tablet width" aria-label="Tablet width">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="2.5" width="14" height="19" rx="2"/><path d="M11 18.5h2"/></svg>
        </button>
        <button type="button" data-device="mobile" title="Mobile width" aria-label="Mobile width">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M11 18.5h2"/></svg>
        </button>
      </div>

      <span class="role-pill" id="rolePill" hidden></span>

      <div class="menu-wrap">
        <button type="button" class="ghost-btn" id="demoBtn" aria-haspopup="true" aria-expanded="false">
          Demo access
          <svg viewBox="0 0 24 24" aria-hidden="true" class="chev"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div class="menu-pop" id="demoMenu" hidden>
          <div class="menu-label">Local demo accounts</div>
          <button type="button" data-demo="student">
            <span><strong>Student portal</strong><small>987654321 · password123</small></span>
            <span class="menu-go">Open</span>
          </button>
          <button type="button" data-demo="admin">
            <span><strong>Admin panel</strong><small>admin · admin123</small></span>
            <span class="menu-go">Open</span>
          </button>
          <button type="button" data-demo="logout" class="menu-quiet">
            <span><strong>Log out of both</strong><small>Clear the current demo sessions</small></span>
          </button>
        </div>
      </div>

      <button type="button" class="icon-btn" id="helpBtn" aria-label="How to use the tour">?</button>
    </div>
  </header>

  <div class="workspace">
    <aside class="rail" id="tour-rail" aria-label="Chapters">
      <div class="rail-head">
        <span>Case chapters</span>
        <span class="rail-count" id="railProgress">0 / 0</span>
      </div>
      <nav class="chapter-list" id="chapterList"></nav>
      <div class="rail-foot">
        <button type="button" class="system-launcher" id="systemBtn">
          <span class="system-launcher-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5z"/><path d="M4 5.5v16M8 7h8M8 11h6"/></svg>
          </span>
          <span class="system-launcher-text">
            <strong>System reference</strong>
            <small>Architecture · database · APIs</small>
          </span>
        </button>
        <div class="legend">
          <span><i class="cat-ui"></i>Interface</span>
          <span><i class="cat-logic"></i>Logic</span>
          <span><i class="cat-data"></i>Data</span>
          <span><i class="cat-security"></i>Security</span>
        </div>
        <p class="rail-note">Source is read live from this repository. Use Inspect to click anything.</p>
      </div>
    </aside>

    <main class="stage" id="stage">
      <div class="stage-head">
        <div class="stage-title">
          <span class="eyebrow">Live system</span>
          <h1 id="stageTitle">Landing page</h1>
        </div>
        <div class="stage-actions">
          <span class="route-pill" id="routePill">/landingpage.php</span>
          <button type="button" class="stage-btn" id="reloadFrame">Reload</button>
          <a class="stage-btn" id="openFrame" href="../landingpage.php" target="_blank" rel="noopener">Open tab</a>
        </div>
      </div>

      <section class="system-view" id="systemView" hidden aria-label="System reference">
        <div class="print-head">
          <strong>voting-app3 · System reference</strong>
          <span id="printDate"></span>
        </div>
        <header class="system-head">
          <div class="system-heading">
            <span class="eyebrow">System reference</span>
            <h2 id="systemTitle">How voting-app3 works</h2>
            <p id="systemLead">Architecture, database, endpoints, security, and flows. Every entry opens its real source.</p>
          </div>
          <button type="button" class="system-print" id="systemPrint">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V4h10v5M7 18H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2M7 14h10v6H7z"/></svg>
            Print
          </button>
          <button type="button" class="icon-btn system-close" id="systemClose" aria-label="Close system reference">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
          </button>
        </header>
        <div class="system-tabs" id="systemTabs" role="tablist" aria-label="System reference sections"></div>
        <div class="system-body" id="systemBody"></div>
      </section>

      <div class="frame-wrap" id="frame-shell" data-device="desktop">
        <div class="browser">
          <div class="browser-bar">
            <span class="browser-dot is-red"></span>
            <span class="browser-dot is-amber"></span>
            <span class="browser-dot is-green"></span>
            <div class="url-pill" id="urlPill">localhost/voting-app3/landingpage.php</div>
            <button type="button" class="browser-btn" id="frameReload">Reload</button>
          </div>
          <div class="frame-viewport" id="frameViewport">
            <iframe id="app-frame" src="../landingpage.php" title="voting-app3 live application"></iframe>
            <div class="marker-layer" id="marker-layer"></div>
            <div class="frame-banner" id="frameBanner" hidden></div>
            <div class="fallback" id="fallback" hidden>
              <div class="fallback-card">
                <span class="eyebrow">Recorded screen</span>
                <h2>The live app is not responding.</h2>
                <p>The tour is showing a recorded view. Code and explanations still work.</p>
                <img id="fallbackImage" alt="Recorded application screen">
                <div class="fallback-list" id="fallbackList"></div>
                <button type="button" class="primary-btn" id="fallbackRetry">Retry live view</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="step-bar" id="stepBar">
        <div class="step-meta">
          <span class="eyebrow">Step</span>
          <strong id="stepPosition">1 of 1</strong>
        </div>
        <div class="step-title" id="stepTitle">The public front door</div>
        <div class="step-actions">
          <button type="button" class="step-btn follow-btn is-active" id="followToggle" aria-pressed="true">Following</button>
          <button type="button" class="step-btn" id="prevStep">← Previous</button>
          <button type="button" class="step-btn is-primary" id="nextStep">Next →</button>
        </div>
      </div>
    </main>
  </div>

  <aside class="drawer" id="drawer" aria-hidden="true" aria-label="Element inspector">
    <div class="drawer-top">
      <div class="drawer-kicker">
        <span class="category-badge cat-ui" id="drawerCategory">Interface</span>
        <button type="button" class="file-chip" id="drawerFile" title="Copy file path">file.php:1</button>
      </div>
      <button type="button" class="icon-btn drawer-close" id="drawerClose" aria-label="Close inspector">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
      </button>
    </div>
    <div class="drawer-heading">
      <h2 id="drawerTitle">Select an element</h2>
      <p id="drawerSummary">Use Inspect mode and click any part of the running app.</p>
    </div>
    <div class="drawer-tabs" role="tablist" aria-label="Inspector sections">
      <button type="button" role="tab" data-tab="behavior" class="active">What it does</button>
      <button type="button" role="tab" data-tab="code">Code</button>
      <button type="button" role="tab" data-tab="connections">Connections</button>
      <button type="button" role="tab" data-tab="defense">Defense</button>
    </div>
    <div class="drawer-body" id="drawerBody"></div>
    <div class="drawer-foot">
      <button type="button" class="step-btn" id="drawerPrev">←</button>
      <span id="drawerStep">Not in a tour step</span>
      <button type="button" class="step-btn" id="drawerNext">→</button>
    </div>
  </aside>

  <div class="palette" id="palette" hidden>
    <div class="palette-card" role="dialog" aria-modal="true" aria-label="Search the guide">
      <div class="palette-input-wrap">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>
        <input id="paletteInput" type="search" placeholder="Search annotations, tables, endpoints, questions…" autocomplete="off">
        <kbd>Esc</kbd>
      </div>
      <div class="palette-results" id="paletteResults"></div>
      <div class="palette-hint">↑ ↓ move · Enter open · Esc close</div>
    </div>
  </div>

  <div class="welcome" id="welcome">
    <div class="welcome-card" role="dialog" aria-modal="true" aria-labelledby="welcomeTitle">
      <div class="welcome-left">
        <span class="stamp">CASE FILE 03</span>
        <span class="eyebrow">voting-app3 · Aklan Catholic College</span>
        <h1 id="welcomeTitle">Read the system like a case file.</h1>
        <p>This is the real application, not a mock-up. Every marked element opens its behavior, its file, its actual code, and the line you can say at defense.</p>
        <div class="welcome-stats">
          <span><strong id="welcomeChapters">0</strong> chapters</span>
          <span><strong id="welcomeAnnotations">0</strong> annotations</span>
          <span><strong>100%</strong> live source</span>
        </div>
      </div>
      <div class="welcome-right">
        <ol class="welcome-steps">
          <li><span>01</span><div><strong>Pick a chapter</strong><p>Start with the public site, then follow a student through the ballot into the admin panel.</p></div></li>
          <li><span>02</span><div><strong>Inspect anything</strong><p>Numbered markers are shortcuts. Inspect mode lets you click any element on the page.</p></div></li>
          <li><span>03</span><div><strong>Show the code</strong><p>Open the Code tab to see the exact file, resolved line numbers, and live source.</p></div></li>
        </ol>
        <div class="welcome-actions">
          <button type="button" class="primary-btn" id="welcomeStart">Start the guided tour</button>
          <button type="button" class="secondary-btn" id="welcomeExplore">Explore freely</button>
        </div>
        <label class="welcome-skip"><input type="checkbox" id="welcomeSkip"> Don't show this again</label>
      </div>
    </div>
  </div>

  <div class="toast" id="toast" role="status" aria-live="polite"></div>

  <noscript>
    <div class="noscript">This guide needs JavaScript to inspect the live application.</div>
  </noscript>
</div>

<script>
window.GUIDE_DATA = <?= json_encode($guideData, $jsonFlags) ?>;
</script>
<script src="assets/highlight.js?v=<?= $assetVer ?>"></script>
<script src="assets/tour.js?v=<?= $assetVer ?>"></script>
</body>
</html>
