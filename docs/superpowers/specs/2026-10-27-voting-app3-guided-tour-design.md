# Guided Tour for voting-app3 — Design

**Date:** 2026-10-27
**Status:** Awaiting approval
**Owner:** Software engineer delivering the thesis project
**Audience:** Thesis students (client) + defense panel

## 1. Intent

The client owns a working PHP/MySQL voting system but cannot explain it, and
"it looks vibe-coded" is a defense risk. Build a **guided code tour** that sits
on top of the real app: every meaningful element is clickable and answers three
questions on the spot:

1. **What does this do?** — plain-English behavior.
2. **Where does it live?** — exact file, function/section, and line range.
3. **What is the actual code?** — the real source, read live from disk.
4. **How do I defend it?** — a one-line talk track for the panel.

The tour must make the app feel intentional and teachable, not change how the
app works.

## 2. Goals / non-goals

### Goals
- Faithful to the actual app: live pages, real data, real source. No invented
  code or stale copies.
- Beginner-friendly default layer, with technical depth one click away.
- Cover the full system: public site, student portal, ballot, live results,
  admin panel, database, APIs, security, deployment, and defense Q&A.
- Work as a presentation aid: guided mode, deep links, progress, printable
  defense cheat sheet.
- Degrade gracefully if the live app or database is unavailable.

### Non-goals
- Rewriting, refactoring, or "fixing" voting-app3.
- Replacing the thesis manuscript or the developer documentation checklist.
- A hosted public product. This is a local handoff/demo tool.
- AI chat or runtime content generation.

## 3. Constraints

- App is PHP 8 + MariaDB on XAMPP at `http://localhost/voting-app3/`.
- `.htaccess` sets `X-Frame-Options: SAMEORIGIN` and CSP
  `frame-ancestors 'self'`, so a guide under `/voting-app3/guide/` can frame the
  app and read its DOM (same origin). This is load-bearing.
- No build step: vanilla PHP, CSS, JS. No npm runtime dependencies.
- Student dashboard and admin panel render panels client-side, so annotations
  must re-scan on DOM mutations and after async data loads.
- Demo data is stale: all elections are currently closed, so the live ballot and
  vote flow need a dedicated tour demo election (approved separately).
- The guide reads source files. It must not become a source-disclosure hole if
  the project is ever deployed.

## 4. Approaches considered

| Approach | Trade-off | Decision |
|---|---|---|
| **A. Live annotated mirror** — frame the real app, overlay hotspots, read source server-side | Always truthful, least duplication, needs Apache/MySQL | Chosen as the primary mode |
| **B. Static replica** — captured screens with hotspots | Offline-stable but drifts from code | Rejected as primary; used only as fallback imagery |
| **C. Hybrid** — live mirror + offline screen fallbacks + system chapters | Most complete handoff, more build | **Chosen** (user selection) |

## 5. Experience

### 5.1 Shell
A full-viewport "case file" workspace in the app's civic identity (navy, ballot
paper, lime, gold) with a distinct editorial design:

- **Top bar:** brand, chapter selector, **Inspect/Browse** toggle, device width
  (Desktop / Tablet / Mobile), **Demo access** menu (Student / Admin / Log out),
  search (Ctrl/Cmd+K), help.
- **Left rail (collapsible):** chapters grouped as *Start Here, Public, Student,
  Admin, System, Defense Kit*, each with steps and completion state.
- **Stage:** the live app in a browser-chrome frame. Same-origin iframe.
- **Marker layer:** numbered lime markers on annotated elements, repositioned on
  scroll/resize/DOM change. Hover shows a label; click opens the inspector.
- **Inspector drawer (right, 380–420px):** tabs for *What it does*, *Code*,
  *Connections*, *Defense*. Header shows category, `file:line`, copy-path.
  Code is syntax-highlighted, numbered, expandable (±40 lines), with the exact
  resolved range. On mobile the drawer becomes a bottom sheet.
- **Bottom bar:** step position, prev/next, "Follow the tour" toggle.

