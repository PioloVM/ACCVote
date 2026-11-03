-- ============================================================
-- VOTING SYSTEM - MYSQL / MARIADB SCHEMA (XAMPP)
-- ============================================================
-- Current schema for local XAMPP development. This file is the
-- POST-migration shape: it already includes everything the three
-- school-year migrations add (school_years, users.school_year_id,
-- users.account_status, elections.target_school_year_id, the
-- widened faculty columns and candidates.department), so a fresh
-- install needs NO migrations.
--
-- Use this file for a NEW database. To bring an EXISTING database up
-- to date instead, run the migrations in order:
--     migration_schoolyear.mysql.sql
--     migration_election_target_schoolyear.mysql.sql
--     migration_faculty_full_name.mysql.sql
-- (The Postgres equivalents are the same names without .mysql.)
--
-- Includes DEMO SEED DATA so a fresh install is immediately explorable:
--   4 faculties, 4 year levels, 5 school years
--   20 students - guided-tour demo login: 987654321 / password123 (Demo Account)
--   1 admin     - admin panel: admin / admin123
--   1 SSG election (ongoing), 1 DSG election (upcoming),
--   1 DSG election (ended, with tallies so Results has data)
--
-- Load it with:
--   C:\xampp\mysql\bin\mysql.exe -u root < schema.mysql.sql
--
-- To reset to an empty database instead, delete everything below the
-- "DEMO SEED DATA" marker near the end of this file.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS voting_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE voting_app;

-- ============================================================
-- TABLE: activity_logs
-- ============================================================
DROP TABLE IF EXISTS activity_logs;
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_username VARCHAR(50) NULL,
    action TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TABLE: admins
