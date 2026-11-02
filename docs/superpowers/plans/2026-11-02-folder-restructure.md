# Folder Restructure — voting-app3 Implementation Plan

**REQUIRED SUB-SKILL: executing-plans** — run inline in this session (default), OR **subagent-driven-development** if the user prefers one subagent per task. Decide before Task 1.

**Plan inputs:**
- Spec: `docs/superpowers/specs/2026-11-02-folder-restructure-design.md` (approved in chat; committed as `cf2e352`)
- Spec code-informed: **YES** — every edit below cites line numbers grep-verified against the live tree at `cf2e352`.

**Architecture / technical approach:** Move 33 root files into `student/`, `public/`, `database/`, `config/`, `docs/`; fix every relative reference (PHP includes, Location redirects, HTML links, asset URLs, tests, workflows, docs); add **identical** old→new 302 redirect maps to `.htaccess` (Apache/XAMPP) and `config/router.php` (production `php -S`) so anything missed degrades to a redirect instead of a 404. `guide/` is excluded (user is editing it) and gets a deferred follow-up pass.

**User override:** `guide/` stays untouched — direct user instruction mid-design; captured in spec §Approved decisions #3.

---

## Global Constraints

1. **`guide/` is OFF-LIMITS.** No file under `guide/` may be created, edited, or deleted. Reading is fine. (The checkpoint commit in Task 1 makes later `git diff -- guide/` verification possible.)
2. **`admin/` untouched** except the single link at `admin/login.php:357`.
3. **No behavior or visual changes** — pure relocation + reference fixes. Page output must be byte-identical apart from changed path strings.
4. **Redirects are 302, never 301** (browsers cache 301 aggressively during transition).
5. **Root keeps exactly three files:** `.gitignore`, `.htaccess`, `Dockerfile`. Everything else at root must become a folder.
6. **File rewrites must be UTF-8 WITHOUT BOM and preserve CRLF.** Use the harness `edit` tool, or PowerShell `[System.IO.File]::WriteAllText($path, $text)`. **Never** `Set-Content`/`Out-File` (PowerShell 5.1 defaults to ANSI — it would corrupt literal Unicode such as the `🗳` at `student/dashboard.php:1427`).
7. **The working tree holds uncommitted user work** (modified `dashboard.php`, `admin/*`, untracked `guide/`, `word-docs/`, migrations). Task 1 checkpoints it first. Never run `git checkout --`, `git reset --hard`, or `git clean` without explicit user confirmation.
8. **Run everything from `C:\xampp\htdocs\voting-app3`.** PHP = `C:\xampp\php\php.exe`, MySQL = `C:\xampp\mysql\bin\mysql.exe`.
9. **`.htaccess` and `config/router.php` redirect maps must contain the same 15 entries** — repo convention ("keep both in sync"), and the smoke suite pins both files.
10. **`assetV()`:** the filesystem stat path (`__DIR__ . '/../'`) stays root-anchored; only the *emitted URL* gains the `../` prefix.
11. **DO NOT "fix" the no-edit list (M5)** — those relative paths are correct after the move; editing them breaks things.

## Review Focus

A reviewer should read these closely; everything else is mechanical:

- `includes/functions.php` `assetV()` signature + the 4 call sites in `student/dashboard.php` (prefix on output only, stat path unchanged).
- `public/index.php` `$appRoot` construction — the root entry (`/` via DirectoryIndex) must land on `/student/dashboard.php` without `../` escaping the app (spec amendment in Task 4).
- `public/404.php` `$root` computation — must work for **both** runtimes (Apache ErrorDocument → `/voting-app3/public/404.php`; php -S router → unknown SCRIPT_NAME shape, falls back to `''`).
- `config/router.php`: `$oldMap` (15 entries, query-string preservation), `dirname(__DIR__)` file-existence check, `/` → `public/index.php`, 404 → `public/404.php`.
- `.htaccess`: `ErrorDocument` points **directly** at `public/404.php` (a redirect here would not serve content); `DirectoryIndex public/index.php` (without it, `/` 404s since root `index.php` moved); rewrite patterns anchored on full filenames so `student/…` and `admin/…` never match.
- `tests/http_smoke.ps1`: 48 URL refs + 12 local-path lines updated; content-pinning assertions at lines 467, 472, 474, 490, 1294 must still match after edits (verified they do).
- `requireLogin()` @ `includes/functions.php:97` and `requireAdminLogin()` @ `:179` — **must remain untouched** (callers grep-verified: all-student / all-admin).
- Final diff must show **zero changes under `guide/`** (unless the user edited it concurrently — those entries appear as uncommitted, never as our diff).

