# voting-app3 Guided Tour — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a local, same-origin "guided code tour" at `/voting-app3/guide/` that frames the real voting app, annotates every meaningful element, and shows accurate purpose, `file:line`, live source, connections, and defense talk-tracks.

**Architecture:** A vanilla PHP/JS tour shell frames the real app same-origin. A marker layer reads iframe DOM rects; clicking opens an inspector drawer. Code is resolved server-side from the actual files using stable anchor strings, never copied. Static system chapters (architecture, database, APIs, security, flows, defense) render from curated PHP data. No app file or database row is modified.

**Tech Stack:** PHP 8, vanilla JS/CSS, MySQL/MariaDB (read-only), Chrome DevTools Protocol for QA, XAMPP.

**Spec:** `docs/superpowers/specs/2026-10-27-voting-app3-guided-tour-design.md`

## Global Constraints

- Do **not** edit any existing voting-app3 file or database row. Work only under `voting-app3/guide/` plus the spec/plan docs.
- Guide is local-only by default: allow `127.0.0.1`, `::1`, `::ffff:127.0.0.1`; remote requires `GUIDE_ALLOW_REMOTE=1`.
- No new runtime dependencies. No CDN in the guide UI. Vanilla PHP/JS/CSS only.
- All snippet text rendered with `textContent`/escaped HTML; never `innerHTML` for source.
- Demo credentials: student `987654321` / `password123`; admin `admin` / `admin123`.
- Every code reference uses `file` + `anchor`; never hard-coded line numbers.
- Do not commit unless the user explicitly asks.
- Preserve the app's identity: navy `#081428`, ballot paper `#f6f3ea`, lime `#84cc16`, gold `#d4af37`, Sora/Inter/IBM Plex Mono.

## Review Focus

1. **Source API containment** — a crafted `file` must never read outside the project, `.git`, env files, or non-text assets. Test: traversal, denied extension, oversized file.
2. **Role state** — student and admin sessions can coexist; the guide must log in and label the current role correctly, never assume one. Test: student login → admin login → student route.
3. **Client-rendered panels** — student/admin panels render after fetch; markers must re-scan and reposition without drift. Test: open admin Students after load, scroll, resize.
4. **Stale election data** — all current elections are closed. The ballot chapter must use the dynamic admin preview or an honest empty state, never fake a live ballot. Test: preview resolution and no-election fallback.
5. **Offline/CDN failure** — Chart.js/SheetJS come from cdnjs; if blocked, the guide must show a banner and snapshot fallback, not a silent blank. Test: stub `window.Chart` missing.
6. **Mobile layout** — at 390×844 the rail, stage, markers, and drawer must remain usable. Test: headless mobile screenshot + drawer open.

---

### Task 1: Scaffold, local gate, and shell

**Files:**
- Create: `guide/config.php`
- Create: `guide/index.php`
- Create: `guide/assets/tour.css`
- Create: `guide/assets/tour.js` (minimal stub, replaced in Task 3)
- Create: `guide/assets/highlight.js` (minimal stub, replaced in Task 2)
- Create: `guide/data/annotations.php`
- Create: `guide/data/system.php`
- Create: `guide/README.md`

**Interfaces:**
- Produces:
  - `guide_is_local(): bool`
  - `guide_root(): string` — realpath of `guide/..`
  - `guide_json(array $payload, int $status = 200): void`
  - `guide_require_local(): void` — sends 403 JSON/HTML and exits
  - `guide_annotations(): array` — wrapper in `config.php` returning `require guide/data/annotations.php`
  - `guide_system(): array` — wrapper returning `require guide/data/system.php`
  - Page exposes `window.GUIDE_DATA = {chapters, annotations, system, appRoot: '/voting-app3/'}`.
  - Annotation `match` keys: `path` (required), `page`, `panel`, `preview`, `hasElection` (all optional).
- Produces DOM ids: `#tour-topbar`, `#tour-rail`, `#stage`, `#frame-shell`, `#app-frame`, `#marker-layer`, `#fallback`, `#drawer`, `#palette`, `#welcome`, `#toast`.

- [ ] **Step 1: Write the gate test as a command**

Run:
```bash
php -r '$_SERVER["REMOTE_ADDR"]="8.8.8.8"; putenv("GUIDE_ALLOW_REMOTE=0"); require "guide/config.php"; var_dump(guide_is_local());'
php -r '$_SERVER["REMOTE_ADDR"]="127.0.0.1"; require "guide/config.php"; var_dump(guide_is_local());'
```
Expected: first `bool(false)`, second `bool(true)`.

