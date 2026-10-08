-- ============================================================
-- migration_ballot_submissions.mysql.sql   (MySQL / MariaDB — XAMPP)
-- ============================================================
-- Adds the ballot_submissions table and the two performance indexes:
--
--   * ballot_submissions — one row per (student, election) ballot that
--     was SUBMITTED, including empty ballots (abstentions). With every
--     position skippable, a submitted ballot can insert zero rows into
--     `votes`, so `votes` alone can no longer answer "did this student
--     vote here?". PRIMARY KEY (user_id, election_id) is both the lock
--     and the 409 on re-submit.
--   * idx_votes_position — MariaDB/InnoDB already indexes FK columns,
--     but the explicit name keeps both dialects identical.
--   * idx_elections_status — status is filtered on every request
--     (sync + dashboard + results), previously unindexed.
--
-- Load it with:
--   C:\xampp\mysql\bin\mysql.exe -u root voting_app < migration_ballot_submissions.mysql.sql
--
-- Safe to run more than once. Run AFTER schema.mysql.sql.
-- ============================================================

CREATE TABLE IF NOT EXISTS ballot_submissions (
    user_id INT NOT NULL,
    election_id INT NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, election_id),
    CONSTRAINT ballot_submissions_user_id_fkey FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT ballot_submissions_election_id_fkey FOREIGN KEY (election_id)
        REFERENCES elections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_votes_position   ON votes (position_id);
CREATE INDEX IF NOT EXISTS idx_elections_status ON elections (status);
