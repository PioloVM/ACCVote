# Plan: fix + extend tests/http_smoke.ps1 for voting-app3

Status: COMPLETE — 445 PASS / 0 FAIL (run5), verified leave-DB-as-found.

- [x] 1. Repoint $base to voting-app3; run suite; capture all failures
- [x] 2. Add election-status capture/restore around Task 4/7 fixtures (suite must leave DB as found)
- [x] 3. Fix stale assertions (CASED fixture, drifted source pins) until existing sections green
- [x] 4. New section SY1: school-year mechanics (settings years, duplicate rule 400s, new-election target year, next-school-year.php with capture/restore)
- [x] 5. New section INT1: data-integrity invariants (orphans, shared positions, vote/position mismatch, winner_count cap, join equivalence)
- [x] 6. New section API1: admin API contracts (elections numbers vs DB, students, settings, logs, admins, CSRF/method guards)
- [x] 7. New section WF1: admin election write flow (create DSG draft -> target label, status transition rules, archive/restore + audit logs, delete)
- [x] 8. New section RPT1: report noVotes pins (source-level)
- [x] 9. Full end-to-end run until green; verify DB snapshot unchanged pre/post
- [x] 10. Report results

## Notes (2026-10-30)

Run command: `powershell -NoProfile -File tests\http_smoke.ps1` from repo root. Exit 0 = pass.

The app was redesigned to a single **combined ballot** during an earlier batch
(`TODO-batch-20261029.md`): `next_election`/`remaining`, `data-continuous`,
chain/final modals, the exit-guard (`ballotExitLinks`), vote drafts, and the
admin reset-vote handler are all intentionally gone. Stale pins that asserted
those old contracts were rewritten to the current ones (`submitted_elections`,
`data-combined` + `combined_elections[]`, one submit locks every open election,
confirm-before-cast + the "can't be changed after you submit" warning). The
duplicate wall allows one SSG election per school year and one DSG election per
faculty per school year, counting archived rows — so the C1/C2/WF1 fixtures use
DSG + a department with no existing DSG election.

Leave-DB-as-found is enforced by the SUITE PRELUDE / SUITE EPILOGUE pair. The
epilogue restores elections 1-2 (status + window), the fixture student's votes,
ballot locks and `has_voted` flag, candidate photos, and the SSG logo value +
`updated_at`. It truncates `activity_logs` back to a prelude high-water id so
audit lines the suite's own maintenance/logs/SY1/WF1 sections append (written as
the real admin, not `fixture`) do not accumulate across runs. Verified by
SELECT-based per-table dumps (15 tables) compared byte-for-byte pre vs post —
all identical. (`mysqldump` and `information_schema` crash this MariaDB build,
so the dump helper uses base-table SELECTs only; `admins` is dumped without
`last_login`, which bumps on every admin login.)