---

## Reference maps (use these; do not re-derive)

### M1 — File moves (Task 2)

| From (root) | To |
|---|---|
| `login.php` `logout.php` `dashboard.php` `dashboard-data.php` `vote.php` `draft.php` `update-account.php` `change-password.php` `photo.php` `icon.php` | `student/` (folder exists; `register.php` already there) |
| `index.php` `landingpage.php` `results.php` `health.php` `404.php` | `public/` (create) |
| `schema.sql` `schema.mysql.sql` + all 11 `migration_*.sql` (incl. `migration_neon.sql`) | `database/` (create) |
| `router.php` | `config/` (exists) |
| `README.md` `TODO-batch-20261029.md` | `docs/` (exists; **no collision** — verified) |

Root after move = `.gitignore` `.htaccess` `Dockerfile` only.

### M2 — URL old→new (applies to `$base/…` in `tests/http_smoke.ps1`, `BASE + '…'` in `docs/design/capture.js`, and the hardcoded URL in `tests/verify-local-env.ps1`)

- → **`student/`**: `login` `logout` `dashboard` `dashboard-data` `vote` `draft` `update-account` `change-password` `photo` `icon`
- → **`public/`**: `index` `landingpage` `results` `404` `health`
- Unchanged: `admin/…` paths, `$base/__no_such_page_9_5.php`, `student/register.php` (capture.js), waitForURL regexes `/dashboard\.php/` (substring matches both dashboards).

`http_smoke.ps1` `$base/` refs to convert (48 total): lines 99, 106, 109, 113, 118, 121, 143, 146, 149, 158, 167, 171, 178, 202, 224, 243, 249, 256, 267, 277, 291, 294, 565, 573, 574, 582, 603, 662, 985, 987, 994, 996, 1001, 1006, 1012, 1018, 1023, 1030, 1032, 1172, 1186, 1194, 1198, 1211, 1215, 1275, 1276, 1281.

### M3 — Local paths inside `tests/http_smoke.ps1` (Task 8)

| Line(s) | Old | New |
|---|---|---|
| 411, 592, 618, 1219 | `.\dashboard.php` | `.\student\dashboard.php` |
| 435 | `.\dashboard-data.php` | `.\student\dashboard-data.php` |
| 445 | `.\login.php` | `.\student\login.php` |
| 463 (×2) | `.\404.php` | `.\public\404.php` |
| 471, 668 | `.\router.php` | `.\config\router.php` |
| 941 | `.\schema.mysql.sql` | `.\database\schema.mysql.sql` |
| 942 | `.\schema.sql` | `.\database\schema.sql` |
| 943 | `.\change-password.php` | `.\student\change-password.php` |
| 1042 | `.\vote.php` | `.\student\vote.php` |
| 1271, 1285 | `.\health.php` | `.\public\health.php` |

All other `Get-Content`/`Test-Path` lines (admin/, assets/, includes/, .htaccess, .gitignore, Dockerfile, ci.yml, uptime.yml, db-backup.yml, tools/backup.ps1) stay as-is.

### M4 — Moved-page include rule (Task 3)

In every file under `student/` and `public/` **that came from root**:
- `__DIR__ . '/includes/` → `__DIR__ . '/../includes/`
- `__DIR__ . '/config/` → `__DIR__ . '/../config/`

14 files: the 10 `student/{login,logout,dashboard,dashboard-data,vote,draft,update-account,change-password,photo,icon}.php` (all have both halves except `logout.php` = includes only) + `public/{index,landingpage,results,health}.php` (`index` = includes only, `health` = config only). `public/404.php` has no requires — not in this set. `student/register.php` and `admin/*` already use `../` — the pattern cannot match them (safe).

### M5 — NO-EDIT list (verified correct after the move; editing these breaks the app)

