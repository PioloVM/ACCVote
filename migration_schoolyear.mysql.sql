-- ============================================================
-- migration_schoolyear.mysql.sql   (MySQL / MariaDB — XAMPP)
-- ============================================================
-- MariaDB counterpart of migration_schoolyear.sql. Same end state,
-- same order, same intent — see that file for the full rationale.
--
-- Load it with:
--   C:\xampp\mysql\bin\mysql.exe -u root voting_app < migration_schoolyear.mysql.sql
--
-- Safe to run more than once. Run AFTER schema.mysql.sql, BEFORE the
-- other migrations.
-- ============================================================

-- ------------------------------------------------------------------
-- 1. The school_years table
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_years (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    label       VARCHAR(20) NOT NULL,
    start_year  INT NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 0,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- label/start_year are unique because getOrCreateSchoolYear() looks
-- rows up by start_year and creates them on demand.
CREATE UNIQUE INDEX school_years_label_key      ON school_years (label);
CREATE UNIQUE INDEX school_years_start_year_key ON school_years (start_year);

-- "At most ONE active school year."
--
-- Postgres gets this from a partial unique index
-- (... WHERE is_active = true). MariaDB has no partial indexes, so
-- this emulates one with a VIRTUAL generated column that collapses
-- every inactive row to NULL, then a plain unique index on that
-- column. MySQL/MariaDB exempt NULLs from unique constraints, so any
-- number of inactive years coexist while only one active row is
-- permitted — the same invariant, portably.
ALTER TABLE school_years
    ADD COLUMN active_guard TINYINT
        GENERATED ALWAYS AS (IF(is_active = 1, 1, NULL)) VIRTUAL;

CREATE UNIQUE INDEX school_years_one_active ON school_years (active_guard);

-- ------------------------------------------------------------------
-- 2. users.school_year_id + users.account_status
-- ------------------------------------------------------------------
-- account_status is ADMIN-SET. 'normal' is the only value under which
-- login.php recomputes year_level from school_year_id; the repeating /
-- undergrad / suspended values hand control of year_level to the
-- student, and dropped / graduated block login outright.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS school_year_id INT NULL,
    ADD COLUMN IF NOT EXISTS account_status VARCHAR(30) NOT NULL DEFAULT 'normal';

-- MariaDB 10.2+ enforces CHECK constraints.
ALTER TABLE users
    ADD CONSTRAINT users_account_status_check
        CHECK (account_status IN (
            'normal',
            'repeating',
            'undergrad_repeating',
            'suspended_repeating',
            'dropped',
            'graduated'
        ));

CREATE INDEX users_school_year_id_idx ON users (school_year_id);

ALTER TABLE users
    ADD CONSTRAINT users_school_year_id_fkey
        FOREIGN KEY (school_year_id) REFERENCES school_years(id);

-- ------------------------------------------------------------------
-- 3. Backfill: derive a start year for existing students
-- ------------------------------------------------------------------
-- The current school year follows the June cutover (see
-- currentSchoolYearStartYear()): from June 1 onward it is the current
-- calendar year, before June it is the previous one.
--
-- A student's start year is the active year minus their year_level's
-- 1-based POSITION in year_levels. Students whose year_level is not in
-- year_levels (or who have none) are left NULL on purpose: login.php
-- skips the lifecycle gate for a NULL school_year_id, so a record we
-- cannot reason about is never silently blocked.
--
-- The position must come from ROW_NUMBER() over `ORDER BY sort_order,
-- name`, NOT from the raw sort_order value. The two are not
-- interchangeable: schema.mysql.sql seeds year_levels with sort_order
-- 1-4 while the production database uses 0-3, and nothing in the app
-- depends on the base. computeYearLevel() indexes into the array built
-- by `SELECT name FROM year_levels ORDER BY sort_order, name`, so the
-- rank is the only thing that agrees with the PHP.
SET @active_start = YEAR(CURDATE()) - IF(MONTH(CURDATE()) < 6, 1, 0);

-- Make sure the active school year itself exists, so Settings always
-- has a row to badge as "Current" with zero manual setup (and so the
-- partial-unique index has exactly one row to point at).
INSERT IGNORE INTO school_years (label, start_year, sort_order)
VALUES (CONCAT(@active_start, '-', @active_start + 1), @active_start, @active_start);

-- Create every start year the existing students imply.
INSERT IGNORE INTO school_years (label, start_year, sort_order)
SELECT DISTINCT
       CONCAT(implied.start_year, '-', implied.start_year + 1),
       implied.start_year,
       implied.start_year
  FROM (
        SELECT (@active_start - (yl.pos - 1)) AS start_year
          FROM users u
          JOIN (
                SELECT name,
                       ROW_NUMBER() OVER (ORDER BY sort_order, name) AS pos
                  FROM year_levels
               ) yl ON yl.name = u.year_level
         WHERE u.year_level IS NOT NULL
       ) AS implied
 WHERE implied.start_year BETWEEN @active_start - 20 AND @active_start;

UPDATE users u
  JOIN (
        SELECT name,
               ROW_NUMBER() OVER (ORDER BY sort_order, name) AS pos
          FROM year_levels
       ) yl ON yl.name = u.year_level
  JOIN school_years sy ON sy.start_year = @active_start - (yl.pos - 1)
   SET u.school_year_id = sy.id;

-- Exactly one active row, matching getActiveSchoolYear(). The unique
-- index on active_guard guarantees only one can be set, so clear every
-- flag before setting the right one.
UPDATE school_years SET is_active = 0;
UPDATE school_years SET is_active = 1 WHERE start_year = @active_start;

-- ------------------------------------------------------------------
-- 4. Retire the per-student free-text track columns
-- ------------------------------------------------------------------
-- "Major" and "Section" were removed in Phase 2/4: the registration
-- form, the admin Students form and the CSV template all dropped them.
-- The `majors` table is left in place (now empty and unmanaged) rather
-- than dropped, to keep this non-destructive on a live database.
ALTER TABLE users DROP COLUMN IF EXISTS major;
ALTER TABLE users DROP COLUMN IF EXISTS section;

-- ============================================================
-- Verify:
--   SELECT * FROM school_years ORDER BY start_year;
--   SELECT student_id, year_level, school_year_id, account_status
--     FROM users ORDER BY id;
-- ============================================================