-- ============================================================
DROP TABLE IF EXISTS admins;
CREATE TABLE admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) NULL,
    role VARCHAR(20) DEFAULT 'admin',
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TABLE: departments  (labelled "Faculty" throughout the UI)
-- ============================================================
-- `code` and `name` are kept as separate columns purely to avoid a
-- rename across 6+ files, but they now always hold the SAME full
-- faculty name: the admin enters one field (admin/api/settings.php
-- writes the name into both), and a rename cascades to every table
-- that references the code. See migration_faculty_full_name.mysql.sql.
DROP TABLE IF EXISTS departments;
CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(150) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    logo LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TABLE: positions
-- ============================================================
DROP TABLE IF EXISTS positions;
CREATE TABLE positions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TABLE: school_years
-- ============================================================
-- A school year runs roughly June -> the following May. Students point
-- at the year they STARTED (users.school_year_id) and their year level
-- is derived from it, so nobody hand-picks "3rd Year" any more.
--
-- `active_guard` + its unique index enforce "at most one active school
-- year" on engines with no partial indexes. Every inactive row
-- collapses to NULL, and NULLs are exempt from a unique constraint, so
-- any number of historical years can coexist. Postgres uses a partial
-- index instead -- see migration_schoolyear.sql.
DROP TABLE IF EXISTS school_years;
CREATE TABLE school_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(20) NOT NULL UNIQUE,
    start_year INT NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    active_guard TINYINT GENERATED ALWAYS AS (IF(is_active = 1, 1, NULL)) VIRTUAL,
    UNIQUE KEY school_years_one_active (active_guard)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


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
-- `major` and `section` were retired in Phase 2/4 and no longer exist.
DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    password VARCHAR(255) NULL,
    department VARCHAR(150) NULL,
    year_level VARCHAR(20) NULL,
    has_voted INT NOT NULL DEFAULT 0,
    registration_status VARCHAR(20) DEFAULT 'unregistered',
    academic_year VARCHAR(10) NULL,
    email VARCHAR(100) NULL,
    school_year_id INT NULL,
    account_status VARCHAR(30) NOT NULL DEFAULT 'normal',
    password_reset_pending BOOLEAN NOT NULL DEFAULT 0,
    KEY users_school_year_id_idx (school_year_id),
    CONSTRAINT users_school_year_id_fkey FOREIGN KEY (school_year_id)
        REFERENCES school_years(id),
    CONSTRAINT users_account_status_check CHECK (account_status IN (
        'normal','repeating','undergrad_repeating',
        'suspended_repeating','dropped','graduated'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TABLE: elections
-- ============================================================
-- target_school_year_id is PURELY metadata: it labels which school year
-- the election is FOR (the 🎓 SY badge). It never projects a student's
-- year level forward -- students vote as whatever level they are now.
DROP TABLE IF EXISTS elections;
CREATE TABLE elections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(10) NOT NULL,
    department VARCHAR(150) NULL,
    status VARCHAR(20) DEFAULT 'draft',
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    results_visibility VARCHAR(20) DEFAULT 'after',
    parties_enabled TINYINT(1) DEFAULT 0,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    parties LONGTEXT NULL,
    target_school_year_id INT NULL,
    KEY elections_target_school_year_id_idx (target_school_year_id),
    KEY idx_elections_status (status),
    CONSTRAINT elections_target_school_year_id_fkey FOREIGN KEY (target_school_year_id)
        REFERENCES school_years(id),
    CONSTRAINT elections_results_visibility_check
        CHECK (results_visibility IN ('always','after','never')),
    CONSTRAINT elections_status_check
        CHECK (status IN ('draft','scheduled','ongoing','paused','closed','archived')),
    CONSTRAINT elections_type_check
        CHECK (type IN ('SSG','DSG'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TABLE: candidates
-- ============================================================
-- `course` is retained for historical rows but is no longer shown or
-- selected anywhere (removed from the UI in Phase 4). `department` is
-- a real reference to departments.code.
DROP TABLE IF EXISTS candidates;
CREATE TABLE candidates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    position_id INT NOT NULL,
    photo LONGTEXT NULL,
    course VARCHAR(100) NULL,
    candidate_year VARCHAR(20) NULL,
    platform TEXT NULL,
    party VARCHAR(100) NULL,
    name VARCHAR(100) NOT NULL,
    department VARCHAR(150) NOT NULL,
    KEY idx_candidates_position (position_id),
    CONSTRAINT candidates_position_id_fkey FOREIGN KEY (position_id)
        REFERENCES positions(id) ON DELETE CASCADE,
    CONSTRAINT candidates_department_fkey FOREIGN KEY (department)
        REFERENCES departments(code) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TABLE: election_positions
-- ============================================================
DROP TABLE IF EXISTS election_positions;
CREATE TABLE election_positions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    election_id INT NOT NULL,
    position_id INT NOT NULL,
    winner_count INT DEFAULT 1,
    candidate_limit INT NULL,
    year_restriction VARCHAR(20) NULL,
    UNIQUE KEY election_positions_election_position (election_id, position_id),
    KEY idx_election_positions_position (position_id),
    CONSTRAINT election_positions_election_id_fkey FOREIGN KEY (election_id)
        REFERENCES elections(id) ON DELETE CASCADE,
    CONSTRAINT election_positions_position_id_fkey FOREIGN KEY (position_id)
        REFERENCES positions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TABLE: login_attempts
-- ============================================================
DROP TABLE IF EXISTS login_attempts;
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(150) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_identifier_time (identifier, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TABLE: majors  (RETIRED -- kept empty and unmanaged)
-- ============================================================
-- Majors were removed in Phase 2/4. The table is intentionally left in
-- place but nothing in the application reads or writes it any more
-- (see the header comment in admin/api/settings.php). It is kept
-- rather than dropped so applying this file stays non-destructive.
DROP TABLE IF EXISTS majors;
CREATE TABLE majors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_code VARCHAR(20) NOT NULL,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TABLE: year_levels
-- ============================================================
-- The label source for every year-level reference in the app. The
-- POSITION of a row in `ORDER BY sort_order, name` is what matters
-- (1st, 2nd, 3rd, 4th) -- not the sort_order value itself, which may
-- start at 0 or 1 depending on how the rows were created.
DROP TABLE IF EXISTS year_levels;
CREATE TABLE year_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(20) NOT NULL UNIQUE,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TABLE: site_settings
-- ============================================================
-- Generic key/value store. Holds the site-wide SSG logo (ssg_logo).
DROP TABLE IF EXISTS site_settings;
CREATE TABLE site_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value LONGTEXT NULL,
    updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TABLE: vote_drafts  ("save for later" -- never counted as a vote)
-- ============================================================
DROP TABLE IF EXISTS vote_drafts;
CREATE TABLE vote_drafts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    election_id INT NOT NULL,
    selections LONGTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY vote_drafts_user_election (user_id, election_id),
    CONSTRAINT vote_drafts_election_id_fkey FOREIGN KEY (election_id)
        REFERENCES elections(id) ON DELETE CASCADE,
    CONSTRAINT vote_drafts_user_id_fkey FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TABLE: votes
-- ============================================================
-- One row per (voter, candidate). Note the unique key is on
-- (user_id, candidate_id), NOT (user_id, position_id): the database
-- cannot stop one voter picking two different candidates for the same
-- position. That rule is enforced in vote.php, which caps selections
-- at the position's winner_count.
DROP TABLE IF EXISTS votes;
CREATE TABLE votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    candidate_id INT NOT NULL,
    position_id INT NOT NULL,
    voted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY votes_user_candidate (user_id, candidate_id),
    KEY idx_votes_candidate (candidate_id),
    KEY idx_votes_position (position_id),
    CONSTRAINT votes_user_id_fkey FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT votes_candidate_id_fkey FOREIGN KEY (candidate_id)
        REFERENCES candidates(id) ON DELETE CASCADE,
    CONSTRAINT votes_position_id_fkey FOREIGN KEY (position_id)
        REFERENCES positions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ############################################################
-- ballot_submissions: one row per (student, election) ballot that was
-- SUBMITTED — including empty ballots (abstentions). With every position
-- now skippable, a submitted ballot can insert zero rows into `votes`,
-- so `votes` alone can no longer answer "did this student vote here?".
-- This table is the authoritative per-election lock: vote.php inserts
-- here inside the voting transaction; all already-voted detection reads
-- here. PRIMARY KEY (user_id, election_id) = at most one submission per
-- student per election, which is what makes a re-submit 409.
-- ############################################################
DROP TABLE IF EXISTS ballot_submissions;
CREATE TABLE ballot_submissions (
    user_id INT NOT NULL,
    election_id INT NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, election_id),
    CONSTRAINT ballot_submissions_user_id_fkey FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT ballot_submissions_election_id_fkey FOREIGN KEY (election_id)
        REFERENCES elections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;


-- ############################################################
-- ####################  DEMO SEED DATA  ######################
-- ###  Everything below is sample content for local dev.   ###
-- ###  Delete this section for a clean production install. ###
-- ############################################################

-- ---- Admin (login: admin / admin123) -----------------------
INSERT INTO admins (username, password_hash, email, role) VALUES
('admin', '$2y$10$MTX5RHnN2DmiKXfWfb.v2OTx1d2tUyOvmow2wJ/HHqXf2H9VjSLFm', 'admin@acc.edu.ph', 'superadmin');

-- ---- Faculties ---------------------------------------------
-- Single-field model: the admin types the full name once and it is
-- stored in BOTH `code` and `name`. The code is what users.department,
-- elections.department and candidates.department reference.
INSERT INTO departments (code, name, sort_order) VALUES
('College of Information and Communications Technology', 'College of Information and Communications Technology', 1),
('College of Business Management and Accountancy',       'College of Business Management and Accountancy',       2),
('College of Arts, Sciences and Education',              'College of Arts, Sciences and Education',              3),
('College of Nursing',                                   'College of Nursing',                                   4);

-- `majors` is retired and intentionally left empty.

-- ---- Year levels -------------------------------------------
INSERT INTO year_levels (name, sort_order) VALUES
('1st Year', 1),
('2nd Year', 2),
('3rd Year', 3),
('4th Year', 4);

-- ---- School years ------------------------------------------
-- Derived from today's date using the same June cutover as
-- currentSchoolYearStartYear() in includes/functions.php, so the
-- seed is always correct no matter when it is loaded. The current
-- school year is flagged active; the next one exists because
-- elections default to targeting it.
SET @sy := YEAR(CURDATE()) - IF(MONTH(CURDATE()) < 6, 1, 0);

INSERT INTO school_years (label, start_year, is_active, sort_order) VALUES
(CONCAT(@sy - 3, '-', @sy - 2), @sy - 3, 0, @sy - 3),
(CONCAT(@sy - 2, '-', @sy - 1), @sy - 2, 0, @sy - 2),
(CONCAT(@sy - 1, '-', @sy    ), @sy - 1, 0, @sy - 1),
(CONCAT(@sy,     '-', @sy + 1), @sy    , 1, @sy    ),
(CONCAT(@sy + 1, '-', @sy + 2), @sy + 1, 0, @sy + 1);

-- ---- Students -----------------------------------------------
-- All demo passwords are "password123". The first two accounts are
-- left "unregistered" so you can try the activation flow at
-- student/register.php with student IDs 20260001 / 20260002.
--
-- school_year_id and year_level are filled in by the backfill below
-- rather than hand-written, so the demo data can never drift out of
-- step with the year_levels table.
INSERT INTO users (student_id, name, password, department, year_level, has_voted, registration_status, email) VALUES
('20260001', 'Liza Bautista',   NULL, 'College of Information and Communications Technology', '1st Year', 0, 'unregistered', NULL),
('20260002', 'Miguel Torres',   NULL, 'College of Business Management and Accountancy',       '1st Year', 0, 'unregistered', NULL),
('987654321', 'Demo Account',   '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Information and Communications Technology', '2nd Year', 0, 'active', 'demo.account@acc.edu.ph'),
('20230101', 'Maria Santos',    '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Information and Communications Technology', '3rd Year', 0, 'active', NULL),
('20230102', 'Julian Reyes',    '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Information and Communications Technology', '4th Year', 0, 'active', NULL),
('20230103', 'Anna Cruz',       '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Information and Communications Technology', '2nd Year', 0, 'active', NULL),
('20230104', 'Carlo Mendoza',   '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Information and Communications Technology', '1st Year', 0, 'active', NULL),
('20230105', 'Sophia Garcia',   '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Information and Communications Technology', '3rd Year', 0, 'active', NULL),
('20230201', 'Rafael Lim',      '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Business Management and Accountancy',       '2nd Year', 0, 'active', NULL),
('20230202', 'Bea Villanueva',  '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Business Management and Accountancy',       '3rd Year', 0, 'active', NULL),
('20230203', 'Diego Ramos',     '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Business Management and Accountancy',       '1st Year', 0, 'active', NULL),
('20230204', 'Karla Navarro',   '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Business Management and Accountancy',       '4th Year', 0, 'active', NULL),
('20230301', 'Ella Domingo',    '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Arts, Sciences and Education',              '1st Year', 0, 'active', NULL),
('20230302', 'Paolo Salazar',   '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Arts, Sciences and Education',              '2nd Year', 0, 'active', NULL),
('20230303', 'Rizza Fernandez', '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Arts, Sciences and Education',              '3rd Year', 0, 'active', NULL),
('20230304', 'Marco Aquino',    '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Arts, Sciences and Education',              '4th Year', 0, 'active', NULL),
('20230305', 'Trina Alvarado',  '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Arts, Sciences and Education',              '1st Year', 0, 'active', NULL),
('20230401', 'Gabriel Ureta',   '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Nursing', '2nd Year', 0, 'active', NULL),
('20230402', 'Hannah Sibayan',  '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Nursing', '3rd Year', 0, 'active', NULL),
('20230403', 'Ivo Beltran',     '$2y$10$xWhLKxNXXmf.r.gJcS5JLOVMrLzipDcH2DV999/koNPFhFz2q2vNC', 'College of Nursing', '1st Year', 0, 'active', NULL);

-- Backfill each student's start year from their year level, using the
-- RANK of that level in year_levels (not the raw sort_order value, which
-- is not guaranteed to be 0-based). Same rule as computeYearLevel().
UPDATE users u
  JOIN (
        SELECT name,
               ROW_NUMBER() OVER (ORDER BY sort_order, name) AS pos
          FROM year_levels
       ) yl ON yl.name = u.year_level
  JOIN school_years sy ON sy.start_year = @sy - (yl.pos - 1)
   SET u.school_year_id = sy.id;

-- ############################################################
-- Elections use NOW()-relative dates so the demo stays valid:
--   Election 1 (SSG):   ongoing right now, ends in 4 days
--   Election 2 (DSG):   starts in 3 days (upcoming)
--   Election 3 (DSG):   ended 2 weeks ago (results visible)
-- All three target the UPCOMING school year, which is what
-- admin/api/elections.php now defaults to.
-- ############################################################

INSERT INTO elections (name, type, department, status, start_date, end_date, results_visibility, parties_enabled, parties, target_school_year_id) VALUES
('SSG General Elections 2026', 'SSG', NULL, 'ongoing',
 DATE_SUB(NOW(), INTERVAL 12 HOUR), DATE_ADD(NOW(), INTERVAL 4 DAY),
 'always', 1, '["UNITY PARTY","PROGRESSIVE ALLIANCE"]',
 (SELECT id FROM school_years WHERE start_year = @sy + 1)),

('FICT Department Student Council Election 2026', 'DSG',
 'College of Information and Communications Technology', 'scheduled',
 DATE_ADD(NOW(), INTERVAL 3 DAY), DATE_ADD(NOW(), INTERVAL 6 DAY),
 'after', 0, '[]',
 (SELECT id FROM school_years WHERE start_year = @sy + 1)),

('CASED Student Council Election 2026', 'DSG',
 'College of Arts, Sciences and Education', 'closed',
 DATE_SUB(NOW(), INTERVAL 16 DAY), DATE_SUB(NOW(), INTERVAL 14 DAY),
 'always', 0, '[]',
 (SELECT id FROM school_years WHERE start_year = @sy + 1));

-- ---- Positions + candidates ---------------------------------
-- SSG election (election 1)
INSERT INTO positions (title) VALUES ('President'),('Vice President'),('Secretary'),('Treasurer'),('Auditor');
INSERT INTO election_positions (election_id, position_id, winner_count) VALUES
(1, 1, 1),(1, 2, 1),(1, 3, 1),(1, 4, 1),(1, 5, 1);
INSERT INTO candidates (position_id, name, course, candidate_year, platform, party, department) VALUES
(1, 'Maria Santos',   'BS Computer Science',        '3rd Year', 'Transparent budget reports every quarter and open town halls each month.', 'UNITY PARTY',           'College of Information and Communications Technology'),
(1, 'Julian Reyes',   'BS Information Technology',  '4th Year', 'A student-led innovation fund for club projects and research.',            'PROGRESSIVE ALLIANCE', 'College of Information and Communications Technology'),
(1, 'Anna Cruz',      'AB English Language',        '4th Year', 'Stronger student welfare: study hubs, mental health desk, fair fees.',     'UNITY PARTY',           'College of Information and Communications Technology'),
(2, 'Carlo Mendoza',  'BS Computer Science',        '1st Year', 'Digital suggestion box with published response times.',                    'PROGRESSIVE ALLIANCE', 'College of Information and Communications Technology'),
(2, 'Sophia Garcia',  'BS Information Technology',  '3rd Year', 'Peer tutoring network covering all faculties.',                             'UNITY PARTY',           'College of Information and Communications Technology'),
(3, 'Rafael Lim',     'BS Business Administration', '2nd Year', 'Clean, audited minutes released within 48 hours of every meeting.',        'UNITY PARTY',           'College of Business Management and Accountancy'),
(3, 'Bea Villanueva', 'BS Accountancy',             '3rd Year', 'Paperless documentation and open records portal.',                         'PROGRESSIVE ALLIANCE', 'College of Business Management and Accountancy'),
(4, 'Diego Ramos',    'BS Office Administration',   '1st Year', 'Itemized monthly financial summaries posted on every bulletin.',           'PROGRESSIVE ALLIANCE', 'College of Business Management and Accountancy'),
(4, 'Karla Navarro',  'BS Business Administration', '4th Year', 'Zero-hidden-fees policy and student-funded scholarship top-ups.',          'UNITY PARTY',           'College of Business Management and Accountancy'),
(5, 'Gabriel Ureta',  'BS Nursing',                 '2nd Year', 'Independent audit committee with rotating student members.',               'UNITY PARTY',           'College of Nursing'),
(5, 'Hannah Sibayan', 'BS Nursing',                 '3rd Year', 'Publish event budgets before approval, not after.',                        'PROGRESSIVE ALLIANCE', 'College of Nursing');

-- FICT DSG election (election 2)
INSERT INTO positions (title) VALUES ('Governor'),('Vice Governor');
INSERT INTO election_positions (election_id, position_id, winner_count) VALUES
(2, 6, 1),(2, 7, 1);
INSERT INTO candidates (position_id, name, course, candidate_year, platform, party, department) VALUES
(6, 'Marco Aquino',   'BS Information Technology', '4th Year', 'Hackathon lab hours extended to weekends and evenings.', 'No Party / Independent', 'College of Information and Communications Technology'),
(6, 'Ivo Beltran',    'BS Computer Science',       '3rd Year', 'Free certification review sessions every semester.',     'No Party / Independent', 'College of Information and Communications Technology'),
(7, 'Rizza Fernandez','BS Information Systems',    '2nd Year', 'Peer mentoring: every freshman paired with a senior.',   'No Party / Independent', 'College of Information and Communications Technology');

-- CASED DSG election (election 3, ended — tallies below)
INSERT INTO positions (title) VALUES ('Governor'),('Vice Governor'),('Secretary');
INSERT INTO election_positions (election_id, position_id, winner_count) VALUES
(3, 8, 1),(3, 9, 1),(3, 10, 1);
INSERT INTO candidates (position_id, name, course, candidate_year, platform, party, department) VALUES
(8,  'Paolo Salazar',  'Bachelor of Secondary Education',   '2nd Year', 'Reading remediation program for partner schools.', 'No Party / Independent', 'College of Arts, Sciences and Education'),
(8,  'Trina Alvarado', 'Bachelor of Elementary Education',  '1st Year', 'Annual inter-school teaching showcase.',           'No Party / Independent', 'College of Arts, Sciences and Education'),
(9,  'Ella Domingo',   'Bachelor of Elementary Education',  '1st Year', 'Storybook donation drives every quarter.',         'No Party / Independent', 'College of Arts, Sciences and Education'),
(9,  'Liza Bautista',  'AB English Language',                '3rd Year', 'Debate and public-speaking circuit for CASED.',    'No Party / Independent', 'College of Arts, Sciences and Education'),
(10, 'Marco Aquino',   'Bachelor of Secondary Education',   '4th Year', 'Digital attendance and minutes for all councils.', 'No Party / Independent', 'College of Arts, Sciences and Education');

-- ---- Votes for the ENDED CASED election ----------------------
-- All 5 active CASED students "voted"; their tallies show up on the
-- public results page immediately. They're done voting in that
-- election either way since it's closed.
-- Candidate ids here: Governor = 15 (Paolo) / 16 (Trina),
-- Vice Governor = 17 (Ella) / 18 (Liza), Secretary = 19 (Marco).
INSERT INTO votes (user_id, candidate_id, position_id) VALUES
(13, 15, 8),(13, 17, 9),(13, 19, 10),
(14, 16, 8),(14, 18, 9),(14, 19, 10),
(15, 15, 8),(15, 17, 9),(15, 19, 10),
(16, 16, 8),(16, 18, 9),(16, 19, 10),
(17, 15, 8),(17, 17, 9),(17, 19, 10);

UPDATE users SET has_voted = 1 WHERE id IN (13,14,15,16,17);

-- ---- Activity log seed ---------------------------------------
INSERT INTO activity_logs (admin_username, action, created_at) VALUES
('admin', 'Created election "SSG General Elections 2026"', DATE_SUB(NOW(), INTERVAL 5 DAY)),
('admin', 'Created election "FICT Department Student Council Election 2026"', DATE_SUB(NOW(), INTERVAL 4 DAY)),
('admin', 'Created election "CASED Student Council Election 2026"', DATE_SUB(NOW(), INTERVAL 18 DAY));
