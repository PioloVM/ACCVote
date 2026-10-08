# Voting Rework — Scroll Ballot, Vote All, Receipt Removal, Single-Candidate Elections, Performance

Date: 2026-10-09
Status: Approved (design), pending spec review
Target project: `C:\xampp\htdocs\voting-app2-updated`

## Context

The school voting system (plain PHP, XAMPP/MySQL local, Neon Postgres + Render in production) currently
votes one position at a time: a step-wizard ballot that blocks advancing until a candidate is picked,
a "paper ballot receipt" review step, a featured-election hero with quick-action tiles on the student
home, and a Save & Exit draft flow. A judge load test measured ~4s page loads.

This rework makes voting optional per position, converts the ballot to a single continuous scroll,
adds an optional "Vote All" chain across eligible elections, removes receipts/drafts, confirms
solo-candidate positions work end-to-end, and substantially speeds up the site.

## Decisions (confirmed with partner)

| Decision | Resolution |
|---|---|
| Implementation approach | **Approach 1**: rework the existing server-rendered ballot branch (`dashboard.php?page=vote&election_id=X`, lines 311–886) in place. No SPA migration. |
| Empty ballot submit | **Allowed.** Zero picks anywhere = valid abstention; the election locks as voted (409 on re-vote), no `votes` rows inserted. |
| Panel switch mid-ballot | Sidebar navigation works freely (no exit-guard modal). **Selections reset on switch** — nothing persisted. |
| Drafts | Remove the Save & Exit **option** and all draft reading. No **new** draft-cleanup code; the pre-existing `DELETE FROM vote_drafts` on submit stays as-is, the `draft.php` endpoint file remains untouched, and old draft rows are simply never read again. |
| Database | Local dev uses **local MySQL** (never Neon). Deliver a validated **fresh-install Postgres schema** for a **new** Neon database after deploy. Current Neon DB must not be modified. |
| Sidebar nav | Keep **Dashboard**, rename Vote → **Vote Now**, keep **Candidates**, **Results**. |

## Part A — Student Voting Experience

### A1. Scroll ballot (rework Branch A of `dashboard.php:311–886`)

The server already renders every position into the page; the wizard JS hides all but one step.

Remove:

- Step show/hide: `showStep()`, `.ballot-step` `.active` toggling, `currentStep` state.
- `ballotNext()` / `ballotPrev()` / `ballotGoTo()` and the `currentStepValid()` gate
  ("Please select a candidate before continuing.").
- Per-step nav buttons (`dashboard.php:474–477`) and the review step (`dashboard.php:481–505`).
- Exit-guard confirm modal (`handleBallotExitClick`, `dashboard.php:776–788`).

Add/change:

- All positions + candidates render as **one continuous scroll**. Existing card markup stays
  (radio group per position when `winner_count = 1`, checkbox when `> 1`).
- **Sticky header**: `.ballot-header` becomes `position: sticky; top: 0`, containing the progress
  bar (`#ballotProgressFill`) + label **"N of M positions answered"**.
  Progress = positions with ≥1 selection ÷ total positions.
- **Max-select enforcement retained**: at `winner_count` picks, remaining options in that position
  disable (existing `updateDimming` behavior, now bound per position rather than per step).
- **Submit at the end**: button after the last position opens `#ballotConfirmModal`, simplified to
  *"Submit your ballot? This cannot be changed afterwards."* → **[Cast My Vote]** / **[Go Back]**.
  **No summary of picks** (receipt requirement).
- Success modal (`#ballotSuccessModal`) shows only the server message (single-election flow:
  [View Results] / [Return to Dashboard]).

### A2. Skipping (`vote.php`)

