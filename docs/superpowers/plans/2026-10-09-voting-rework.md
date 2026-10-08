# Voting Rework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the position-by-position ballot into a skippable continuous scroll with a Vote All chain, remove receipts/quick-buttons/drafts, confirm solo-candidate elections work end-to-end, and cut page load times.

**Architecture:** Approach 1 — rework the existing server-rendered ballot branch (`dashboard.php?page=vote&...`) in place; no SPA migration. Add lightweight image endpoints, a `ballot_submissions` table for abstention locks, batched SQL, and compression/caching.

**Tech Stack:** Plain PHP 8.0 (XAMPP/Apache, MySQL local), vanilla JS inline in page files, `Dockerfile` php:8.3-cli for Render. No Composer, no build step, no test framework — tests are CLI-guarded PHP scripts in `tests/` + curl against `http://localhost/voting-app2-updated`.

**Spec:** `docs/superpowers/specs/2026-10-09-voting-rework-design.md`

## Global Constraints

- Never connect local dev to Neon; `.htaccess` must run `DB_DRIVER=mysql` with `DATABASE_URL` commented (Task 1). The existing Neon DB is never modified.
- No frameworks/dependencies added; follow existing patterns (`h()` escaping, `csrfToken()`/`verifyCsrfToken()`, `jsonResponse()`, docblock style explaining *why*).
- The repo contains the partner's own pre-existing staged/modified changes. Every commit must use explicit paths (`git commit --only <files>`) and include only files the task touched.
- `.htaccess` may be gitignored — check with `git check-ignore .htaccess` before trying to commit it.
- Line numbers cited are pre-change references from the spec; re-locate by symbol name if shifted.
- The fresh-install Postgres deliverable is `schema.sql` (Postgres) — must stay table-for-table parity with `schema.mysql.sql` (enforced by `tests/schema_parity.php`).
- JS copy is exact: "N of M positions answered", "Submit your ballot? This cannot be changed afterwards.", "Cast My Vote", "Go Back", "Vote All Elections", "You're all caught up ✓", "Start Voting", "Continue Voting", "Exit", "You've completed all your elections!", sidebar label "Vote Now".

## Review Focus

1. **Empty-ballot double-submit** — a student submits an abstention, gets an error/retry, submits again: second POST must 409, never double-lock. Test in Task 4 (`tests/http_smoke.ps1`: second empty POST → 409).
2. **Vote All chain eligibility mismatch** — `vote.php`'s `next_election` must visit exactly the set `dashboard-data.php` reports votable (DSG department + year restrictions), no more, no fewer. Test in Task 7 (assert id sequence equality for the seeded student).
3. **Multi-winner regression** — after default changes, `winner_count > 1` positions must still render checkboxes, cap at N picks, and `winners > candidates` must still be rejected. Test in Task 6 (max-select disable) and Task 9 (wizard reject/accept cases).
4. **Image endpoint abuse** — bad/missing ids must return a bare 404 with no SQL/PDO/credential text, and valid responses must carry `Cache-Control`. Test in Task 3.
5. **Prelude gating starves the SPA** — moving data queries behind `if ($page === 'vote')` can strip variables the Branch B shell uses (sidebar profile). Test in Task 10: all four panels + ballot return 200 with an empty PHP error log and smaller HTML.
6. **Zero-vote / uncontested results math** — after abstentions exist, results pages must show 0% cleanly with the "Uncontested" label and no division warnings. Test in Task 9 + final walkthrough in Task 12.

---

### Task 1: Local MySQL, config switch, baseline timings

**Files:**
- Modify: `.htaccess` (lines ~12–14)
- Create: `tests/baseline-timings.md`

**Interfaces:**
- Consumes: existing `config/db.php` XAMPP fallback (`voting_app` / `root` / blank password).
- Produces: running local DB `voting_app` (imported from `schema.mysql.sql`), baseline timing table used by Task 12.