- [ ] **Step 2: Implement `config.php`**

`guide_is_local()` returns true for loopback values or `GUIDE_ALLOW_REMOTE=1`. `guide_root()` uses `realpath(__DIR__ . '/..')`. `guide_json()` sets `Content-Type: application/json`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, then `json_encode` and exit. `guide_require_local()` responds 403 with a plain page when denied.

- [ ] **Step 3: Build the shell**

`index.php` requires the gate and data files, then renders:
- top bar: brand, chapter select, Inspect/Browse toggle, device buttons, Demo access, search, help
- left rail: chapter list
- stage: browser-chrome frame with `#app-frame` pointing at `../landingpage.php` and `#marker-layer`
- right drawer (empty state), welcome overlay, palette, toast, fallback container
- inline script `window.GUIDE_DATA`
- links `assets/tour.css`, `assets/highlight.js`, `assets/tour.js`

`tour.css` defines tokens, 64px top bar, 264px rail, stage, 420px drawer, marker styles, palette, welcome, responsive rules (rail overlay ≤1100px, drawer bottom-sheet ≤760px, reduced-motion support).

- [ ] **Step 4: Add one landing annotation**

In `data/annotations.php`, add an annotation:
```php
'landing-hero' => [
  'id' => 'landing-hero',
  'match' => ['path' => '/voting-app3/landingpage.php'],
  'selector' => 'section.hero',
  'title' => 'Hero — “Your Voice, Your Choice”',
  'category' => 'ui',
  'summary' => 'The public front door of the election site.',
  'behavior' => 'Explains the election, shows the live voting banner when an election is open, and offers the primary Vote Now action.',
  'under_the_hood' => 'The section is server-rendered from the landingpage.php query that loads non-archived elections.',
  'code' => [['file' => 'landingpage.php', 'anchor' => '<section class="hero">', 'before' => 3, 'after' => 18, 'label' => 'Hero markup']],
  'tables' => ['elections'], 'endpoints' => [],
  'defense' => 'This is the public entry point; it only reads public election data and never exposes student records.',
]
```
Add one chapter/step referencing `landing-hero`.

- [ ] **Step 5: Verify the shell**

Run:
```bash
php -l guide/config.php && php -l guide/index.php && php -l guide/data/annotations.php
curl -s -o /dev/null -w "%{http_code}\n" http://localhost/voting-app3/guide/
```
Expected: no syntax errors, `200`.

- [ ] **Step 6: Checkpoint** — report shell URL and files; do not commit.

---

### Task 2: Source resolver, validator, highlighter

**Files:**
- Create: `guide/lib/source.php`
- Create: `guide/api/snippet.php`
- Create: `guide/api/validate.php`
- Create: `guide/assets/highlight.js`

**Interfaces:**
- `guide_lang_for(string $relativePath): string` — `php|js|css|sql|md|json|html`.
- `guide_resolve_snippet(array $ref): array` — keys `ok,file,lang,start,end,anchor_line,total,code`. Throws `GuideSourceError($code, $message, $status)`.
- `GuideSourceError::getCode()` returns `invalid_file|denied|not_found|anchor_not_found|too_large|no_anchor`.
- API `GET /guide/api/snippet.php?file=login.php&anchor=password_verify&before=4&after=8`
  returns `{"ok":true,"file":"login.php","lang":"php","start":67,"end":79,"anchor_line":71,"total":637,"code":"..."}`.
- `window.GuideHighlight.highlight(code, lang)` returns escaped HTML; `renderCode(code, lang, startLine)` returns numbered HTML.

- [ ] **Step 1: Write the API tests**

Run (expect failures before implementation):
```bash
curl -s "http://localhost/voting-app3/guide/api/snippet.php?file=login.php&anchor=password_verify&before=4&after=8" | python -c "import json,sys;d=json.load(sys.stdin);assert d['ok'] and 'password_verify' in d['code']"
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost/voting-app3/guide/api/snippet.php?file=../.env&anchor=x"
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost/voting-app3/guide/api/snippet.php?file=assets/logo.png&anchor=x"
curl -s "http://localhost/voting-app3/guide/api/snippet.php?file=login.php&anchor=__nope__"
```
Expected after implementation: `200` JSON pass, `400`, `400`, `404` with `anchor_not_found`.

- [ ] **Step 2: Implement containment**