- **Delete** the all-positions-required rejection (`vote.php:146–149`, "Please select a candidate for
  every position"). Positions with zero picks are skipped; a fully empty ballot submits and locks
  the election as voted (`users.has_voted = 1`, no `votes` rows).
- Retain: login/POST/CSRF/rate-limit guards, `syncElectionStatuses`, ongoing+date window check,
  DSG department eligibility, already-voted 409, year-restriction filter, per-position
  `winner_count` cap, and candidate-belongs-to-position validation for any picks provided.
- Retain existing draft-row cleanup on submit (`DELETE FROM vote_drafts`, `vote.php:186–187`) —
  it is pre-existing behavior, not a new feature.
- **Response gains chaining data** (computed from the same eligibility rule as
  `dashboard-data.php:75–105`): `next_election: {id, name} | null`, `remaining: int`.

### A3. Vote All (Home, `renderHomePanel` `dashboard.php:1411–1561`)

- Remove the featured-election hero + countdown (`dashboard.php:1425–1492`, ticker `1554–1558`).
- Add a **"Vote All Elections"** card: *"You have X elections left"* → **[Start Voting]** →
  `dashboard.php?page=vote&election_id=<first>&continuous=1`.
- Eligibility chain (same as A2): status `ongoing`, within date window, DSG department-eligible,
  year-restriction-compatible, not yet voted, ≥1 position.
- **Between elections** (continuous mode only): on submit success the modal reads
  *"✅ You're done with the <current election name>. Next up: <next election name>."*
  → **[Continue Voting]** (→ next ballot URL, `continuous=1` preserved) / **[Exit]** (→ home).
- **Final election**: *"You've completed all your elections!"* → **[View Results]** / **[Dashboard]**.
- Single-election flow (no `continuous` flag): current plain success modal unchanged.
- Leaving the chain (sidebar navigation, logout) ends it; the card can restart it.
- Nothing left to vote: card renders *"You're all caught up ✓"*, no button.
- `vote.php`'s `next_election` is authoritative; the client does not compute eligibility.

### A4. Navigation cleanup

- Remove quick-action tiles (`dashboard.php:1509–1514`) + `.quick-action-tile` CSS
  (`assets/dashboard-app.css:935–949, 990–993`).
- Sidebar (`dashboard.php:925–930`): **Dashboard · Vote Now · Candidates · Results**
  ("Vote" renamed to "Vote Now"; branch-A nav links `dashboard.php:337–342` renamed to match).
- Remove the position dot-stepper (`renderBallotProgressStepper`, `dashboard.php:136–173`; JS port
  `1159–1188`) and its uses on home cards/hero — a partial-draft concept that no longer exists.
- Home election-card CTAs ("Vote Now"/"View Candidates" on each card) remain — they are content
  links, not quick buttons.

### A5. Receipt & drafts removal

- Delete review step, `buildSummary()` (`dashboard.php:705–745`), and receipt CSS
  (`.ballot-summary-sheet*`, `dashboard-app.css:842–851`).
- Verify during implementation that **no post-submit screen reveals the student's picks**
  (results/candidates panels and success modal must not highlight the user's own selections).
- Remove Save & Exit button (`dashboard.php:403`) + `saveDraftAndExit()` (`802–826`), draft
  pre-fill of the ballot (`dashboard.php:418, 426, 451`), `$draftsByElection` (`34–40`), and the
  `answeredPositionIds` merge + field in `dashboard-data.php` (`139–146, 167`).
- `draft.php` stays on disk, unused; draft rows stay in the DB, unread.

## Part B — Admin, Database, Performance

### B1. Single-candidate / single-winner positions

The 2-candidate minimum does **not** exist in code — validation already permits 1/1 at both
`admin/dashboard.php:2027–2033` and `admin/api/elections.php:155–160`; tally code already guards
uncontested positions (admin `computeWinners`, "Uncontested" label at `admin/dashboard.php:3226`;
`results.php` percentage guards). Changes:

- New positions seed **1** candidate row instead of 2 (`admin/dashboard.php:1990–1993`).
- Candidate-count input default `value="${p.candidates.length || 2}"` → `|| 1` (`admin/dashboard.php:1902`).
- Fix missing `wizardInvalidFields.add('cand_...')` on the "needs at least 1 candidate" error
  (`admin/dashboard.php:2030`) so the field highlights.
- End-to-end verification: wizard save 1/1 → start election → student ballot single card →
  submit 0 or 1 pick → results show "Uncontested" with safe percentages.

### B2. Databases

**Local (dev/testing):**

- `.htaccess`: uncomment `SetEnv DB_DRIVER mysql`, comment out `SetEnv DATABASE_URL` so local
  requests never reach Neon (config falls back to `voting_app` / root / blank password in
  `config/db.php`).
- Start XAMPP MySQL (currently down), create database `voting_app`, import `schema.mysql.sql`.
- Render runs `php -S` via `Dockerfile` and ignores `.htaccess`; production continues to read
  `DATABASE_URL` from Render's environment. The local switch cannot affect the deploy.

**Neon deliverable:**

- A validated **fresh-install Postgres schema** file for a new Neon database: audit `schema.sql`
  against actual code usage for drift, add the B3 indexes, verify table-for-table parity with
  `schema.mysql.sql`, and provide it as the import file for post-deploy setup. The existing Neon
  database is never touched.

### B3. Performance (ordered by impact)

1. **Eliminate the double query set**: gate `dashboard.php:1–292` data prelude to the ballot branch
   only (`$page === 'vote'`); Branch B relies solely on the SPA's `dashboard-data.php` fetch.
2. **Batch N+1 queries**: `dashboard-data.php` `7 + Σ(P+2)` queries → ~7 via `IN (...)` batches
   (all positions, all candidates, per-election has-voted via grouped join). Same treatment for the
   ballot page's position/candidate loads and `results.php` (including per-election
   `COUNT(*) FROM users`, converted to grouped counts).
3. **Remove base64 payloads from JSON**: new `photo.php?c=<candidate_id>` endpoint for candidate
   photos and `icon.php?e=<election_id>` for the per-election logo (`config.icon`), both with
   cache headers; `dashboard-data.php` emits `photo_url` / `icon_url` instead of inline base64.
   Remove the now-unused `data-candidate-photo` duplication on the ballot.
4. **Guard status sync**: rewrite `syncElectionStatuses()` (`includes/functions.php:321–331`) from
   unconditional `UPDATE`s to guarded statements that affect 0 rows when nothing changed.
5. **Defer admin CDN JS**: `defer` + `preconnect` for SheetJS and Chart.js
   (`admin/dashboard.php:701, 707`).
6. **Compression/caching**: `.htaccess` gains mod_deflate + mod_expires rules for assets (Apache
   local and any Apache deploy); Dockerfile/`php -S` gets `zlib.output_compression`; enable
   **OPcache** and **mod_deflate** in local XAMPP config (`php.ini:958`, `httpd.conf:111`).
7. **Indexes** in both schema files: `votes.position_id`, `elections.status`.
8. Remove the dev artifact `_overview_check.html` from the webroot.

## Out of scope

- RBAC, password reset, CSP, CI/PHPUnit, framework migration, splitting the monolithic files,
  any change to the production Neon database, admin-panel feature work beyond B1.

## Verification plan

- Functional walkthrough: skip-all submit (abstention locks election), partial submit, max-select
  caps, single-election flow, Vote All full chain across DSG→SSG with mid-chain Exit, panel-switch
  reset, receipt absence post-submit, Save & Exit absence, 1-candidate election end-to-end.
- **Before/after page timings** (local, MySQL) for landing, login, dashboard, results; report
  numbers. Payload sizes for `dashboard-data.php` before/after.
- Admin wizard regression: create/edit/start/close an election; exports and charts still work.
- Confirm no request from local dev hits Neon (verified by DB_DRIVER=mysql + observed query log /
  timing).
