# Folder Restructure — voting-app3 (design)

**Date:** 2026-11-02 (approved in session)
**Status:** approved in chat; spec written for review
**Repo:** `C:\xampp\htdocs\voting-app3`

## Goal

Reorganize the project so the root folder contains **only folders** (plus the
three tooling files that physically must stay), with:

1. all migration/SQL files in one folder,
2. all student-related files in `student/`,
3. every code reference, test, workflow, and doc that points at moved files
   updated so the app does not break.

Explicit user constraint: **do not make mistakes — this might break the app.**
A second constraint added mid-session: **`guide/` is being edited by the user
and is out of scope for now** (deferred follow-up).

## Approved decisions

| # | Decision | Choice |
|---|---|---|
| 1 | Layout | `student/` + `public/` + `database/` |
| 2 | URL strategy | Move + fix all references + **old→new 302 redirects** in both `.htaccess` and `router.php` |
| 3 | `guide/` | **Untouched for now** (user is editing it); guide references updated in a deferred pass |
| 4 | Root exceptions | `.htaccess` (Apache docroot), `.gitignore` (git), `Dockerfile` (Render build context) stay — tooling requires them |
| 5 | Judgment calls | `photo.php`/`icon.php` → `student/`; `router.php` → `config/`; `README.md` + `TODO-batch-20261029.md` → `docs/` |

### Rationale for judgment calls

- **`photo.php`/`icon.php` → `student/`**: `dashboard-data.php` emits bare
  relative URLs (`photo.php?c=…`, `icon.php?e=`) that the browser resolves
  against the *page* URL (`/student/dashboard.php`). Same-folder placement
  makes these correct with **zero edits**. Grep proves their only consumers
  are `dashboard.php` and `dashboard-data.php`.
- **`router.php` → `config/`**: it is server configuration for the `php -S`
  runtime (sibling of `db.php`), not page content. Dockerfile CMD updated.
