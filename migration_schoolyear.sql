-- ============================================================
-- migration_schoolyear.sql   (PostgreSQL / Neon)
-- ============================================================
-- Phase 1 of the School Year Overhaul. Adds the `school_years`
-- table (a school year runs roughly June -> the following May) and
-- ties each student to the year they STARTED, replacing the old
-- free-text `users.academic_year` as the source of truth.
--
-- A student's year level is no longer hand-picked: it is derived
-- from (their start year vs. the active school year) by
-- computeYearLevel() in includes/functions.php. See README.md.
--
-- Safe to run more than once — every statement is guarded.
-- Run AFTER schema.sql, BEFORE the other migrations.
-- ============================================================

BEGIN;

-- ------------------------------------------------------------------
-- 1. The school_years table
-- ------------------------------------------------------------------
-- label/start_year are unique because getOrCreateSchoolYear() looks
-- rows up by start_year and creates them on demand (registration and
-- election creation both rely on this, so no admin setup is required).
CREATE TABLE IF NOT EXISTS school_years (
    id          SERIAL PRIMARY KEY,
    label       VARCHAR(20) NOT NULL,
    start_year  INTEGER     NOT NULL,
    is_active   BOOLEAN     NOT NULL DEFAULT FALSE,
    sort_order  INTEGER     NOT NULL DEFAULT 0,
    created_at  TIMESTAMP   NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS school_years_label_key      ON school_years (label);
CREATE UNIQUE INDEX IF NOT EXISTS school_years_start_year_key ON school_years (start_year);

-- "At most ONE active school year" — a PARTIAL unique index.
--
-- The `WHERE is_active = true` clause is load-bearing. A plain unique
-- index on a boolean would only permit two rows in the entire table
-- (one true, one false), because a boolean has just two distinct
-- values. Restricting the index to the active rows enforces the
-- invariant we actually want while leaving any number of inactive
-- (historical) years in place.
--
-- MariaDB has no partial indexes — see migration_schoolyear.mysql.sql,
-- which emulates this with a generated column. Do not "simplify" this
-- to a bare unique index on is_active.
CREATE UNIQUE INDEX IF NOT EXISTS school_years_one_active
    ON school_years (is_active) WHERE is_active = true;

-- ------------------------------------------------------------------
-- 2. users.school_year_id — the year the student started
-- ------------------------------------------------------------------
ALTER TABLE users ADD COLUMN IF NOT EXISTS school_year_id INTEGER;

-- account_status is ADMIN-SET, not auto-derived. 'normal' is the only
-- value under which login.php recomputes year_level from
-- school_year_id; the repeating/undergrad/suspended values hand
-- control of year_level to the student (update-account.php), and
-- dropped/graduated block login outright.
ALTER TABLE users ADD COLUMN IF NOT EXISTS account_status VARCHAR(30);

-- Backfill before tightening to NOT NULL, so the default applies to
-- any pre-existing row rather than failing the ALTER.
UPDATE users SET account_status = 'normal' WHERE account_status IS NULL;

ALTER TABLE users ALTER COLUMN account_status SET DEFAULT 'normal';
ALTER TABLE users ALTER COLUMN account_status SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'users_account_status_check'
    ) THEN
        ALTER TABLE users ADD CONSTRAINT users_account_status_check
            CHECK (account_status IN (
                'normal',
                'repeating',
                'undergrad_repeating',
                'suspended_repeating',
                'dropped',
                'graduated'
            ));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS users_school_year_id_idx ON users (school_year_id);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'users_school_year_id_fkey'
    ) THEN
        ALTER TABLE users ADD CONSTRAINT users_school_year_id_fkey
            FOREIGN KEY (school_year_id) REFERENCES school_years(id);
    END IF;
END $$;

