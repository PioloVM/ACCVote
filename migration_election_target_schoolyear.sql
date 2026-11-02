-- ============================================================
-- migration_election_target_schoolyear.sql   (PostgreSQL / Neon)
-- ============================================================
-- Phase 2 of the School Year Overhaul. Elections are run FOR an
-- upcoming school year, not the one currently in progress: the SSG/
-- DSG officers elected now take office once the next school year
-- starts. This adds elections.target_school_year_id so an election
-- can declare which year it belongs to, surfaced in the UI as a
-- "🎓 SY <label>" badge.
--
-- Important: this column is PURELY metadata. It labels an election;
-- it does NOT project students' year levels forward. A student votes
-- as whatever year level they currently are — an election held during
-- 2026-2027 that targets 2027-2028 is still voted on by today's
-- 2nd-years, not by who they will be next year. Year-level tracking
-- is handled entirely by school_year_id + account_status (see
-- migration_schoolyear.sql and admin/api/next-school-year.php).
--
-- Safe to run more than once. Run AFTER migration_schoolyear.sql.
-- ============================================================

BEGIN;

ALTER TABLE elections ADD COLUMN IF NOT EXISTS target_school_year_id INTEGER;

CREATE INDEX IF NOT EXISTS elections_target_school_year_id_idx
    ON elections (target_school_year_id);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'elections_target_school_year_id_fkey'
    ) THEN
        ALTER TABLE elections ADD CONSTRAINT elections_target_school_year_id_fkey
            FOREIGN KEY (target_school_year_id) REFERENCES school_years(id);
    END IF;
END $$;

-- ------------------------------------------------------------------
-- Backfill
-- ------------------------------------------------------------------
-- Live elections default to the UPCOMING school year, which is what
-- admin/api/elections.php's resolveTargetSchoolYearId() now does for
-- every new election.
--
-- Archived elections are deliberately left NULL. They are closed
-- historical records; back-dating them with a guessed target would
-- put a label on the record that was never actually chosen by an
-- admin, and results.php keeps showing archived elections as a
-- permanent public record. A missing badge on a closed election from
-- before this feature existed is the honest outcome.
DO $$
DECLARE
    next_start INTEGER;
    next_id   INTEGER;
BEGIN
    SELECT EXTRACT(YEAR FROM NOW())::INTEGER + 1 INTO next_start;

    INSERT INTO school_years (label, start_year, sort_order)
    VALUES (next_start::TEXT || '-' || (next_start + 1)::TEXT, next_start, next_start)
    ON CONFLICT (start_year) DO NOTHING;

    SELECT id INTO next_id FROM school_years WHERE start_year = next_start;

    UPDATE elections
       SET target_school_year_id = next_id
     WHERE target_school_year_id IS NULL
       AND status <> 'archived';
END $$;

COMMIT;

-- ============================================================
-- Verify:
--   SELECT e.id, e.name, e.status, sy.label
--     FROM elections e LEFT JOIN school_years sy ON sy.id = e.target_school_year_id
--    ORDER BY e.id;
-- Expect: every non-archived election points at next year's row;
-- archived ones show a NULL label.
-- ============================================================