Allowed extensions: `php,js,css,sql,md,json,html`. Allowed roots: root-level files, `admin/`, `assets/`, `config/`, `includes/`, `student/`, `docs/`, `tests/`, `tools/`, `guide/data/`, `guide/assets/`, `guide/lib/`, `guide/tools/`, `guide/README.md`. Deny `.git`, `.env`, `guide/screens/`, `guide/api/`, `.tour-shots/`, backups, binaries. Use `realpath`, prefix check against `guide_root() . DIRECTORY_SEPARATOR`, reject `..`, enforce file ≤ 512 KB. `before`/`after` clamp to 0–80; total snippet ≤ 400 lines. Anchor lookup is case-sensitive first-line `str_contains`; duplicate anchors use the first match and report `anchor_line`.

- [ ] **Step 3: Implement the validator**

`validate.php` loads annotations + system data, recursively collects every `file`+`anchor` ref, resolves each, and returns:
```json
{"ok": true, "unresolved": [], "checked": 42, "annotations": 24}
```
Also flag duplicate annotation ids, step→annotation references that don't exist, and annotations without a selector.

- [ ] **Step 4: Implement the highlighter**

`highlight.js` escapes `&<>`, then applies one combined tokenizer for comments, strings, numbers, keywords, PHP variables, SQL keywords, and CSS properties. Preserve whitespace. `renderCode` splits lines and prefixes `<span class="ln">n</span>` using the server-provided `start`.

- [ ] **Step 5: Verify**

Run:
```bash
php -l guide/lib/source.php && php -l guide/api/snippet.php && php -l guide/api/validate.php
curl -s http://localhost/voting-app3/guide/api/validate.php
```
Expected: `{"ok":true,...,"unresolved":[]}`.

- [ ] **Step 6: Checkpoint** — report API proof; do not commit.

---

### Task 3: Iframe engine, markers, inspector, demo login

**Files:**
- Create: `guide/assets/tour.js`
- Modify: `guide/index.php`, `guide/assets/tour.css`
- Create: `guide/tools/cdp.mjs`, `guide/tools/qa.mjs`

**Interfaces:**
- `window.Guide = { init, navigate, openAnnotation, setInspect, loginAs, logout, setDevice, nextStep, prevStep, search }`.
- `navigate(target)` where target = `{path, page?, panel?, previewElection?, step?}`.
- `openAnnotation(id)` renders the drawer for an annotation id.
- `loginAs('student'|'admin')` fills and submits the real form inside the iframe.
- `resolvePreviewElection()` fetches `../admin/api/elections.php` and returns the newest non-archived election id with positions.
- QA helper `guide/tools/cdp.mjs` exports `launch()`, `page()`, `evalJs()`, `shot()`; `qa.mjs` runs assertions.

- [ ] **Step 1: Write the failing QA test**

`qa.mjs` must assert:
1. guide loads and `#app-frame` reaches `landingpage.php`
2. at least one marker exists
3. clicking the marker opens `#drawer` with the annotation title
4. code block contains an expected token
5. Inspect toggle click on `section.hero` opens the same drawer
6. zero console errors
7. desktop and 390×844 screenshots saved to `guide/tools/out/`

Run: `node guide/tools/qa.mjs` — expected to fail before implementation.

- [ ] **Step 2: Implement frame lifecycle**

On `iframe.load`: attach `scroll`/`resize` listeners, `MutationObserver`, click capture, and mousemove to the iframe document. Re-scan after 0/300/1000/2500 ms. Determine current route from `location.pathname`, `?page`, and `.panel.active` id. Show a `Log in as demo student` banner when a student route lands on login.

- [ ] **Step 3: Implement markers**

For each annotation matching route/page/panel/preview, resolve `selector`; create a numbered button in `#marker-layer`. Position = iframe rect + element rect, clamped to stage; hide if outside the iframe viewport. Use longest-selector matching for inspect clicks; fall back to the closest annotated ancestor, then a generic page-source drawer. Markers must be keyboard-focusable and have `aria-label`.

- [ ] **Step 4: Implement the drawer**

Tabs: What it does, Code, Connections, Defense. Header: category badge, title, `file:line`, copy-path. Fetch each code ref from the snippet API, cache by `file|anchor|before|after`, render with `GuideHighlight.renderCode`, show resolved `Lx–y`, error state with anchor text, and an Expand button that refetches `before+40/after+40`. Add Esc-to-close, a focus trap, and return focus to the triggering marker.

