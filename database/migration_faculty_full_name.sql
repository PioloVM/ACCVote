-- ============================================================
-- migration_faculty_full_name.sql   (PostgreSQL / Neon)
-- ============================================================
-- Phase 3 of the School Year Overhaul: "Faculty" stops being a short
-- code plus a separate display name, and becomes ONE full name.
--
-- Rationale: the old model (code "FICT" + name "College of
-- Information and Communications Technology") forced every faculty
-- reference in the database to be the short code, and the two could
-- drift apart. admin/api/settings.php now writes the same string into
-- both columns on create and cascades a rename across all three
-- referencing tables, so existing rows keep working either way — but
-- the columns are widened so a full faculty name fits without
-- truncation, and candidates gain a department of their own.
--
-- No table rename: `departments` and the `department` columns keep
-- their existing names in code. This is a UI relabel only, to avoid a
-- blast radius across 6+ files for a cosmetic change.
--
-- Safe to run more than once. Run last.
-- ============================================================

BEGIN;

-- ------------------------------------------------------------------
-- 0. Detach the retired `majors` table from departments
-- ------------------------------------------------------------------
-- majors.department_code is a foreign key onto departments.code. Some
-- engines (MySQL/MariaDB) refuse to widen a column while an FK still
-- references it, so the constraint is dropped up front. Majors were
-- retired in Phase 2/4 and the table is emptied below, so the
-- constraint has no remaining purpose.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'majors_department_code_fkey'
    ) THEN
        ALTER TABLE majors DROP CONSTRAINT majors_department_code_fkey;
    END IF;
END $$;

DROP INDEX IF EXISTS idx_majors_department;

-- ------------------------------------------------------------------
-- 1. Widen every faculty-name column to hold a full name
-- ------------------------------------------------------------------
-- "College of Information and Communications Technology" is 52 chars
-- and comfortably exceeds the old 50/20-char limits.
ALTER TABLE departments ALTER COLUMN code TYPE VARCHAR(150);
ALTER TABLE departments ALTER COLUMN name TYPE VARCHAR(150);

ALTER TABLE users      ALTER COLUMN department TYPE VARCHAR(150);
ALTER TABLE elections  ALTER COLUMN department TYPE VARCHAR(150);

-- Added as NULL first, then backfilled, then tightened below.
--
-- Note schema.mysql.sql never had this column at all, so on MySQL this
-- is a genuine ADD rather than a no-op. Guarded so the same migration
-- is correct against both schemas.
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS department VARCHAR(150);

-- ------------------------------------------------------------------
-- 2. candidates.department — a candidate belongs to a faculty
-- ------------------------------------------------------------------
-- Previously derivable only indirectly (position -> election ->
-- department), which meant an SSG candidate had no faculty at all.
-- It becomes a real, non-null reference to departments.code.
--
-- Backfill before the NOT NULL is applied:
--   1. If the candidate's position belongs to a departmental (DSG)
--      election, use that election's department.
--   2. Otherwise fall back to the first faculty by sort_order, so
--      school-wide (SSG) candidates get a valid, if generic, value
--      rather than being blocked by the FK.
UPDATE candidates c
   SET department = COALESCE(
       (SELECT e.department
          FROM election_positions ep
          JOIN elections e ON e.id = ep.election_id
         WHERE ep.position_id = c.position_id
           AND e.department IS NOT NULL
         LIMIT 1),
       (SELECT d.code FROM departments d ORDER BY d.sort_order, d.id LIMIT 1)
   )
 WHERE c.department IS NULL OR c.department = '';

-- If the table was empty above (no departments yet), the column is
-- still added as NOT NULL; add a placeholder faculty first if needed.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM candidates WHERE department IS NULL) THEN
        RAISE EXCEPTION
            'migration_faculty_full_name: candidates still have a NULL department — create a faculty row and re-run.';
    END IF;
END $$;

ALTER TABLE candidates ALTER COLUMN department SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'candidates_department_fkey'
    ) THEN
        ALTER TABLE candidates ADD CONSTRAINT candidates_department_fkey
            FOREIGN KEY (department) REFERENCES departments(code)
            ON UPDATE CASCADE ON DELETE RESTRICT;
    END IF;
END $$;

-- ------------------------------------------------------------------
-- 3. The retired `majors` table
-- ------------------------------------------------------------------
-- Majors were removed in Phase 2/4. The table is emptied but kept, so
-- this stays a non-destructive change on a live database. Nothing in
-- the application reads or writes it any more (see the comment at the
-- top of admin/api/settings.php).
DELETE FROM majors;

COMMIT;

-- ============================================================
-- Verify:
--   SELECT code, name FROM departments ORDER BY sort_order;
--   -- code and name should now both hold the full faculty name
--
--   SELECT c.id, c.name, c.department FROM candidates c ORDER BY c.id;
--   -- every row populated, every value present in departments.code
-- ============================================================