### 5.2 Modes
- **Guided tour:** each step navigates the iframe, scrolls the target into view,
  opens its annotation, and offers Next. Progress saved in `localStorage`.
- **Inspect:** clicks are captured on annotated elements and open the drawer;
  unknown elements fall back to the closest annotated ancestor or the page's
  source file. Toggle off for normal app use.
- **Browse:** the app behaves normally inside the frame.
- **Offline fallback:** if the iframe fails or the DB is unavailable, show the
  recorded screen image plus the annotation list (code still works).

### 5.3 Demo access
"Login as Student" / "Login as Admin" navigate the iframe to the real login
pages, fill the demo credentials through the parent (same origin), and submit the
real form. No auth changes in the app. Verified credentials:

- Student — `987654321` / `password123` (`Juan Dela Cruz`, active)
- Admin — `admin` / `admin123` (superadmin)

Credentials are shown and labeled local-demo-only. If an existing session
belongs to the other role, the guide logs out first.

## 6. Content map

### Start Here
What the system is, who uses it, tech stack, repo map, how to run, demo accounts,
how the tour works, and a 60-second architecture overview.

### Public
Landing page (nav, hero, CTAs, ticker stats, current elections, candidate
marquee, footer) and public results (`results.php`), annotated down to the SQL
and status helpers.

### Student
Login (CSRF, rate limit, password verify, lifecycle gate, session
regeneration), registration, account activation, update-year-level; dashboard
shell (sidebar, profile, theme), home progress, election cards, vote-all;
ballot (header, progress, position step, candidate selection, max-select logic,
submit confirmation, `vote.php` transaction and validation); candidates; live
results polling.

### Admin
Login and session gate; dashboard stats and charts; election management and
wizard; students (CRUD, CSV, reset password, pagination); results
(overview/detail/reports/export); archive; logs and throttle unlock; settings
(school years, faculty, year levels, logos, admins, maintenance); next-school-year
rollover.

### System
Architecture and request lifecycle; auth/session flow; vote transaction flow;
school-year lifecycle; database explorer (15 tables + relationships + SQL);
API reference (`admin/api/*.php`, `dashboard-data.php`, `vote.php`, `photo.php`,
`icon.php`, `health.php`); security controls (CSRF, prepared statements,
password hashing, rate limits, security headers, session policy, uploads);
testing/CI; deployment (XAMPP and Docker/Render).

### Defense Kit
Likely panel questions with answers, a click-by-click demo script, glossary, and
"known limitations / future work". Printable view.

Target ≈60 annotations, each with: plain-English behavior, technical note,
1–3 real code snippets, DB/API connections, and a defense line.

## 7. Data and source-of-truth

Annotations live in `guide/data/annotations.php` (PHP array → JSON in the page).
Each code reference stores **`file` + `anchor` + before/after window** instead of
hard-coded line numbers. The server resolves the anchor in the current file at
request time, returns the real range and line numbers. This keeps the tour
truthful if code shifts.

`guide/api/snippet.php`:
- Local-only by default (`REMOTE_ADDR` loopback or `GUIDE_ALLOW_REMOTE=1`).
- Whitelisted extensions: `php, js, css, sql, md, json, html`.
- Realpath must stay inside the project root; deny `.git`, `.env`, backups,
  uploaded binaries, and anything under `guide/screens`.
- Max file size and max snippet lines; returns JSON with escaped content.
- Supports anchor lookup, line range, and "expand" requests.

## 8. Demo data decision: database stays untouched

The user declined the demo-election write. No database rows will be created,
updated, or deleted. Consequences and replacements:

- The ballot UI is shown through the app's existing admin preview route:
  `dashboard.php?page=vote&election_id=<id>&preview=1`. It renders the exact
  ballot with voting disabled, requiring no data change.
- A recorded ballot-preview snapshot is included as the offline fallback.
- The vote submission flow (`vote.php`, transaction, validation, locking) is
  taught through annotated source code and a step-by-step flow diagram, not by
  casting a real ballot.