- [ ] **Step 5: Implement demo access and device widths**

Student: `login.php` → set `#student_id`, `#password` → submit `#loginForm`. Admin: `admin/login.php` → set `username`, `password` → submit. Wait for dashboard, toast success, refresh route. Logout uses `logout.php` or `admin/logout.php`. Device buttons set frame width `100%`, `834px`, `390px` and persist the choice.

- [ ] **Step 6: Implement preview resolution and CDN banner**

`resolvePreviewElection()` uses `fetch('../admin/api/elections.php', {credentials:'same-origin'})`; pick the newest non-archived election with positions; fall back to id `143`. If `window.Chart` or `window.XLSX` is missing on admin/results routes, show a dismissible banner with a "View recorded screen" link.

- [ ] **Step 7: Run QA**

Run: `node guide/tools/qa.mjs`
Expected: all assertions pass; screenshots show desktop and mobile shell.

- [ ] **Step 8: Checkpoint** — report QA output; do not commit.

---

### Task 4: Content — Start Here, Public, Authentication

**Files:**
- Modify: `guide/data/annotations.php`

**Interfaces:** annotations and steps per the schema in Task 1; ids stable and referenced by steps.

- [ ] **Step 1: Add annotations**

Cover at least:
- Start: repo map, tech stack, request lifecycle summary (system view may host these).
- Landing: `nav.nav`, `section.hero`, `.hero-ctas`, `.hero-voting-banner`, `.ticker-wrap`, `section#elections`, `.election-card` (first card), `section.how`, `section.candidates`, `.final-cta`, `footer`.
- Public results: `results.php` header, results cards, auto-refresh script.
- Login: `.login-card`/form, `#student_id`, `#password`, toggle button, `#submitBtn`, hidden `csrf_token`, error alert, rate-limit branch, lifecycle gate, session regeneration.
- Register: form, student ID/name/faculty/year, lifecycle status, password policy/confirm.
- Update account: declaration form.

Use anchors: `<nav class="nav">`, `<section class="hero">`, `<div class="ticker-wrap">`, `<section class="sec elections"`, `<footer>`, `<form method="POST" action="login.php"`, `password_verify`, `isRateLimited($pdo, 'student_login')`, `session_regenerate_id(true)`, `computeYearLevel`, `passwordPolicyError`.

- [ ] **Step 2: Add chapter/step structure**

Groups: Start Here, Public, Student, Admin, System, Defense Kit. Each step has `{id, title, annotation, intro}`. Add "What to click" intro copy.

- [ ] **Step 3: Verify anchors and QA**

```bash
curl -s http://localhost/voting-app3/guide/api/validate.php
node guide/tools/qa.mjs
```
Expected: zero unresolved; QA clicks each new step and asserts drawer titles/code tokens.

- [ ] **Step 4: Checkpoint** — report coverage count; do not commit.

---

### Task 5: Content — Student dashboard, ballot, candidates, results

**Files:**
- Modify: `guide/data/annotations.php`

- [ ] **Step 1: Add shell annotations**

Selectors/anchors: `.sidebar`, `.student-profile-top`, `.theme-switcher`, `.sidebar-bottom`, `.page-header`, `#panel-home`, progress markup (`Voting progress`), `.vote-hero`, `.election-card`, `#startVoteAllBtn` / vote-all CTA, `showPanel`, `dashboard-data.php`.

- [ ] **Step 2: Add ballot annotations**

Route match: `dashboard.php`, `page=vote`, `hasElection=true` (preview included). Selectors: `.ballot-page-header`, `.ballot-header`, `#ballotProgressFill`, `.ballot-election-block`, `.ballot-step`, `.ballot-candidates`, `.ballot-candidate`, `.ballot-select-count`, `.ballot-submit-block`, `#submitBtn`, `#ballotConfirmModal`, `#ballotSuccessModal`, `#voteForm`.
Anchors: `function updateProgress`, `function updateDimming`, `performBallotSubmit`, `vote.php` CSRF check, per-user rate limit, `SELECT ... FOR UPDATE`, `INSERT INTO votes`, `INSERT INTO ballot_submissions`, eligibility checks.
Add a visible note: "This chapter uses the admin ballot preview; voting is disabled and no votes are cast."

- [ ] **Step 3: Add candidates and results annotations**

