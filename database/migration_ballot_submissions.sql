-- ============================================================
-- migration_ballot_submissions.sql   (PostgreSQL — Neon)
-- ============================================================
-- MariaDB counterpart: migration_ballot_submissions.mysql.sql. Same end
-- state, same order, same intent — see that file for the full rationale.
--
-- Load it with psql against the target database:
--   psql "$DATABASE_URL" -f migration_ballot_submissions.sql
--
-- Safe to run more than once. Run AFTER schema.sql.
-- ============================================================

CREATE TABLE IF NOT EXISTS ballot_submissions (
    user_id      INTEGER NOT NULL,
    election_id  INTEGER NOT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, election_id),
    CONSTRAINT ballot_submissions_user_id_fkey
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT ballot_submissions_election_id_fkey
        FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_votes_position   ON votes (position_id);
CREATE INDEX IF NOT EXISTS idx_elections_status ON elections (status);