- [ ] **Step 1: Switch config to local MySQL**
In `.htaccess`: uncomment `SetEnv DB_DRIVER mysql`, comment out `SetEnv DATABASE_URL` (and its follow-line if present). Verify with `git check-ignore .htaccess` whether the file is tracked; if tracked, it will be committed in Step 5.

- [ ] **Step 2: Start MySQL and import schema**
Start the MySQL service (XAMPP). Run: `mysql -u root -e "CREATE DATABASE IF NOT EXISTS voting_app CHARACTER SET utf8mb4"` then `mysql -u root voting_app < schema.mysql.sql`.

- [ ] **Step 3: Verify a login-capable seed exists**
Run `mysql -u root voting_app -e "SELECT student_id, registration_status FROM users LIMIT 5"`. If no usable student row exists (or credentials unknown), insert one: generate a hash with `php -r "echo password_hash('testpass123', PASSWORD_DEFAULT);"` and insert a row with `student_id='202600001'`, that hash, `registration_status='registered'`, a valid `department` and `year_level` matching CHECK constraints in `schema.mysql.sql`. Record the chosen credentials in `tests/baseline-timings.md`.

- [ ] **Step 4: Capture baseline timings**
For `landingpage.php`, `login.php`, `results.php` run 3× `curl -o NUL -s -w "%{time_total}" <url>` against `http://localhost/voting-app2-updated/` and record min/max in `tests/baseline-timings.md` under `## Baseline (original code, local MySQL)`. Also note if any page shows a PHP error/SQL exception — that is schema drift to fix in Task 2.

- [ ] **Step 5: Commit**
```bash
git add tests/baseline-timings.md
git commit -m "chore: local MySQL dev config + baseline load timings" --only tests/baseline-timings.md
# plus .htaccess only if git-tracked (check-ignore said no)
```

---

### Task 2: Schema — ballot_submissions, indexes, parity test, migrations

**Files:**
- Modify: `schema.mysql.sql`, `schema.sql`
- Create: `migration_ballot_submissions.mysql.sql`, `migration_ballot_submissions.sql`
- Test: `tests/schema_parity.php`

**Interfaces:**
- Produces: table `ballot_submissions(user_id INT/FK→users.id ON DELETE CASCADE, election_id INT/FK→elections.id ON DELETE CASCADE, submitted_at TIMESTAMP, PRIMARY KEY(user_id, election_id))`; indexes `idx_votes_position(votes.position_id)`, `idx_elections_status(elections.status)` present in **both** schema files. Task 4 consumes `ballot_submissions`.

- [ ] **Step 1: Write the failing parity test**
`tests/schema_parity.php` (CLI-guarded with `if (PHP_SAPI !== 'cli') exit;`): parse `schema.mysql.sql` and `schema.sql` for `CREATE TABLE` blocks and `CREATE INDEX`/`KEY`/`INDEX` entries. Assert: (a) identical table-name sets, (b) `ballot_submissions` present in both with columns `user_id`, `election_id`, `submitted_at`, (c) `idx_votes_position` present in both, (d) `idx_elections_status` present in both. Print PASS/FAIL per assertion, exit non-zero on failure.

- [ ] **Step 2: Run it to verify it fails**
Run: `php tests/schema_parity.php` → Expected: FAIL (ballot_submissions missing, indexes missing).

- [ ] **Step 3: Add table + indexes to both schema files and write the migration pair**
Append the `ballot_submissions` DDL (FKs `ON DELETE CASCADE`, PK `(user_id, election_id)`) and the two indexes to `schema.mysql.sql` and `schema.sql`, using each file's existing dialect/style. Create `migration_ballot_submissions.mysql.sql` / `migration_ballot_submissions.sql` as idempotent guarded migrations (match the `IF NOT EXISTS`/`DO`-block style of the existing `migration_*.sql` pair).

- [ ] **Step 4: Run parity test + apply migration to local DB**
Run: `php tests/schema_parity.php` → Expected: PASS.
Run: `mysql -u root voting_app < migration_ballot_submissions.mysql.sql` then `mysql -u root voting_app -e "DESCRIBE ballot_submissions"` → table exists locally.