- `includes/functions.php:97` `header('Location: login.php')` (requireLogin) — callers: `dashboard`, `change-password`, `dashboard-data`, `update-account`, all landing in `student/` → resolves to `/student/login.php` ✓. **No `public/` page calls `requireLogin()`** (grep-verified).
- `includes/functions.php:179` `header('Location: login.php')` (requireAdminLogin) — callers: `admin/*` only → `/admin/login.php` ✓.
- `student/login.php` own Locations: lines 9, 115, 126 (`dashboard.php`, `update-account.php` — same folder) and form `action="login.php"` line 538.
- `student/dashboard.php:51` `Location: change-password.php`; nav links lines 318–332, 935, 938, 1427 (`dashboard.php?page=…`, `update-account.php`, `logout.php` — same folder); `fetch('vote.php')` line 840; `photo.php?c=` at line 514 (same folder).
- `student/change-password.php:30,53` `Location: dashboard.php`; `student/update-account.php:146,164` `Location: dashboard.php`, lines 280–281 links.
- `public/results.php:360` `href="landingpage.php"` (same folder).
- `public/index.php` — **superseded by Task 4's amendment**: line 16 is replaced wholesale by the `$appRoot` absolute construction; there is no surviving relative landing/dashboard target to "leave alone".
- `dashboard-data.php` bare `photo.php?c=` / `icon.php?e=` JSON — consumers are `student/dashboard.php` (same folder) ✓.
- All `admin/*` (except `admin/login.php:357`), `student/register.php` asset paths (already `../assets/…`), `guide/**`, `word-docs/`.

---

## Tasks

### Task 1 — Checkpoint commit (rollback anchor)

- [ ] `git add -A && git commit -m "chore: checkpoint before folder restructure"` — freezes ALL current user work (modified files, untracked `guide/`, `word-docs/`, migrations).
- [ ] Record the SHA (`git rev-parse --short HEAD`).
- [ ] Verify: `git status --short` → empty.
- Rollback for any later task: `git reset --hard <SHA>` (never without user confirmation).

### Task 2 — Move files (M1)

- [ ] Create `public/` and `database/` (`New-Item -ItemType Directory -Force`).
- [ ] Execute the M1 moves (PowerShell `Move-Item`; works for tracked + untracked alike — git records renames at commit time):
  ```powershell
  'login','logout','dashboard','dashboard-data','vote','draft','update-account','change-password','photo','icon' | % { Move-Item "$_.php" student\ }
  'index','landingpage','results','health','404' | % { Move-Item "$_.php" public\ }
  Move-Item schema.sql, schema.mysql.sql database\
  Get-ChildItem -Filter 'migration_*.sql' | Move-Item -Destination database\
  Move-Item router.php config\
  Move-Item README.md, TODO-batch-20261029.md docs\
  ```
- [ ] **Verify:** `Get-ChildItem -File -Force | Select-Object -ExpandProperty Name` → exactly `.gitignore`, `.htaccess`, `Dockerfile`.
- [ ] **Verify:** `Get-ChildItem database -Filter *.sql | Measure-Object` → 13 SQL files (2 schema + 11 migrations).

### Task 3 — Fix PHP includes in moved pages (M4)

- [ ] In each of the 14 files, apply the two M4 substitutions (harness `edit` tool, or bulk via `[IO.File]::WriteAllText` — see constraint 6).
- [ ] **Verify:** `Select-String -Path student\*.php,public\*.php -Pattern "__DIR__ \. '/(includes|config)/"` → **0 matches**.
- [ ] **Verify:** `C:\xampp\php\php.exe -l` on all 14 files → no syntax errors.
- [ ] **Commit B:** `refactor: relocate files into student/public/database/config/docs; fix includes`

### Task 4 — Redirects & links (exact sites)

