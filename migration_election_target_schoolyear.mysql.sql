-- ============================================================
-- migration_election_target_schoolyear.mysql.sql   (MySQL / MariaDB)
-- ============================================================
-- MariaDB counterpart of migration_election_target_schoolyear.sql.
-- Same end state and intent — see that file for the full rationale.
--
-- Safe to run more than once. Run AFTER migration_schoolyear.mysql.sql.
-- ============================================================

ALTER TABLE elections
    ADD COLUMN IF NOT EXISTS target_school_year_id INT NULL;

CREATE INDEX elections_target_school_year_id_idx
    ON elections (target_school_year_id);

ALTER TABLE elections
    ADD CONSTRAINT elections_target_school_year_id_fkey
        FOREIGN KEY (target_school_year_id) REFERENCES school_years(id);

-- ------------------------------------------------------------------
-- Backfill
-- ------------------------------------------------------------------
-- Live elections default to the UPCOMING school year, which is what
-- admin/api/elections.php's resolveTargetSchoolYearId() now does for
-- every new election.
--
-- Archived elections are deliberately left NULL. They are closed
-- historical records; back-dating them with a guessed target would
-- stamp a label on the record that no admin ever actually chose, and
-- results.php keeps showing archived elections as a permanent public
-- record. A missing badge on a closed election that predates this
-- feature is the honest outcome.
SET @next_start = YEAR(CURDATE()) + 1 - IF(MONTH(CURDATE()) < 6, 1, 0);

INSERT IGNORE INTO school_years (label, start_year, sort_order)
VALUES (CONCAT(@next_start, '-', @next_start + 1), @next_start, @next_start);

UPDATE elections e
  JOIN school_years sy ON sy.start_year = @next_start
   SET e.target_school_year_id = sy.id
 WHERE e.target_school_year_id IS NULL
   AND e.status <> 'archived';

-- ============================================================
-- Verify:
--   SELECT e.id, e.name, e.status, sy.label
--     FROM elections e LEFT JOIN school_years sy ON sy.id = e.target_school_year_id
--    ORDER BY e.id;
-- ============================================================
