# Baseline & after load timings — voting-app2-updated

Local environment: XAMPP Apache (localhost/voting-app2-updated), MariaDB 10.4.32 (local),
PHP 8.0.30. Measured with `curl -w "%{time_total}"`, 3 runs, min–max.

## Test account (local `voting_app` DB, seeded by schema.mysql.sql)

- Student: `987654321` / `password123` (verified against stored hash)
- Other seeded students: `20230101`–`20230105`, `20260001`/`20260002` (unregistered)

## Baseline (original code, local MySQL)

Captured 2026-10-09, Task 1, before any rework code changes.
Note: with the original `.htaccess` pointing at remote Neon these same pages measured
~1,748ms (landing) / ~1,150ms (login) / ~2,427ms (results) — switching to local MySQL
alone removes that remote latency. Reference-only, from the pre-work audit.

| Page | min | max | bytes | PHP errors |
|---|---|---|---|---|
| landingpage.php | 0.016s | 0.037s | 42,659 | none |
| login.php | 0.007s | 0.020s | 18,008 | none |
| results.php | 0.059s | 0.184s | 32,536 | none |
| login POST (302) | — | 0.066s | — | none |
| dashboard.php (logged in) | 0.050s | 0.081s | 63,683 | none |
| dashboard-data.php (logged in) | 0.046s | 0.067s | 5,657 | none |

## After (rework complete)

Captured 2026-10-09, Task 12, after Tasks 1–11 (identical method: `curl -o NUL -s -w
"%{time_total}"`, 3 runs, min–max; opcache + mod_deflate/mod_expires enabled machine-side).
Percent change is vs the Baseline row above.

| Page | min (Δ) | max (Δ) | bytes | PHP errors |
|---|---|---|---|---|
| landingpage.php | 0.023s (+45%) | 0.042s (+13%) | 42,882 | none |
| login.php | 0.006s (−9%) | 0.026s (+28%) | 18,286 | none |
| results.php | 0.015s (**−75%**) | 0.022s (**−88%**) | 32,269 | none |
| login POST (302) | — | 0.077s (+17%) | — | none |
| dashboard.php (logged in) | 0.018s (**−63%**) | 0.021s (**−74%**) | 57,947 | none |
| dashboard-data.php (logged in) | 0.022s (**−52%**) | 0.028s (**−59%**) | 5,178 | none |

Notes:

- **Sub-25ms pages are noise-dominated** on this machine. landingpage.php / login.php /
  login POST were not touched by the perf tasks (their byte growth is partner churn);
  their swings are machine variance, not regressions.
- **dashboard.php −63/−74% and 5.7 KB smaller**: the heavy per-election build (positions +
  candidates per election + per-election hasVoted) no longer runs or renders on SPA loads —
  it is gated behind `page=vote` (Task 10); panel data moved to dashboard-data.php (Task 5).
- **results.php −75/−88%**: per-election + per-position N+1 queries replaced by four batched
  queries (positions IN, turnout GROUP BY, candidates IN, eligible GROUP BY department).
- **dashboard-data.php bytes 5,657 → 5,178 (−8%)**: candidate photos are URLs
  (`photo.php?c=…`) instead of base64 payloads (Task 5); `data:image` count in its output: 0.
- **Query-count reduction (qualitative)**: SPA dashboard load went from ≈2+3E queries
  (sync + user + logos + per-election positions/candidates/hasVoted) to 2 (sync + user);
  results.php from ≈2+3E+P to 6 fixed (logos ×2 + 4 batches); election status sync affects
  0 rows on a quiet system (guarded WHERE clauses).
- Compression/caching: assets now serve `Content-Encoding: gzip` +
  `Cache-Control: max-age=31536000` + `Expires +1y` (assetV versioning makes long TTLs safe).