**`public/index.php` line 16 — spec amendment (rationale in "Spec amendment" below):** a plain `../student/dashboard.php` is WRONG for the main entry: via `DirectoryIndex` the browser URL is `/voting-app3/`, so `../` escapes the app (`/student/dashboard.php` → 404). The file is reachable from two different base URLs (`/voting-app3/` and `/voting-app3/public/index.php`), so relative targets cannot work for both. Compute the app root from `SCRIPT_NAME` (same pattern as `public/404.php` — under php -S `dirname` never ends in `/public`, so the root falls back to `''`, correct for Render's domain root). **Replace line 16 entirely** with:

```php
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$dir = dirname($scriptName);
// Served from <approot>/public/index.php (DirectoryIndex or direct hit);
// php -S serves it at the domain root. Absolute targets work from either base.
$appRoot = str_ends_with($dir, '/public') ? substr($dir, 0, -strlen('/public')) : '';
header('Location: ' . $appRoot . (!empty($_SESSION['user_id']) ? '/student/dashboard.php' : '/public/landingpage.php'));
```

(`require_once` on line 12 already fixed in Task 3.)

**All other rows:**

| File | Line | Old | New |
|---|---|---|---|
| `student/logout.php` | 23 | `Location: index.php` | `Location: ../public/index.php` |
| `student/dashboard.php` | 40 | `Location: index.php` | `Location: ../public/index.php` |
| `student/update-account.php` | 53 | `Location: index.php` | `Location: ../public/index.php` |
| `student/register.php` | 9 | `Location: ../dashboard.php` | `Location: dashboard.php` |
| `includes/functions.php` | 59 | `href="index.php"` | `href="../public/index.php"` (error-block; all callers are one level deep) |
| `student/login.php` | 47, 571 | `student/register.php` | `register.php` |
| `student/login.php` | 575 | `href="landingpage.php"` | `href="../public/landingpage.php"` |
| `public/landingpage.php` | 533 / 534 / 537 / 789 | `dashboard.php` / `logout.php` / `login.php` ×2 | `../student/…` each |
| `public/landingpage.php` | 536, 776, 790 | `student/register.php` | `../student/register.php` |
| `public/results.php` | 366 / 368 | `dashboard.php` / `login.php` | `../student/…` |
| `admin/login.php` | 357 | `../login.php` | `../student/login.php` ← **the only admin edit** |

- [ ] Apply all rows. Respect M5 (do not touch anything else in these files).
- [ ] **Verify:** `Select-String -Path student\*.php,public\*.php -Pattern "header\('Location: index\.php'\)"` → 0; `Select-String -Path student\register.php -Pattern '\.\./dashboard\.php'` → 0.

### Task 5 — Asset references + `assetV()` prefix

`includes/functions.php` (lines 110–123) — add optional prefix, stat path unchanged:

```php
function assetV(string $path, string $prefix = ''): string
{
    $full = __DIR__ . '/../' . ltrim($path, '/');
    $mtime = @filemtime($full);
    return $mtime ? $prefix . $path . '?v=' . $mtime : $prefix . $path;
}
```
(also note `$prefix` in the doc comment above the function)

| File | Line(s) | Edit |
|---|---|---|
| `student/dashboard.php` | 293, 294, 892, 893 | `assetV('assets/X')` → `assetV('assets/X', '../')` |
| `student/dashboard.php` | 307, 913 | `src="assets/logo.png"` → `src="../assets/logo.png"` |
| `student/dashboard.php` | 514 (PHP), 1564, 1669 (JS) | `'assets/default-avatar.svg'` → `'../assets/default-avatar.svg'` |
| `student/login.php` | 149 | `href="assets/style.css"` → `../assets/style.css` |
| `student/login.php` | 165 | `url('assets/school-bg.jpg')` → `url('../assets/school-bg.jpg')` |
| `student/login.php` | 524 | `src="assets/logo.png"` → `../assets/logo.png` |
| `public/landingpage.php` | 526, 785 | logo → `../assets/logo.png` |
| `public/landingpage.php` | 590, 600, 610, 755 | default-avatar → `../assets/default-avatar.svg` |
| `public/results.php` | 361 | logo → `../assets/logo.png` |
| `student/update-account.php` | 180 | `href="assets/style.css"` → `../assets/style.css` |

- [ ] Apply all rows (bulk-safe: replace `="assets/` → `="../assets/` and `'assets/default-avatar` → `'../assets/default-avatar` **only within these five files** — dashboard, login, landingpage, results, update-account — then fix the `assetV` rows individually).
- [ ] **Verify:** `Select-String -Path student\*.php,public\*.php -Pattern '(href|src)="assets/|url\(''assets/'` → 0.
- [ ] **Verify:** `Select-String -Path *.php,student\*.php,public\*.php -Pattern "assetV\('assets/[^']+'\)"` → 0 (old single-arg form gone).

### Task 6 — `public/404.php` root-relative links

- [ ] Line 11 default: `'/404.php'` → `'/public/404.php'`.
- [ ] After line 12 (`$base = …`), add:
  ```php
  // 404.php now lives in public/. Apache ErrorDocument gives
  // $base = "/voting-app3/public"; the php -S router gives "" or a
  // router-shaped path — in every non-public case the app is mounted at
  // the URL root, so links resolve from "".
  $root = str_ends_with($base, '/public') ? substr($base, 0, -strlen('/public')) : '';
  ```
- [ ] Line 146: `href="<?= htmlspecialchars($base, ENT_QUOTES) ?>/login.php"` → `href="<?= htmlspecialchars($root, ENT_QUOTES) ?>/student/login.php"`.
- [ ] Line 152: `… ?>/index.php"` → `… ?>/public/index.php"` (also `$base` → `$root`).
- [ ] **Verify:** `Select-String -Path public\404.php -Pattern '\$base'` → only lines 11–12 remain.
- [ ] **Commit C:** `refactor: fix redirects, links and asset paths for the new layout` (Tasks 4–6).

### Task 7 — Infra

**`.htaccess`**
- [ ] Line 5 comment: `see schema.mysql.sql` → `see database/schema.mysql.sql`.
- [ ] Line 80: `ErrorDocument 404 /voting-app3/404.php` → `ErrorDocument 404 /voting-app3/public/404.php` (direct — never via redirect).
- [ ] Immediately after it, insert:
  ```apache
  # Default document for the folder root (root index.php moved to public/).
  DirectoryIndex public/index.php

  # Old root URLs -> new locations (pages moved into student/ and public/).
  # 302, not 301: browsers cache 301s aggressively; the smoke suite hits
  # new URLs directly. No "?" in the substitutions, so query strings pass
  # through automatically. Anchored full filenames never match student/…
  # or admin/… paths. Keep in sync with config/router.php.
  <IfModule mod_rewrite.c>
      RewriteEngine On
      RewriteRule ^(login|logout|dashboard|dashboard-data|vote|draft|update-account|change-password|photo|icon)\.php$ student/$1.php [R=302,L]
      RewriteRule ^(index|landingpage|results|404|health)\.php$ public/$1.php [R=302,L]
  </IfModule>
  ```

**`config/router.php`** (moved in Task 2)
- [ ] Comment line 2: `php -S ... router.php` → `php -S ... config/router.php`.
- [ ] After the header block (after line 22), insert the redirect map — **15 entries, same as .htaccess** — with query preservation:
  ```php
  // Old root URLs -> new locations (keep in sync with .htaccess).
  // Production serves at the domain root, so targets are root-absolute.
  $oldMap = [
      '/login.php' => '/student/login.php',
      '/logout.php' => '/student/logout.php',
      '/dashboard.php' => '/student/dashboard.php',
      '/dashboard-data.php' => '/student/dashboard-data.php',
      '/vote.php' => '/student/vote.php',
      '/draft.php' => '/student/draft.php',
      '/update-account.php' => '/student/update-account.php',
      '/change-password.php' => '/student/change-password.php',
      '/photo.php' => '/student/photo.php',
      '/icon.php' => '/student/icon.php',
      '/index.php' => '/public/index.php',
      '/landingpage.php' => '/public/landingpage.php',
      '/results.php' => '/public/results.php',
      '/404.php' => '/public/404.php',
      '/health.php' => '/public/health.php',
  ];
  $query = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');
  if (isset($oldMap[$path])) {
      header('Location: ' . $oldMap[$path] . ($query !== '' ? '?' . $query : ''), true, 302);
      return true;
  }
  ```
  (Must sit **before** the file-existence check — old URLs no longer exist as files.)
- [ ] Root logic: `require __DIR__ . '/index.php'` → `require __DIR__ . '/../public/index.php'` — **`config/` is one level deep; a bare `'/public/…'` would resolve to `config/public/…` and fatal.**
- [ ] File check: `$file = realpath(__DIR__ . $path);` / `$root = realpath(__DIR__);` → use `$appRoot = dirname(__DIR__);` once, then `realpath($appRoot . $path)` / `realpath($appRoot)`. (Without this, every static asset 404s in production.)
- [ ] Final 404: `require __DIR__ . '/404.php'` → `require __DIR__ . '/../public/404.php'` (same `config/` depth rule).
- [ ] **Verify:** `C:\xampp\php\php.exe -l config\router.php` → no errors.

**`Dockerfile`** line 18: `"/app/router.php"` → `"/app/config/router.php"`.

**`.github/workflows/ci.yml`** line 39: `for page in dashboard.php admin/dashboard.php` → `for page in student/dashboard.php admin/dashboard.php` (smoke line 490 still matches both patterns ✓).

**`.github/workflows/uptime.yml`** — line 35 is **mandatory** (`"$SITE_URL/health.php"` → `"$SITE_URL/public/health.php"` — that curl has no `-L`, a 302 would file a false incident). Also update the text occurrences at lines 3, 28, 37, 57, 62, 80 to `/public/health.php` (keeps smoke line 1294's `health\.php` match ✓).

- [ ] **Commit D:** `refactor: update .htaccess, router, Dockerfile and workflows for the new layout`

### Task 8 — Tests

- [ ] `tests/schema_parity.php` lines 17–18: `__DIR__ . '/../schema.mysql.sql'` → `'/../database/schema.mysql.sql'`; same for `schema.sql`.
- [ ] `tests/verify-local-env.ps1` line 5: `http://localhost/voting-app3/landingpage.php` → `http://localhost/voting-app3/public/landingpage.php`.
- [ ] `tests/http_smoke.ps1` — bulk URL conversion (M2; safe: patterns cannot match `admin/…`, `student/register.php` refs don't exist here, `__no_such_page` not in the alternation):
  ```powershell
  $p = 'tests\http_smoke.ps1'
  $c = [IO.File]::ReadAllText($p)
  $c = $c -replace '\$base/(dashboard-data|update-account|change-password|login|logout|dashboard|vote|draft|photo|icon)\.php', '$base/student/$1.php'
  $c = $c -replace '\$base/(landingpage|results|health|index|404)\.php', '$base/public/$1.php'
  # local paths (M3)
  $c = $c -replace '\.\\dashboard-data\.php', '.\student\dashboard-data.php'
  $c = $c -replace '\.\\change-password\.php', '.\student\change-password.php'
  $c = $c -replace '\.\\dashboard\.php', '.\student\dashboard.php'
  $c = $c -replace '\.\\login\.php', '.\student\login.php'
  $c = $c -replace '\.\\vote\.php', '.\student\vote.php'
  $c = $c -replace '\.\\404\.php', '.\public\404.php'
  $c = $c -replace '\.\\health\.php', '.\public\health.php'
  $c = $c -replace '\.\\router\.php', '.\config\router.php'
  $c = $c -replace '\.\\schema\.mysql\.sql', '.\database\schema.mysql.sql'
  $c = $c -replace '(?<!database\\)\.\\schema\.sql', '.\database\schema.sql'
  [IO.File]::WriteAllText((Resolve-Path $p), $c)
  ```
  Note: `.\admin\login.php` etc. are safe (pattern requires `.\` immediately before the name); `.\public\404.php` is safe for the `.\404.php` pattern (preceded by `public\`, not `.\`).
- [ ] **Verify:** old-form greps → 0:
  - `Select-String -Path tests\http_smoke.ps1 -Pattern '\$base/(login|logout|dashboard|dashboard-data|vote|draft|update-account|change-password|photo|icon|index|landingpage|results|404|health)\.php'` → 0
  - `Select-String -Path tests\http_smoke.ps1 -Pattern '\.\\(login|dashboard|dashboard-data|change-password|vote|404|health|router)\.php|\.\\schema'` → 0
  - `Select-String -Path tests\http_smoke.ps1 -Pattern '\$base/(student|public)/'` → **48 matches**
- [ ] **No edits** to `tests/extract_admin_js.php` (usage is a generic `<path-to-php-page>` placeholder) or `tests/PLAN-audit-suite.md` (no runnable path refs).
- [ ] **Commit E:** `refactor: update tests and docs paths for the new layout` (include Task 9).

### Task 9 — Docs

- [ ] `config/db.php` line 18: `< schema.mysql.sql` → `< database/schema.mysql.sql` (prose line 16 keeps the bare filename — still accurate).
- [ ] `docs/README.md` (after move) — line 3: `` `migration_schoolyear.sql` / `migration_schoolyear.mysql.sql` `` → prefix both with `database/`; line 52: `in login.php` → `in student/login.php`; line 119: `` `register.php`, `login.php` `` → `` `student/register.php`, `student/login.php` ``. Everything else (title, content) unchanged.
- [ ] `docs/TODO-batch-20261029.md`: **content untouched** (historical log).
- [ ] `tests/baseline-timings.md` line 6: `seeded by schema.mysql.sql` → `seeded by database/schema.mysql.sql` (timing tables are historical — leave).
- [ ] `docs/design/capture.js`: line 14, 42 → `/public/landingpage.php`; lines 19, 65, 82 → `/student/login.php`; line 29 → `/public/results.php`; line 34 → `/public/404.php`. **Leave** line 24 (`/student/register.php`), line 48 (`/admin/login.php`), and the `waitForURL(/dashboard\.php/)` regexes (substring matches still work).
- [ ] Commit E (with Task 8).

### Task 10 — Static verification battery (all must pass before HTTP tests)

Run from repo root; expect zero findings unless noted:

1. **Root files:** `Get-ChildItem -File -Force | % Name` → `.gitignore` `.htaccess` `Dockerfile`.
2. **`php -l` every PHP file** (mirrors CI): `Get-ChildItem -Recurse -Filter *.php | Where-Object FullName -notmatch '\\\.git\\' | ForEach-Object { & C:\xampp\php\php.exe -l $_.FullName }` → no `Parse error`.
3. **Schema parity:** `& C:\xampp\php\php.exe tests\schema_parity.php` → `PASS`.
4. **JS parse guard, both dashboards:**
   ```powershell
   $js = & C:\xampp\php\php.exe tests\extract_admin_js.php student\dashboard.php; node --check $js
   $js = & C:\xampp\php\php.exe tests\extract_admin_js.php admin\dashboard.php; node --check $js
   ```
5. **Require-target existence** (script; excludes nothing — a `guide/` MISS is reported but NOT edited, per constraint 1):
   ```powershell
   Get-ChildItem -Recurse -Filter *.php | Where-Object FullName -notmatch '\\\.git\\' | ForEach-Object {
     $src = [IO.File]::ReadAllText($_.FullName)
     foreach ($m in [regex]::Matches($src, "require(?:_once)?\s+\(?__DIR__\s*\.\s*'([^']+)'")) {
       $t = Join-Path $_.DirectoryName ($m.Groups[1].Value -replace '/', '\')
       if (-not (Test-Path $t)) { "MISSING: $($_.FullName) -> $($m.Groups[1].Value)" }
     }
   }
   ```
   → no `MISSING:` lines. (`config/router.php`'s `require __DIR__ . '/../public/index.php'` resolves to `public/index.php` ✓ automatically.)
6. **Grep audits → 0** (exclude `guide\`):
   - M4 leftovers: `__DIR__ \. '/(includes|config)/` in `student\`,`public\`
   - Old `$base/` and `.\` forms in `tests\http_smoke.ps1` (Task 8 checks)
   - Bare assets: `(href|src)="assets/|url\('assets/` in `student\`,`public\`
   - Old assetV form: `assetV\('assets/[^']+'\)` repo-wide
   - `header\('Location: index\.php'\)` in `student\` → 0
7. **Infra pins present:** `.htaccess` contains `ErrorDocument 404 /voting-app3/public/404.php` + `DirectoryIndex public/index.php` + 2 `R=302` rules; `config/router.php` contains 15 `$oldMap` entries + `dirname(__DIR__)`; `Dockerfile` contains `/app/config/router.php`; `ci.yml` contains `student/dashboard.php`; `uptime.yml` contains `public/health.php`.
8. **guide/ untouched:** `git status --short guide/` → empty (user may have concurrent edits — if entries appear, confirm they are NOT ours: `git diff -- guide/` must show no structural rewrites by this plan).
- [ ] Fix any finding (smallest possible edit), re-run the failing check, commit if a file changed.

### Task 11 — HTTP smoke suite (the main gate)

Preconditions: XAMPP **Apache + MariaDB** running; `curl http://localhost/voting-app3/public/health.php` → 200 first.

```powershell
powershell -ExecutionPolicy Bypass -File tests\http_smoke.ps1
```
- [ ] **Exit code 0** (suite covers login → vote → results → photo/icon → landing → health → 404 → security headers → source pins, end-to-end at the NEW URLs).
- [ ] If a failure is caused by a missed reference: fix, re-run from the top. Never weaken an assertion to make it pass.

### Task 12 — Browser / Apache behavior pass

- [ ] `http://localhost/voting-app3/` → landing page renders, logo + avatars + CSS load (200s in network tab).
- [ ] Login `987654321` / `password123` → dashboard; nav, photos, election logos load.
- [ ] **Redirects:** `http://localhost/voting-app3/login.php` → 302 → `/voting-app3/student/login.php` renders; same for `/landingpage.php`.
- [ ] **404 page:** open `http://localhost/voting-app3/no-such-page` → branded 404 (404 status), and its "Back to login" href = `/voting-app3/student/login.php`, "Go home" = `/voting-app3/public/index.php` (view-source).
- [ ] **Root entry, both branches:** logged **out**, `http://localhost/voting-app3/` → landing (DirectoryIndex + `$appRoot` landing target); logged **in**, `http://localhost/voting-app3/` → dashboard (`$appRoot` dashboard target — the case a plain `../` would break).

### Task 13 — `php -S` production rehearsal (Render path)

```powershell
$p = Start-Process C:\xampp\php\php.exe -ArgumentList '-S','localhost:8090','config/router.php' -PassThru -WindowStyle Hidden
# battery (run from repo root):
curl.exe -s -D - -o NUL http://localhost:8090/login.php            | findstr /i Location   # -> /student/login.php
curl.exe -s -D - -o NUL "http://localhost:8090/landingpage.php?x=1" | findstr /i Location   # -> /public/landingpage.php?x=1 (query kept)
curl.exe -s -o NUL -w "%{http_code}`n" http://localhost:8090/student/login.php             # 200
curl.exe -s -o NUL -w "%{http_code}`n" http://localhost:8090/public/health.php             # 200
curl.exe -s -o NUL -w "%{http_code}`n" http://localhost:8090/assets/style.css              # 200 (dirname(__DIR__) fix)
curl.exe -s -L -o NUL -w "%{url_effective}`n" http://localhost:8090/                       # .../public/landingpage.php
curl.exe -s http://localhost:8090/nope-xyz | findstr "isn't on the ballot"                 # branded 404 body
curl.exe -s http://localhost:8090/nope-xyz | findstr "/student/login.php"                  # 404 links resolve under php -S
Stop-Process -Id $p.Id
```
- [ ] All eight behave as commented.
- [ ] **Final commit** (only if verification produced edits): `test: fix references found by restructure verification`.

### Task 14 — Report + deferred follow-ups

Report to the user: root tree (3 files), commits made, verification results (battery, smoke exit code, browser, php -S), and the **deferred items**:

- **`guide/` reference pass** (user is editing it now): ~300 `'file' => …` entries, `'match' => ['/voting-app3/…']` route URLs, `endpoints` chips, `system.php` paths **and anchors** (e.g. `"require_once __DIR__ . '/includes/functions.php';"` — that source line changes in Task 3, so those anchors go stale), `guide/lib/source.php` `$topLevel` allowlist += `public`, `database`, `guide/index.php` iframe/`../landingpage.php`, `tour.js` hardcoded routes. Verify with `bash guide/tools/test.sh` → `unresolved: []` and `node guide/tools/qa.mjs` → PASS once the user finishes.

## Spec amendment (found during plan self-review, 2026-11-02)

The spec's Section B row `public/index.php | Location: dashboard.php → ../student/dashboard.php` is **superseded** by the `$appRoot` construction in Task 4: via `DirectoryIndex` the browser URL for the main entry is `/voting-app3/`, where `../student/dashboard.php` resolves *outside* the app (`/student/dashboard.php` → 404). Same reasoning as `404.php`'s `$base` handling. Everything else in the spec stands as written.

## Definition of Done

- Root contains only `.gitignore`, `.htaccess`, `Dockerfile` + folders.
- All tasks' Verify lines pass; `tests/http_smoke.ps1` exits 0; `php -S` battery green.
- `git log` shows the checkpoint + A–E (+ fix commits if any); `git diff` shows **zero** `guide/` changes from us.
- No assertion was weakened; no page behavior changed apart from paths.