- **README/TODO → docs/**: the root must be folders-only; README is the
  living doc (paths updated), TODO is a historical log (content untouched).

## Target tree

```
voting-app3/
├── .htaccess · .gitignore · Dockerfile      ← stay (tooling requires root)
├── student/
│   ├── register.php          (already there)
│   ├── login.php · logout.php
│   ├── dashboard.php · dashboard-data.php
│   ├── vote.php · draft.php
│   ├── update-account.php · change-password.php
│   └── photo.php · icon.php
├── public/
│   ├── index.php · landingpage.php · results.php
│   ├── health.php
│   └── 404.php
├── database/
│   ├── schema.sql · schema.mysql.sql
│   └── migration_*.sql        (11 files, incl. migration_neon.sql)
├── config/
│   ├── db.php                 (untouched)
│   └── router.php             (moved; root logic → dirname(__DIR__))
├── guide/                     (stays; references updated in deferred pass)
├── admin/                     (untouched except admin/login.php link)
├── includes/ · assets/        (contents untouched; only callers updated)
├── docs/                      (+ README.md, TODO-batch-20261029.md moved in)
├── word-docs/ · tests/ · tools/ · .github/   (stay; path refs updated)
```

## Reference updates (excluding `guide/`)

### A. PHP includes in moved pages (14 files)

`__DIR__ . '/includes/…'` → `'/../includes/…'` and `'/config/db.php'` →
`'/../config/db.php'` in every moved page under `student/` and `public/`.
No change for `admin/*` and `student/register.php` (already `../`).

`config/router.php` root logic: `__DIR__` → `dirname(__DIR__)` for the app
root; `/` requires `public/index.php`; 404 requires `public/404.php`.

### B. Redirects and links

| File | Edit |
|---|---|
| `public/index.php` | `Location: dashboard.php` → `../student/dashboard.php` (landing target unchanged, same folder) |
| `student/logout.php` | `Location: index.php` → `../public/index.php` |
| `student/dashboard.php` | line 40: `Location: index.php` → `../public/index.php` |
| `student/update-account.php` | line 53: `Location: index.php` → `../public/index.php` |
| `student/register.php` | `Location: ../dashboard.php` → `dashboard.php` (now same folder) |
| `includes/functions.php` | error-block `href="index.php"` → `../public/index.php`; `assetV()` gains optional `$prefix = ''` param (filesystem stat stays root-anchored; emitted URL gets prefix) |
| `student/login.php` | `student/register.php` → `register.php` (×2); `landingpage.php` → `../public/landingpage.php`; `assets/*` → `../assets/*` |
| `public/landingpage.php` | `dashboard/logout/login.php` → `../student/…`; `student/register.php` → `../student/register.php`; `assets/*` → `../assets/*` |
| `public/results.php` | `dashboard/login.php` → `../student/…`; `assets/*` → `../assets/*` |
| `admin/login.php` | `../login.php` → `../student/login.php` (the only admin edit) |
| `student/dashboard.php` | `assets/default-avatar.svg` ×2 in JS → `../assets/…`; `assetV('assets/…')` ×4 → `assetV('assets/…', '../')` |

**Verified no-edit (do not "fix"):**

- `requireLogin()`'s relative `Location: login.php` — only student pages call
  it (grep-verified: dashboard, change-password, dashboard-data,
  update-account) → resolves to `/student/login.php` ✓
- `requireAdminLogin()`'s `Location: login.php` — admin-only callers ✓
- `fetch('vote.php')`, `photo_url`/`icon_url` JSON, `admin/*` fetches —
  same-folder after the move ✓
- `login.php`'s own `Location: dashboard.php` / `update-account.php` —
  same-folder targets ✓ (also keeps the guide annotation anchors at
  `guide/data/annotations.php:1547,1583` valid — good news for the
  deferred guide pass)

### C. Infra

| File | Edit |
|---|---|
| `.htaccess` | `ErrorDocument 404 /voting-app3/public/404.php` (direct — must serve content, never a redirect); add `DirectoryIndex public/index.php`; add redirect rules (below); schema comment → `database/schema.mysql.sql` |
| `config/router.php` | redirect map + root logic (above) |
| `Dockerfile` | CMD → `/app/config/router.php` |
| `.github/workflows/ci.yml` | JS-guard loop → `student/dashboard.php admin/dashboard.php` |
| `.github/workflows/uptime.yml` | probe → `$SITE_URL/public/health.php` (**mandatory**: that curl has no `-L`, a 302 would file a false incident) |

### D. Tests

- `tests/schema_parity.php`: `__DIR__ . '/../schema…'` → `'/../database/schema…'`
- `tests/http_smoke.ps1`: ~94 reference updates — every `$base/*.php` URL to
  its `student/`/`public/` path, and local paths (`.\dashboard.php`,
  `.\login.php`, `.\404.php`, `.\router.php`, `.\schema*.sql`, `.\health.php`,
  `.\vote.php`, `.\change-password.php`, `.\dashboard-data.php`, …) including
  `Test-Path` checks.
- `tests/baseline-timings.md`, `tests/PLAN-audit-suite.md`: living path
  references updated where they state runnable commands.

### E. Docs

- `README.md` → `docs/README.md` with import commands and structure updated
  (`< schema.mysql.sql` → `< database/schema.mysql.sql`, etc.)
- `config/db.php` doc comment: import path → `database/schema.mysql.sql`
- `TODO-batch-20261029.md` → `docs/` (content untouched — historical log)

## Redirect design

### `.htaccess` (path relative to `/voting-app3/`)

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    # Old root URLs → new locations. 302 (not 301): browsers cache 301
    # aggressively; the suite hits new URLs directly anyway.
    RewriteRule ^(login|logout|dashboard|dashboard-data|vote|draft|update-account|change-password|photo|icon)\.php$ student/$1.php [R=302,L]
    RewriteRule ^(index|landingpage|results|404|health)\.php$ public/$1.php [R=302,L]
</IfModule>
DirectoryIndex public/index.php
ErrorDocument 404 /voting-app3/public/404.php
```

No collisions: `student/login.php` and `admin/login.php` do not match
`^login\.php$`. Query strings pass through (no `?` in substitution).

### `config/router.php` (production `php -S`, domain root)

Same map as a `$oldMap` array keyed by root-relative path →
`header('Location: /' . $new, true, 302); return true;` before the
file-existence check. App root for the file check and the `/` + 404 requires
becomes `dirname(__DIR__)`.

### Why this is safe

Pages only ever render at new URLs (redirect fires before render), so every
relative link resolves against the new base. Any reference missed by the
edits still resolves through the redirect instead of 404ing. Both runtimes
keep their existing "keep in sync" convention.

## Verification plan

1. **Checkpoint commit first** (`git add -A; git commit`) — freezes all
   uncommitted work (modified `dashboard.php` etc., untracked `guide/`,
   `word-docs/`, migrations). Rollback = `git reset --hard <sha>`.
2. Move files (filesystem moves; git records renames by content).
3. Apply reference updates (A–E).
4. **Static:** `php -l` over every PHP file (mirrors CI) ·
   `php tests/schema_parity.php` → PASS · `node --check` on both dashboards'
   extracted JS · scripted require-target existence check · grep audits (no
   stale `'/includes` in moved files, no bare `assets/` hrefs, no old
   `$base/*.php` in the smoke suite, no wrong-depth `Location:`).
5. **HTTP smoke:** `powershell -File tests/http_smoke.ps1` → exit 0
   (requires local XAMPP Apache + MariaDB).
6. **Browser pass:** `/` → landing · login → dashboard · photos/logos load ·
   `/login.php` → 302 → page works · guide frame still loads.
7. **`php -S` rehearsal:** `php -S localhost:8090 config/router.php`, curl
   old + new URLs — proves the production path before Render sees it.
8. **Deferred:** `guide/` reference pass + `bash guide/tools/test.sh`
   (`unresolved: []`) + `node guide/tools/qa.mjs` (PASS) once the user
   finishes editing `guide/`.

## Out of scope

- `guide/` reference updates (deferred — user is editing it now)
- `word-docs/` (binary), historical specs/plans, `.superpowers/`
- Any visual/behavioural change to pages (pure relocation + reference fix)

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Missed relative reference → broken asset/link | Redirect safety net + grep audits + full smoke suite |
| `assetV()` stat breaks if call-site path changes | Keep filesystem path root-anchored; add `$prefix` only to the emitted URL |
| Uptime probe files false incident (no `-L`) | Workflow probe URL updated to `/public/health.php` |
| `ErrorDocument` via redirect would not serve content | Points directly at `public/404.php` |
| Uncommitted user work lost | Checkpoint commit before any move |
| Guide anchors reference edited source lines | Guide pass deferred; guide's own validator (`test.sh`) proves resolution |
| POST through 302 edge case | All form actions are same-folder relative → new URLs directly; redirects only as fallback |

## Amendment (2026-11-02, found during plan self-review)

Section B's row `public/index.php | Location: dashboard.php → ../student/dashboard.php` is **superseded**: via `DirectoryIndex` the browser URL for the main entry (`/`) is the *directory* URL, so `../student/dashboard.php` resolves outside the app (`/student/dashboard.php` → 404). `public/index.php` is reachable from two different base URLs (`/voting-app3/` and `/voting-app3/public/index.php`), so it must compute an app-root-absolute target from `SCRIPT_NAME` — the same pattern already required for `404.php` (strip a trailing `/public`, else fall back to `''` for the domain-root runtime). The implementation plan's Task 4 carries the exact code. All other rows stand.

## Pinned assumptions (grep-verified during design)

- `requireLogin()` is called only from pages that end up in `student/`.
- `photo.php`/`icon.php` are consumed only by `dashboard.php` and
  `dashboard-data.php`.
- Production `php -S` runs at the domain root (Render), so router redirect
  targets are `/student/…`, `/public/…`.
- XAMPP has `mod_rewrite` + `AllowOverride All` enabled (default).