-- ------------------------------------------------------------------
-- 3. Backfill: derive a start year for existing students
-- ------------------------------------------------------------------
-- The current school year follows the June cutover (see
-- currentSchoolYearStartYear()): from June 1 onward it is the current
-- calendar year, before June it is the previous one.
--
-- A student's start year is then the active year minus their
-- year_level's 1-based position in year_levels. Students whose
-- year_level isn't in year_levels (or who have none) are left NULL on
-- purpose: login.php skips the lifecycle gate for a NULL
-- school_year_id, so a record we cannot reason about is never
-- silently blocked from logging in.
--
-- The position must come from ROW_NUMBER() over `ORDER BY sort_order,
-- name`, NOT from the raw sort_order value. The two are not
-- interchangeable: schema.mysql.sql seeds year_levels with sort_order
-- 1-4 while this database uses 0-3, and nothing in the app depends on
-- the base. computeYearLevel() indexes into the array built by
-- `SELECT name FROM year_levels ORDER BY sort_order, name`, so the
-- rank is the only thing that agrees with the PHP.
DO $$
DECLARE
    active_start INTEGER;
BEGIN
    SELECT EXTRACT(YEAR FROM NOW())::INTEGER
           - CASE WHEN EXTRACT(MONTH FROM NOW()) < 6 THEN 1 ELSE 0 END
      INTO active_start;

    -- Make sure the active school year itself exists, so Settings
    -- always has a row to badge as "Current" with zero manual setup.
    INSERT INTO school_years (label, start_year, sort_order)
    VALUES (active_start::TEXT || '-' || (active_start + 1)::TEXT, active_start, active_start)
    ON CONFLICT (start_year) DO NOTHING;

    -- Create every start year the existing students imply, so the FK
    -- below has somewhere to point.
    INSERT INTO school_years (label, start_year, sort_order)
    SELECT DISTINCT
           (start_year)::TEXT || '-' || (start_year + 1)::TEXT,
           start_year,
           start_year
      FROM (
            SELECT (active_start - (yl.pos - 1))::INTEGER AS start_year
              FROM users u
              JOIN (
                    SELECT name,
                           ROW_NUMBER() OVER (ORDER BY sort_order, name) AS pos
                      FROM year_levels
                   ) yl ON yl.name = u.year_level
             WHERE u.year_level IS NOT NULL
           ) implied
     WHERE start_year BETWEEN active_start - 20 AND active_start
    ON CONFLICT (start_year) DO NOTHING;

    UPDATE users u
       SET school_year_id = sy.id
      FROM (
            SELECT name,
                   ROW_NUMBER() OVER (ORDER BY sort_order, name) AS pos
              FROM year_levels
           ) yl,
           school_years sy
     WHERE yl.name = u.year_level
       AND sy.start_year = active_start - (yl.pos - 1);
END $$;

-- Exactly one active row, matching getActiveSchoolYear(). The partial
-- index guarantees uniqueness, so clear every flag before setting it.
UPDATE school_years SET is_active = FALSE WHERE is_active;
UPDATE school_years SET is_active = TRUE
 WHERE start_year = (
       SELECT EXTRACT(YEAR FROM NOW())::INTEGER
              - CASE WHEN EXTRACT(MONTH FROM NOW()) < 6 THEN 1 ELSE 0 END
 );

-- ------------------------------------------------------------------
-- 4. Retire the per-student free-text year/track columns
-- ------------------------------------------------------------------
-- "Major" and "Section" were removed in Phase 2/4: the registration
-- form, the admin Students form and the CSV template all dropped them.
-- The `majors` table itself is left in place (it is now empty and
-- unmanaged — see admin/api/settings.php) rather than dropped, to
-- avoid a destructive change on a live database.
ALTER TABLE users DROP COLUMN IF EXISTS major;
ALTER TABLE users DROP COLUMN IF EXISTS section;

COMMIT;

-- ============================================================
-- Verify:
--   SELECT * FROM school_years ORDER BY start_year;
--   SELECT student_id, year_level, school_year_id, account_status
--     FROM users ORDER BY id;
-- Expect: one is_active = true row, and every user with a recognised
-- year_level pointing at a plausible start year.
-- ============================================================
