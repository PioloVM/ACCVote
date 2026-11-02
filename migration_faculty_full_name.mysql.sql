-- ============================================================
-- migration_faculty_full_name.mysql.sql   (MySQL / MariaDB)
-- ============================================================
-- MariaDB counterpart of migration_faculty_full_name.sql. Same end
-- state and intent — see that file for the full rationale.
--
-- Safe to run more than once. Run last.
-- ============================================================

-- ------------------------------------------------------------------
-- 0. Detach the retired `majors` table from departments
-- ------------------------------------------------------------------
-- majors.department_code is a foreign key onto departments.code, and
-- MySQL/MariaDB refuse to widen a column while an FK still references
-- it (ERROR 1833). Majors were retired in Phase 2/4 and the table is
-- emptied below, so the constraint has no remaining purpose. Dropping
-- it is also why this migration is listed as running LAST.
ALTER TABLE majors DROP FOREIGN KEY majors_department_code_fkey;
DROP INDEX IF EXISTS idx_majors_department ON majors;

-- ------------------------------------------------------------------
-- 1. Widen every faculty-name column to hold a full name
-- ------------------------------------------------------------------
-- "College of Information and Communications Technology" is 52 chars
-- and comfortably exceeds the old 50/20-char limits.
ALTER TABLE departments MODIFY COLUMN code VARCHAR(150) NOT NULL;
ALTER TABLE departments MODIFY COLUMN name VARCHAR(150) NOT NULL;

ALTER TABLE users      MODIFY COLUMN department VARCHAR(150) NULL;
ALTER TABLE elections  MODIFY COLUMN department VARCHAR(150) NULL;

-- Added as NULL first, then backfilled, then tightened below.
--
-- Note the Postgres schema has always had this column but
-- schema.mysql.sql never did — so this is a genuine ADD on MySQL and a
-- no-op there. Guarded so the migration is correct against both.
ALTER TABLE candidates
    ADD COLUMN IF NOT EXISTS department VARCHAR(150) NULL;

-- ------------------------------------------------------------------
-- 2. candidates.department — a candidate belongs to a faculty
-- ------------------------------------------------------------------
-- Previously derivable only indirectly (position -> election ->
-- department), which meant an SSG candidate had no faculty at all.
-- It becomes a real, non-null reference to departments.code.
--
-- Backfill before the FK is applied:
--   1. If the candidate's position belongs to a departmental (DSG)
--      election, use that election's department.
--   2. Otherwise fall back to the first faculty by sort_order, so
--      school-wide (SSG) candidates get a valid, if generic, value
--      rather than being blocked by the FK.
UPDATE candidates c
  LEFT JOIN (
        SELECT ep.position_id, e.department
          FROM election_positions ep
          JOIN elections e ON e.id = ep.election_id
         WHERE e.department IS NOT NULL
  ) src ON src.position_id = c.position_id
   SET c.department = COALESCE(
           src.department,
           (SELECT d.code FROM departments d ORDER BY d.sort_order, d.id LIMIT 1)
       )
 WHERE c.department IS NULL OR c.department = '';

-- A candidate whose position is in no election at all still needs a
-- value. Guard explicitly so the FK error below is actionable.
SELECT CASE
         WHEN EXISTS (SELECT 1 FROM candidates WHERE department IS NULL OR department = '')
           THEN 'ERROR: candidates still have an empty department — create a faculty row and re-run.'
         ELSE 'OK: every candidate has a department'
       END AS backfill_check;

ALTER TABLE candidates MODIFY COLUMN department VARCHAR(150) NOT NULL;

ALTER TABLE candidates
    ADD CONSTRAINT candidates_department_fkey
        FOREIGN KEY (department) REFERENCES departments(code)
        ON UPDATE CASCADE ON DELETE RESTRICT;

-- ------------------------------------------------------------------
-- 3. The retired `majors` table
-- ------------------------------------------------------------------
-- Majors were removed in Phase 2/4. The table is emptied but kept, so
-- this stays a non-destructive change on a live database. Nothing in
-- the application reads or writes it any more (see the comment at the
-- top of admin/api/settings.php).
DELETE FROM majors;

-- ============================================================
-- Verify:
--   SELECT code, name FROM departments ORDER BY sort_order;
--   SELECT c.id, c.name, c.department FROM candidates c ORDER BY c.id;
-- ============================================================
