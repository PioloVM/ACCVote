-- ============================================================
-- NEON (PostgreSQL) MIGRATION - voting-app3
-- ============================================================
-- Fresh install for a Neon Postgres database. Creates the FULL
-- schema (all 16 tables, indexes, and constraints) with NO data,
-- then seeds exactly one row: the admin account (admin / admin123).
--
-- Apply with (psql):
--   psql $DATABASE_URL -f migration_neon.sql
-- or paste into the Neon SQL Editor and run once.
--
-- The app connects via DATABASE_URL (see config/db.php):
--   postgres://USER:PASSWORD@HOST/DBNAME?sslmode=require
--
-- Admin login is verified with PHP password_verify() against
-- admins.password_hash. The seeded hash below is a PASSWORD_DEFAULT
-- (bcrypt, cost 10) hash of admin123, verified with
--   password_verify("admin123", <hash>) === true.
-- ============================================================

BEGIN;

-- ============================================================
-- TABLE: activity_logs
-- ============================================================
-- Also used for student self-declaration history: a year-level update
-- made from update-account.php is written with admin_username =
-- 'student:<student_id>', which is how the once-per-school-year
-- cooldown is enforced without an extra column.
CREATE TABLE activity_logs (
    id            SERIAL PRIMARY KEY,
    admin_username VARCHAR(50),
    action         TEXT NOT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_activity_logs_created ON activity_logs (created_at);

-- ============================================================
-- TABLE: admins
-- ============================================================
CREATE TABLE admins (
    id            SERIAL PRIMARY KEY,
    username      VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email         VARCHAR(100),
    role          VARCHAR(20) DEFAULT 'admin',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login    TIMESTAMP
);

-- ============================================================
-- TABLE: departments  (labelled "Faculty" throughout the UI)
-- ============================================================
-- `code` and `name` are kept as separate columns purely to avoid a
-- rename across 6+ files, but they now always hold the SAME full
-- faculty name: the admin enters one field (admin/api/settings.php
-- writes the name into both), and a rename cascades to every table
-- that references the code. See migration_faculty_full_name.sql.
CREATE TABLE departments (
    id         SERIAL PRIMARY KEY,
    code       VARCHAR(150) NOT NULL UNIQUE,
    name       VARCHAR(150) NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    logo       TEXT
);

-- ============================================================
-- TABLE: school_years
-- ============================================================
-- A school year runs roughly June -> the following May. Students point
-- at the year they STARTED (users.school_year_id) and their year level
-- is derived from it, so nobody hand-picks "3rd Year" any more.
CREATE TABLE school_years (
    id         SERIAL PRIMARY KEY,
    label      VARCHAR(20) NOT NULL UNIQUE,
    start_year INTEGER NOT NULL UNIQUE,
    is_active  BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- "At most ONE active school year" -- a PARTIAL unique index.
--
-- The `WHERE is_active = true` clause is load-bearing. A plain unique
-- index on a boolean would permit only two rows in the entire table
-- (one true, one false), because a boolean has just two distinct
-- values. Restricting the index to active rows enforces the invariant
-- we actually want while leaving any number of inactive (historical)
-- years in place.
--
-- MariaDB has no partial indexes, so migration_schoolyear.mysql.sql
-- emulates this with a generated column. Do not "simplify" this to a
-- bare unique index on is_active.
CREATE UNIQUE INDEX school_years_one_active
    ON school_years (is_active) WHERE is_active = TRUE;

-- ============================================================
-- TABLE: users
-- ============================================================
-- account_status is ADMIN-SET, not auto-derived:
--   normal                 year_level is recomputed from school_year_id
--                          on every login (the only automatic value)
--   repeating              student-managed year level
--   undergrad_repeating    student-managed year level
--   suspended_repeating    student-managed year level
--   dropped                cannot log in
--   graduated              cannot log in
--
-- `major` and `section` were retired in Phase 2/4 and no longer exist.
CREATE TABLE users (
    id                  SERIAL PRIMARY KEY,
    student_id          VARCHAR(20) NOT NULL UNIQUE,
    name                VARCHAR(100) NOT NULL,
    password            VARCHAR(255),
    department          VARCHAR(150),
    year_level          VARCHAR(20),
    has_voted           INTEGER NOT NULL DEFAULT 0,
    registration_status VARCHAR(20) DEFAULT 'unregistered',
    academic_year       VARCHAR(10),
    email               VARCHAR(100),
    school_year_id      INTEGER REFERENCES school_years(id),
    account_status      VARCHAR(30) NOT NULL DEFAULT 'normal',
    password_reset_pending BOOLEAN NOT NULL DEFAULT FALSE,
    CONSTRAINT users_account_status_check CHECK (account_status IN (
        'normal','repeating','undergrad_repeating',
        'suspended_repeating','dropped','graduated'))
);
CREATE INDEX users_school_year_id_idx ON users (school_year_id);

-- ============================================================
-- TABLE: positions
-- ============================================================
CREATE TABLE positions (
    id    SERIAL PRIMARY KEY,
    title VARCHAR(50) NOT NULL
);

-- ============================================================
-- TABLE: year_levels
-- ============================================================
-- The label source for every year-level reference in the app. The
-- POSITION of a row in `ORDER BY sort_order, name` is what matters
-- (1st, 2nd, 3rd, 4th) -- not the sort_order value itself, which may
-- start at 0 or 1 depending on how the rows were created.
CREATE TABLE year_levels (
    id         SERIAL PRIMARY KEY,
    name       VARCHAR(20) NOT NULL UNIQUE,
    sort_order INTEGER NOT NULL DEFAULT 0
);

-- ============================================================
-- TABLE: elections
-- ============================================================
-- target_school_year_id is PURELY metadata: it labels which school year
-- the election is FOR (the SY badge). It never projects a student's
-- year level forward -- students vote as whatever level they are now.
CREATE TABLE elections (
    id                    SERIAL PRIMARY KEY,
    name                  VARCHAR(100) NOT NULL,
    type                  VARCHAR(10) NOT NULL,
    department            VARCHAR(150),
    status                VARCHAR(20) DEFAULT 'draft',
    start_date            TIMESTAMP NOT NULL,
    end_date              TIMESTAMP NOT NULL,
    results_visibility    VARCHAR(20) DEFAULT 'after',
    parties_enabled       BOOLEAN DEFAULT FALSE,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    parties               JSONB DEFAULT '[]',
    target_school_year_id INTEGER REFERENCES school_years(id),
    CONSTRAINT elections_results_visibility_check
        CHECK (results_visibility IN ('always','after','never')),
    CONSTRAINT elections_status_check
        CHECK (status IN ('draft','scheduled','ongoing','paused','closed','archived')),
    CONSTRAINT elections_type_check
        CHECK (type IN ('SSG','DSG'))
);
CREATE INDEX idx_elections_status ON elections (status);
CREATE INDEX elections_target_school_year_id_idx ON elections (target_school_year_id);

-- ============================================================
-- TABLE: candidates
-- ============================================================
-- `course` is retained for historical rows but is no longer shown or
-- selected anywhere (removed from the UI in Phase 4). `department` is
-- a real reference to departments.code.
CREATE TABLE candidates (
    id             SERIAL PRIMARY KEY,
    position_id    INTEGER NOT NULL,
    photo          TEXT,
    course         VARCHAR(100),
    candidate_year VARCHAR(20),
    platform       TEXT,
    party          VARCHAR(100),
    name           VARCHAR(100) NOT NULL,
    department     VARCHAR(150) NOT NULL,
    CONSTRAINT candidates_position_id_fkey
        FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
    CONSTRAINT candidates_department_fkey
        FOREIGN KEY (department) REFERENCES departments(code)
        ON UPDATE CASCADE ON DELETE RESTRICT
);
CREATE INDEX idx_candidates_position ON candidates (position_id);

-- ============================================================
-- TABLE: election_positions
-- ============================================================
CREATE TABLE election_positions (
    id               SERIAL PRIMARY KEY,
    election_id      INTEGER NOT NULL,
    position_id      INTEGER NOT NULL,
    winner_count     INTEGER DEFAULT 1,
    candidate_limit  INTEGER,
    year_restriction VARCHAR(20),
    CONSTRAINT election_positions_election_id_fkey
        FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE,
    CONSTRAINT election_positions_position_id_fkey
        FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
    CONSTRAINT election_positions_election_position
        UNIQUE (election_id, position_id)
);
CREATE INDEX idx_election_positions_position ON election_positions (position_id);

-- ============================================================
-- TABLE: login_attempts
-- ============================================================
CREATE TABLE login_attempts (
    id            SERIAL PRIMARY KEY,
    identifier    VARCHAR(150) NOT NULL,
    attempted_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_login_attempts_identifier_time ON login_attempts (identifier, attempted_at);

-- ============================================================
-- TABLE: majors  (RETIRED -- kept empty and unmanaged)
-- ============================================================
-- Majors were removed in Phase 2/4. The table is intentionally left in
-- place but nothing in the application reads or writes it any more
-- (see the header comment in admin/api/settings.php). It is kept
-- rather than dropped so applying this file stays non-destructive.
CREATE TABLE majors (
    id              SERIAL PRIMARY KEY,
    department_code VARCHAR(20) NOT NULL,
    name            VARCHAR(100) NOT NULL,
    sort_order      INTEGER NOT NULL DEFAULT 0
);

-- ============================================================
-- TABLE: year_levels label helper is above; site_settings below
-- ============================================================
-- Generic key/value store. Holds the site-wide SSG logo (ssg_logo).
CREATE TABLE site_settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- TABLE: vote_drafts  ("save for later" -- never counted as a vote)
-- ============================================================
CREATE TABLE vote_drafts (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL,
    election_id INTEGER NOT NULL,
    selections  JSONB NOT NULL DEFAULT '{}',
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT vote_drafts_user_id_fkey
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT vote_drafts_election_id_fkey
        FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE,
    CONSTRAINT vote_drafts_user_id_election_id UNIQUE (user_id, election_id)
);

-- ============================================================
-- TABLE: votes
-- ============================================================
-- One row per (voter, candidate). Note the unique constraint is on
-- (user_id, candidate_id), NOT (user_id, position_id): the database
-- cannot stop one voter picking two different candidates for the same
-- position. That rule is enforced in vote.php, which caps selections
-- at the position's winner_count.
CREATE TABLE votes (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER NOT NULL,
    candidate_id INTEGER NOT NULL,
    position_id  INTEGER NOT NULL,
    voted_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT votes_user_id_fkey
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT votes_candidate_id_fkey
        FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
    CONSTRAINT votes_position_id_fkey
        FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
    CONSTRAINT votes_user_id_candidate UNIQUE (user_id, candidate_id)
);
CREATE INDEX idx_votes_candidate ON votes (candidate_id);
CREATE INDEX idx_votes_position ON votes (position_id);

-- ballot_submissions: one row per (student, election) ballot that was
-- SUBMITTED — including empty ballots (abstentions). With every position
-- now skippable, a submitted ballot can insert zero rows into `votes`,
-- so `votes` alone can no longer answer "did this student vote here?".
-- This table is the authoritative per-election lock: vote.php inserts
-- here inside the voting transaction; all already-voted detection reads
-- here. PRIMARY KEY (user_id, election_id) = at most one submission per
-- student per election, which is what makes a re-submit 409.
CREATE TABLE ballot_submissions (
    user_id      INTEGER NOT NULL,
    election_id  INTEGER NOT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, election_id),
    CONSTRAINT ballot_submissions_user_id_fkey
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT ballot_submissions_election_id_fkey
        FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
);

COMMIT;

-- ============================================================
-- Sanity check after loading — every line should return one row:
--
--   SELECT count(*) FROM school_years WHERE is_active;         -- 0 or 1
--   SELECT indexdef FROM pg_indexes
--     WHERE indexname = 'school_years_one_active';            -- must include
--                                                              -- WHERE is_active
--   SELECT column_name FROM information_schema.columns
--     WHERE table_name = 'users' AND column_name IN
--           ('school_year_id','account_status','major','section');
--                                                              -- 2 rows, not 4
-- ============================================================
-- ============================================================
-- SEED: single admin account (admin / admin123)
-- ============================================================
-- Everything above is pure DDL - no other data is inserted.
-- Delete this block for a strictly empty production database.
INSERT INTO admins (username, password_hash, email, role) VALUES
    ('admin',
     '$2y$10$MTX5RHnN2DmiKXfWfb.v2OTx1d2tUyOvmow2wJ/HHqXf2H9VjSLFm',
     'admin@acc.edu.ph',
     'superadmin')
ON CONFLICT (username) DO NOTHING;

