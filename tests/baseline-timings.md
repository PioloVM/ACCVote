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

(To be filled in by Task 12 with the identical method.)