- Closed/stale elections are labeled honestly in the tour as existing demo data;
  the guide does not pretend they are live.
- No reset or removal SQL is needed.

## 9. Offline and CDN resilience

The framed app loads Chart.js and SheetJS from cdnjs plus Google Fonts. The guide
does not edit app files. Instead:

- After each iframe load, the guide checks for `window.Chart` / `window.XLSX`
  where those screens need them.
- If missing (offline venue), the stage shows a non-blocking banner explaining
  that charts/exports need internet, with a link to the recorded screen snapshot.
- The guide's own UI ships no CDN dependencies and works fully offline; only the
  framed app's charts/exports degrade.

## 10. File layout

```
voting-app3/guide/
  index.php                shell, injects annotation data
  config.php               local-only gate + project root
  api/snippet.php          safe source resolver
  api/validate.php         dev-only anchor validation report
  data/annotations.php     chapters, steps, annotations
  data/system.php          architecture/DB/API/security content
  assets/tour.css          design system + layout
  assets/tour.js           iframe engine, markers, drawer, search, tour
  assets/highlight.js      small dependency-free syntax highlighter
  screens/*.webp           fallback screenshots (incl. ballot preview)
  README.md                how to run, demo creds, safety notes
docs/superpowers/specs/2026-10-27-voting-app3-guided-tour-design.md
```

No existing app file or database row is modified.

## 11. Security

- Local-only guide by default; remote access requires an explicit env opt-in.
- Source API: whitelist + realpath containment + extension/size limits; never
  lists directories; never reads `.git`, env, or uploaded files.
- Demo credentials visibly labeled as local-only; guide README warns against
  deploying the guide publicly.
- All snippet content rendered with `textContent` (no HTML injection).
- No new third-party runtime dependencies; no CDN required.

## 12. Verification

1. `php -l` every new PHP file.
2. Anchor validation: every code reference resolves; zero unresolved.
3. Snippet API tests: valid file, unknown file, traversal attempt, denied
   extension, oversized request.
4. Headless Chrome QA at 1440×900 and 390×844: tour loads, markers position,
   inspect click opens drawer, code renders, demo login works, chapters navigate,
   search works, no console errors.
5. Live-app checks: landing, student login, dashboard, admin ballot preview,
   results, admin login and all seven panels.
6. Fallback check: simulate app unavailable; snapshot + annotation list renders.
7. Human demo run: one full guided pass, start to defense kit.

## 13. Risks

- **Stale demo elections** — labeled honestly; the tour uses the admin ballot
  preview and never fabricates live results.
- **Selector drift** — anchors keep code truthful; selectors can drift, so the
  engine falls back to text/aria selectors and records unresolved markers in the
  dev validation report.
- **Iframe auth state** — guide shows a "log in as demo student" banner when the
  expected screen is gated.
- **Large inline source** — snippets are fetched on demand, not embedded.
- **Performance** — vanilla JS, lazy images, debounced repositioning.

## 14. Build order

1. Shell, design system, navigation, local gate.
2. Annotation engine + snippet API + highlighter + search.
3. Public/Auth/Student annotations (including ballot and vote handler).
4. Admin annotations.
5. System chapters + database/API explorers.
6. Defense kit + guided tour + fallback screenshots.
7. Full QA pass + README.

## 15. Acceptance criteria

- Opening `/voting-app3/guide/` shows the tour shell and live landing page.
- Every annotated element opens a drawer with accurate behavior, file path,
  resolved line numbers, real code, connections, and a defense line.
- Demo Student/Admin buttons reach the correct real screens.
- Inspect/Browse, device widths, chapters, guided steps, search, copy path, and
  expandable code all work; state survives refresh.
- System chapters explain architecture, all core tables, endpoints, security,
  and flows; defense kit is printable.
- Offline fallback works.
- No app file or database row is modified.
- All verification steps pass; no console errors at 1440 and 390 widths.