- [ ] **Step 5: Commit**
```bash
git add schema.mysql.sql schema.sql migration_ballot_submissions.mysql.sql migration_ballot_submissions.sql tests/schema_parity.php
git commit -m "feat: ballot_submissions table + votes/elections indexes with schema parity test" --only <those files>
```

---

### Task 3: Image endpoints (photo.php, icon.php)

**Files:**
- Create: `photo.php`, `icon.php`
- Test: `tests/http_smoke.ps1` (created here; Tasks 4–7 append assertions)

**Interfaces:**
- Produces: `GET photo.php?c=<candidate_id>` → image bytes with `Content-Type` derived from the stored data-URL prefix and `Cache-Control: public, max-age=86400`; missing/invalid → HTTP 404, empty body. `GET icon.php?e=<election_id>` → same contract for the election logo (resolve the source using the existing logo helpers in `includes/functions.php:347–400`, e.g. `electionLogoHtml()`'s lookup). Task 5 consumers use these URLs.

- [ ] **Step 1: Write the failing smoke test**
`tests/http_smoke.ps1`: `$base = "http://localhost/voting-app2-updated"`; assert with `curl -s -o NUL -w "%{http_code}"` / `-D -`:
  - `photo.php?c=1` → 200, response has `Cache-Control: public` and `Content-Type: image/`
  - `photo.php?c=999999` → 404, body does NOT match `SQLSTATE|PDO|neon|password`
  - `icon.php?e=1` → 200 or 404 (if no logo configured), never 500
Print PASS/FAIL lines, exit non-zero on any FAIL.

- [ ] **Step 2: Run it to verify it fails**
Run: `powershell -File tests/http_smoke.ps1` → Expected: FAIL (404s on `photo.php` — file not found / router 404).

- [ ] **Step 3: Implement the endpoints**
Both files: read `?c=` / `?e=` as positive int, prepared-statement SELECT, validate the value starts with `data:image/` before use; on miss → `http_response_code(404); exit;`. Decode base64 after the comma and emit with the derived MIME + `Cache-Control` header. On any exception → `error_log()` + bare 404/500, never echoing DSN/credentials (match `config/db.php` error style). Add a docblock noting these replace base64-in-JSON.

- [ ] **Step 4: Run smoke test to verify it passes**
Run: `powershell -File tests/http_smoke.ps1` → Expected: PASS.

- [ ] **Step 5: Commit**
```bash
git add photo.php icon.php tests/http_smoke.ps1
git commit -m "feat: cacheable photo/icon endpoints replacing base64 payloads" --only <those files>
```

---

### Task 4: ballot_submissions locking + skip-able positions in vote.php

**Files:**
- Modify: `vote.php` (lines 86–95 already-voted, 146–149 required check, 169–191 success path)
- Modify: `dashboard-data.php:60–69` (`userHasVotedInElection`), `dashboard.php:48–57` (branch-A `hasVoted`)
- Modify: `admin/api/students.php` (per-student vote reset must clear `ballot_submissions`)
- Test: `tests/http_smoke.ps1` (append)

**Interfaces:**
- Consumes: `ballot_submissions` (Task 2), CSRF/session/rate-limit helpers as currently used.
- Produces: `POST vote.php` response JSON = `{"success": true, "message": "...", "next_election": {"id": int, "name": string} | null, "remaining": int}`. Detection rule everywhere: **election is voted iff a `ballot_submissions` row exists**. Task 7 consumes `next_election`/`remaining`.

- [ ] **Step 1: Append failing tests to `tests/http_smoke.ps1`**
Script flow: GET `login.php`, extract `csrf_token` from the form, POST login (credentials from Task 1) with a cookie jar; GET `dashboard-data.php` → save payload.
  1. Pick first votable `election_id` from payload; POST `vote.php` with `csrf_token`, that `election_id`, **no position fields** → expect HTTP 200/`success:true` and response contains `next_election` key and `remaining` (int).
  2. POST the same again → expect 409.
  3. Assert `dashboard-data.php` now reports that election `hasVoted: true`.

- [ ] **Step 2: Run to verify it fails**
Run: `powershell -File tests/http_smoke.ps1` → Expected: FAIL at assertion 1 (current code 400s "Please select a candidate for every position").

- [ ] **Step 3: Implement vote.php changes**
- Delete the empty-selection 400 block (`vote.php:146–149`); zero-pick allowed positions are skipped.
- Already-voted check (`:86–95`): switch from counting `votes` rows to `SELECT 1 FROM ballot_submissions WHERE user_id=? AND election_id=?` → 409.
- On success (inside the transaction, before commit): `INSERT INTO ballot_submissions (user_id, election_id, submitted_at) VALUES (?,?,NOW())` — executed whether or not any votes were inserted. Keep legacy `users.has_voted=1` and the existing draft-row `DELETE`.
- Compute `next_election`/`remaining` with the same eligibility rule as `dashboard-data.php:75–105` (ongoing + date window, own-department DSG, year-restriction-compatible position exists, not in `ballot_submissions`), ordered like `dashboard.php sortedElectionIds()`; exclude the just-submitted election. Include in the success JSON.

- [ ] **Step 4: Switch all detection sites + admin reset**
Replace the `votes`-row detection in `dashboard-data.php:60–69` and `dashboard.php:48–57` with a `ballot_submissions` lookup (one prepared query each; Task 5 batches them). In `admin/api/students.php` vote-reset handler, add `DELETE FROM ballot_submissions WHERE user_id=?` alongside the existing `votes` cleanup.

- [ ] **Step 5: Run smoke test to verify it passes**
Run: `powershell -File tests/http_smoke.ps1` → Expected: all PASS (including 409 on re-submit and `hasVoted` flip).

- [ ] **Step 6: Commit**
```bash
git add vote.php dashboard-data.php dashboard.php admin/api/students.php tests/http_smoke.ps1
git commit -m "feat: skippable positions, ballot_submissions abstention lock, next_election chaining" --only <those files>
```

---

### Task 5: dashboard-data batching + URL fields + consumer updates

**Files:**
- Modify: `dashboard-data.php` (lines 60–69, 75–146, 149–169)
- Modify: `dashboard.php` JS consumers of `photo`/`icon` (candidates panel ~1684, candidate modal ~1761, results panel ~1944, home carousel ~1534–1543, election cards' `icon`)
- Test: `tests/http_smoke.ps1` (append)

**Interfaces:**
- Consumes: `photo.php?c=`, `icon.php?e=` (Task 3), `ballot_submissions` detection (Task 4).
- Produces: payload contract change — candidate objects expose `photo_url: string` (no `photo`), election objects expose `icon_url: string` (no `icon`, no `electionLogoHtml` base64). Query count becomes a fixed batch set (user, sync, submissions, settings, departments, elections, positions-IN, candidates-IN, hasVoted-IN) with **no per-election/per-position loops**.

- [ ] **Step 1: Append failing payload test**
Assert: raw JSON of `dashboard-data.php` (logged-in) contains `"photo_url"` and `"icon_url"`, contains **no** `data:image`, and total response < 50,000 bytes.

- [ ] **Step 2: Run to verify it fails**
Run: `powershell -File tests/http_smoke.ps1` → Expected: FAIL (payload still has base64).

- [ ] **Step 3: Implement batching + URL fields**
Rewrite the per-election loops as batched queries: one `SELECT ... FROM positions WHERE election_id IN (...)`, one `FROM candidates WHERE position_id IN (...)`, one has-voted query `SELECT DISTINCT ep.election_id FROM votes v JOIN election_positions ep ON ep.position_id = v.position_id WHERE v.user_id=?` merged with `ballot_submissions` ids (or select `ballot_submissions` directly — it is now authoritative per Task 4). Replace `photo`/`icon` values with `photo.php?c=<id>` / `icon.php?e=<id>` strings. Keep the docblock at the top accurate.

- [ ] **Step 4: Update JS consumers**
Replace `c.photo` / `e.icon` usages in `dashboard.php` renderers with `c.photo_url` / `e.icon_url` (`<img src>` only — no `data:` attribute copies). Ballot markup photos are handled in Task 6.

- [ ] **Step 5: Run smoke test + eyeball panels**
Run: `powershell -File tests/http_smoke.ps1` → PASS. In the browser: Candidates, Results, and home carousel all show images; no broken `<img>`.

- [ ] **Step 6: Commit**
```bash
git add dashboard-data.php dashboard.php tests/http_smoke.ps1
git commit -m "perf: batch N+1 queries, move photos/icons to cacheable URLs" --only <those files>
```

---

### Task 6: Scroll ballot rework (Branch A)

**Files:**
- Modify: `dashboard.php` Branch A (lines ~393–530 markup, 645–880 JS)
- Modify: `assets/dashboard-app.css` (`.ballot-step` show/hide ~764–767, `.ballot-nav`, receipt styles 842–851, `.ballot-header` 764)

**Interfaces:**
- Consumes: `vote.php` success JSON (Task 4), `photo.php` (Task 3).
- Produces: `#voteForm` gains `data-continuous="1"` when `$_GET['continuous']` is truthy (Task 7 reads it); confirm modal = title + message + `[Cast My Vote]`/`[Go Back]` only (no summary list); success handler branches single vs continuous (Task 7 supplies the continuous copy).

- [ ] **Step 1: Markup — remove wizard chrome**
Delete the review step block (`dashboard.php:481–505`), Save & Exit button (`:403`), and Back/Next buttons (`:474–477`). Keep the per-position foreach loop, fieldsets, radio/checkbox inputs, and draft pre-check removal: drop `data-candidate-photo` attributes and pre-fill-from-draft logic (`:418, 426, 451`). Point candidate `<img>` at `photo.php?c=`. Add `data-continuous` on `#voteForm` from `$_GET['continuous']`. Simplify `#ballotConfirmModal` to message + the two exact-copy buttons. Add the "N of M positions answered" label into `.ballot-header` beside `#ballotProgressFill`.

- [ ] **Step 2: JS — strip the step machine**
Delete `showStep`, `ballotNext`, `ballotPrev`, `ballotGoTo`, `currentStepValid`, the initial "jump to first unanswered step" block (`:868–874`), `handleBallotExitClick`, `saveDraftAndExit`, and `buildSummary`. Rewrite `updateProgress()` to answered-positions/total (count fieldsets containing a checked input; update on `change` and after load; set both bar width and label). Keep `updateDimming` per fieldset (max-select disable) wired to each fieldset's `change`. Keep the form-submit → confirm-modal → `fetch('vote.php')` flow; on success show `#ballotSuccessModal` with the server `message` (single mode — Task 7 extends for continuous); on non-OK show the server error message in the modal/status area.

- [ ] **Step 3: CSS — one continuous scroll + sticky header**
Make every `.ballot-step` visible (remove the `.active` show/hide rule), delete `.ballot-nav` and receipt styles (`.ballot-summary-sheet*`), and make `.ballot-header` `position: sticky; top: 0` with a solid background + z-index above candidate cards.

- [ ] **Step 4: Verify (browser + curl)**
Browser: all positions visible by one scroll; header stays pinned; skip everything → submit → empty abstention success; select within `winner_count` → extra options disable; leaving via sidebar (Candidates) → back to Vote shows a fresh ballot, no exit-guard popup; no receipt/review anywhere; no Save & Exit.
Curl: `curl -s "http://localhost/voting-app2-updated/dashboard.php?page=vote&election_id=<id>" -b cookies.txt` → assert no `ballotNext`, no `ballotSummaryList`, no `Save & Exit`, page has all position sections, and a rendered `<img src="photo.php?c=` (no `data:image` in the HTML).

- [ ] **Step 5: Commit**
```bash
git add dashboard.php assets/dashboard-app.css
git commit -m "feat: continuous scroll ballot with sticky progress, receipt/save-exit removed" --only <those files>
```

---

### Task 7: Vote All (home card + election chain)

**Files:**
- Modify: `dashboard.php` `renderHomePanel` (hero `1425–1492`, ticker `1554–1558`, injection `1548–1551`), submit success handler (from Task 6), `init()`/boot for `continuous` entry

**Interfaces:**
- Consumes: `vote.php` `next_election`/`remaining` (Task 4), `data-continuous` on `#voteForm` (Task 6), `canVote` predicate (`dashboard.php:1270`).
- Produces: home card markup with `#startVoteAllBtn` linking `dashboard.php?page=vote&election_id=<id>&continuous=1`; chain modals: "done" modal (Continue Voting/Exit) and final modal (You've completed all your elections! → View Results/Dashboard).

- [ ] **Step 1: Remove featured hero, add Vote All card**
Delete hero selection/render (`:1425–1492`) + countdown ticker. Add a card computing remaining count client-side from `elections` with the same `canVote` predicate; states: N left → **[Start Voting]** (first eligible id, `continuous=1`); 0 left → "You're all caught up ✓" (no button). Keep the featured-candidates carousel and quick tiles (Task 8 owns tiles).

- [ ] **Step 2: Chain modals in the submit success handler**
On `success`: if NOT `data-continuous` → current single-mode modal. If continuous and `next_election` → done-modal: `✅ You're done with the <current election name>. Next up: <next_election.name>.` with **[Continue Voting]** (navigate to `?page=vote&election_id=<id>&continuous=1`) and **[Exit]** (home). If continuous and `next_election === null` → final modal with the exact copy + [View Results] / [Dashboard]. Non-OK (e.g. 409 stale link) → error message + [Return to Dashboard].

- [ ] **Step 3: Review-Focus chain test**
Append to `tests/http_smoke.ps1`: from the login session, (a) collect votable ids from `dashboard-data.php` (`canVote` equivalent server-side: ongoing, not `hasVoted`, positions>0, dept-eligible), (b) loop: POST vote.php for first id → read `next_election.id` → assert it equals the next id in list (a) → until null; assert final `remaining == 0` and `dashboard-data.php` shows every one `hasVoted`. Reset the student's rows afterward (`DELETE FROM ballot_submissions/votes ...`) so the suite is re-runnable.
Run → Expected: PASS (fails before implementation).

- [ ] **Step 4: Verify in browser**
Start → DSG ballot → submit → done-modal names the SSG election → Continue → SSG ballot → submit → final modal → View Results. Also: Exit mid-chain ends at home; sidebar away mid-chain ends it; card restarts it.

- [ ] **Step 5: Commit**
```bash
git add dashboard.php tests/http_smoke.ps1
git commit -m "feat: Vote All continuous election chain with between-election modals" --only <those files>
```

---

### Task 8: Nav cleanup + draft remnants

**Files:**
- Modify: `dashboard.php` (quick tiles `1509–1514`, stepper `136–173` + JS `1159–1188` + uses, sidebar nav `925–930`, branch-A nav `337–342`, `$draftsByElection` `34–40`, remaining `answeredPositionIds` uses)
- Modify: `dashboard-data.php` (drafts query `52–58`, answered-merge `139–146`, payload field `167`)
- Modify: `assets/dashboard-app.css` (`.quick-action-tile*` `935–949, 990–993`)
- Test: `tests/http_smoke.ps1` (append)

**Interfaces:**
- Consumes: Task 6/7 markup (do not disturb vote-all card).
- Produces: sidebar exactly **Dashboard · Vote Now · Candidates · Results**; payload no longer contains `answeredPositionIds`.

- [ ] **Step 1: Append failing test**
Assert: `dashboard-data.php` payload has no `answeredPositionIds`; `dashboard.php` (SPA shell) contains no `quick-action-tile` and no `renderBallotProgressStepper`; sidebar text contains `Vote Now` and not `>Vote<`.

- [ ] **Step 2: Run to verify it fails**, then **Step 3: implement**
Remove quick tiles + CSS; delete `renderBallotProgressStepper` and its JS port plus all call sites (cards/hero — hero already gone); rename nav labels "Vote" → "Vote Now" in both navs; delete `$draftsByElection`, drafts query, answered-merge, and the payload field in `dashboard-data.php`.

- [ ] **Step 4: Run test to verify it passes**
Run: `powershell -File tests/http_smoke.ps1` → PASS. Grep repo: `answeredPositionIds|quick-action|renderBallotProgressStepper|draftsByElection` → zero hits outside `draft.php` docs.

- [ ] **Step 5: Commit**
```bash
git add dashboard.php dashboard-data.php assets/dashboard-app.css tests/http_smoke.ps1
git commit -m "feat: sidebar-only navigation, drafts/stepper remnants removed" --only <those files>
```

---

### Task 9: Admin — single-candidate positions

**Files:**
- Modify: `admin/dashboard.php` (candidate row seeding `1990–1993`, input default `1902`, validation highlight `2030`)

**Interfaces:**
- Consumes: existing validators (`admin/dashboard.php:2027–2033`, `admin/api/elections.php:155–160`) — server needs **no change** (already allows 1/1).
- Produces: wizard seeds 1 candidate row; errors highlight fields.

- [ ] **Step 1: Implement the three edits**
Seed new positions with 1 empty candidate row; default input `value="${p.candidates.length || 1}"`; on the `candCount < 1` message also `wizardInvalidFields.add(<same field id pattern the winner validation uses at :2032>)`.

- [ ] **Step 2: Verify end-to-end (browser)**
Create election → 1 position → 1 candidate → saves without "2 candidates" friction → start election → student ballot shows the single card → submit **with** the pick and (fresh student or reset) submit **empty** → admin Results shows position labelled **Uncontested**, percentages 0–100, no JS warnings. Negative: `winner_count=2` with 1 candidate → wizard error still blocks (Review Focus #3).

- [ ] **Step 3: Commit**
```bash
git add admin/dashboard.php
git commit -m "feat: solo-candidate positions — single-row default + field highlight fix" --only admin/dashboard.php
```

---

### Task 10: Performance core — prelude gating, results batching, guarded sync

**Files:**
- Modify: `dashboard.php` prelude (`:27–57`, `:214–290`)
- Modify: `results.php` (positions/candidates loops `:56, 76–85, 120`, turnout `:68`)
- Modify: `includes/functions.php:321–331` (`syncElectionStatuses`)
- Test: `tests/http_smoke.ps1` (append)

**Interfaces:**
- Consumes: Task 4–6 structures.
- Produces: `syncElectionStatuses()` — same external behavior, but its `UPDATE`s only match rows whose status actually changes (0 rows affected on a quiet system). Branch A keeps its full data set; Branch B keeps only session/user/light queries.

- [ ] **Step 1: Append failing test**
Assert: (a) with an election whose `start_date` is set to the past and status still `scheduled` (set via SQL), loading `landingpage.php` flips it to `ongoing`; (b) loading it twice in a row runs without error and the second load is not slower; (c) `dashboard.php` (logged-in, no `?page`) HTML does **not** contain `ballot-step` (proves Branch A markup isn't rendered in SPA mode).

- [ ] **Step 2: Implement prelude gating**
Wrap the heavy data block (elections, positions, candidates, hasVoted, votes, drafts queries) in `if ($page === 'vote' && $selectedElectionId) {...}` — after first checking which variables the Branch B HTML shell actually references (sidebar profile likely needs `$user`/settings; keep those outside the gate). Keep `syncElectionStatuses` where it is.

- [ ] **Step 3: Implement results batching + guarded sync**
`results.php`: one positions query with `IN (election ids)`, one candidates query with `IN (position ids)`, turnout via grouped counts instead of per-election `COUNT(*) FROM users`. `syncElectionStatuses`: rewrite each `UPDATE` with a `WHERE` that only matches rows needing the transition (e.g. `status='scheduled' AND start_date<=NOW()`), so quiet systems affect 0 rows.

- [ ] **Step 4: Run tests + verify no regression**
Run: `powershell -File tests/http_smoke.ps1` → PASS. Browser: all four panels + ballot render (Review Focus #5); check PHP error log is empty; `results.php` percentages unchanged vs before.

- [ ] **Step 5: Commit**
```bash
git add dashboard.php results.php includes/functions.php tests/http_smoke.ps1
git commit -m "perf: gate ballot prelude, batch results queries, no-op status sync" --only <those files>
```

---

### Task 11: Infra — compression, caching, deferred CDN, OPcache

**Files:**
- Modify: `admin/dashboard.php:701, 707` (CDN scripts), `.htaccess`, `Dockerfile`
- Delete: `_overview_check.html`
- Machine config (NOT committed): `C:\xampp\apache\conf\httpd.conf:111`, `C:\xampp\php\php.ini:958`

**Interfaces:**
- Consumes: nothing prior.
- Produces: gzip + browser caching on Apache; `zlib.output_compression` for `php -S`; OPcache on locally.

- [ ] **Step 1: CDN defer**
Add `defer` to both cdnjs `<script>` tags and `<link rel="preconnect" href="https://cdnjs.cloudflare.com">` in `admin/dashboard.php` head. Verify admin charts/export still initialize (script order is Chart.js/xlsx only consumers — check no inline script runs before them without defer-wait; if inline code references `XLSX`/`Chart` at load time, switch those call sites to `window.addEventListener('load', ...)`).

- [ ] **Step 2: .htaccess compression/caching + Dockerfile zlib**
Append to `.htaccess`: mod_deflate filter block (guard with `IfModule mod_deflate.so`) for html/css/js/json/svg, and mod_expires block (guard with `IfModule mod_expires.so`, `ExpiresByType text/css "access plus 1 year"` etc.). In `Dockerfile`'s start command add `-d zlib.output_compression=1`. Delete `_overview_check.html`.

- [ ] **Step 3: XAMPP tuning (machine, not committed)**
Uncomment `zend_extension=opcache` (`php.ini:958`) — also ensure `opcache.enable=1` — and `LoadModule deflate_module` (`httpd.conf:111`); restart Apache.

- [ ] **Step 4: Verify**
`curl -H "Accept-Encoding: gzip" -D - <base>/assets/dashboard-app.css` → `Content-Encoding: gzip` + `Expires`/`Cache-Control`; `php -i | findstr -i opcache` shows enabled; admin dashboard HTML shows `defer` on both CDN tags; `_overview_check.html` → 404.

- [ ] **Step 5: Commit**
```bash
git add admin/dashboard.php .htaccess Dockerfile
git commit -m "perf: defer CDN, gzip + cache headers, zlib compression, drop dev artifact" --only <those files>
```

---

### Task 12: Final verification — timings, walkthrough, deliverables

**Files:**
- Modify: `tests/baseline-timings.md` (append `## After` section)

- [ ] **Step 1: After-timings**
Repeat Task 1 Step 4's exact measurements; append to `tests/baseline-timings.md` with percent change. Also record `dashboard-data.php` response bytes before/after (before = from Task 5 pre-change capture if noted, else state N/A) and note query-count reduction qualitatively.

- [ ] **Step 2: Full functional walkthrough (spec §Verification plan)**
Empty abstention locks; partial submit; max-select caps; single-election success modal; Vote All full chain DSG→SSG with mid-chain Exit; selections reset on sidebar switch; no receipt post-submit anywhere; no Save & Exit; 1-candidate election end-to-end with Uncontested results; admin create/start/close + exports/charts still work; results 0% math clean (Review Focus #6).

- [ ] **Step 3: Spec coverage + repo hygiene check**
Skim spec section-by-section → each bullet maps to a completed task; `git status` shows only intended commits; grep confirms no `data:image` in `dashboard-data.php` output, no leftover receipt/stepper/quick-tile symbols; confirm `schema.sql` is the ready-to-import Neon file (parity test PASS).

- [ ] **Step 4: Report**
Append final results to the plan file (checked boxes) and summarize timings + the Neon import instructions (`schema.sql` → new Neon DB → set `DATABASE_URL` on Render).