Selectors: `.cand-tab-controller`, `.cand-search-wrap`, `.cand-card`, `.cand-modal`, result rows (`.result-row`/`.results-block`), live-results heading, polling copy.
Anchors: `renderCandidates`, `switchCandTab`, `openCandidateModal`, `renderResults`, `setInterval`, `results.php` `?partial=1`, `results_visibility`.

- [ ] **Step 4: Verify**

```bash
curl -s http://localhost/voting-app3/guide/api/validate.php
node guide/tools/qa.mjs
```
Expected: zero unresolved; ballot step uses preview, submit disabled, drawer code contains `FOR UPDATE` and `ballot_submissions`.

- [ ] **Step 5: Checkpoint** — report ballot proof; do not commit.

---

### Task 6: Content — Admin panel

**Files:**
- Modify: `guide/data/annotations.php`

- [ ] **Step 1: Add admin shell and dashboard**

Selectors/anchors: `.sidebar`, admin nav links, `.theme-switcher`, `.stats-bar`, `.stat-box`, turnout chart cards, recent activity card, recent elections card, `renderDashboard`, `admin/api/logs.php`, `admin/api/elections.php`.

- [ ] **Step 2: Add elections and students**

Election list `.election-card`, `#panel-election`, "Next School Year Election" button, "Create New Election", wizard `.wizard-section`, archive/duplicate actions, `showPanel('election')`, `renderWizard`, `admin/api/next-school-year.php`.
Students: `.students-toolbar`, `.students-table`, filters, CSV buttons, reset password, pagination, `renderStudentList`, `admin/api/students.php`, password reset policy.

- [ ] **Step 3: Add results, archive, logs, settings**

Results: segmented view, overview stat tiles, detail charts, Reports & Export, `renderResults`, `renderOverviewView`, `renderDetailView`, `renderReportsView`, SheetJS export.
Archive: `.election-card`, restore/delete, `renderArchive`.
Logs: log rows, throttle card, `renderThrottleCard`, `admin/api/logs.php`.
Settings: school years/faculty/year levels/SSG logo/admins/maintenance, `renderSettingsContent`, `admin/api/settings.php` actions `prune_drafts|prune_attempts|prune_logs`.

- [ ] **Step 4: Verify**

```bash
curl -s http://localhost/voting-app3/guide/api/validate.php
node guide/tools/qa.mjs
```
Expected: zero unresolved; QA opens each admin panel, waits for client-rendered content, asserts marker positioning after scroll (no drift), and drawer code tokens.

- [ ] **Step 5: Checkpoint** — report admin proof; do not commit.

---

### Task 7: System chapters — architecture, database, API, security, flows

**Files:**
- Create/Modify: `guide/data/system.php`
- Modify: `guide/assets/tour.js`, `guide/assets/tour.css`, `guide/index.php`, `guide/api/validate.php`

**Interfaces:**
- `guide_system()` returns keys: `overview`, `architecture`, `flows`, `tables`, `endpoints`, `security`, `testing`, `deployment`, `glossary`.
- Each `tables` entry: `{name, purpose, columns:[{name,type,note}], keys, relationships, ref}`.
- Each `endpoints` entry: `{group, method, path, purpose, auth, ref}`.
- `Guide.renderSystem(view, focus)` renders the view; `ref` objects use the same `file+anchor` schema.

- [x] **Step 1: Author the data**

Tables (15): `activity_logs, admins, departments, positions, school_years, users, elections, candidates, election_positions, login_attempts, majors, year_levels, site_settings, vote_drafts, votes, ballot_submissions`. Include purpose, key columns, relationships, SQL anchor.
Endpoints: `admin/api/{admins,elections,logs,next-school-year,settings,students}.php`, `dashboard-data.php`, `vote.php`, `results.php?partial=1`, `photo.php`, `icon.php`, `health.php`, `login.php`, `student/register.php`, `update-account.php`, `change-password.php`.
Security: CSRF, session policy, rate limits, prepared statements, password hashing/policy, headers/CSP, upload validation, audit logs, forced password reset.
Flows: login gate, vote transaction, election status sync, year-level lifecycle, next-school-year rollover, results visibility.

- [x] **Step 2: Render views**

Add system view containers and renderers: architecture node/edge diagram (clickable nodes with refs), database table explorer (list + detail + SQL), API reference (grouped, clickable), security checklist, flow stepper, testing/deployment cards, glossary.

- [x] **Step 3: Verify**

```bash
curl -s http://localhost/voting-app3/guide/api/validate.php
node guide/tools/qa.mjs
```
Expected: zero unresolved; QA opens every system view, clicks a table and an endpoint, asserts the source drawer opens with the correct file.

- [x] **Step 4: Checkpoint** — report system coverage; do not commit.

---

### Task 8: Defense kit, guided tour, search, fallback screenshots

**Files:**
- Modify: `guide/data/system.php`, `guide/data/annotations.php`, `guide/assets/tour.js`, `guide/assets/tour.css`
- Create: `guide/tools/capture.mjs`
- Create: `guide/screens/*.webp`

- [x] **Step 1: Add defense content**

20 likely panel questions with answers (why PHP/MySQL, how votes are protected from double submission, how CSRF works, how results are computed, how year levels are derived, how roles are separated, how the system scales, known limitations, future work), a 60-second pitch, a click-by-click demo script, and a glossary.

- [x] **Step 2: Add guided mode**

`startTour()` walks chapters/steps in order; each step navigates, waits for the selector, scrolls it into view, opens the drawer, and updates `Step n of N`. Next/prev, resume from `localStorage`, deep-link `#chapter=<id>&step=<id>`, and "Follow" toggle.

- [x] **Step 3: Add search**

Ctrl/Cmd+K palette searches annotation titles/summaries, chapter titles, table names, endpoint paths, and glossary terms. Enter opens the result; arrow keys move; Esc closes.

- [x] **Step 4: Capture fallbacks**

`capture.mjs` logs in as student/admin and captures webp screens at 1440×900 for: landing, login, register, public results, student home, vote picker, candidates, results, ballot preview, admin login, admin dashboard/election/students/results/logs/settings. Save under `guide/screens/`. Map each route to its screen in data. On iframe failure/timeout, show the screen image plus an annotation list.

- [x] **Step 5: Add print CSS**

`@media print` hides chrome, expands the defense kit, and prints Q&A + glossary with the system name and date.

- [x] **Step 6: Verify**

```bash
node guide/tools/qa.mjs
```
Expected: guided tour advances 10 steps; palette finds "ballot"; fallback renders when the iframe URL is forced to a 404; print media screenshot is readable.

- [x] **Step 7: Checkpoint** — report proof; do not commit.

---

### Task 9: Final QA, README, cleanup

**Files:**
- Modify: `guide/README.md`
- Delete: `.tour-shots/` temp artifacts (created during exploration)

- [x] **Step 1: Write README**

Run instructions, URL, demo credentials, local-only gate, structure, how to add annotations, how anchors work, how to validate, troubleshooting (Apache/MySQL, CDN offline), and a clear "do not deploy the guide publicly" warning.

- [x] **Step 2: Run the full verification suite**

```bash
find guide -name '*.php' -print0 | xargs -0 -n1 php -l
curl -s http://localhost/voting-app3/guide/api/validate.php
node guide/tools/qa.mjs
```
Expected: all lint clean, `unresolved: []`, QA pass, no console errors, desktop + mobile screenshots readable.

- [x] **Step 3: Security probes**

```bash
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost/voting-app3/guide/api/snippet.php?file=../.env&anchor=x"
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost/voting-app3/guide/api/snippet.php?file=guide/screens/landing.webp&anchor=x"
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost/voting-app3/guide/api/snippet.php?file=guide/api/snippet.php&anchor=guide_root"
```
Expected: all `400` (or `403` for denied paths).

- [x] **Step 4: Manual demo pass**

Open `/voting-app3/guide/`, log in as student and admin, walk every chapter, confirm one code snippet per chapter, open the defense kit print view, and confirm no app screen is broken by the frame.

- [x] **Step 5: Report** — summarize files, coverage count, QA evidence, and any known limitations. Do not commit.

---

## Self-Review

- **Spec coverage:** shell (§5), modes (§5.2), demo access (§5.3), content map (§6), source/anchors (§7), no DB writes (§8), CDN resilience (§9), file layout (§10), security (§11), verification (§12), acceptance (§15) each map to Tasks 1–9.
- **Step scan:** each step names an action and a checkable result; no "handle edge cases" placeholders.
- **Type consistency:** `guide_resolve_snippet` ref shape is reused by annotations, system, validator, and JS; `window.Guide` method names match QA calls.
- **Review Focus:** each of the six risks has an explicit test (Task 2 traversal; Task 3 role login; Task 6 panel markers; Task 5 preview/empty; Task 3/8 CDN fallback; Task 3/9 mobile).
